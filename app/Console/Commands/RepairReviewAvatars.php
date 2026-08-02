<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\ProfileReview;
use App\Services\Top20BulkImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Переімпортовує відгуки для профілів, у яких аватарки авторів масово втрачені
 * (записані NULL через тротлінг Top20 у пікові дні імпорту). Тягне лише відгуки
 * з Top20-картки, поля профілю не чіпає. Запускати на хостингу, щоб файли лягли
 * на бойовий диск.
 */
class RepairReviewAvatars extends Command
{
    protected $signature = 'dovira:repair-review-avatars
        {--min-reviews=5 : Лише профілі, де щонайменше стільки зовнішніх відгуків}
        {--null-threshold=70 : Поріг % відгуків без аватарки, щоб профіль вважати постраждалим}
        {--limit=0 : Обмежити кількість профілів (0 = всі)}
        {--sleep=1 : Пауза між профілями, сек}
        {--dry-run : Лише показати список, без переімпорту}';

    protected $description = 'Відновлює втрачені аватарки відгуків через переімпорт з Top20 (лише відгуки).';

    public function handle(Top20BulkImportService $service): int
    {
        $minReviews = max(1, (int) $this->option('min-reviews'));
        $nullThreshold = max(1, min(100, (int) $this->option('null-threshold')));
        $limit = max(0, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Шукаю профілі з масово втраченими аватарками відгуків...');

        // Профілі із зовнішніми відгуками, де частка NULL-аватарок вища за поріг.
        $candidates = ProfileReview::query()
            ->where('verification_type', 'external_google_import')
            ->groupBy('profile_id')
            ->havingRaw('count(*) >= ?', [$minReviews])
            ->havingRaw('(sum(external_review_author_avatar_url is null) * 100 / count(*)) >= ?', [$nullThreshold])
            ->selectRaw('profile_id, count(*) total, sum(external_review_author_avatar_url is null) null_av')
            ->get();

        // Лишаємо тільки ті, у яких є Top20-джерело (є звідки переімпортувати).
        $sourced = DB::table('profile_data_sources')
            ->whereIn('source_type', ['top20_import', 'top20'])
            ->whereNotNull('url')
            ->whereNotNull('profile_id')
            ->pluck('profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $sourced = array_fill_keys($sourced, true);

        $targets = $candidates->filter(fn ($row) => isset($sourced[(int) $row->profile_id]))->values();
        if ($limit > 0) {
            $targets = $targets->take($limit);
        }

        $this->line(sprintf(
            'Постраждалих профілів з Top20-джерелом: %d (відгуків без аватарки: %d)',
            $targets->count(),
            $targets->sum('null_av')
        ));

        if ($targets->isEmpty()) {
            $this->info('Нічого відновлювати.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['profile_id', 'назва', 'відгуків', 'без аватарки'],
                $targets->take(25)->map(function ($row) {
                    $p = Profile::find($row->profile_id);

                    return [$row->profile_id, mb_substr((string) ($p->name ?? '?'), 0, 40), $row->total, $row->null_av];
                })->all()
            );
            $this->comment('Dry-run: переімпорт не виконувався.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($targets->count());
        $bar->start();
        $recovered = 0;
        $profilesTouched = 0;
        $errors = 0;

        foreach ($targets as $row) {
            $profile = Profile::find($row->profile_id);
            $bar->advance();

            if (! $profile) {
                continue;
            }

            $top20Url = $service->resolveProfileTop20Url($profile);
            if ($top20Url === null) {
                continue;
            }

            $before = ProfileReview::query()
                ->where('profile_id', $profile->id)
                ->whereNotNull('external_review_author_avatar_url')
                ->count();

            try {
                $service->importReviewsFromTop20Url($profile, $top20Url);
            } catch (\Throwable $e) {
                $errors++;
                continue;
            }

            $after = ProfileReview::query()
                ->where('profile_id', $profile->id)
                ->whereNotNull('external_review_author_avatar_url')
                ->count();

            $delta = max(0, $after - $before);
            if ($delta > 0) {
                $recovered += $delta;
                $profilesTouched++;
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(['Метрика', 'Значення'], [
            ['Профілів оброблено', (string) $targets->count()],
            ['Профілів з відновленими аватарками', (string) $profilesTouched],
            ['Аватарок відновлено', (string) $recovered],
            ['Помилок', (string) $errors],
        ]);

        return self::SUCCESS;
    }
}

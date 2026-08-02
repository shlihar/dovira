<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * Вивантажує профілі-кандидати на досьє у JSONL-пакети (по 50 за замовч.) —
 * формат під зовнішню генерацію (ChatGPT/Deep Research). Назад результат
 * повертається командою dovira:import-dossiers.
 */
class ExportDossierCandidates extends Command
{
    protected $signature = 'dovira:export-dossier-candidates
        {--category=90 : ID категорії (за замовч. Адвокати)}
        {--limit=0 : Скільки профілів максимум (0 — всі)}
        {--chunk=50 : Розмір пакета}
        {--base-url=https://test.top-shop.website : Базовий URL для profile_url}
        {--with-existing : Включати профілі, що вже мають досьє}';

    protected $description = 'Вивантажує кандидатів на AI-досьє у JSONL-пакети для зовнішньої генерації.';

    public function handle(): int
    {
        $categoryId = (int) $this->option('category');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));
        $baseUrl = rtrim((string) $this->option('base-url'), '/');

        $query = Profile::query()
            ->where('status', 'active')
            ->whereHas('categories', fn ($q) => $q->where('categories.id', $categoryId))
            ->when(! $this->option('with-existing'), fn ($q) => $q->whereNull('dossier')->whereNull('dossier_exported_at'))
            ->with('categories:id,name')
            // Спершу найвідвідуваніші/найбільш наповнені — вони дають трафік.
            ->orderByDesc('reviews_count')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $profiles = $query->get([
            'id', 'name', 'slug', 'city', 'address', 'phone', 'website', 'type', 'short_description', 'description', 'reviews_count',
        ]);

        if ($profiles->isEmpty()) {
            $this->info('Кандидатів немає (всі вже з досьє?).');

            return self::SUCCESS;
        }

        $dir = storage_path('app/dossier-batches');
        @mkdir($dir, 0775, true);
        $stamp = now()->format('Ymd-His').'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));

        $files = 0;
        foreach ($profiles->chunk($chunk) as $i => $batch) {
            $path = sprintf('%s/dossier-batch-%s-%03d.jsonl', $dir, $stamp, $i + 1);
            $fh = fopen($path, 'w');

            foreach ($batch as $p) {
                $identity = array_filter([
                    'category' => $p->categories->pluck('name')->implode(', '),
                    'address' => $p->address,
                    'phone' => $p->phone,
                    'type' => $p->type,
                    'about_excerpt' => \Illuminate\Support\Str::limit(trim(strip_tags((string) ($p->short_description ?: $p->description))), 200, '…') ?: null,
                ]);

                fwrite($fh, json_encode([
                    'lawyer_id' => $p->id,
                    'full_name' => $p->name,
                    'city' => $p->city,
                    // Окремої колонки немає: для компаній дублюємо назву,
                    // для персон — нехай знайде зовнішній пошук.
                    'company_name' => $p->type === 'company' ? $p->name : null,
                    'certificate_number' => null,
                    'profile_url' => $baseUrl.'/profiles/'.$p->slug,
                    'company_url' => $p->website ?: null,
                    'additional_identity_data' => $identity === [] ? null : $identity,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            }

            fclose($fh);
            $files++;
            $this->line(sprintf('%s — %d профілів', basename($path), $batch->count()));
        }

        // Позначаємо вигружені — у наступні пакети (CLI чи адмінка) не потраплять.
        Profile::query()->whereIn('id', $profiles->pluck('id'))->update(['dossier_exported_at' => now()]);

        $this->info("Готово: {$profiles->count()} профілів у {$files} файлах → {$dir}");

        return self::SUCCESS;
    }
}

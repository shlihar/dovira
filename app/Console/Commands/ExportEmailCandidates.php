<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Вивантажує профілі БЕЗ email у JSONL-пакети для зовнішнього пошуку пошти
 * (ChatGPT/Deep Research — той самий конвеєр, що й досьє: експорт → генерація
 * в чаті → dovira:import-emails). Поруч із пакетами кладе PROMPT.txt, який
 * можна вставити в ChatGPT разом із вмістом пакета.
 *
 * Приклад: dovira:export-email-candidates --only-dossier --chunk=50
 */
class ExportEmailCandidates extends Command
{
    protected $signature = 'dovira:export-email-candidates
        {--category=Адвокати : Назва категорії}
        {--only-dossier : Лише профілі з досьє (пріоритетний сегмент)}
        {--limit=0 : Скільки профілів максимум (0 — всі)}
        {--chunk=50 : Розмір пакета}';

    protected $description = 'Вивантажує профілі без email у JSONL-пакети для пошуку пошти через ChatGPT';

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $ids = CategoryHierarchy::categoryIdsFromNames([(string) $this->option('category')]);
        if (empty($ids)) {
            $this->error('Категорію не знайдено.');

            return self::FAILURE;
        }

        $query = Profile::query()
            ->where('status', 'active')
            ->whereNull('owner_user_id')
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
            ->whereIn('id', fn ($q) => $q->select('profile_id')->from('profile_category')->whereIn('category_id', $ids))
            ->when($this->option('only-dossier'), fn ($q) => $q->whereNotNull('dossier'))
            // Спершу ті, де більше зачіпок для пошуку (сайт, відгуки).
            ->orderByRaw("case when website is not null and website != '' then 0 else 1 end")
            ->orderByDesc('reviews_count')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $profiles = $query->get(['id', 'name', 'city', 'address', 'phone', 'website', 'type']);

        if ($profiles->isEmpty()) {
            $this->info('Кандидатів немає — у всіх уже є email.');

            return self::SUCCESS;
        }

        $dir = storage_path('app/email-batches');
        @mkdir($dir, 0775, true);
        $stamp = now()->format('Ymd-His') . '-' . Str::lower(Str::random(4));

        $files = 0;
        foreach ($profiles->chunk($chunk) as $i => $batch) {
            $path = sprintf('%s/email-batch-%s-%03d.jsonl', $dir, $stamp, $i + 1);
            $fh = fopen($path, 'w');

            foreach ($batch as $p) {
                fwrite($fh, json_encode([
                    'lawyer_id' => $p->id,
                    'full_name' => $p->name,
                    'city' => $p->city,
                    'address' => $p->address ?: null,
                    'phone' => $p->phone ?: null,
                    'website' => $p->website ?: null,
                    'type' => $p->type,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }

            fclose($fh);
            $files++;
            $this->line(sprintf('%s — %d профілів', basename($path), $batch->count()));
        }

        // Промпт для ChatGPT — поруч із пакетами, щоб не шукати щоразу.
        file_put_contents($dir . '/PROMPT.txt', $this->prompt());

        $this->info("Готово: {$profiles->count()} профілів у {$files} файлах → {$dir}");
        $this->line('Далі: відкрий ChatGPT → встав PROMPT.txt + вміст пакета → результат збережи у файл →');
        $this->line('php artisan dovira:import-emails <файл> --dry-run');

        return self::SUCCESS;
    }

    private function prompt(): string
    {
        return <<<'TXT'
Ти — асистент з пошуку публічних контактів. Нижче JSONL-список українських адвокатів
та юридичних компаній (імʼя, місто, телефон, іноді сайт).

ЗАВДАННЯ: для кожного рядка знайди через веб-пошук ПУБЛІЧНИЙ контактний email саме
цієї особи/компанії. Шукай за: "ПІБ місто адвокат email", телефоном, сайтом (сторінки
«Контакти»), каталогами (uadvokat, jurliga, linkedin, facebook-сторінки бізнесу).

ПРАВИЛА:
- Повертай email ЛИШЕ якщо впевнений, що він належить саме цій особі/компанії
  (збігається імʼя/телефон/сайт). Якщо сумніваєшся — email: null.
- НЕ ВИГАДУЙ адрес. Краще null, ніж хибний контакт.
- source_url — сторінка, де знайшов email.

ФОРМАТ ВІДПОВІДІ — лише JSONL, по рядку на кожного, без пояснень:
{"lawyer_id": 123, "email": "adv@example.com", "source_url": "https://…"}
{"lawyer_id": 124, "email": null, "source_url": null}
TXT;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\ProfileCases;
use Illuminate\Console\Command;

/**
 * Ручне наповнення блоку кейсів (перші профілі заповнюємо самі, далі власники
 * редагують у кабінеті).
 *
 * Формат файлу — JSON-масив:
 * [
 *   {
 *     "slug": "kasianenko-i-partneri...",
 *     "cases": [
 *       {"title": "...", "task": "...", "result": "...", "tag": "...",
 *        "period": "...", "review_id": 123456, "review_date": "03.2026"}
 *     ]
 *   }
 * ]
 *
 * review_id — id РЕАЛЬНОГО опублікованого відгуку, з якого складено кейс.
 * Команда перевіряє, що такий відгук існує саме в цього профілю: кейс без
 * підтвердження нічим не кращий за рекламний текст, а з підтвердженням його
 * може перевірити будь-хто на цій же сторінці.
 */
class ImportProfileCases extends Command
{
    protected $signature = 'dovira:cases:import
        {file : Шлях до JSON-файлу з кейсами}
        {--dry-run : Показати, що буде записано, без збереження}
        {--replace : Замінити наявні кейси профілю (за замовчуванням — теж заміна, прапорець лишено для явності)}';

    protected $description = 'Імпортує кейси PRO-профілів із JSON-файлу';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($rows)) {
            $this->error('Некоректний JSON: ' . json_last_error_msg());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $saved = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            $profile = $slug !== '' ? Profile::query()->where('slug', $slug)->first() : null;

            if (! $profile) {
                $this->warn("Профіль не знайдено: {$slug}");
                $skipped++;

                continue;
            }

            $cases = ProfileCases::normalize($row['cases'] ?? []);
            if ($cases === []) {
                $this->warn("Немає жодного придатного кейсу: {$slug}");
                $skipped++;

                continue;
            }

            // Перевірка підтверджень: id відгуку має належати цьому профілю
            // й бути опублікованим, інакше посилання «за відгуком клієнта»
            // веде в нікуди і працює проти довіри.
            foreach ($cases as $index => $case) {
                $reviewId = (int) ($case['review_id'] ?? 0);
                if ($reviewId <= 0) {
                    continue;
                }

                $exists = $profile->reviews()
                    ->whereKey($reviewId)
                    ->where('status', 'published')
                    ->exists();

                if (! $exists) {
                    $this->warn("{$slug}: відгук #{$reviewId} не знайдено серед опублікованих — підтвердження знято.");
                    unset($cases[$index]['review_id'], $cases[$index]['review_date']);
                }
            }

            $cases = array_values($cases);

            $aiData = (array) ($profile->ai_suggested_data ?? []);
            $ownerProfile = is_array($aiData['owner_profile'] ?? null) ? $aiData['owner_profile'] : [];
            $ownerProfile['cases'] = $cases;
            $aiData['owner_profile'] = $ownerProfile;

            $this->line(sprintf(
                '%s %s — кейсів: %d%s',
                $dryRun ? '[dry-run]' : '[ok]',
                $profile->name,
                count($cases),
                $profile->hasActiveProSubscription() ? '' : ' (УВАГА: профіль без активної PRO-підписки)'
            ));

            if (! $dryRun) {
                $profile->ai_suggested_data = $aiData;
                $profile->saveQuietly();
            }

            $saved++;
        }

        $this->newLine();
        $this->info(sprintf('Готово. Профілів оброблено: %d, пропущено: %d.', $saved, $skipped));

        return self::SUCCESS;
    }
}

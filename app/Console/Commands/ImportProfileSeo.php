<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Дозаливка крафтових SEO-полів для профілів, у яких уже є досьє, але
 * seo_title/seo_description лишилися порожніми (старі пачки імпорту).
 *
 * Формат JSONL, по одному об'єкту на рядок:
 *   {"id": 123, "seo_title": "...", "meta_description": "..."}
 *
 * Досьє, вердикт і будь-які інші поля команда не чіпає — тільки SEO.
 */
class ImportProfileSeo extends Command
{
    protected $signature = 'dovira:import-profile-seo {file} {--dry-run} {--force : перезаписувати вже заповнені поля}';

    protected $description = 'Дозалити seo_title і seo_description для профілів із досьє';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $lineNumber = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $lineNumber++;
            $row = json_decode($line, true);

            if (! is_array($row)) {
                $this->error("Рядок {$lineNumber}: не JSON");
                $errors++;

                continue;
            }

            $id = (int) ($row['id'] ?? $row['lawyer_id'] ?? 0);
            $profile = $id > 0 ? Profile::find($id) : null;

            if ($profile === null) {
                $this->error("Рядок {$lineNumber}: профіль {$id} не знайдено");
                $errors++;

                continue;
            }

            $fill = [];
            $title = trim((string) ($row['seo_title'] ?? ''));
            $description = trim((string) ($row['meta_description'] ?? $row['seo_description'] ?? ''));

            if ($title !== '' && ($this->option('force') || blank($profile->seo_title))) {
                $fill['seo_title'] = Str::limit($title, 255, '');
            }

            if ($description !== '' && ($this->option('force') || blank($profile->seo_description))) {
                $fill['seo_description'] = Str::limit($description, 160, '');
            }

            if ($fill === []) {
                $skipped++;

                continue;
            }

            if (! $this->option('dry-run')) {
                $profile->forceFill($fill)->saveQuietly();
            }

            $this->line("{$id} {$profile->name}: ".implode(', ', array_keys($fill)));
            $updated++;
        }

        $this->info(($this->option('dry-run') ? '(dry-run) ' : '')."Оновлено: {$updated}, пропущено: {$skipped}, помилок: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

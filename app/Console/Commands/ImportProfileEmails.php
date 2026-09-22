<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * Імпортує знайдені зовні (ChatGPT/Deep Research) email з JSONL у профілі —
 * друга половина конвеєра dovira:export-email-candidates. Пише лише в ПОРОЖНЄ
 * поле email (ручні дані не перетирає). Формат рядка:
 * {"lawyer_id": 123, "email": "x@y.ua", "source_url": "https://…"} —
 * ключі lawyer_id|profile_id|id приймаються як синоніми, email: null пропускається.
 */
class ImportProfileEmails extends Command
{
    protected $signature = 'dovira:import-emails
        {file : Шлях до JSONL-файлу з результатами}
        {--dry-run : Перевірити файл без збереження}';

    protected $description = 'Імпортує email з JSONL-файлу (конвеєр ChatGPT) у порожні поля профілів';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $imported = 0;
        $skippedNull = 0;
        $skippedTaken = 0;
        $errors = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNo => $line) {
            $line = trim($line);
            // ChatGPT інколи загортає відповідь у ```json — толерантно чистимо.
            if ($line === '' || str_starts_with($line, '```')) {
                continue;
            }

            $row = json_decode($line, true);
            if (! is_array($row)) {
                $this->error('Рядок ' . ($lineNo + 1) . ': не JSON — пропущено.');
                $errors++;

                continue;
            }

            $id = (int) ($row['lawyer_id'] ?? $row['profile_id'] ?? $row['id'] ?? 0);
            $email = $row['email'] ?? null;

            if ($id <= 0) {
                $this->error('Рядок ' . ($lineNo + 1) . ': нема lawyer_id — пропущено.');
                $errors++;

                continue;
            }

            if (! is_string($email) || trim($email) === '') {
                $skippedNull++;

                continue;
            }

            $email = mb_strtolower(trim($email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Рядок ' . ($lineNo + 1) . ": невалідний email «{$email}» — пропущено.");
                $errors++;

                continue;
            }

            $profile = Profile::query()->find($id, ['id', 'name', 'email']);
            if (! $profile) {
                $this->error('Рядок ' . ($lineNo + 1) . ": профіль #{$id} не знайдено.");
                $errors++;

                continue;
            }

            if (filled($profile->email)) {
                $skippedTaken++;

                continue;
            }

            $this->line(sprintf('  #%d %s → %s', $id, mb_substr((string) $profile->name, 0, 34), $email));

            if (! $dryRun) {
                rescue(fn () => Profile::query()
                    ->whereKey($id)
                    ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
                    ->update(['email' => $email]));
            }

            $imported++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s Імпортовано: %d, без email (null): %d, вже мали email: %d, помилок: %d.',
            $dryRun ? '[dry-run]' : 'Готово.',
            $imported,
            $skippedNull,
            $skippedTaken,
            $errors
        ));

        return self::SUCCESS;
    }
}

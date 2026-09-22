<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * Імпортує знайдені зовні (ChatGPT/Deep Research) контакти з JSONL у профілі —
 * друга половина конвеєра dovira:export-contact-candidates. Заповнює ЛИШЕ порожні
 * поля email/website та ДОДАЄ нові соцмережі (наявні ключі social_links не перетирає).
 * Формат рядка (ключі lawyer_id|profile_id|id — синоніми):
 * {"lawyer_id":123,"email":"x@y.ua","website":"https://…","socials":{"facebook":"https://…"}}
 */
class ImportProfileContacts extends Command
{
    /** Соцмережі, які показує сайт/кабінет — інші ключі ігноруємо. */
    private const ALLOWED_SOCIALS = ['facebook', 'instagram', 'telegram', 'linkedin', 'youtube', 'viber', 'whatsapp'];

    protected $signature = 'dovira:import-contacts
        {file : Шлях до JSONL-файлу з результатами}
        {--dry-run : Перевірити файл без збереження}';

    protected $description = 'Імпортує email/сайт/соцмережі з JSONL-файлу (конвеєр ChatGPT) у профілі';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $emails = 0;
        $sites = 0;
        $socials = 0;
        $touched = 0;
        $skippedEmpty = 0;
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
            if ($id <= 0) {
                $this->error('Рядок ' . ($lineNo + 1) . ': нема lawyer_id — пропущено.');
                $errors++;

                continue;
            }

            $profile = Profile::query()->find($id, ['id', 'name', 'email', 'website', 'social_links']);
            if (! $profile) {
                $this->error('Рядок ' . ($lineNo + 1) . ": профіль #{$id} не знайдено.");
                $errors++;

                continue;
            }

            $updates = [];

            // Email — лише у порожнє поле, з валідацією.
            $email = $this->cleanEmail($row['email'] ?? null, $lineNo, $errors);
            if ($email !== null && blank($profile->email)) {
                $updates['email'] = $email;
                $emails++;
            }

            // Website — лише у порожнє поле, з нормалізацією схеми.
            $website = $this->cleanUrl($row['website'] ?? null);
            if ($website !== null && blank($profile->website)) {
                $updates['website'] = $website;
                $sites++;
            }

            // Соцмережі — злиття: додаємо тільки нові ключі, наявні не чіпаємо.
            $existingSocials = array_filter((array) ($profile->social_links ?? []));
            $newSocials = $existingSocials;
            foreach ((array) ($row['socials'] ?? []) as $network => $url) {
                $network = mb_strtolower(trim((string) $network));
                if (! in_array($network, self::ALLOWED_SOCIALS, true) || isset($existingSocials[$network])) {
                    continue;
                }
                $url = $this->cleanUrl($url);
                if ($url !== null) {
                    $newSocials[$network] = $url;
                    $socials++;
                }
            }
            if (count($newSocials) !== count($existingSocials)) {
                $updates['social_links'] = $newSocials;
            }

            if (empty($updates)) {
                $skippedEmpty++;

                continue;
            }

            $summary = collect($updates)->map(fn ($v, $k) => $k === 'social_links'
                ? 'socials:' . implode(',', array_keys(array_diff_key($v, $existingSocials)))
                : "{$k}:{$v}")->implode('  ');
            $this->line(sprintf('  #%d %s → %s', $id, mb_substr((string) $profile->name, 0, 30), $summary));

            if (! $dryRun) {
                rescue(fn () => tap(Profile::query()->find($id), function (Profile $fresh) use ($updates): void {
                    // Повторна перевірка на порожнечу — на випадок гонки з ручним редагуванням.
                    if (isset($updates['email']) && filled($fresh->email)) {
                        unset($updates['email']);
                    }
                    if (isset($updates['website']) && filled($fresh->website)) {
                        unset($updates['website']);
                    }
                    if (! empty($updates)) {
                        $fresh->forceFill($updates)->save();
                    }
                }));
            }

            $touched++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s Оновлено профілів: %d (email: %d, сайт: %d, соцмереж: %d). Без нових даних: %d, помилок: %d.',
            $dryRun ? '[dry-run]' : 'Готово.',
            $touched,
            $emails,
            $sites,
            $socials,
            $skippedEmpty,
            $errors
        ));

        return self::SUCCESS;
    }

    private function cleanEmail(mixed $value, int $lineNo, int &$errors): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $email = mb_strtolower(trim($value));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Рядок ' . ($lineNo + 1) . ": невалідний email «{$email}» — пропущено.");
            $errors++;

            return null;
        }

        return $email;
    }

    private function cleanUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}

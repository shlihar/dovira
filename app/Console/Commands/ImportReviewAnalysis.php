<?php

namespace App\Console\Commands;

use App\Services\ProfileReviewAiAnalysisService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Заливає згенеровані поза застосунком AI-висновки по відгуках у профілі.
 * Формат — JSONL або JSON-масив, один запис на профіль:
 * {"profile_id": 1, "sentiment": "positive",
 *  "summary": [{"type": "pos", "text": "…"}, {"type": "tip", "text": "…"}],
 *  "people": [{"name": "…", "role": "", "text": "…"}]}
 */
class ImportReviewAnalysis extends Command
{
    protected $signature = 'dovira:import-review-analysis
        {file : Шлях до JSONL або JSON-файлу з висновками}
        {--provider=claude : Значення поля provider у збереженому аналізі}
        {--dry-run : Перевірити файл без запису в базу}';

    protected $description = 'Імпортує готові AI-висновки по відгуках у профілі (без звернення до OpenAI).';

    public function handle(ProfileReviewAiAnalysisService $service): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readRows($path);
        if ($rows === null) {
            $this->error('Не вдалося розібрати файл: очікується JSON-масив або JSONL.');

            return self::FAILURE;
        }

        $provider = trim((string) $this->option('provider')) ?: 'claude';
        $imported = 0;
        $errors = 0;

        foreach ($rows as $index => $row) {
            $label = 'Запис '.($index + 1);

            if (! is_array($row)) {
                $this->error("{$label}: не об'єкт — пропущено.");
                $errors++;

                continue;
            }

            $profileId = (int) ($row['profile_id'] ?? $row['id'] ?? 0);
            $summary = (array) ($row['summary'] ?? []);

            if ($profileId <= 0 || $summary === []) {
                $this->error("{$label}: немає profile_id або summary — пропущено.");
                $errors++;

                continue;
            }

            $decoded = [
                'summary' => $summary,
                'people' => (array) ($row['people'] ?? []),
                'sentiment' => (string) ($row['sentiment'] ?? 'neutral'),
            ];

            if ($this->option('dry-run')) {
                $this->line("{$label}: #{$profileId} — ok");
                $imported++;

                continue;
            }

            try {
                $stored = $service->storeGeneratedAnalysis($profileId, $decoded, $provider);
            } catch (Throwable $exception) {
                $this->error("{$label}: #{$profileId} — {$exception->getMessage()}");
                $errors++;

                continue;
            }

            if (! $stored) {
                $this->error("{$label}: #{$profileId} — профіль не знайдено або бракує обов'язкових пунктів (pos + tip).");
                $errors++;

                continue;
            }

            $imported++;
        }

        $this->info(($this->option('dry-run') ? 'Перевірено' : 'Імпортовано').": {$imported}, помилок: {$errors}.");

        return $errors > 0 && $imported === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function readRows(string $path): ?array
    {
        $contents = trim((string) file_get_contents($path));
        if ($contents === '') {
            return [];
        }

        if (str_starts_with($contents, '[')) {
            $decoded = json_decode($contents, true);

            return is_array($decoded) ? array_values($decoded) : null;
        }

        $rows = [];
        // Не \R: без модифікатора /u він матчить байт \x85, який зустрічається
        // всередині UTF-8 кирилиці і рве рядок посеред слова.
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $rows[] = json_decode($line, true);
        }

        return $rows;
    }
}

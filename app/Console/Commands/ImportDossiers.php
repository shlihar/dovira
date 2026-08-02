<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * Імпортує згенеровані зовні (ChatGPT/Deep Research) досьє з JSONL у профілі.
 * Формат рядка: {"lawyer_id": 123, "dossier": "markdown…"} — ключі
 * lawyer_id|profile_id|id та dossier|text приймаються як синоніми.
 */
class ImportDossiers extends Command
{
    protected $signature = 'dovira:import-dossiers
        {file : Шлях до JSONL-файлу}
        {--force : Перезаписувати, зокрема ручні правки (admin/owner)}
        {--dry-run : Перевірити файл без збереження}';

    protected $description = 'Імпортує AI-досьє з JSONL-файлу у профілі.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNo => $line) {
            $row = json_decode(trim($line), true);
            if (! is_array($row)) {
                $this->error('Рядок '.($lineNo + 1).': не JSON — пропущено.');
                $errors++;

                continue;
            }

            $id = (int) ($row['lawyer_id'] ?? $row['profile_id'] ?? $row['id'] ?? 0);
            $dossier = trim((string) ($row['dossier'] ?? $row['dossier_markdown'] ?? $row['text'] ?? ''));

            if ($id <= 0 || $dossier === '') {
                $this->error('Рядок '.($lineNo + 1).': нема lawyer_id або dossier — пропущено.');
                $errors++;

                continue;
            }

            $profile = Profile::find($id);
            if (! $profile) {
                $this->error("Рядок ".($lineNo + 1).": профіль {$id} не знайдено.");
                $errors++;

                continue;
            }

            if (mb_strlen($dossier) < 200) {
                $this->warn("{$id} {$profile->name}: досьє закоротке (".mb_strlen($dossier)." симв.) — пропущено.");
                $skipped++;

                continue;
            }

            // Ручні правки адміна/власника не перетираємо мовчки.
            if (filled($profile->dossier) && in_array($profile->dossier_source, ['admin', 'owner'], true) && ! $this->option('force')) {
                $this->warn("{$id} {$profile->name}: досьє редагував {$profile->dossier_source} — пропущено (є --force).");
                $skipped++;

                continue;
            }

            // Захист від помилкової ідентифікації: якщо генератор сам
            // сумнівається, чию біографію знайшов — не публікуємо.
            $confidence = $row['identity_confidence'] ?? null;
            if ($confidence !== null && (int) $confidence < 85) {
                $this->warn("{$id} {$profile->name}: identity_confidence {$confidence} < 85 — пропущено.");
                $skipped++;

                continue;
            }

            $extras = [];

            if ($this->option('dry-run')) {
                $this->line("{$id} {$profile->name}: ок (".mb_strlen($dossier)." симв.) — (dry-run)");
                $imported++;

                continue;
            }

            $fill = [
                'dossier' => $dossier,
                'dossier_source' => 'ai',
                'dossier_generated_at' => now(),
            ];

            // Світлофор-вердикт: red / yellow / green + рядок-підсумок.
            $verdict = strtolower(trim((string) ($row['verdict'] ?? '')));
            if (in_array($verdict, ['red', 'yellow', 'green'], true)) {
                $fill['dossier_verdict'] = $verdict;
                $fill['dossier_verdict_note'] = \Illuminate\Support\Str::limit(trim((string) ($row['verdict_note'] ?? '')), 250, '');
                $extras[] = 'verdict:'.$verdict;
            }

            // Крафтові SEO-поля з генерації — кращі за шаблонні.
            $seoTitle = trim((string) ($row['seo_title'] ?? ''));
            if ($seoTitle !== '') {
                $fill['seo_title'] = \Illuminate\Support\Str::limit($seoTitle, 255, '');
                $extras[] = 'seo_title';
            }
            $metaDescription = trim((string) ($row['meta_description'] ?? ''));
            if ($metaDescription !== '') {
                $fill['seo_description'] = \Illuminate\Support\Str::limit($metaDescription, 160, '');
                $extras[] = 'seo_description';
            }

            // FAQ ({question, answer}) → ai_suggested_data.faq ({q, a}) —
            // рендериться на сторінці і в schema.org. Власницький FAQ не чіпаємо.
            $aiData = (array) ($profile->ai_suggested_data ?? []);
            $faq = collect((array) ($row['faq'] ?? []))
                ->filter(fn ($f) => is_array($f) && filled($f['question'] ?? null) && filled($f['answer'] ?? null))
                ->map(fn ($f) => ['q' => trim((string) $f['question']), 'a' => trim((string) $f['answer'])])
                ->values()
                ->all();
            if ($faq !== [] && blank($aiData['faq'] ?? null)) {
                $aiData['faq'] = $faq;
                $extras[] = 'faq('.count($faq).')';
            }

            // Джерела/попередження/факти — для перевірки в адмінці.
            $aiData['dossier_meta'] = array_filter([
                'sources' => $row['sources'] ?? null,
                'warnings' => $row['warnings'] ?? null,
                'main_reputation_fact' => $row['main_reputation_fact'] ?? null,
                'identity_confidence' => $confidence,
                'publication_status' => $row['publication_status'] ?? null,
                'checked_at' => $row['checked_at'] ?? null,
            ]);
            $fill['ai_suggested_data'] = $aiData;

            $profile->forceFill($fill)->saveQuietly();

            $this->line("{$id} {$profile->name}: імпортовано (".mb_strlen($dossier)." симв."
                .($extras !== [] ? ', +'.implode(', ', $extras) : '').')');
            $imported++;
        }

        $this->info(($this->option('dry-run') ? '(dry-run) ' : '')."Імпортовано: {$imported}, пропущено: {$skipped}, помилок: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

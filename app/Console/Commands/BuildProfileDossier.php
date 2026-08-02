<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\AiEnrichment\ProfileContentAiService;
use Illuminate\Console\Command;

/**
 * Генерує AI-досьє профілю: веб-пошук по відкритих джерелах (реєстри, суди,
 * ЗМІ) → markdown-текст у profiles.dossier. Не перетирає ручні правки
 * (admin/owner) без --force.
 */
class BuildProfileDossier extends Command
{
    protected $signature = 'dovira:build-dossier
        {profiles* : ID або slug профілів}
        {--force : Перегенерувати, навіть якщо досьє вже є (зокрема ручне)}
        {--dry-run : Показати результат без збереження}';

    protected $description = 'Збирає AI-досьє профілю з відкритих джерел (веб-пошук).';

    public function handle(ProfileContentAiService $ai): int
    {
        $failed = false;

        foreach ((array) $this->argument('profiles') as $key) {
            $profile = Profile::query()
                ->when(is_numeric($key), fn ($q) => $q->whereKey((int) $key), fn ($q) => $q->where('slug', $key))
                ->first();

            if (! $profile) {
                $this->error("Профіль «{$key}» не знайдено.");
                $failed = true;

                continue;
            }

            if (filled($profile->dossier) && ! $this->option('force')) {
                $source = $profile->dossier_source ?: 'ai';
                $this->line("{$profile->id} {$profile->name}: досьє вже є (джерело: {$source}) — пропущено. Використайте --force.");

                continue;
            }

            $this->info("{$profile->id} {$profile->name} ({$profile->city}) — збираю досьє…");

            try {
                $result = $ai->composeDossier($profile);
            } catch (\Throwable $e) {
                $this->error('  Помилка: '.$e->getMessage());
                $failed = true;

                continue;
            }

            if (! $result) {
                $this->warn('  Замало даних у відкритих джерелах — досьє не сформовано.');

                continue;
            }

            $this->line('');
            $this->line($result['dossier']);
            $this->line('');
            if ($result['sources'] !== []) {
                $this->line('  Джерела: '.implode(' | ', array_slice($result['sources'], 0, 8)));
            }

            if ($ai->lastUsage !== []) {
                $in = (int) data_get($ai->lastUsage, 'input_tokens', 0);
                $out = (int) data_get($ai->lastUsage, 'output_tokens', 0);
                $reason = (int) data_get($ai->lastUsage, 'output_tokens_details.reasoning_tokens', 0);
                $this->line("  Токени: вхід {$in}, вихід {$out} (з них reasoning {$reason})");
            }

            if ($this->option('dry-run')) {
                $this->line('  (dry-run) не збережено.');

                continue;
            }

            $profile->forceFill([
                'dossier' => $result['dossier'],
                'dossier_source' => 'ai',
                'dossier_generated_at' => now(),
            ])->saveQuietly();

            $this->info('  Збережено.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

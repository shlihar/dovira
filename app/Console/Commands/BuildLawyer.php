<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Profile;
use App\Services\AiEnrichment\PortraitPickerService;
use App\Services\AiProfileEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/**
 * Повний потік «Бакуліна» одним викликом: створити/знайти профіль адвоката за
 * сайтом → збагатити (опис, контакти) → портрет (vision) → Google-відгуки
 * (Trustindex) → AI-підсумок. Соцмережі НЕ додаються (PRO-фіча).
 */
class BuildLawyer extends Command
{
    protected $signature = 'dovira:build-lawyer
        {--url= : Сайт адвоката (обовʼязково для нового профілю)}
        {--name= : Імʼя/назва (обовʼязково при створенні)}
        {--city= : Місто}
        {--id= : Обробити існуючий профіль за ID замість створення}
        {--publish : Опублікувати профіль}
        {--dry-run : Показати кроки без збереження}';

    protected $description = 'Створює і наповнює профіль адвоката з його сайту: опис, фото, Google-відгуки, підсумок.';

    public function handle(AiProfileEnrichmentService $enricher, PortraitPickerService $picker): int
    {
        // Справжнє OpenAI-збагачення з веб-пошуком (не локальний шаблон).
        config([
            'ai_enrichment.openai.prefer_local_for_structured_rows' => false,
            'ai_enrichment.openai.web_search' => true,
        ]);

        $profile = $this->resolveOrCreateProfile();
        if (! $profile) {
            return self::FAILURE;
        }

        $this->info("Профіль #{$profile->id}: {$profile->name}");

        if ($this->option('dry-run')) {
            $this->line('  (dry-run) сайт: '.$profile->website);

            return self::SUCCESS;
        }

        // 1) Збагачення: опис, контакти.
        try {
            $enricher->refreshSingleProfile($profile);
            $profile->refresh();
            $profile->social_links = null; // соцмережі не показуємо (PRO)
            $profile->save();
            $this->line('  ✓ опис '.mb_strlen((string) $profile->description).' симв, сайт: '.($profile->website ?: '—'));
        } catch (Throwable $e) {
            $this->error('  збагачення: '.$e->getMessage());
        }

        // 2) Портрет через vision.
        if (filled($profile->website)) {
            try {
                $pick = $picker->pickFromWebsite((string) $profile->website, (string) $profile->name);
                if ($pick) {
                    $profile->forceFill(['logo_url' => $pick['url']])->save();
                    $this->line('  ✓ фото: '.$pick['url']);
                } else {
                    $this->line('  · портрет не знайдено');
                }
            } catch (Throwable $e) {
                $this->error('  фото: '.$e->getMessage());
            }
        }

        // 3) Google-відгуки з Trustindex.
        Artisan::call('dovira:import-trustindex', ['profile' => $profile->id], $this->output);

        // 4) AI-підсумок, якщо є достатньо відгуків.
        $profile->refresh();
        if ((int) $profile->reviews_count >= 3) {
            Artisan::call('dovira:summarize-reviews', ['--id' => [$profile->id], '--min-reviews' => 3], $this->output);
        }

        if ($this->option('publish')) {
            $profile->forceFill(['status' => 'active', 'is_published' => true, 'show_in_catalog' => true])->save();
        }

        $profile->refresh();
        $this->info("Готово: відгуків {$profile->reviews_count}, рейтинг {$profile->rating_avg}, опубл: ".($profile->is_published ? 'так' : 'ні'));
        $this->line('  '.route('profile.show', ['slug' => $profile->slug]));

        return self::SUCCESS;
    }

    private function resolveOrCreateProfile(): ?Profile
    {
        if ($id = $this->option('id')) {
            return Profile::find((int) $id);
        }

        $url = trim((string) $this->option('url'));
        $name = trim((string) $this->option('name'));
        if ($url === '' || $name === '') {
            $this->error('Для нового профілю потрібні --url і --name (або --id для існуючого).');

            return null;
        }

        $domain = preg_replace('#^https?://(www\.)?#', '', rtrim($url, '/'));
        $domain = preg_replace('#/.*$#', '', $domain);

        // Уникаємо дублів: шукаємо за доменом у website.
        $existing = Profile::where('website', 'like', "%{$domain}%")->first();
        if ($existing) {
            $this->warn("  вже існує #{$existing->id} — оновлюю його");

            return $existing;
        }

        $profile = Profile::create([
            'type' => 'company',
            'name' => $name,
            'city' => $this->option('city') ?: null,
            'website' => Str::startsWith($url, 'http') ? $url : "https://{$url}",
            'status' => 'draft',
            'is_published' => false,
            'show_in_catalog' => false,
        ]);

        if ($cat = Category::where('slug', 'advokaty')->first()) {
            $profile->categories()->syncWithoutDetaching([$cat->id => ['is_primary' => true]]);
        }

        return $profile;
    }
}

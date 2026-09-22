<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Шукає публічний контактний email профілю через OpenAI web search — ВУЗЬКИЙ
 * запит (лише email), а не повний enrichment: у рази дешевше за токенами.
 * Для профілів без сайту це єдиний спосіб дістати пошту (Google Maps і
 * реєстри її не віддають; сайти покриває dovira:scrape-emails).
 *
 * Приклад: dovira:find-emails-ai --only-dossier --no-website-only --limit=50 --dry-run
 */
class FindProfileEmailsViaAi extends Command
{
    protected $signature = 'dovira:find-emails-ai
        {--category=Адвокати : Назва категорії}
        {--only-dossier : Лише профілі з досьє}
        {--no-website-only : Лише профілі БЕЗ сайту (з сайтом дешевше скрейпером)}
        {--limit=25 : Скільки профілів опрацювати за запуск}
        {--sleep=500 : Пауза між запитами, мс}
        {--dry-run : Не писати в БД, лише показати знахідки}';

    protected $description = 'Шукає email профілів через OpenAI web search (вузький дешевий запит) і дозаписує в порожнє поле';

    private int $totalInputTokens = 0;

    private int $totalOutputTokens = 0;

    public function handle(): int
    {
        $apiKey = (string) config('ai_enrichment.openai.api_key');
        if (blank($apiKey)) {
            $this->error('OPENAI_API_KEY не налаштовано.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));

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
            ->when($this->option('no-website-only'), fn ($q) => $q->where(fn ($w) => $w->whereNull('website')->orWhere('website', '')))
            ->orderBy('id');

        $this->info(($dryRun ? '[dry-run] ' : '') . 'Кандидатів: ' . (clone $query)->count() . " (опрацюємо до {$limit})");

        $processed = 0;
        $found = 0;

        $query->chunkById(50, function ($profiles) use (&$processed, &$found, $dryRun, $limit, $sleepMs, $apiKey): bool {
            foreach ($profiles as $profile) {
                if ($processed >= $limit) {
                    return false;
                }
                $processed++;

                $email = rescue(fn () => $this->searchEmail($apiKey, $profile), null, false);

                if ($email === null) {
                    $this->line(sprintf('  #%d %s → —', $profile->id, Str::limit($profile->name, 34)));
                } else {
                    $found++;
                    $this->line(sprintf('  #%d %s → %s', $profile->id, Str::limit($profile->name, 34), $email));

                    if (! $dryRun) {
                        rescue(fn () => Profile::query()
                            ->whereKey($profile->id)
                            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
                            ->update(['email' => $email]));
                    }
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            return true;
        });

        $this->newLine();
        $this->info(sprintf(
            '%s Опрацьовано: %d, знайдено email: %d (%.0f%%). Токени: %s in / %s out.',
            $dryRun ? '[dry-run]' : 'Готово.',
            $processed,
            $found,
            $processed > 0 ? $found / $processed * 100 : 0,
            number_format($this->totalInputTokens),
            number_format($this->totalOutputTokens)
        ));

        return self::SUCCESS;
    }

    private function searchEmail(string $apiKey, Profile $profile): ?string
    {
        $context = array_filter([
            'name' => (string) $profile->name,
            'city' => $profile->city ? (string) $profile->city : null,
            'phone' => $profile->phone ? (string) $profile->phone : null,
            'website' => $profile->website ? (string) $profile->website : null,
        ]);

        $payload = [
            'model' => (string) config('ai_enrichment.openai.model', 'gpt-4.1-mini'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => 'Ти шукаєш ПУБЛІЧНИЙ контактний email українського адвоката/юрфірми. '
                            . 'Використай веб-пошук (ім’я + місто + телефон). Поверни email ЛИШЕ якщо впевнений, '
                            . 'що це контакт саме цієї особи/компанії (сайт, каталог, сторінка контактів). '
                            . 'Якщо не знайшов або не впевнений — email: null. Не вигадуй адрес.',
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'contact_email',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'email' => ['type' => ['string', 'null']],
                            'source_url' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['email', 'source_url'],
                    ],
                ],
            ],
            'tools' => [
                ['type' => (string) config('ai_enrichment.openai.web_search_tool', 'web_search_preview')],
            ],
            'tool_choice' => 'auto',
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(45)
            ->retry(1, 750, throw: false)
            ->post((string) config('ai_enrichment.openai.endpoint'), $payload)
            ->throw();

        $json = (array) $response->json();
        $this->totalInputTokens += (int) data_get($json, 'usage.input_tokens', 0);
        $this->totalOutputTokens += (int) data_get($json, 'usage.output_tokens', 0);

        // Responses API: шукаємо перший message-output з текстом.
        $text = null;
        foreach ((array) data_get($json, 'output', []) as $item) {
            if (($item['type'] ?? '') === 'message') {
                $text = (string) data_get($item, 'content.0.text', '');
                break;
            }
        }
        if (! $text) {
            return null;
        }

        $decoded = json_decode($text, true);
        $email = is_array($decoded) ? ($decoded['email'] ?? null) : null;
        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}

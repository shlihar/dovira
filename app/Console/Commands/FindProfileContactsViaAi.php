<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Вузький web-search для дозаповнення публічних контактів профілів.
 * Пише тільки в порожні поля, щоб не перетирати імпортовані/ручні дані.
 */
class FindProfileContactsViaAi extends Command
{
    protected $signature = 'dovira:find-contacts-ai
        {--category=Адвокати : Назва категорії}
        {--only-unclaimed : Лише незаявлені профілі}
        {--reviews-min= : Мінімальна кількість відгуків}
        {--reviews-max= : Максимальна кількість відгуків}
        {--name-like=* : Додатковий фільтр по назві профілю; можна кілька або через кому}
        {--limit=25 : Скільки профілів опрацювати за запуск}
        {--sleep=500 : Пауза між запитами, мс}
        {--dry-run : Не писати в БД, лише показати знахідки}';

    protected $description = 'Шукає публічні email/телефон/сайт профілів через OpenAI web search і дозаписує порожні поля';

    private int $totalInputTokens = 0;

    private int $totalOutputTokens = 0;

    public function handle(): int
    {
        $apiKey = (string) config('ai_enrichment.openai.api_key');
        if (blank($apiKey)) {
            $this->error('OPENAI_API_KEY не налаштовано.');

            return self::FAILURE;
        }

        $ids = CategoryHierarchy::categoryIdsFromNames([(string) $this->option('category')]);
        if (empty($ids)) {
            $this->error('Категорію не знайдено.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $reviewsMin = $this->nullableIntOption('reviews-min');
        $reviewsMax = $this->nullableIntOption('reviews-max');
        $nameFilters = $this->nameFilters();

        $query = Profile::query()
            ->where('status', 'active')
            ->whereIn('id', fn ($q) => $q->select('profile_id')->from('profile_category')->whereIn('category_id', $ids))
            ->when($this->option('only-unclaimed'), fn ($q) => $q->whereNull('owner_user_id'))
            ->when($reviewsMin !== null, fn ($q) => $q->where('reviews_count', '>=', $reviewsMin))
            ->when($reviewsMax !== null, fn ($q) => $q->where('reviews_count', '<=', $reviewsMax))
            ->when($nameFilters !== [], function ($q) use ($nameFilters): void {
                $q->where(function ($names) use ($nameFilters): void {
                    foreach ($nameFilters as $filter) {
                        $names->orWhere('name', 'like', '%' . $filter . '%');
                    }
                });
            })
            ->where(function ($q): void {
                $q->whereNull('email')
                    ->orWhere('email', '')
                    ->orWhereNull('phone')
                    ->orWhere('phone', '')
                    ->orWhereNull('website')
                    ->orWhere('website', '');
            })
            ->when($reviewsMax !== null && $reviewsMax > 30, fn ($q) => $q->orderByRaw('CASE WHEN reviews_count <= 30 THEN 0 ELSE 1 END'))
            ->orderByDesc('reviews_count')
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info(sprintf(
            '%sКандидатів: %d (опрацюємо до %d)',
            $dryRun ? '[dry-run] ' : '',
            $total,
            $limit
        ));

        $processed = 0;
        $updated = 0;
        $foundEmails = 0;
        $foundPhones = 0;
        $foundWebsites = 0;

        foreach ($query->limit($limit)->get(['id', 'name', 'city', 'district', 'address', 'phone', 'email', 'website', 'reviews_count']) as $profile) {
            $processed++;

            $result = rescue(fn () => $this->searchContacts($apiKey, $profile), null, false);
            if (! is_array($result)) {
                $this->line(sprintf('  #%d %s → —', $profile->id, Str::limit($profile->name, 34)));
                $this->sleep($sleepMs);

                continue;
            }

            $email = $this->cleanEmail($result['email'] ?? null);
            $phone = $this->cleanPhone($result['phone'] ?? null);
            $website = $this->cleanUrl($result['website'] ?? null);

            $updates = [];
            if ($email !== null && blank($profile->email)) {
                $updates['email'] = $email;
                $foundEmails++;
            }
            if ($phone !== null && blank($profile->phone)) {
                $updates['phone'] = $phone;
                $foundPhones++;
            }
            if ($website !== null && blank($profile->website)) {
                $updates['website'] = $website;
                $foundWebsites++;
            }

            $summary = $updates === []
                ? '—'
                : collect($updates)->map(fn ($value, $key) => "{$key}:{$value}")->implode('  ');
            $source = trim((string) ($result['source_url'] ?? ''));
            $this->line(sprintf(
                '  #%d %s → %s%s',
                $profile->id,
                Str::limit($profile->name, 34),
                $summary,
                $source !== '' ? '  source:' . Str::limit($source, 60) : ''
            ));

            if ($updates !== []) {
                $updated++;
                if (! $dryRun) {
                    rescue(fn () => tap(Profile::query()->find($profile->id), function (Profile $fresh) use ($updates): void {
                        if (isset($updates['email']) && filled($fresh->email)) {
                            unset($updates['email']);
                        }
                        if (isset($updates['phone']) && filled($fresh->phone)) {
                            unset($updates['phone']);
                        }
                        if (isset($updates['website']) && filled($fresh->website)) {
                            unset($updates['website']);
                        }
                        if ($updates !== []) {
                            $fresh->forceFill($updates)->save();
                        }
                    }));
                }
            }

            $this->sleep($sleepMs);
        }

        $this->newLine();
        $this->info(sprintf(
            '%sОпрацьовано: %d, оновлено профілів: %d (email: %d, phone: %d, website: %d). Токени: %s in / %s out.',
            $dryRun ? '[dry-run] ' : 'Готово. ',
            $processed,
            $updated,
            $foundEmails,
            $foundPhones,
            $foundWebsites,
            number_format($this->totalInputTokens),
            number_format($this->totalOutputTokens)
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{email:?string, phone:?string, website:?string, source_url:?string}|null
     */
    private function searchContacts(string $apiKey, Profile $profile): ?array
    {
        $context = array_filter([
            'profile_id' => $profile->id,
            'name' => (string) $profile->name,
            'city' => $profile->city ? (string) $profile->city : null,
            'district' => $profile->district ? (string) $profile->district : null,
            'address' => $profile->address ? (string) $profile->address : null,
            'known_phone' => $profile->phone ? (string) $profile->phone : null,
            'known_email' => $profile->email ? (string) $profile->email : null,
            'known_website' => $profile->website ? (string) $profile->website : null,
            'reviews_count' => (int) $profile->reviews_count,
        ]);

        $payload = [
            'model' => (string) config('ai_enrichment.openai.model', 'gpt-4.1-mini'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => 'Ти шукаєш ПУБЛІЧНІ контакти українського агентства нерухомості. '
                            . 'Використай web search за назвою, містом, адресою і телефоном. '
                            . 'Поверни email, phone, website ЛИШЕ якщо впевнений, що контакт належить саме цьому профілю. '
                            . 'Сильні сигнали: офіційний сайт, Google Business/Maps сторінка, Facebook/Instagram бізнес-сторінка, '
                            . 'каталог із збігом назви + міста/адреси/телефону. Не вигадуй контакти. '
                            . 'Якщо email не знайдено або є сумнів, email має бути null. '
                            . 'Якщо phone/website уже відомі у вхідних даних, можеш повернути їх лише коли вони підтвердили ідентичність.',
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
                    'name' => 'profile_public_contacts',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'email' => ['type' => ['string', 'null']],
                            'phone' => ['type' => ['string', 'null']],
                            'website' => ['type' => ['string', 'null']],
                            'source_url' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['email', 'phone', 'website', 'source_url'],
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
            ->timeout(60)
            ->retry(1, 750, throw: false)
            ->post((string) config('ai_enrichment.openai.endpoint'), $payload)
            ->throw();

        $json = (array) $response->json();
        $this->totalInputTokens += (int) data_get($json, 'usage.input_tokens', 0);
        $this->totalOutputTokens += (int) data_get($json, 'usage.output_tokens', 0);

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

        return is_array($decoded) ? $decoded : null;
    }

    private function cleanEmail(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $email = mb_strtolower(trim($value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function cleanPhone(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $phone = trim(preg_replace('/\s+/', ' ', $value) ?: $value);
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (strlen($digits) < 9 || strlen($digits) > 15) {
            return null;
        }

        return $phone;
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

    private function sleep(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    private function nullableIntOption(string $name): ?int
    {
        $value = $this->option($name);

        return filled($value) ? (int) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function nameFilters(): array
    {
        return collect((array) $this->option('name-like'))
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

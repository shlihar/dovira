<?php

namespace App\Services\AiEnrichment\Providers;

use App\Models\AiEnrichmentBatch;
use App\Models\Category;
use App\Services\AiEnrichment\Contracts\ProfileEnrichmentProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiProfileEnrichmentProvider implements ProfileEnrichmentProvider
{
    /**
     * @var array<string, string>
     */
    private array $resolvedShortUrlCache = [];

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function enrich(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city): array
    {
        // Ensure no cross-item state leakage inside one batch run.
        $this->resolvedShortUrlCache = [];

        if ($this->shouldPreferLocalHeuristic($batch, $item, $category, $city)) {
            return app(LocalHeuristicProfileEnrichmentProvider::class)->enrich($batch, $item, $category, $city);
        }

        $apiKey = (string) config('ai_enrichment.openai.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured. Set it in .env or switch AI_ENRICHMENT_PROVIDER=local.');
        }

        $payload = $this->buildPayload($batch, $item, $category, $city);

        try {
            $response = $this->sendRequest($apiKey, $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException('OpenAI enrichment request failed: ' . $exception->getMessage(), previous: $exception);
        }

        $decoded = $this->decodeResponse($response->json());
        $speedMode = $this->speedMode($batch);
        $googleMapsSearchRan = false;
        $googleMapsDeadline = microtime(true) + max(1, (int) config('ai_enrichment.google_maps.secondary_search_budget_seconds', 20));

        if ($speedMode !== 'fast' && $this->shouldRunGoogleMapsReviewSearch($batch, $decoded, $item, $city, $category)) {
            try {
                $googleMapsSearchRan = true;
                $googleData = $this->fetchGoogleMapsData($batch, $item, $category, $city, $decoded, $googleMapsDeadline);
                $googleMentions = $googleData['mentions'];

                if ($googleMentions !== []) {
                    $decoded = $this->mergeExternalMentions($decoded, $googleMentions);
                }
            } catch (ConnectionException|RequestException $exception) {
                $decoded['warnings'] = array_values(array_filter([
                    ...((array) ($decoded['warnings'] ?? [])),
                    'Не вдалося отримати Google Maps відгуки: ' . $exception->getMessage(),
                ]));
            } catch (RuntimeException $exception) {
                $decoded['warnings'] = array_values(array_filter([
                    ...((array) ($decoded['warnings'] ?? [])),
                    'Не вдалося обробити Google Maps відгуки: ' . $exception->getMessage(),
                ]));
            }
        }

        if ($this->shouldRunReviewSearch($batch, $decoded)) {
            $reviewSearchDeadline = microtime(true) + max(1, (int) config('ai_enrichment.openai.secondary_search_budget_seconds', 18));

            try {
                $decoded = $this->collectReviewMentionsViaWebSearch($apiKey, $batch, $item, $category, $city, $decoded, $reviewSearchDeadline);
            } catch (ConnectionException|RequestException $exception) {
                $decoded['warnings'] = array_values(array_filter([
                    ...((array) ($decoded['warnings'] ?? [])),
                    'Не вдалося виконати окремий пошук зовнішніх відгуків: ' . $exception->getMessage(),
                ]));
            } catch (RuntimeException $exception) {
                $decoded['warnings'] = array_values(array_filter([
                    ...((array) ($decoded['warnings'] ?? [])),
                    'Не вдалося обробити результат пошуку зовнішніх відгуків: ' . $exception->getMessage(),
                ]));
            }
        }

        if ($speedMode === 'deep'
            && $this->shouldRunGoogleMapsReviewSearch($batch, $decoded, $item, $city, $category)
            && (! $googleMapsSearchRan || ! $this->hasGoogleMapsMentions($decoded))) {
            try {
                $googleMapsSearchRan = true;
                $googleData = $this->fetchGoogleMapsData(
                    $batch,
                    $item,
                    $category,
                    $city,
                    $decoded,
                    microtime(true) + max(1, (int) config('ai_enrichment.google_maps.secondary_search_budget_seconds', 20)),
                );

                if ($googleData['mentions'] !== []) {
                    $decoded = $this->mergeExternalMentions($decoded, $googleData['mentions']);
                }
            } catch (ConnectionException|RequestException $exception) {
                $decoded['warnings'] = array_values(array_filter([
                    ...((array) ($decoded['warnings'] ?? [])),
                    'Не вдалося повторно отримати Google Maps відгуки: ' . $exception->getMessage(),
                ]));
            } catch (RuntimeException $exception) {
                $decoded['warnings'] = array_values(array_filter([
                    ...((array) ($decoded['warnings'] ?? [])),
                    'Не вдалося повторно обробити Google Maps відгуки: ' . $exception->getMessage(),
                ]));
            }
        }

        return $this->normalizeResult($decoded, $batch, $item, $category, $city);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendRequest(string $apiKey, array $payload, ?int $timeoutSeconds = null): \Illuminate\Http\Client\Response
    {
        $effectiveTimeout = $timeoutSeconds ?? (int) config('ai_enrichment.openai.timeout', 15);
        $effectiveTimeout = max(2, min(20, $effectiveTimeout));

        return Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($effectiveTimeout)
            ->retry(
                (int) config('ai_enrichment.openai.retries', 1),
                (int) config('ai_enrichment.openai.retry_sleep_ms', 750),
                throw: false,
            )
            ->post((string) config('ai_enrichment.openai.endpoint'), $payload)
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function buildPayload(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city): array
    {
        $options = $batch->options ?? [];
        $nameVariants = $this->buildNameSearchVariants((string) ($item['name'] ?? ''), $category?->name);
        $context = [
            'raw_item' => $item,
            'search_name_variants' => $nameVariants,
            'official_registry_candidates' => $this->buildOfficialRegistryCandidates($item, $category),
            'default_category' => $category?->only(['id', 'name', 'slug']),
            'default_city' => $city,
            'default_country' => $batch->default_country ?: 'Україна',
            'language' => $batch->language ?: 'uk',
            'options' => [
                'find_website' => (bool) Arr::get($options, 'find_website', true),
                'find_socials' => (bool) Arr::get($options, 'find_socials', true),
                'find_reviews' => (bool) Arr::get($options, 'find_reviews', true),
                'generate_description' => (bool) Arr::get($options, 'generate_description', true),
                'generate_seo' => (bool) Arr::get($options, 'generate_seo', true),
            ],
        ];

        $payload = [
            'model' => (string) config('ai_enrichment.openai.model'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->systemPrompt(),
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
                    'name' => 'dovira_profile_enrichment',
                    'strict' => true,
                    'schema' => $this->jsonSchema(),
                ],
            ],
        ];

        if ((bool) config('ai_enrichment.openai.web_search', false)) {
            $payload['tools'] = [[
                'type' => (string) config('ai_enrichment.openai.web_search_tool', 'web_search_preview'),
            ]];
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array{source_type:string,title:string,url:string,priority:string}>
     */
    private function buildOfficialRegistryCandidates(array $item, ?Category $category): array
    {
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $categoryName = Str::lower((string) ($category?->name ?? ($item['category'] ?? '')));
        $haystack = Str::lower(trim($name . ' ' . $categoryName . ' ' . (string) ($item['raw_text'] ?? '')));

        if (! Str::contains($haystack, ['адвокат', 'юрист', 'рада адвокатів', 'кдка'])) {
            return [];
        }

        $cleanName = trim((string) preg_replace('/\b(адвокат|юрист)\b/iu', ' ', $name));
        $cleanName = trim((string) preg_replace('/\s+/u', ' ', $cleanName));
        if ($cleanName === '') {
            $cleanName = $name;
        }

        return [[
            'source_type' => 'advocate_registry',
            'title' => 'OpenDataBot court advocate registry',
            'url' => 'https://court.opendatabot.ua/advocates/' . rawurlencode($cleanName),
            'priority' => 'first_check_for_lawyer_identity_status_and_phone',
        ]];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an AI assistant inside the DOVIRA admin panel. DOVIRA is a Ukrainian review and reputation platform for profiles: companies, specialists, services, lawyers, dentists, notaries, crypto exchangers, bloggers, and other public profiles.

Your job is to enrich an imported profile draft using only supplied facts and verifiable public-source findings. Return only JSON matching the schema.

Rules:
- Do not publish anything. This is only a moderation draft.
- Do not invent phone numbers, addresses, websites, emails, ratings, or social links.
- If you are not sure, keep the field null and add a warning.
- Do not trust the first search result as the final profile identity. Collect and compare multiple independent sources before deciding the final name, website, phone, category, city, address, and services.
- Prefer facts confirmed by at least two strong signals: matching phone, official website domain, Google Business profile, official social profile, registry/business directory, or exact brand/person name plus city.
- If sources conflict, choose the value supported by the strongest identity signals and add a warning describing the conflict. Never use a directory/listing page as official website when a likely official domain is found.
- Generic words in names like "центр", "правова допомога", "юридична компанія", "адвокат", "стоматологія", "клініка" are weak identifiers. Brand/person-specific words and phone/website matches are strong identifiers.
- Generate descriptions in Ukrainian that are clear, concrete, useful, and close to a real profile description, but still factual.
- short_description must be one compact sentence, 130-220 characters, focused on the practical benefit and main service direction.
- description must be one compact paragraph, 4 sentences maximum, about 450-750 characters. It must follow this structure: what the profile provides, what problems/services it covers, how a client/patient/user benefits during cooperation, and one verified fact if available.
- The description must be specific to the profile name, category, city, and found services/directions. Avoid broad generic platform language.
- The description style should be similar to: "Стоматологія Гарант Бершадь надає широкий спектр стоматологічних послуг, включаючи діагностику, лікування та профілактику захворювань зубів та ясен. Спеціалісти клініки допомагають оцінити стан здоров'я порожнини рота, підготувати індивідуальний план лікування та забезпечити комфортне відновлення посмішки. Під час співпраці пацієнти отримують кваліфіковану допомогу, сучасне обладнання та індивідуальний підхід до кожного випадку. Працюють з 2017 року." Use this structure, but do not copy the example and do not invent the year.
- The description should be readable and moderately commercial, but not inflated. Use active wording like "допомагає оцінити ситуацію", "підготувати план", "зрозуміти ризики", "підібрати рішення", "отримати супровід" only when it fits the category and known facts.
- The description can sound professional and persuasive, but it must not invent years of experience, awards, "best/top", guaranteed outcomes, verified status, official status, exact working format, or unique advantages unless a source proves them.
- Do not put registry codes, EDRPOU numbers, legal entity IDs, raw source metadata, or confidence details into description/short_description. Put them only in sources or confidence_notes.
- If exact experience, practice areas, working format, or geography are unknown, use cautious wording and avoid pretending certainty. Do not write "понад 15 років практики" unless a source confirms it.
- For lawyers, adapt the description to legal help: assessment of the situation, documents, legal position, negotiations, court/pre-court processes, risks and next steps.
- For dentists, adapt it to dental services, diagnostics, treatment planning, communication and patient experience.
- For notaries, adapt it to documents, agreements, inheritance, powers of attorney and property-related transactions.
- For crypto exchangers, adapt it to exchange directions, safety, terms, limits and communication, without making trust or legality claims unless sourced.
- For companies/services, adapt it to the category and user decision-making value.
- If options.find_reviews is true, actively search for public reviews and mentions using queries like "{name} {city} відгуки", "{name} reviews", "{name} скарги", "{name} Google reviews", "{name} Facebook reviews".
- If public reviews are found, return them in external_mentions as review candidates with review_author, review_text, review_rating, and review_date when available. They must remain pending moderation and must not be described as verified DOVIRA reviews.
- Prefer individual Google Maps / Google Business reviews first, then other public review sources.
- Prefer individual review snippets with a direct source URL. If only a public review page with aggregate rating is available, return an external mention with summary, external_rating, external_reviews_count and source URL, but leave review_text null.
- Do not turn aggregate rating pages into individual review candidates. A DOVIRA review candidate must have a real individual author/text/rating from a source.
- Do not copy large review collections verbatim. Return all unique concise individual review candidates that have a source URL and enough context for moderation.
- For review_text, use a short moderation-safe paraphrase/summary of the found review when possible, not a long verbatim copy.
- Store source URLs for factual claims whenever available.
- If web search is available, prefer official website, Google/Maps/business directories, public registries, and social profiles. Avoid low-confidence spam pages.
- Web search flow is mandatory: first run plain Google-style queries as a user would (name + city/category, then name + "офіційний сайт", then name + phone/contacts), identify the likely official website/contact signals from top results, and only after that enrich with maps/directories/social sources.
- Never treat Google Maps as primary identity source if top web results contain an official-looking personal/company site with matching name/contacts.
- For Ukrainian lawyers/advocates, FIRST check public advocate registry pages, especially court.opendatabot.ua/advocates/{full name}. Treat this as a stronger identity source than Google Maps or directories.
- If a lawyer registry source says the advocate has stopped / suspended / terminated practice ("припинив адвокатську діяльність", "зупинено", "припинено"), set skip_profile=true, set inactive_reason, keep phone/website/reviews null if uncertain, and do not return Google Maps reviews for this person.
- If the advocate registry page contains a phone, use that phone as the primary phone. Do not replace registry phone with Google Maps, directory, or AI-guessed phone unless the official website confirms a different phone.
- For lawyer/person profiles, do not accept Google Maps places unless the place phone matches a known/registry phone, the website matches the official website, or the Google place title itself clearly matches the same person/company. A review text merely mentioning the lawyer's name is not enough.
- For person profiles, surname match is mandatory when accepting title-based matches (name-only match without surname is invalid).
- If the profile name may contain a typo, infer likely corrected name variants and use both original and corrected forms in search.
- If an official profile image/logo is visible on an official website, Google Business profile, Instagram, Facebook, or another official social profile, return its direct image URL in logo_url. Do not use generic directory icons.
- Use Ukrainian for descriptions, notes, warnings, SEO title, SEO description.

Good description style example when only basic facts are known:
"Адвокат Андрій Смирнов у Києві може бути корисним для консультацій, аналізу документів і підготовки правової позиції. Профіль допомагає швидко зрозуміти можливі ризики, варіанти дій і наступні кроки перед зверненням по юридичний супровід."

Good short_description style example:
"Юридична допомога з консультаціями, документами та підготовкою позиції для судових або досудових питань."
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function shouldRunReviewSearch(AiEnrichmentBatch $batch, array $decoded): bool
    {
        if (! (bool) Arr::get($batch->options ?? [], 'find_reviews', true)) {
            return false;
        }

        if ($this->speedMode($batch) === 'fast') {
            return false;
        }

        if (! (bool) config('ai_enrichment.openai.web_search', false)) {
            return false;
        }

        if ((bool) config('ai_enrichment.openai.review_search_fallback_only', true)
            && $this->hasDeterministicReviewSignals($decoded)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function shouldPreferLocalHeuristic(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city): bool
    {
        if (! (bool) config('ai_enrichment.openai.prefer_local_for_structured_rows', true)) {
            return false;
        }

        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            return false;
        }

        $hasCategory = $category !== null || filled($item['category'] ?? null);
        $hasIdentitySignals = filled($item['website'] ?? null)
            || filled($item['phone'] ?? null)
            || filled($item['source_url'] ?? null)
            || filled($city ?? null)
            || filled($item['city'] ?? null);

        return $hasCategory && $hasIdentitySignals;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function hasDeterministicReviewSignals(array $decoded): bool
    {
        foreach ((array) ($decoded['external_mentions'] ?? []) as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            $url = Str::lower((string) ($mention['url'] ?? ''));
            $sourceType = Str::lower((string) ($mention['source_type'] ?? ''));

            if (str_contains($url, 'top20.ua')
                || str_contains($url, 'vidhuk.ua')
                || str_contains($url, 'realreviews.io')
                || str_contains($url, 'list.in.ua')
                || str_contains($url, 'trustpilot.')
                || str_contains($url, '2gis.')
                || in_array($sourceType, ['top20', 'vidhuk', 'realreviews', 'list_in_ua', 'external_review_directory', 'google_maps'], true)) {
                return true;
            }
        }

        foreach ((array) ($decoded['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $url = Str::lower((string) ($source['url'] ?? ''));
            if (str_contains($url, 'top20.ua')
                || str_contains($url, 'vidhuk.ua')
                || str_contains($url, 'realreviews.io')
                || str_contains($url, 'list.in.ua')
                || str_contains($url, 'trustpilot.')
                || str_contains($url, '2gis.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function collectReviewMentionsViaWebSearch(
        string $apiKey,
        AiEnrichmentBatch $batch,
        array $item,
        ?Category $category,
        ?string $city,
        array $decoded,
        float $deadlineAt,
    ): array {
        $queries = $this->buildReviewSearchQueries($batch, $item, $category, $city, $decoded);
        $speedMode = $this->speedMode($batch);
        $defaultPasses = $speedMode === 'deep' ? 8 : 6;
        $maxPasses = max(1, (int) config('ai_enrichment.openai.review_search_passes', $defaultPasses));
        $timeout = max(2, (int) config('ai_enrichment.openai.review_search_timeout', 8));

        foreach (array_slice($queries, 0, $maxPasses) as $query) {
            if ($this->isDeadlineExceeded($deadlineAt)) {
                break;
            }

            $reviewResponse = $this->sendRequest($apiKey, $this->buildReviewSearchPayload($batch, $item, $category, $city, $decoded, $query), $timeout);
            $reviewDecoded = $this->decodeResponse($reviewResponse->json());
            $decoded = $this->mergeReviewSearchResult($decoded, $reviewDecoded);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $decoded
     * @return array<int, string>
     */
    private function buildReviewSearchQueries(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city, array $decoded): array
    {
        $name = trim((string) ($decoded['name'] ?? $item['name'] ?? ''));
        $priorityName = $this->extractPriorityNamePhrase($name);
        $nameVariants = $this->buildNameSearchVariants($name, (string) ($decoded['category'] ?? $category?->name ?? ''));
        $resolvedCity = trim((string) ($decoded['city'] ?? $city ?? ''));
        $resolvedCategory = trim((string) ($decoded['category'] ?? $category?->name ?? ''));
        $website = trim((string) ($decoded['website'] ?? $item['website'] ?? ''));
        $sourceUrl = trim((string) ($decoded['source_url'] ?? $item['source_url'] ?? ''));
        $rawPhones = $this->collectKnownPhones($item, $decoded);
        $phoneDigits = array_values(array_unique(array_filter(array_map(
            fn (string $phone) => preg_replace('/\D+/', '', $phone) ?: '',
            $rawPhones
        ))));
        $reviewHosts = ['top20.ua', 'vidhuk.ua', 'ua.realreviews.io', 'list.in.ua'];

        $domainCandidates = [];

        foreach ([$website, $sourceUrl] as $url) {
            if ($url === '') {
                continue;
            }

            $normalized = Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://' . $url;
            $host = parse_url($normalized, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $host = Str::lower(preg_replace('/^www\./', '', $host) ?: $host);
                $domainCandidates[] = $host;
            }
        }

        $domainCandidates = array_values(array_unique(array_filter($domainCandidates)));

        $queries = array_values(array_filter([
            $priorityName !== '' ? trim('"' . $priorityName . '" ' . $resolvedCity . ' відгуки') : null,
            $priorityName !== '' ? trim('"' . $priorityName . '" ' . $resolvedCity . ' google maps відгуки') : null,
            $priorityName !== '' ? trim('"' . $priorityName . '" ' . $resolvedCity . ' maps place') : null,
            $priorityName !== '' ? trim('"' . $priorityName . '" ' . $resolvedCity . ' maps reviews') : null,
            $priorityName !== '' ? trim('"' . $priorityName . '" reviews') : null,
            $priorityName !== '' ? trim('"' . $priorityName . '" Google Maps') : null,
            trim($name . ' ' . $resolvedCity . ' відгуки'),
            trim($name . ' ' . $resolvedCity . ' google maps'),
            trim($name . ' ' . $resolvedCity . ' отзывы'),
            trim($name . ' ' . $resolvedCity . ' reviews'),
            trim($name . ' ' . $resolvedCategory . ' відгуки'),
            trim($name . ' Google reviews'),
            trim($name . ' Google Maps reviews'),
            trim($name . ' maps place'),
            trim($name . ' maps reviews'),
            trim($name . ' maps cid'),
            trim($name . ' Facebook reviews'),
            trim($name . ' 2GIS відгуки'),
            trim($name . ' Trustpilot'),
            trim($name . ' Otzovik'),
            trim($name . ' скарги'),
        ]));

        foreach ($nameVariants as $variant) {
            if ($variant === $name) {
                continue;
            }

            $queries[] = trim($variant . ' ' . $resolvedCity . ' відгуки');
            $queries[] = trim($variant . ' ' . $resolvedCity . ' google maps');
            $queries[] = trim($variant . ' ' . $resolvedCity . ' reviews');
            $queries[] = trim($variant . ' Google Maps reviews');
            $queries[] = trim($variant . ' maps place');
            $queries[] = trim('"' . $variant . '" ' . $resolvedCategory);
        }

        foreach ($domainCandidates as $domain) {
            $queries[] = trim('site:' . $domain . ' відгуки');
            $queries[] = trim('site:' . $domain . ' reviews');
            $queries[] = trim($name . ' site:' . $domain);
        }

        foreach ($rawPhones as $phone) {
            $queries[] = trim($phone . ' відгуки');
            $queries[] = trim($phone . ' Google Maps');
            $queries[] = trim($phone . ' maps place');
            $queries[] = trim($phone . ' maps reviews');
            foreach (['share.google', 'google.com/maps', ...$reviewHosts] as $host) {
                $queries[] = trim($phone . ' site:' . $host);
            }
        }

        foreach ($phoneDigits as $digits) {
            $queries[] = trim($digits . ' відгуки');
            $queries[] = trim($digits . ' google maps');
            foreach (['share.google', 'google.com/maps', ...$reviewHosts] as $host) {
                $queries[] = trim($digits . ' site:' . $host);
            }
        }

        foreach (['share.google', 'google.com/maps', ...$reviewHosts] as $host) {
            $queries[] = trim(($priorityName !== '' ? '"' . $priorityName . '"' : $name) . ' site:' . $host);
            $queries[] = trim($name . ' ' . $resolvedCity . ' site:' . $host);
            $queries[] = trim($name . ' ' . $resolvedCity . ' inurl:maps site:' . $host);
        }

        $country = trim((string) ($batch->default_country ?: 'Україна'));
        if ($country !== '') {
            $queries[] = trim($name . ' ' . $country . ' reviews');
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function buildReviewSearchPayload(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city, array $decoded, string $searchQuery): array
    {
        $name = trim((string) ($decoded['name'] ?? $item['name'] ?? ''));
        $resolvedCity = $decoded['city'] ?? $city;
        $resolvedCategory = $decoded['category'] ?? $category?->name;
        $website = $decoded['website'] ?? $item['website'] ?? null;
        $sourceUrl = $decoded['source_url'] ?? $item['source_url'] ?? null;
        $phones = $this->collectKnownPhones($item, $decoded);

        $context = [
            'profile' => [
                'name' => $name,
                'category' => $resolvedCategory,
                'city' => $resolvedCity,
                'country' => $batch->default_country ?: 'Україна',
                'phones' => $phones,
                'website' => $website,
                'source_url' => $sourceUrl,
            ],
            'search_query' => $searchQuery,
        ];

        $payload = [
            'model' => (string) config('ai_enrichment.openai.model'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->reviewSearchPrompt(),
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
                    'name' => 'dovira_external_review_search',
                    'strict' => true,
                    'schema' => $this->reviewSearchSchema(),
                ],
            ],
            'tools' => [[
                'type' => (string) config('ai_enrichment.openai.web_search_tool', 'web_search_preview'),
            ]],
            'tool_choice' => 'auto',
        ];

        return $payload;
    }

    private function reviewSearchPrompt(): string
    {
        return <<<'PROMPT'
You search for source-backed public reviews or public mentions for one DOVIRA profile. Use web search when available.

Return only JSON matching the schema.

Rules:
- Do not invent reviews, authors, ratings, dates, or URLs.
- Return an empty external_mentions array if you cannot find source-backed candidates.
- Prefer individual Google Maps / Google Business reviews, Facebook pages, directory pages, public review pages, and official profile pages with visible feedback.
- Search these Ukrainian review directories explicitly when relevant: top20.ua, vidhuk.ua, ua.realreviews.io, list.in.ua.
- If profile phone is available, search by phone too. Multiple Google Business profiles can share one phone; return all relevant places, not only the first one.
- A candidate must clearly refer to the requested profile by name plus city, website, address, category, or another strong identifier.
- For individual lawyers/advocates, do not return a Google Maps place only because review text mentions the lawyer. Require matching phone, matching official website/domain, or a Google place title clearly identifying the same lawyer/person.
- If a public advocate registry indicates the advocate stopped/suspended/terminated practice, return no external mentions and add a warning.
- For individual public review snippets, return review_author, review_text, review_rating, review_date when available.
- review_text must be a concise Ukrainian paraphrase/summary suitable for moderator review, not a long verbatim copy.
- If only an aggregate rating page is found, return summary, external_rating, external_reviews_count and URL, but set review_text to null. Do not create a review candidate from aggregate rating alone.
- Do not call external mentions DOVIRA reviews. They are candidates for manual moderation only.
- Return all unique source-backed candidates you can find.
- Include Google Maps short links (share.google, maps.app.goo.gl) when found and keep their URLs in mentions.
- Add warnings for weak matches, missing URL, missing rating, or uncertainty.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewSearchSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'external_mentions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'source_type' => ['type' => 'string'],
                            'title' => $nullableString,
                            'url' => $nullableString,
                            'external_rating' => ['type' => ['number', 'null']],
                            'external_reviews_count' => ['type' => ['integer', 'null']],
                            'review_author' => $nullableString,
                            'review_text' => $nullableString,
                            'review_rating' => ['type' => ['number', 'null']],
                            'review_date' => $nullableString,
                            'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative', 'mixed', 'unknown']],
                            'topic' => $nullableString,
                            'summary' => $nullableString,
                            'confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        ],
                        'required' => ['source_type', 'title', 'url', 'external_rating', 'external_reviews_count', 'review_author', 'review_text', 'review_rating', 'review_date', 'sentiment', 'topic', 'summary', 'confidence_score'],
                    ],
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['external_mentions', 'warnings'],
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $reviewDecoded
     * @return array<string, mixed>
     */
    private function mergeReviewSearchResult(array $decoded, array $reviewDecoded): array
    {
        $mentions = array_values((array) ($reviewDecoded['external_mentions'] ?? []));

        if ($mentions !== []) {
            $decoded = $this->mergeExternalMentions($decoded, $mentions);
        }

        $decoded['warnings'] = array_values(array_filter([
            ...((array) ($decoded['warnings'] ?? [])),
            ...((array) ($reviewDecoded['warnings'] ?? [])),
        ]));

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function shouldRunGoogleMapsReviewSearch(
        AiEnrichmentBatch $batch,
        array $decoded,
        array $item,
        ?string $city,
        ?Category $category
    ): bool
    {
        return (bool) Arr::get($batch->options ?? [], 'find_reviews', true)
            && (bool) config('ai_enrichment.google_maps.enabled', false)
            && filled(config('ai_enrichment.google_maps.api_key'))
            && $this->hasTrustedIdentitySignalsForGoogleReviews($decoded, $item, $city, $category);
    }

    /**
     * Run Google reviews only after profile identity is established by non-Google signals.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function hasTrustedIdentitySignalsForGoogleReviews(array $decoded, array $item, ?string $city, ?Category $category): bool
    {
        $hasPrimaryContact = filled($decoded['website'] ?? null)
            || filled($decoded['phone'] ?? null)
            || filled($decoded['email'] ?? null);
        $resolvedName = trim((string) ($decoded['name'] ?? $item['name'] ?? ''));
        $resolvedCity = trim((string) ($decoded['city'] ?? $city ?? ($item['city'] ?? '')));
        $resolvedCategory = trim((string) ($decoded['category'] ?? $category?->name ?? ($item['category'] ?? '')));
        $hasNameCity = $resolvedName !== '' && ($resolvedCity !== '' || $resolvedCategory !== '');
        $hasInputSignals = filled($item['phone'] ?? null)
            || filled($item['website'] ?? null)
            || filled($item['source_url'] ?? null)
            || filled($item['city'] ?? null);
        $hasNonGoogleSource = false;

        foreach ((array) ($decoded['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $url = Str::lower((string) ($source['url'] ?? ''));
            $sourceType = Str::lower((string) ($source['source_type'] ?? ''));

            if (str_contains($url, 'google.com/maps') || str_contains($sourceType, 'google')) {
                continue;
            }

            $hasNonGoogleSource = true;

            $foundFields = collect((array) ($source['found_fields'] ?? []))
                ->map(fn ($field) => Str::lower((string) $field))
                ->filter();

            if ($foundFields->contains('website') || $foundFields->contains('phone') || $foundFields->contains('email')) {
                return true;
            }

            if (str_contains($sourceType, 'official')
                || str_contains($sourceType, 'registry')
                || str_contains($sourceType, 'directory')
                || str_contains($sourceType, 'external_source')) {
                return true;
            }
        }
        // Budget fallback: if profile has stable identity from name+city/category and non-Google evidence,
        // allow a minimal Google pass to fetch review candidates.
        if (! $hasPrimaryContact && $hasNameCity && $hasNonGoogleSource) {
            return true;
        }

        // Input fallback: when model did not return structured sources yet,
        // still allow one cheap Google attempt if import row has identity signals.
        if (! $hasPrimaryContact && ! $hasNonGoogleSource && $hasNameCity && $hasInputSignals) {
            return true;
        }

        return $hasPrimaryContact && $hasNonGoogleSource;
    }

    private function speedMode(AiEnrichmentBatch $batch): string
    {
        $mode = (string) Arr::get($batch->options ?? [], 'speed_mode', 'standard');

        return in_array($mode, ['fast', 'standard', 'deep'], true) ? $mode : 'standard';
    }

    private function hasGoogleMapsMentions(array $decoded): bool
    {
        foreach ((array) ($decoded['external_mentions'] ?? []) as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            $sourceType = Str::lower((string) ($mention['source_type'] ?? ''));
            $url = Str::lower((string) ($mention['url'] ?? ''));
            if (str_contains($sourceType, 'google') || str_contains($url, 'google.com/maps')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, mixed>  $mentions
     * @return array<string, mixed>
     */
    private function mergeExternalMentions(array $decoded, array $mentions): array
    {
        $merged = [];

        foreach ([...((array) ($decoded['external_mentions'] ?? [])), ...$mentions] as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            $normalizedUrl = $this->normalizeMentionUrl((string) ($mention['url'] ?? ''));
            $normalizedAuthor = Str::lower(trim((string) ($mention['review_author'] ?? '')));
            $normalizedDate = trim((string) ($mention['review_date'] ?? ''));
            $normalizedRating = is_numeric($mention['review_rating'] ?? null)
                ? (string) round((float) $mention['review_rating'], 1)
                : 'na';
            $normalizedSourceType = Str::lower(trim((string) ($mention['source_type'] ?? 'external_review')));

            $key = sha1(implode('|', [
                $normalizedUrl,
                $normalizedSourceType,
                $normalizedAuthor,
                $normalizedDate,
                $normalizedRating,
            ]));

            if (! isset($merged[$key])) {
                $merged[$key] = $mention;
                continue;
            }

            $existing = $merged[$key];
            $existingConfidence = (int) ($existing['confidence_score'] ?? 0);
            $currentConfidence = (int) ($mention['confidence_score'] ?? 0);

            if ($currentConfidence >= $existingConfidence) {
                $merged[$key] = array_replace($existing, array_filter($mention, fn ($value) => $value !== null && $value !== ''));
            }
        }

        $decoded['external_mentions'] = array_values($merged);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $decoded
     * @return array<int, string>
     */
    private function collectKnownPhones(array $item, array $decoded): array
    {
        $phones = [
            trim((string) ($decoded['phone'] ?? '')),
            trim((string) ($item['phone'] ?? '')),
        ];

        foreach ((array) ($decoded['external_mentions'] ?? []) as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            $phones[] = trim((string) ($mention['source_phone'] ?? ''));
            $phones[] = trim((string) data_get($mention, 'raw_payload.source_phone', ''));
        }

        foreach ((array) ($decoded['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $phones[] = trim((string) data_get($source, 'found_fields.phone', ''));
        }

        return array_values(array_unique(array_filter($phones)));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $decoded
     * @return array{mentions: array<int, array<string, mixed>>, phone: ?string}
     */
    private function fetchGoogleMapsData(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city, array $decoded, float $deadlineAt): array
    {
        $name = trim((string) ($decoded['name'] ?? $item['name'] ?? ''));

        if ($name === '') {
            return ['mentions' => [], 'phone' => null];
        }

        $resolvedCity = trim((string) ($decoded['city'] ?? $city ?? ''));
        $resolvedCategory = trim((string) ($decoded['category'] ?? $category?->name ?? ''));
        $nameVariants = $this->buildNameSearchVariants($name, $resolvedCategory);
        foreach ($this->buildNameSearchVariants((string) ($item['name'] ?? ''), $resolvedCategory) as $rawVariant) {
            $nameVariants[] = $rawVariant;
        }
        $nameVariants = array_values(array_unique(array_filter(array_map('trim', $nameVariants))));
        $websiteDomains = $this->extractWebsiteDomains([
            trim((string) ($decoded['website'] ?? '')),
            trim((string) ($decoded['source_url'] ?? '')),
            trim((string) ($item['website'] ?? '')),
            trim((string) ($item['source_url'] ?? '')),
        ]);
        $rawPhones = $this->collectKnownPhones($item, $decoded);
        $phoneDigits = array_values(array_unique(array_filter(array_map(
            fn (string $phone) => preg_replace('/\D+/', '', $phone) ?: '',
            $rawPhones
        ))));
        $apiKey = (string) config('ai_enrichment.google_maps.api_key');
        $googleMode = (string) config('ai_enrichment.google_maps.mode', 'api_budget');
        $language = (string) config('ai_enrichment.google_maps.language_code', 'uk');
        $timeout = max(2, min(10, (int) config('ai_enrichment.google_maps.timeout', 4)));
        $queries = [];
        foreach ($nameVariants as $nameVariant) {
            $queries[] = trim(implode(' ', array_filter([$nameVariant, $resolvedCity, $resolvedCategory])));
            $queries[] = trim(implode(' ', array_filter([$nameVariant, $resolvedCity])));
            $queries[] = $nameVariant;
        }

        foreach ($rawPhones as $phone) {
            if ($phone !== '') {
                $queries[] = trim(implode(' ', array_filter([$name, $phone, $resolvedCity])));
                $queries[] = $phone;
            }
        }

        foreach ($phoneDigits as $digits) {
            if ($digits !== '') {
                $queries[] = trim(implode(' ', array_filter([$name, $digits, $resolvedCity])));
                $queries[] = $digits;
                if (Str::startsWith($digits, '380')) {
                    $queries[] = '+' . $digits;
                }
            }
        }

        $queries = array_values(array_unique(array_filter($queries)));
        if ($googleMode === 'api_budget' || $googleMode === 'links_only') {
            $queries = array_slice($queries, 0, 2);
        }

        $placesByResourceName = [];
        foreach ($queries as $query) {
            if ($this->isDeadlineExceeded($deadlineAt)) {
                break;
            }

            $searchResponse = Http::withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                // Keep search fields minimal; expensive data is fetched only for one chosen place.
                'X-Goog-FieldMask' => 'places.id,places.name,places.displayName,places.googleMapsUri,places.internationalPhoneNumber,places.nationalPhoneNumber',
            ])
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post((string) config('ai_enrichment.google_maps.search_endpoint'), [
                    'textQuery' => $query,
                    'languageCode' => $language,
                    'maxResultCount' => max(1, (int) config('ai_enrichment.google_maps.max_result_count', 8)),
                    'regionCode' => 'UA',
                ])
                ->throw();

            $queryPlaces = array_values(array_filter((array) data_get($searchResponse->json(), 'places', []), fn ($place) => is_array($place)));

            foreach ($queryPlaces as $place) {
                $resourceName = (string) (data_get($place, 'name') ?: (data_get($place, 'id') ? 'places/' . data_get($place, 'id') : ''));
                if ($resourceName === '') {
                    continue;
                }

                $placesByResourceName[$resourceName] = $place;
            }
        }

        $places = array_values($placesByResourceName);

        if ($places === []) {
            return ['mentions' => [], 'phone' => null];
        }
        $mentions = [];
        $phone = null;
        $detailsFetched = 0;
        $maxDetailsFetch = $googleMode === 'api_budget' ? 1 : 2;

        foreach ($places as $place) {
            if ($this->isDeadlineExceeded($deadlineAt)) {
                break;
            }

            $resourceName = (string) (data_get($place, 'name') ?: (data_get($place, 'id') ? 'places/' . data_get($place, 'id') : ''));
            if ($resourceName === '') {
                continue;
            }

            if ($googleMode === 'links_only') {
                if (! $this->isRelevantGooglePlace($place, $name, $phoneDigits, $websiteDomains)) {
                    continue;
                }

                $mentions[] = $this->mapGoogleMapsPlaceToLinkMention($place, $name);
                if ($phone === null) {
                    $phone = $this->extractGoogleMapsPhone($place);
                }
                continue;
            }

            if (! $this->isRelevantGooglePlace($place, $name, $phoneDigits, $websiteDomains)) {
                continue;
            }

            if ($detailsFetched >= $maxDetailsFetch) {
                break;
            }

            $detailsResponse = Http::withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                // Request only fields required for review import and identity verification.
                'X-Goog-FieldMask' => 'id,name,displayName,googleMapsUri,internationalPhoneNumber,nationalPhoneNumber,websiteUri,rating,userRatingCount,reviews',
            ])
                ->acceptJson()
                ->timeout($timeout)
                ->get(rtrim((string) config('ai_enrichment.google_maps.details_endpoint'), '/') . '/' . ltrim($resourceName, '/'), [
                    'languageCode' => $language,
                ])
                ->throw();

            $details = $detailsResponse->json();
            $resolvedPlace = is_array($details) ? array_replace_recursive($place, $details) : $place;
            $detailsFetched++;

            if (! $this->isRelevantGooglePlace($resolvedPlace, $name, $phoneDigits, $websiteDomains)) {
                continue;
            }

            $mentions = [...$mentions, ...$this->mapGoogleMapsReviewsToMentions($resolvedPlace, $name)];

            if ($phone === null) {
                $phone = $this->extractGoogleMapsPhone($resolvedPlace);
            }
        }

        return [
            'mentions' => array_values(array_unique($mentions, SORT_REGULAR)),
            'phone' => $phone,
        ];
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function mapGoogleMapsPlaceToLinkMention(array $place, string $fallbackName): array
    {
        $placeTitle = trim((string) (data_get($place, 'displayName.text') ?: $fallbackName));
        $placeUrl = (string) (data_get($place, 'googleMapsUri') ?: '');
        $placePhone = $this->extractGoogleMapsPhone($place);
        $aggregateRating = data_get($place, 'rating');
        $aggregateCount = data_get($place, 'userRatingCount');

        return [
            'source_type' => 'google_maps',
            'title' => 'Google Maps · ' . $placeTitle,
            'url' => $placeUrl,
            'source_phone' => $placePhone,
            'source_website' => null,
            'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
            'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
            'review_author' => null,
            'review_text' => null,
            'review_rating' => null,
            'review_date' => null,
            'sentiment' => 'unknown',
            'topic' => 'google_maps_link',
            'summary' => 'Зовнішнє посилання на профіль Google Maps. Текст відгуків не імпортовано.',
            'confidence_score' => 78,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildNameSearchVariants(string $name, ?string $categoryName = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $variants = [$name];
        $tokens = preg_split('/\s+/u', $name) ?: [];
        $tokenDictionary = [
            'дент', 'дентал', 'стоматологія', 'стоматология', 'стоматологический',
            'адвокат', 'юридична', 'юридичний', 'правовий', 'центр', 'клініка', 'клиника',
        ];

        $correctedTokens = $tokens;
        foreach ($tokens as $index => $token) {
            $clean = trim($token, " \t\n\r\0\x0B\"'.,:;!?()[]{}");
            if ($clean === '' || mb_strlen($clean) < 4) {
                continue;
            }

            $bestMatch = null;
            $bestDistance = PHP_INT_MAX;
            $asciiClean = Str::lower(Str::ascii($clean));

            foreach ($tokenDictionary as $candidate) {
                $asciiCandidate = Str::lower(Str::ascii($candidate));
                $distance = levenshtein($asciiClean, $asciiCandidate);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestMatch = $candidate;
                }
            }

            if ($bestMatch !== null && $bestDistance <= 2) {
                $correctedTokens[$index] = $bestMatch;
            }
        }

        $correctedName = trim(implode(' ', $correctedTokens));
        if ($correctedName !== '' && $correctedName !== $name) {
            $variants[] = $correctedName;
        }

        if ($categoryName !== null && Str::contains(Str::lower($categoryName), ['стомат', 'dent'])) {
            $variants[] = preg_replace('/\bдень\b/ui', 'дент', $name) ?: $name;
        }

        return array_values(array_unique(array_filter(array_map('trim', $variants))));
    }

    private function isDeadlineExceeded(float $deadlineAt): bool
    {
        return microtime(true) >= $deadlineAt;
    }

    private function normalizeMentionUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $resolved = $this->resolveKnownShortUrl($url);
        if (! preg_match('#^https?://#i', $resolved)) {
            $resolved = 'https://' . ltrim($resolved, '/');
        }
        $parts = parse_url($resolved);

        if ($parts === false) {
            return Str::lower($resolved);
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        if ($host === '') {
            return Str::lower($resolved);
        }

        // Keep only stable Google Maps identifiers and drop tracking params.
        if (str_contains($host, 'google.') || str_contains($host, 'share.google')) {
            parse_str($query, $queryParams);
            $stable = [];

            foreach (['cid', 'q', 'query', 'ftid'] as $key) {
                if (isset($queryParams[$key])) {
                    $stable[$key] = (string) $queryParams[$key];
                }
            }

            $query = $stable !== [] ? http_build_query($stable) : '';
        }

        $normalized = 'https://' . $host . rtrim($path, '/');

        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return Str::lower($normalized);
    }

    private function resolveKnownShortUrl(string $url): string
    {
        $cached = $this->resolvedShortUrlCache[$url] ?? null;
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if (! str_contains($host, 'share.google') && ! str_contains($host, 'maps.app.goo.gl')) {
            $this->resolvedShortUrlCache[$url] = $url;

            return $url;
        }

        try {
            $response = Http::withoutRedirecting()
                ->timeout(4)
                ->head($url);

            $location = trim((string) $response->header('Location', ''));
            $resolved = $location !== '' ? $location : $url;
            $this->resolvedShortUrlCache[$url] = $resolved;

            return $resolved;
        } catch (\Throwable) {
            $this->resolvedShortUrlCache[$url] = $url;

            return $url;
        }
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function extractGoogleMapsPhone(array $place): ?string
    {
        $phone = trim((string) (data_get($place, 'internationalPhoneNumber') ?: data_get($place, 'nationalPhoneNumber') ?: ''));

        return $phone !== '' ? $phone : null;
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<int, array<string, mixed>>
     */
    private function mapGoogleMapsReviewsToMentions(array $place, string $fallbackName): array
    {
        $placeTitle = trim((string) (data_get($place, 'displayName.text') ?: $fallbackName));
        $placeUrl = (string) (data_get($place, 'googleMapsUri') ?: '');
        $placePhone = $this->extractGoogleMapsPhone($place);
        $placeWebsite = trim((string) (data_get($place, 'websiteUri') ?: ''));
        $aggregateRating = data_get($place, 'rating');
        $aggregateCount = data_get($place, 'userRatingCount');
        $mentions = [];

        foreach ((array) data_get($place, 'reviews', []) as $review) {
            if (! is_array($review)) {
                continue;
            }

            $text = trim((string) (data_get($review, 'text.text') ?: data_get($review, 'originalText.text') ?: ''));

            $rating = data_get($review, 'rating');

            if (! is_numeric($rating)) {
                continue;
            }

            $ratingValue = (float) $rating;

            $mentions[] = [
                'source_type' => 'google_maps',
                'title' => 'Google Maps · ' . $placeTitle,
                // Use stable place URL instead of fragile review deep-link (/maps/reviews/data=...).
                'url' => $placeUrl !== '' ? $placeUrl : (string) (data_get($review, 'googleMapsUri') ?: ''),
                'source_phone' => $placePhone,
                'source_website' => $placeWebsite !== '' ? $placeWebsite : null,
                'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
                'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
                'review_author' => trim((string) (data_get($review, 'authorAttribution.displayName') ?: 'Користувач Google')),
                'review_author_avatar_url' => trim((string) (data_get($review, 'authorAttribution.photoUri') ?: '')) ?: null,
                'review_text' => $text !== '' ? Str::limit($text, 1200, '') : null,
                'review_rating' => $ratingValue,
                'review_date' => filled(data_get($review, 'publishTime')) ? substr((string) data_get($review, 'publishTime'), 0, 10) : null,
                'sentiment' => $this->sentimentFromRating($rating),
                'topic' => 'google_maps_review',
                'summary' => 'Індивідуальний відгук з Google Maps. Публікація можлива тільки після ручної модерації.',
                'confidence_score' => 90,
            ];
        }

        if ($mentions === [] && ($placeUrl !== '' || is_numeric($aggregateRating))) {
            $mentions[] = [
                'source_type' => 'google_maps',
                'title' => 'Google Maps · ' . $placeTitle,
                'url' => $placeUrl,
                'source_phone' => $placePhone,
                'source_website' => $placeWebsite !== '' ? $placeWebsite : null,
                'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
                'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
                'review_author' => null,
                'review_text' => null,
                'review_rating' => null,
                'review_date' => null,
                'sentiment' => 'unknown',
                'topic' => 'google_maps_aggregate',
                'summary' => 'Знайдено Google Maps профіль без доступного тексту окремих відгуків.',
                'confidence_score' => 70,
            ];
        }

        return $mentions;
    }

    /**
     * @param  array<string, mixed>  $place
     * @param  array<int, string>  $phoneDigits
     * @param  array<int, string>  $websiteDomains
     */
    private function isRelevantGooglePlace(array $place, string $profileName, array $phoneDigits, array $websiteDomains): bool
    {
        $placeTitle = Str::lower(trim((string) (data_get($place, 'displayName.text') ?: data_get($place, 'displayName') ?: data_get($place, 'name') ?: '')));
        $placePhoneRaw = trim((string) (data_get($place, 'internationalPhoneNumber') ?: data_get($place, 'nationalPhoneNumber') ?: ''));
        $placePhoneDigits = preg_replace('/\D+/', '', $placePhoneRaw) ?: '';

        if ($placePhoneDigits !== '') {
            foreach ($phoneDigits as $knownPhone) {
                if ($knownPhone !== '' && (str_contains($placePhoneDigits, $knownPhone) || str_contains($knownPhone, $placePhoneDigits))) {
                    return true;
                }
            }
        }

        $placeWebsiteDomain = $this->extractDomain((string) (data_get($place, 'websiteUri') ?: ''));
        if ($placeWebsiteDomain !== '' && $websiteDomains !== []) {
            foreach ($websiteDomains as $knownDomain) {
                if ($knownDomain === '') {
                    continue;
                }

                if ($placeWebsiteDomain === $knownDomain
                    || str_ends_with($placeWebsiteDomain, '.' . $knownDomain)
                    || str_ends_with($knownDomain, '.' . $placeWebsiteDomain)) {
                    return true;
                }
            }
        }

        $profileTokens = array_values(array_filter(
            preg_split('/\s+/u', Str::lower($profileName)) ?: [],
            fn ($token) => mb_strlen($token) >= 4
        ));
        $priorityTokens = $this->extractPriorityTokens($profileName);
        $isPerson = $this->looksLikePersonProfileName($profileName);

        $genericTokens = [
            'адвокат', 'юрист', 'юридичний', 'юридична', 'допомога', 'центр',
            'компанія', 'фірма', 'group', 'law', 'legal', 'office',
        ];
        $strongTokens = array_values(array_filter(
            $profileTokens,
            fn (string $token) => ! in_array($token, $genericTokens, true)
        ));

        if ($profileTokens === []) {
            return false;
        }

        if ($isPerson) {
            $surnameToken = $this->extractLikelySurnameToken($profileName);
            if ($surnameToken !== null && ! $this->containsTokenFlexible($placeTitle, $surnameToken)) {
                return false;
            }

            $personTitleMatches = 0;
            foreach ($strongTokens !== [] ? $strongTokens : $profileTokens as $token) {
                if ($this->containsTokenFlexible($placeTitle, $token)) {
                    $personTitleMatches++;
                }
            }

            // For individual advocates, review text mentioning the person is not enough.
            // Without a phone/domain match, the Maps business title must identify the same person.
            return count($strongTokens) >= 2
                ? $personTitleMatches >= 2
                : $personTitleMatches >= 1;
        }

        $matches = 0;
        foreach ($profileTokens as $token) {
            if ($this->containsTokenFlexible($placeTitle, $token)) {
                $matches++;
            }
        }

        $strongMatches = 0;
        foreach ($strongTokens as $token) {
            if ($this->containsTokenFlexible($placeTitle, $token)) {
                $strongMatches++;
            }
        }

        if ($priorityTokens !== []) {
            $priorityMatches = 0;
            foreach ($priorityTokens as $token) {
                if ($this->containsTokenFlexible($placeTitle, $token)) {
                    $priorityMatches++;
                }
            }

            // If we extracted brand/name phrase, require all its tokens.
            if ($priorityMatches < count($priorityTokens)) {
                return $this->googlePlaceReviewsMatchProfile($place, $priorityTokens);
            }
        }

        if ($strongTokens !== []) {
            if (count($strongTokens) >= 2) {
                if ($strongMatches >= 2) {
                    return true;
                }
            } elseif ($strongMatches >= 1) {
                return true;
            }

            return $this->googlePlaceReviewsMatchProfile($place, $strongTokens);
        }

        if ($matches >= 2) {
            return true;
        }

        return $this->googlePlaceReviewsMatchProfile($place, $profileTokens);
    }

    private function looksLikePersonProfileName(string $name): bool
    {
        $normalized = Str::lower(trim($name));
        if ($normalized === '') {
            return false;
        }

        if (Str::contains($normalized, ['центр', 'компан', 'фірм', 'бюро', 'group', 'law firm', 'legal', 'тов', 'пп', '«', '"'])) {
            return false;
        }

        $normalized = trim((string) preg_replace('/\b(адвокат|юрист|нотаріус|лікар|стоматолог)\b/iu', ' ', $normalized));
        $parts = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));

        if (count($parts) < 2 || count($parts) > 4) {
            return false;
        }

        foreach ($parts as $part) {
            if (! preg_match('/^\p{L}+$/u', $part)) {
                return false;
            }
        }

        return true;
    }

    private function extractLikelySurnameToken(string $name): ?string
    {
        $normalized = Str::lower(trim((string) preg_replace('/\b(адвокат|юрист|нотаріус|лікар|стоматолог)\b/iu', ' ', $name)));
        $parts = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));

        if (count($parts) < 2) {
            return null;
        }

        $first = (string) ($parts[0] ?? '');
        $last = (string) ($parts[count($parts) - 1] ?? '');

        // Pattern "Прізвище Ім'я По-батькові"
        if (count($parts) >= 3 && preg_match('/(ович|евич|йович|вич|івна|ївна|евна)$/u', $last) === 1) {
            return mb_strlen($first) >= 3 ? $first : null;
        }

        // Pattern "Ім'я Прізвище"
        if (count($parts) === 2) {
            return mb_strlen($last) >= 3 ? $last : null;
        }

        return mb_strlen($first) >= 3 ? $first : null;
    }

    /**
     * @param  array<string, mixed>  $place
     * @param  array<int, string>  $tokens
     */
    private function googlePlaceReviewsMatchProfile(array $place, array $tokens): bool
    {
        $reviewHaystack = Str::lower(trim(implode(' ', array_map(
            fn ($review) => is_array($review)
                ? trim((string) (data_get($review, 'text.text') ?: data_get($review, 'originalText.text') ?: ''))
                : '',
            (array) data_get($place, 'reviews', [])
        ))));

        if ($reviewHaystack === '') {
            return false;
        }

        $matches = 0;
        foreach ($tokens as $token) {
            if ($token !== '' && $this->containsTokenFlexible($reviewHaystack, $token)) {
                $matches++;
            }
        }

        return count($tokens) >= 2 ? $matches >= 2 : $matches >= 1;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function extractWebsiteDomains(array $urls): array
    {
        $domains = [];

        foreach ($urls as $url) {
            $domain = $this->extractDomain($url);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    private function extractDomain(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $host = Str::lower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return '';
        }

        return (string) preg_replace('/^www\./', '', $host);
    }

    private function containsTokenFlexible(string $haystack, string $token): bool
    {
        $haystack = Str::lower($haystack);
        $token = Str::lower($token);

        if ($haystack === '' || $token === '') {
            return false;
        }

        if (str_contains($haystack, $token)) {
            return true;
        }

        $asciiHaystack = Str::lower(Str::ascii($haystack));
        $asciiToken = Str::lower(Str::ascii($token));

        if ($asciiHaystack === '' || $asciiToken === '') {
            return false;
        }

        return str_contains($asciiHaystack, $asciiToken);
    }

    /**
     * Extract the most distinctive phrase (prefer quoted brand names).
     */
    private function extractPriorityNamePhrase(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (preg_match('/["«](.+?)["»]/u', $name, $m)) {
            return trim((string) $m[1]);
        }

        $genericPattern = '/\b(центр|юридичн(ої|ий|а)|допомог(и|а)|адвокат|юрист|компанія|фірма|office|law|legal|group)\b/iu';
        $reduced = trim((string) preg_replace($genericPattern, ' ', $name));
        $reduced = trim((string) preg_replace('/\s+/u', ' ', $reduced));

        return mb_strlen($reduced) >= 4 ? $reduced : $name;
    }

    /**
     * @return array<int, string>
     */
    private function extractPriorityTokens(string $name): array
    {
        $phrase = Str::lower($this->extractPriorityNamePhrase($name));

        return array_values(array_filter(
            preg_split('/\s+/u', $phrase) ?: [],
            fn (string $token) => mb_strlen($token) >= 4
        ));
    }

    private function sentimentFromRating(mixed $rating): string
    {
        if (! is_numeric($rating)) {
            return 'unknown';
        }

        $rating = (float) $rating;

        if ($rating >= 4) {
            return 'positive';
        }

        if ($rating <= 2) {
            return 'negative';
        }

        return 'neutral';
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'category' => $nullableString,
                'city' => $nullableString,
                'country' => $nullableString,
                'phone' => $nullableString,
                'website' => $nullableString,
                'email' => $nullableString,
                'address' => $nullableString,
                'logo_url' => $nullableString,
                'source_url' => $nullableString,
                'social_links' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'instagram' => $nullableString,
                        'facebook' => $nullableString,
                        'telegram' => $nullableString,
                        'tiktok' => $nullableString,
                        'youtube' => $nullableString,
                        'linkedin' => $nullableString,
                    ],
                    'required' => ['instagram', 'facebook', 'telegram', 'tiktok', 'youtube', 'linkedin'],
                ],
                'services' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'short_description' => $nullableString,
                'description' => $nullableString,
                'seo_title' => $nullableString,
                'seo_description' => $nullableString,
                'confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'skip_profile' => ['type' => 'boolean'],
                'inactive_reason' => $nullableString,
                'confidence_notes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'source_type' => ['type' => 'string'],
                            'title' => $nullableString,
                            'url' => $nullableString,
                            'found_fields' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        ],
                        'required' => ['source_type', 'title', 'url', 'found_fields', 'confidence_score'],
                    ],
                ],
                'external_mentions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'source_type' => ['type' => 'string'],
                            'title' => $nullableString,
                            'url' => $nullableString,
                            'external_rating' => ['type' => ['number', 'null']],
                            'external_reviews_count' => ['type' => ['integer', 'null']],
                            'review_author' => $nullableString,
                            'review_text' => $nullableString,
                            'review_rating' => ['type' => ['number', 'null']],
                            'review_date' => $nullableString,
                            'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative', 'mixed', 'unknown']],
                            'topic' => $nullableString,
                            'summary' => $nullableString,
                            'confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        ],
                        'required' => ['source_type', 'title', 'url', 'external_rating', 'external_reviews_count', 'review_author', 'review_text', 'review_rating', 'review_date', 'sentiment', 'topic', 'summary', 'confidence_score'],
                    ],
                ],
            ],
            'required' => [
                'name',
                'category',
                'city',
                'country',
                'phone',
                'website',
                'email',
                'address',
                'logo_url',
                'source_url',
                'social_links',
                'services',
                'short_description',
                'description',
                'seo_title',
                'seo_description',
                'confidence_score',
                'skip_profile',
                'inactive_reason',
                'confidence_notes',
                'warnings',
                'sources',
                'external_mentions',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decodeResponse(array $response): array
    {
        $outputText = data_get($response, 'output_text');

        if (blank($outputText)) {
            foreach ((array) data_get($response, 'output', []) as $output) {
                foreach ((array) data_get($output, 'content', []) as $content) {
                    $text = data_get($content, 'text') ?: data_get($content, 'output_text');

                    if (filled($text)) {
                        $outputText = $text;
                        break 2;
                    }
                }
            }
        }

        if (blank($outputText)) {
            throw new RuntimeException('OpenAI response does not contain output_text.');
        }

        $decoded = json_decode((string) $outputText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI response is not valid JSON: ' . Str::limit((string) $outputText, 300));
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeResult(array $decoded, AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city): array
    {
        $name = trim((string) ($decoded['name'] ?? $item['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('OpenAI enrichment returned empty profile name.');
        }

        $decoded['name'] = $name;
        $decoded['category'] = $decoded['category'] ?: $category?->name;
        $decoded['city'] = $decoded['city'] ?: $city;
        $decoded['country'] = $decoded['country'] ?: ($batch->default_country ?: 'Україна');
        $decoded['provider'] = static::class;
        $decoded['skip_profile'] = (bool) ($decoded['skip_profile'] ?? false);
        $decoded['inactive_reason'] = filled($decoded['inactive_reason'] ?? null) ? (string) $decoded['inactive_reason'] : null;
        $decoded['logo_url'] = filled($decoded['logo_url'] ?? null) ? (string) $decoded['logo_url'] : null;
        $decoded['social_links'] = array_filter((array) ($decoded['social_links'] ?? []));
        $decoded['services'] = array_values(array_filter((array) ($decoded['services'] ?? [])));
        $decoded['sources'] = array_values((array) ($decoded['sources'] ?? []));
        $decoded['external_mentions'] = array_values((array) ($decoded['external_mentions'] ?? []));
        $decoded['confidence_notes'] = array_values((array) ($decoded['confidence_notes'] ?? []));
        $decoded['warnings'] = array_values((array) ($decoded['warnings'] ?? []));

        return $decoded;
    }
}

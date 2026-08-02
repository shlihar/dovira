<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileDataSource;
use App\Models\Region;
use App\Support\CategoryHierarchy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Парсер довідника адвокатів uadvokat.com.ua (обгортка над ЄРАУ).
 *
 * Дає базові дані одноосібних адвокатів (ПІБ, телефон, email, адреса) для
 * подальшого AI-збагачення через існуючий AiProfileEnrichmentService:
 * реєстрові контакти йдуть як базові, а enrichment замінює телефон контактом
 * з офіційного сайту адвоката, якщо його знайдено (див.
 * AiProfileEnrichmentService::enrichSuggestedContactsFromOfficialWebsite).
 */
class UadvokatImportService
{
    private const BASE_URL = 'https://uadvokat.com.ua';

    /**
     * Пауза між HTTP-запитами, мс. Сайт без агресивного захисту, але не хамимо.
     */
    private const REQUEST_DELAY_MS = 300;

    /**
     * Обходить пагінований список міста посторінково (по ~10 адвокатів на сторінці).
     *
     * Базова сторінка міста показує інший набір, ніж page-1, тому завжди
     * ходимо по `page-N/` — вони детерміновані й не перетинаються.
     *
     * @return array{urls: array<int, string>, pages_visited: int}
     */
    public function collectCityLawyerUrls(string $regionSlug, string $citySlug, int $startPage = 1, ?int $endPage = null, ?int $maxLawyers = null, int $maxPages = 500): array
    {
        $cityBaseUrl = self::BASE_URL . '/reestr/' . trim($regionSlug, '/') . '/' . trim($citySlug, '/') . '/';
        $startPage = max(1, $startPage);
        $endPage = $endPage !== null && $endPage > 0
            ? max($startPage, $endPage)
            : ($startPage + $maxPages - 1);
        $urls = [];
        $pagesVisited = 0;

        for ($page = $startPage; $page <= $endPage; $page++) {
            $html = $this->fetchHtml($cityBaseUrl . 'page-' . $page . '/');
            if ($html === null) {
                break;
            }

            $pageUrls = $this->extractLawyerUrls($html, $regionSlug, $citySlug);
            if ($pageUrls === []) {
                break;
            }

            $pagesVisited++;
            $newUrls = array_diff($pageUrls, $urls);
            if ($newUrls === []) {
                break;
            }

            $urls = array_merge($urls, array_values($newUrls));
            if ($maxLawyers !== null && $maxLawyers > 0 && count($urls) >= $maxLawyers) {
                $urls = array_slice($urls, 0, $maxLawyers);
                break;
            }

            usleep(self::REQUEST_DELAY_MS * 1000);
        }

        return ['urls' => $urls, 'pages_visited' => $pagesVisited];
    }

    /**
     * @return array{
     *   name:string, phone:?string, email:?string, address:?string,
     *   city:?string, region:?string, activity:?string, certificate:?string,
     *   source_url:string
     * }|null
     */
    public function fetchLawyerDetails(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $name = $this->cleanText($this->matchFirst('/<h1[^>]*itemprop="name"[^>]*>(.*?)<\/h1>/isu', $html));
        if ($name === '') {
            return null;
        }

        $address = $this->cleanText($this->matchFirst('/itemprop="address"[^>]*>\s*Адреса:\s*(.*?)<\/div>/isu', $html));
        $cityBlock = $this->matchFirst('/Місто\/область:\s*<a[^>]*>([^<]+)<\/a>\s*\(\s*<a[^>]*>([^<]+)<\/a>/isu', $html, false);
        $city = '';
        $region = '';
        if (is_array($cityBlock)) {
            $city = $this->cleanText($cityBlock[0] ?? '');
            $region = $this->cleanText($cityBlock[1] ?? '');
        }
        $city = trim((string) preg_replace('/^(м|с|смт|сщ|с-ще)\.\s*/iu', '', $city));

        return [
            'name' => $name,
            'phone' => $this->nullIfBlank($this->cleanText($this->matchFirst('/itemprop="telephone"[^>]*>([^<]+)</isu', $html))),
            'email' => $this->nullIfBlank($this->cleanText($this->matchFirst('/itemprop="email"[^>]*>([^<]+)</isu', $html))),
            'address' => $this->nullIfBlank($address),
            'city' => $this->nullIfBlank($city),
            'region' => $this->nullIfBlank($region),
            'activity' => $this->nullIfBlank($this->cleanText($this->matchFirst('/працює\s+адвокатом\s*\(([^)]+)\)/isu', $html))),
            'certificate' => $this->nullIfBlank($this->cleanText($this->matchFirst('/Номер свідоцтва[^№]*(№\s*[^<(]+)/isu', $html))),
            'source_url' => $url,
        ];
    }

    /**
     * CSV у форматі, який розуміє AiProfileEnrichmentService::parseTextInput.
     *
     * @param  array<int, array<string, mixed>>  $lawyers
     */
    public function buildEnrichmentCsv(array $lawyers): string
    {
        $lines = ['name;city;phone;email;address;source_url'];

        foreach ($lawyers as $lawyer) {
            if (! is_array($lawyer) || trim((string) ($lawyer['name'] ?? '')) === '') {
                continue;
            }

            $lines[] = implode(';', array_map(
                fn ($value): string => str_replace([';', "\n", "\r"], [',', ' ', ' '], trim((string) ($value ?? ''))),
                [
                    $lawyer['name'],
                    $lawyer['city'] ?? '',
                    $lawyer['phone'] ?? '',
                    $lawyer['email'] ?? '',
                    $lawyer['address'] ?? '',
                    $lawyer['source_url'] ?? '',
                ]
            ));
        }

        return implode("\n", $lines);
    }

    public function requestDelayMs(): int
    {
        return self::REQUEST_DELAY_MS;
    }

    /**
     * Створює профілі напряму з реєстрових даних, без AI-збагачення.
     * Такі профілі лишаються "неповними" (без опису й ai_enrichment_status),
     * тому їх потім можна вибірково прогнати через AI.
     *
     * @param  array<int, array<string, mixed>>  $lawyers
     * @return array{created:int, skipped:int, profile_ids:array<int, int>, existing_profile_ids:array<int, int>}
     */
    public function createProfilesDirectly(array $lawyers, ?Category $defaultCategory, bool $publish): array
    {
        $created = 0;
        $skipped = 0;
        $profileIds = [];
        $existingProfileIds = [];

        $rootCategoryId = null;
        $subcategoryId = null;
        if ($defaultCategory) {
            $rootCategoryId = CategoryHierarchy::resolveRootCategoryId((int) $defaultCategory->id);
            $subcategoryId = $defaultCategory->parent_id ? (int) $defaultCategory->id : null;
        }

        foreach ($lawyers as $lawyer) {
            if (! is_array($lawyer)) {
                continue;
            }

            $name = trim((string) ($lawyer['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $city = $this->nullIfBlank(trim((string) ($lawyer['city'] ?? '')));

            $existingId = Profile::query()
                ->where('name', $name)
                ->when($city, fn ($query) => $query->where('city', $city))
                ->value('id');
            if ($existingId) {
                $skipped++;
                $existingProfileIds[] = (int) $existingId;
                continue;
            }

            $profile = Profile::create([
                'name' => $name,
                'slug' => $this->uniqueProfileSlug($name),
                'type' => 'company',
                'phone' => $lawyer['phone'] ?? null,
                'email' => $lawyer['email'] ?? null,
                'address' => $lawyer['address'] ?? null,
                'city' => $city,
                'region_id' => filled($lawyer['region'] ?? null)
                    ? Region::query()->where('name', trim((string) $lawyer['region']))->value('id')
                    : null,
                'status' => $publish ? 'active' : 'draft',
                'is_published' => $publish,
                'show_in_catalog' => $publish,
                'internal_note' => 'Імпортовано з uadvokat.com.ua (реєстр адвокатів). AI-збагачення не виконувалось.',
            ]);

            if ($rootCategoryId) {
                CategoryHierarchy::syncProfileCategories($profile, $rootCategoryId, $subcategoryId);
            }

            if (filled($lawyer['source_url'] ?? null)) {
                ProfileDataSource::create([
                    'profile_id' => $profile->id,
                    'source_type' => 'uadvokat_import',
                    'title' => $name,
                    'url' => (string) $lawyer['source_url'],
                    'found_fields' => [
                        'activity' => $lawyer['activity'] ?? null,
                        'certificate' => $lawyer['certificate'] ?? null,
                    ],
                    'status' => 'imported',
                    'fetched_at' => now(),
                ]);
            }

            $profileIds[] = (int) $profile->id;
            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'profile_ids' => $profileIds,
            'existing_profile_ids' => $existingProfileIds,
        ];
    }

    private function uniqueProfileSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'advokat';
        $slug = $base;
        $suffix = 2;

        while (Profile::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Індекс імен адвокатів міста на top20.ua (тег advokat). Якщо адвокат там є —
     * у нього гарантовано є відгуки, і AI-збагачення має сенс.
     *
     * @return array<int, array<int, string>> нормалізовані токени імен
     */
    public function buildTop20LawyerNameIndex(string $city, int $maxPages = 40): array
    {
        $cityCode = $this->top20CityCode($city);
        if ($cityCode === null) {
            return [];
        }

        $sources = [
            'https://top20.ua/' . $cityCode . '/tag/advokat.html',
            'https://top20.ua/' . $cityCode . '/biznes-poslugi/advokatski-poslugi/',
            'https://top20.ua/' . $cityCode . '/tag/yurist.html',
        ];

        $index = [];
        $seenNames = [];

        foreach ($sources as $baseUrl) {
            for ($page = 1; $page <= $maxPages; $page++) {
                $url = $baseUrl . ($page > 1 ? ('?page=' . $page) : '');
                $html = $this->fetchHtml($url);
                if ($html === null) {
                    break;
                }

                if (! preg_match_all('/caption-title[^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>([^<]+)<\/a>/isu', $html, $matches, PREG_SET_ORDER)) {
                    break;
                }

                $newNames = 0;
                foreach ($matches as $match) {
                    $name = trim(html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($name === '' || isset($seenNames[$name])) {
                        continue;
                    }

                    $seenNames[$name] = true;
                    $index[] = [
                        'tokens' => $this->normalizeNameTokens($name),
                        'name' => $name,
                        'url' => trim(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    ];
                    $newNames++;
                }

                // Сторінка без нових імен = кінець списку або редірект на першу сторінку.
                if ($newNames === 0) {
                    break;
                }

                usleep(self::REQUEST_DELAY_MS * 1000);
            }
        }

        return $index;
    }

    /**
     * @param  array<int, array{tokens: array<int, string>, name: string, url: string}>  $top20Index
     */
    public function lawyerMatchesTop20Index(string $lawyerName, array $top20Index): bool
    {
        return $this->matchTop20Entry($lawyerName, $top20Index) !== null;
    }

    /**
     * Шукає top20-картку адвоката. personal=true — збіг за прізвищем + ім'ям
     * (можна безпечно прив'язувати відгуки картки до цього адвоката);
     * personal=false — збіг за прізвищем у фірмовій назві (лише сигнал для AI).
     *
     * @param  array<int, array{tokens: array<int, string>, name: string, url: string}>  $top20Index
     * @return array{url: string, name: string, personal: bool}|null
     */
    public function matchTop20Entry(string $lawyerName, array $top20Index): ?array
    {
        $tokens = $this->normalizeNameTokens($lawyerName);
        $surname = (string) ($tokens[0] ?? '');
        $firstName = (string) ($tokens[1] ?? '');

        if (mb_strlen($surname) < 4) {
            return null;
        }

        // Порівняння з урахуванням відмінків: "хартов" ↔ "хартова", "андрій" ↔ "андрія".
        $tokenMatches = function (string $needle, string $token): bool {
            if ($needle === $token) {
                return true;
            }

            if ((mb_strlen($needle) >= 5 && str_starts_with($token, $needle))
                || (mb_strlen($token) >= 5 && str_starts_with($needle, $token))) {
                return true;
            }

            // Відмінкові закінчення міняють останні літери ("андріи" ↔ "андрия"):
            // досить збігу основи з перших 5 літер, якщо довжини близькі.
            return mb_strlen($needle) >= 5 && mb_strlen($token) >= 5
                && abs(mb_strlen($needle) - mb_strlen($token)) <= 2
                && mb_substr($needle, 0, 5) === mb_substr($token, 0, 5);
        };

        $firmMarkers = ['бюро', 'партнер', 'обеднанн', 'обединенн', 'компани', 'юридичн', 'контора'];
        $firmMatch = null;

        foreach ($top20Index as $entry) {
            $candidateTokens = (array) ($entry['tokens'] ?? []);

            $surnameHit = false;
            foreach ($candidateTokens as $token) {
                if ($tokenMatches($surname, $token)) {
                    $surnameHit = true;
                    break;
                }
            }
            if (! $surnameHit) {
                continue;
            }

            if ($firstName === '') {
                return ['url' => (string) $entry['url'], 'name' => (string) $entry['name'], 'personal' => true];
            }

            $initial = mb_substr($firstName, 0, 1);
            foreach ($candidateTokens as $token) {
                if ($tokenMatches($firstName, $token)
                    || (mb_strlen($token) <= 2 && str_starts_with($token, $initial))) {
                    return ['url' => (string) $entry['url'], 'name' => (string) $entry['name'], 'personal' => true];
                }
            }

            // Фірмова назва без імені ("Никитинський і партнери", "бюро Хартова").
            if ($firmMatch === null) {
                foreach ($candidateTokens as $token) {
                    foreach ($firmMarkers as $marker) {
                        if (str_starts_with($token, $marker)) {
                            $firmMatch = ['url' => (string) $entry['url'], 'name' => (string) $entry['name'], 'personal' => false];
                            break 2;
                        }
                    }
                }
            }
        }

        return $firmMatch;
    }

    /**
     * Перевірка через Google Places: чи є точка на картах з відгуками.
     *
     * @return bool|null null = API недоступний (не вмикати повторні спроби)
     */
    public function lawyerFindableViaGooglePlaces(string $lawyerName, string $city): ?bool
    {
        $apiKey = trim((string) config('ai_enrichment.google_maps.api_key', ''));
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'places.displayName,places.userRatingCount',
                ])
                ->post('https://places.googleapis.com/v1/places:searchText', [
                    'textQuery' => 'адвокат ' . $lawyerName . ' ' . $city,
                    'languageCode' => 'uk',
                    'maxResultCount' => 5,
                ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->status() === 403) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $surnameTokens = $this->normalizeNameTokens($lawyerName);
        $surname = (string) ($surnameTokens[0] ?? '');

        foreach ((array) $response->json('places', []) as $place) {
            $displayName = mb_strtolower((string) data_get($place, 'displayName.text', ''));
            $ratingCount = (int) data_get($place, 'userRatingCount', 0);

            if ($ratingCount >= 1 && $surname !== '' && str_contains($displayName, $surname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Email на власному домені (не gmail/ukr.net) майже завжди означає сайт
     * адвоката чи його фірми. Живий сайт = валідний кандидат для AI-збагачення,
     * а enrichment потім витягне з нього актуальні контакти.
     */
    public function detectLawyerWebsiteFromEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim((string) substr((string) strrchr($email, '@'), 1)));
        if ($domain === '' || ! str_contains($domain, '.')) {
            return null;
        }

        $genericDomains = [
            'gmail.com', 'gmai.com', 'gmail.ua', 'googlemail.com',
            'ukr.net', 'i.ua', 'meta.ua', 'email.ua', 'online.ua', 'bigmir.net', '3g.ua',
            'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'me.com',
            'mail.ru', 'inbox.ru', 'list.ru', 'bk.ru', 'rambler.ru', 'yandex.ru', 'yandex.ua', 'ya.ru',
            'email.com', 'mail.com', 'protonmail.com', 'proton.me',
            'te.net.ua', 'optima.com.ua', 'paco.net', 'breezein.net', 'tenet.ua',
        ];

        if (in_array($domain, $genericDomains, true)
            || str_ends_with($domain, '.gov.ua')
            || str_ends_with($domain, '.edu.ua')) {
            return null;
        }

        foreach (['https://' . $domain, 'https://www.' . $domain, 'http://' . $domain] as $candidateUrl) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
                ])->timeout(8)->get($candidateUrl);

                if ($response->successful() && strlen((string) $response->body()) > 500) {
                    return $candidateUrl;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    public function top20CityCode(string $city): ?string
    {
        $map = [
            'київ' => 'kyiv',
            'одеса' => 'od',
            'львів' => 'lviv',
            'харків' => 'kh',
            'дніпро' => 'dp',
            'вінниця' => 'vn',
            'миколаїв' => 'mk',
            'запоріжжя' => 'zp',
            'черкаси' => 'ck',
            'чернігів' => 'cn',
            'чернівці' => 'cv',
            'івано-франківськ' => 'if',
            'хмельницький' => 'khm',
            'кропивницький' => 'kr',
            'кривий ріг' => 'krog',
            'луцьк' => 'lutsk',
        ];

        return $map[mb_strtolower(trim($city))] ?? null;
    }

    /**
     * Нормалізує ім'я і "складає" схожі укр/рос літери в одну, щоб
     * "Нікітінський" (реєстр) збігався з "Никитинский" (top20 російською).
     *
     * @return array<int, string>
     */
    private function normalizeNameTokens(string $name): array
    {
        $normalized = mb_strtolower($name);
        $normalized = strtr($normalized, [
            'і' => 'и', 'ї' => 'и', 'й' => 'и', 'ы' => 'и',
            'є' => 'е', 'э' => 'е', 'ё' => 'е',
            'ґ' => 'г',
            'ь' => '', 'ъ' => '', '’' => '', "'" => '',
        ]);
        $normalized = (string) preg_replace('/[^а-яиеa-z0-9]+/ui', ' ', $normalized);
        $tokens = preg_split('/\s+/u', trim($normalized)) ?: [];

        return array_values(array_filter($tokens, fn (string $token): bool => $token !== '' && $token !== 'адвокат'));
    }

    /**
     * Бере адвокатів лише з пагінованого списку (блоки article.pristav_info).
     * Просто грепати всі лінки не можна: у сторінку вшита мапа з десятками
     * адвокатів міста, однакова на всіх сторінках пагінації.
     *
     * @return array<int, string>
     */
    private function extractLawyerUrls(string $html, string $regionSlug, string $citySlug): array
    {
        if (! preg_match_all('#<article class="pristav_info".*?</article>#is', $html, $articleMatches)) {
            return [];
        }

        $pattern = '#href="(' . preg_quote(self::BASE_URL, '#') . '/reestr/'
            . preg_quote(trim($regionSlug, '/'), '#') . '/'
            . preg_quote(trim($citySlug, '/'), '#') . '/[a-z0-9-]+\.html)"#i';

        $urls = [];
        foreach ($articleMatches[0] as $article) {
            if (preg_match($pattern, $article, $match)) {
                $urls[$match[1]] = $match[1];
            }
        }

        return array_values($urls);
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = $this->http()->get($url)->throw();
        } catch (\Throwable) {
            return null;
        }

        $html = (string) $response->body();

        return $html !== '' ? $html : null;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept-Language' => 'uk-UA,uk;q=0.9',
        ])
            ->timeout(20)
            ->retry(2, 400, function (\Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && in_array($exception->response->status(), [408, 425, 429, 500, 502, 503, 504], true);
            });
    }

    /**
     * @return ($firstGroupOnly is true ? string : array<int, string>|string)
     */
    private function matchFirst(string $pattern, string $html, bool $firstGroupOnly = true): string|array
    {
        if (! preg_match($pattern, $html, $matches)) {
            return $firstGroupOnly ? '' : [];
        }

        return $firstGroupOnly ? (string) ($matches[1] ?? '') : array_slice($matches, 1);
    }

    private function cleanText(string|array $value): string
    {
        if (is_array($value)) {
            $value = (string) ($value[0] ?? '');
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function nullIfBlank(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}

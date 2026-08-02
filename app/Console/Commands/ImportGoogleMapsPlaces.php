<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryService;
use App\Models\Profile;
use App\Models\ProfileDataSource;
use App\Models\ProfileReview;
use App\Models\Region;
use App\Services\ProfileReviewStatsService;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ImportGoogleMapsPlaces extends Command
{
    protected $signature = 'dovira:import-google-maps
        {file : Шлях до JSON-датасету Apify (compass/crawler-google-places)}
        {--min-reviews=1 : Створювати нові профілі лише з такою кількістю Google-відгуків}
        {--limit= : Обмежити кількість оброблених місць}
        {--max-new= : Зупинитись після створення такої кількості нових профілів}
        {--skip-existing : Не чіпати існуючі профілі (ні полів, ні відгуків) — лише створювати нові}
        {--include-all-categories : Не фільтрувати за юридичними категоріями}
        {--publish : Публікувати нові профілі одразу}
        {--refresh-existing : Перезаписувати контакти існуючих профілів даними з Google}
        {--dry-run : Аналіз без запису в БД}';

    protected $description = 'Імпортує місця та Google-відгуки з Apify-датасету Google Maps у профілі DOVIRA.';

    /**
     * Google-категорія вважається юридичною, якщо містить один із маркерів.
     * "Офіс фірми" та інші нейтральні категорії пропускаємо, крім випадків,
     * коли юридичний маркер є в самій назві місця.
     */
    private const LEGAL_MARKERS = [
        'адвокат', 'юрид', 'юрист', 'нотаріус', 'правов', 'адвокатура', 'патент',
    ];

    private const NOTARY_MARKER = 'нотаріус';

    /**
     * Префікси загальних слів (у нормалізованій формі, після normalizeNameTokens),
     * які не несуть ідентичності: "Адвокат', юридична компанія" не повинна
     * матчитись з будь-якою "Юридична компанія X".
     */
    private const GENERIC_TOKEN_PREFIXES = [
        'адвокат', 'юрид', 'юрист', 'нотар', 'бюро', 'офис', 'контор',
        'компан', 'фирм', 'обедин', 'обьедин', 'коллег', 'колег',
        'послуг', 'услуг', 'помощ', 'допомог', 'правов', 'консультац',
        'державн', 'государствен', 'частн',
        'центр', 'групп', 'партнер', 'partner', 'group', 'compan', 'law', 'legal',
    ];

    private const GENERIC_TOKENS = ['право', 'права', 'бизнес', 'тов', 'ооо', 'ао', 'пп', 'фоп', 'и', 'та', 'в', 'у'];

    /**
     * Google-категорії місця → назви послуг з довідника CategoryService.
     * Мапимо лише однозначні спеціалізації; загальні ("Адвокат", "Юридичні
     * послуги") нічого не кажуть про профіль.
     */
    private const SERVICE_MAP = [
        'Адвокат у кримінальних справах' => 'Кримінальне право',
        'Адвокат з цивільного права' => 'Цивільне право',
        'Адвокат із розлучень' => 'Сімейне право',
        'Адвокат у сімейних справах' => 'Сімейне право',
        'Сімейний адвокат' => 'Сімейне право',
        'Адвокат із трудових спорів' => 'Трудове право',
        'Адвокат із трудового права' => 'Трудове право',
        'Адвокат у справах оподаткування' => 'Консультації з податкового права',
        'Адвокат із правом виступу в судах' => 'Представництво в суді',
        'Адвокат із питань імміграції' => 'імміграційний адвокат',
        'Служба імміграції та натуралізації' => 'імміграційний адвокат',
        'Адвокат з адміністративних справ' => 'Адміністративне право',
        'Адвокат із нерухомості' => 'Нерухомість та будівництво',
        'Адвокат зі спадкових справ' => 'Спадкове право',
        'Адвокат у спадкових справах' => 'Спадкове право',
        'Адвокат із майнового планування' => 'Спадкове право',
        'Бізнес-адвокат' => 'Корпоративне право',
    ];

    /** @var array<string, int|null> */
    private array $regionIdCache = [];

    /** @var array<string, int> */
    private array $serviceIdCache = [];

    public function handle(ProfileReviewStatsService $stats): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("Файл не знайдено: {$file}");

            return self::FAILURE;
        }

        $items = json_decode((string) file_get_contents($file), true);
        if (! is_array($items) || $items === []) {
            $this->error('JSON порожній або не парситься.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $minReviews = max(0, (int) $this->option('min-reviews'));
        $limit = (int) ($this->option('limit') ?: 0);
        $maxNew = (int) ($this->option('max-new') ?: 0);

        /** @var array<int, Profile> $createdProfiles */
        $createdProfiles = [];

        $advokatyCategory = Category::query()->where('slug', 'advokaty')->first();
        $notariusyCategory = Category::query()->where('slug', 'notariusy')->first();
        $realEstateCategory = Category::query()->where('slug', 'agenstva-neruxomosti')->first();

        $counters = [
            'processed' => 0,
            'skipped_existing' => 0,
            'skipped_category' => 0,
            'skipped_closed' => 0,
            'skipped_min_reviews' => 0,
            'matched_place_id' => 0,
            'matched_phone' => 0,
            'matched_name' => 0,
            'created' => 0,
            'updated' => 0,
            'reviews_created' => 0,
            'reviews_skipped' => 0,
        ];

        $profileIndex = $this->buildProfileIndex($items);
        $touchedProfileIds = [];
        $seenPlaceIds = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($limit > 0 && $counters['processed'] >= $limit) {
                break;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $placeId = trim((string) ($item['placeId'] ?? ''));
            if ($title === '' || $placeId === '' || isset($seenPlaceIds[$placeId])) {
                continue;
            }
            $seenPlaceIds[$placeId] = true;

            if (! empty($item['permanentlyClosed'])) {
                $counters['skipped_closed']++;
                continue;
            }

            if (! $this->option('include-all-categories') && ! $this->isLegalPlace($item)) {
                $counters['skipped_category']++;
                continue;
            }

            $counters['processed']++;

            [$matchedId, $matchType] = $this->matchExistingProfile($item, $profileIndex);

            if ($matchedId !== null && $this->option('skip-existing')) {
                $counters['matched_' . $matchType]++;
                $counters['skipped_existing']++;
                continue;
            }

            if ($matchedId === null && (int) ($item['reviewsCount'] ?? 0) < $minReviews) {
                $counters['skipped_min_reviews']++;
                continue;
            }

            if ($matchType !== null) {
                $counters['matched_' . $matchType]++;
            }

            if ($dryRun) {
                $action = $matchedId
                    ? "оновлення #{$matchedId} ({$matchType})"
                    : 'створення';
                if ($matchedId === null) {
                    $counters['created']++;
                }
                $this->line(sprintf(
                    '  %s★%.1f (%d відгуків) %s — %s',
                    str_pad((string) ($item['categoryName'] ?? ''), 24),
                    (float) ($item['totalScore'] ?? 0),
                    (int) ($item['reviewsCount'] ?? 0),
                    $title,
                    $action
                ));
                if ($maxNew > 0 && $counters['created'] >= $maxNew) {
                    break;
                }
                continue;
            }

            $isNewProfile = $matchedId === null;
            if ($isNewProfile) {
                $profile = $this->createProfile($item, $advokatyCategory, $notariusyCategory, $realEstateCategory);
                $profileIndex['by_place'][(string) $item['placeId']] = $profile->id;
                $profileIndex['by_phone'][$this->phoneKey((string) ($item['phone'] ?? ''))] = $profile->id;
                $counters['created']++;
                $createdProfiles[] = $profile;
            } else {
                $profile = Profile::find($matchedId);
                if ($profile === null) {
                    continue;
                }
                $this->updateProfile($profile, $item);
                $this->ensureDataSource($profile, $item);
                $counters['updated']++;
            }

            $reviewResult = $this->importReviews($profile, $item, $isNewProfile);
            $counters['reviews_created'] += $reviewResult['created'];
            $counters['reviews_skipped'] += $reviewResult['skipped'];

            if ($reviewResult['created'] > 0) {
                $touchedProfileIds[$profile->id] = true;
            }

            if ($maxNew > 0 && $counters['created'] >= $maxNew) {
                break;
            }
        }

        if (! $dryRun) {
            foreach (array_keys($touchedProfileIds) as $profileId) {
                $stats->recalculateForProfileId((int) $profileId);
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'DRY-RUN, у БД нічого не записано.' : 'Імпорт завершено.');
        if ($createdProfiles !== []) {
            $this->newLine();
            $this->info('Створені профілі:');
            foreach ($createdProfiles as $created) {
                $this->line(sprintf('  #%d %s → /profiles/%s', $created->id, $created->name, $created->slug));
            }
        }

        $this->table(['Метрика', 'Значення'], [
            ['Оброблено юридичних місць', $counters['processed']],
            ['Пропущено існуючих (--skip-existing)', $counters['skipped_existing']],
            ['Пропущено (не юридична категорія)', $counters['skipped_category']],
            ['Пропущено (закриті назавжди)', $counters['skipped_closed']],
            ['Пропущено (менше --min-reviews і немає в базі)', $counters['skipped_min_reviews']],
            ['Збіг за placeId', $counters['matched_place_id']],
            ['Збіг за телефоном', $counters['matched_phone']],
            ['Збіг за іменем+містом', $counters['matched_name']],
            ['Створено профілів', $counters['created']],
            ['Оновлено профілів', $counters['updated']],
            ['Створено відгуків', $counters['reviews_created']],
            ['Пропущено відгуків (дублі)', $counters['reviews_skipped']],
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isLegalPlace(array $item): bool
    {
        $haystacks = array_filter([
            mb_strtolower((string) ($item['categoryName'] ?? '')),
            ...array_map(
                fn ($category): string => mb_strtolower((string) $category),
                is_array($item['categories'] ?? null) ? $item['categories'] : []
            ),
        ]);

        foreach ($haystacks as $haystack) {
            foreach (self::LEGAL_MARKERS as $marker) {
                if (str_contains($haystack, $marker)) {
                    return true;
                }
            }
        }

        // Нейтральна категорія ("Офіс фірми" тощо), але юридична назва місця.
        $title = mb_strtolower((string) ($item['title'] ?? ''));
        foreach (self::LEGAL_MARKERS as $marker) {
            if (str_contains($title, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Індекс існуючих профілів міст датасету для матчингу за телефоном та іменем.
     *
     * @param  array<int, mixed>  $items
     * @return array{by_place: array<string, int>, by_phone: array<string, int>, by_city_tokens: array<string, array<int, array{id:int, tokens:array<int, string>}>>}
     */
    private function buildProfileIndex(array $items): array
    {
        $cities = array_values(array_unique(array_filter(array_map(
            fn ($item): string => is_array($item) ? trim((string) ($item['city'] ?? '')) : '',
            $items
        ))));

        $index = ['by_place' => [], 'by_phone' => [], 'by_city_tokens' => []];

        // Раніше імпортовані placeId — вантажимо мапою одним запитом, щоб
        // повторний запуск пропускав уже створене без LIKE-скану на кожне місце
        // (критично для віддаленої БД).
        ProfileDataSource::query()
            ->where('source_type', 'google_maps_import')
            ->get(['profile_id', 'found_fields'])
            ->each(function (ProfileDataSource $source) use (&$index): void {
                $found = is_array($source->found_fields)
                    ? $source->found_fields
                    : (array) json_decode((string) $source->found_fields, true);
                $placeId = trim((string) ($found['place_id'] ?? ''));
                if ($placeId !== '') {
                    $index['by_place'][$placeId] = (int) $source->profile_id;
                }
            });

        Profile::query()
            ->whereIn('city', $cities)
            ->get(['id', 'name', 'phone', 'city'])
            ->each(function (Profile $profile) use (&$index): void {
                $phoneKey = $this->phoneKey((string) $profile->phone);
                if ($phoneKey !== '' && ! isset($index['by_phone'][$phoneKey])) {
                    $index['by_phone'][$phoneKey] = (int) $profile->id;
                }

                $index['by_city_tokens'][(string) $profile->city][] = [
                    'id' => (int) $profile->id,
                    'tokens' => $this->normalizeNameTokens((string) $profile->name, (string) $profile->city),
                    'is_notary' => str_contains(mb_strtolower((string) $profile->name), 'нотар'),
                ];
            });

        return $index;
    }

    /**
     * Матчинг лише за індексами в памʼяті (без запитів на кожне місце):
     * placeId → телефон → нормалізоване ім'я в місті. Повертає ID, а не
     * завантажену модель — щоб пропуск існуючих не бив у БД.
     *
     * @param  array<string, mixed>  $item
     * @param  array{by_place: array<string, int>, by_phone: array<string, int>, by_city_tokens: array<string, array<int, array{id:int, tokens:array<int, string>, is_notary:bool}>>}  $index
     * @return array{0: ?int, 1: ?string}
     */
    private function matchExistingProfile(array $item, array $index): array
    {
        $placeId = (string) $item['placeId'];
        if (isset($index['by_place'][$placeId])) {
            return [$index['by_place'][$placeId], 'place_id'];
        }

        $phoneKey = $this->phoneKey((string) ($item['phone'] ?? ''));
        if ($phoneKey !== '' && isset($index['by_phone'][$phoneKey])) {
            return [$index['by_phone'][$phoneKey], 'phone'];
        }

        $city = trim((string) ($item['city'] ?? ''));
        $tokens = $this->normalizeNameTokens((string) $item['title'], $city);
        $itemIsNotary = str_contains(mb_strtolower((string) $item['title'] . ' ' . (string) ($item['categoryName'] ?? '')), 'нотар');
        $matchedIds = [];
        foreach ($index['by_city_tokens'][$city] ?? [] as $candidate) {
            // "Нотаріус Білик В.В." і юрфірма "Білик та Партнери" — різні
            // бізнеси, навіть якщо прізвище збігається.
            if ($candidate['is_notary'] !== $itemIsNotary) {
                continue;
            }

            if ($this->nameTokensMatch($tokens, $candidate['tokens'])) {
                $matchedIds[] = $candidate['id'];
            }
        }

        // Кілька кандидатів = неоднозначність; краще створити дубль,
        // ніж приліпити чужі відгуки не тому профілю.
        if (count($matchedIds) === 1) {
            return [$matchedIds[0], 'name'];
        }

        return [null, null];
    }

    /**
     * Токен-сети збігаються, якщо один є підмножиною іншого і спільних
     * токенів >= 2 (ПІБ без по батькові тощо), або обидва зводяться до
     * одного й того ж прізвища ("Миронова Т.П." ↔ "МИРОНОВА Т.П. ЧАСТНЫЙ
     * АДВОКАТ"). Одного спільного токена при різних наборах недостатньо.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function nameTokensMatch(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        $common = count(array_intersect($a, $b));

        if ($a === $b && count($a) === 1) {
            return mb_strlen($a[0]) >= 4;
        }

        if ($common < 2) {
            return false;
        }

        return $common === count($a) || $common === count($b);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createProfile(array $item, ?Category $advokaty, ?Category $notariusy, ?Category $realEstate): Profile
    {
        $publish = (bool) $this->option('publish');
        $name = trim((string) $item['title']);

        $profile = Profile::create([
            'name' => $name,
            'slug' => $this->uniqueProfileSlug($name),
            'type' => 'company',
            'phone' => $this->nullIfBlank((string) ($item['phone'] ?? '')),
            'website' => $this->nullIfBlank((string) ($item['website'] ?? '')),
            'address' => $this->nullIfBlank((string) ($item['street'] ?? $item['address'] ?? '')),
            'city' => $this->nullIfBlank((string) ($item['city'] ?? '')),
            'region_id' => $this->resolveRegionId((string) ($item['state'] ?? ''), (string) ($item['city'] ?? '')),
            'google_rating' => $item['totalScore'] ?? null,
            'google_reviews_count' => (int) ($item['reviewsCount'] ?? 0),
            'google_rating_fetched_at' => $this->parseDate($item['scrapedAt'] ?? null),
            'status' => $publish ? 'active' : 'draft',
            'is_published' => $publish,
            'show_in_catalog' => $publish,
            'internal_note' => 'Імпортовано з Google Maps (Apify, категорія: '
                . (string) ($item['categoryName'] ?? '—') . ').',
        ]);

        $category = $this->pickCategory($item, $advokaty, $notariusy, $realEstate);
        if ($category) {
            CategoryHierarchy::syncProfileCategories(
                $profile,
                CategoryHierarchy::resolveRootCategoryId((int) $category->id),
                $category->parent_id ? (int) $category->id : null
            );
        }

        $this->attachServices($profile, $item);
        $this->createDataSource($profile, $item);

        return $profile;
    }

    /**
     * Прямий запис джерела даних для щойно створеного профілю (без перевірки
     * існування — її не потрібно для нового профілю).
     *
     * @param  array<string, mixed>  $item
     */
    private function createDataSource(Profile $profile, array $item): void
    {
        $url = (string) ($item['url'] ?? '');
        if ($url === '') {
            return;
        }

        ProfileDataSource::create($this->dataSourcePayload($profile, $item, $url));
    }

    /**
     * Спеціалізації з Google-категорій місця → чіпи послуг у вкладці
     * "Інформація".
     *
     * @param  array<string, mixed>  $item
     */
    private function attachServices(Profile $profile, array $item): void
    {
        $serviceIds = [];

        foreach ((array) ($item['categories'] ?? []) as $googleCategory) {
            $serviceName = self::SERVICE_MAP[(string) $googleCategory] ?? null;
            if ($serviceName === null) {
                continue;
            }

            $this->serviceIdCache[$serviceName] ??= (int) CategoryService::query()->where('name', $serviceName)->value('id');
            if ($this->serviceIdCache[$serviceName] > 0) {
                $serviceIds[] = $this->serviceIdCache[$serviceName];
            }
        }

        if ($serviceIds !== []) {
            $profile->services()->syncWithoutDetaching(array_unique($serviceIds));
        }
    }

    /**
     * Google-рейтинг оновлюємо завжди, контакти — лише порожні
     * (або всі, якщо --refresh-existing).
     *
     * @param  array<string, mixed>  $item
     */
    private function updateProfile(Profile $profile, array $item): void
    {
        $refresh = (bool) $this->option('refresh-existing');

        $profile->google_rating = $item['totalScore'] ?? $profile->google_rating;
        $profile->google_reviews_count = (int) ($item['reviewsCount'] ?? 0);
        $profile->google_rating_fetched_at = $this->parseDate($item['scrapedAt'] ?? null) ?? now();

        foreach ([
            'phone' => $this->nullIfBlank((string) ($item['phone'] ?? '')),
            'website' => $this->nullIfBlank((string) ($item['website'] ?? '')),
            'address' => $this->nullIfBlank((string) ($item['street'] ?? $item['address'] ?? '')),
        ] as $field => $value) {
            if ($value !== null && ($refresh || blank($profile->{$field}))) {
                $profile->{$field} = $value;
            }
        }

        $profile->saveQuietly();
    }

    /**
     * Категорію даємо лише коли вона однозначна: нотаріуси, нерухомість
     * (гібриди типу центрів нерухомості з юрпослугами) або юридична. Решта —
     * без категорії, краще ніж чужа.
     *
     * @param  array<string, mixed>  $item
     */
    private function pickCategory(array $item, ?Category $advokaty, ?Category $notariusy, ?Category $realEstate): ?Category
    {
        $categoryName = mb_strtolower((string) ($item['categoryName'] ?? ''));
        $title = mb_strtolower((string) ($item['title'] ?? ''));

        if ($notariusy && str_contains($categoryName, self::NOTARY_MARKER)) {
            return $notariusy;
        }

        foreach (['нерухом', 'недвижим', 'ріелт', 'риелт', 'ріелтер', 'realt'] as $marker) {
            if (str_contains($categoryName . ' ' . $title, $marker)) {
                return $realEstate;
            }
        }

        foreach (self::LEGAL_MARKERS as $marker) {
            if (str_contains($categoryName, $marker) || str_contains($title, $marker)) {
                return $advokaty;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function ensureDataSource(Profile $profile, array $item): void
    {
        $url = (string) ($item['url'] ?? '');
        if ($url === '') {
            return;
        }

        $exists = ProfileDataSource::query()
            ->where('profile_id', $profile->id)
            ->where('source_type', 'google_maps_import')
            ->where('url', 'like', '%' . (string) $item['placeId'] . '%')
            ->exists();
        if ($exists) {
            return;
        }

        ProfileDataSource::create($this->dataSourcePayload($profile, $item, $url));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function dataSourcePayload(Profile $profile, array $item, string $url): array
    {
        return [
            'profile_id' => $profile->id,
            'source_type' => 'google_maps_import',
            'title' => (string) $item['title'],
            'url' => $url,
            'found_fields' => [
                'place_id' => (string) $item['placeId'],
                'cid' => $item['cid'] ?? null,
                'category_name' => $item['categoryName'] ?? null,
                'total_score' => $item['totalScore'] ?? null,
                'reviews_count' => $item['reviewsCount'] ?? null,
                'location' => $item['location'] ?? null,
                'search_string' => $item['searchString'] ?? null,
            ],
            'status' => 'imported',
            'fetched_at' => $this->parseDate($item['scrapedAt'] ?? null) ?? now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{created: int, skipped: int}
     */
    private function importReviews(Profile $profile, array $item, bool $isNew): array
    {
        $created = 0;
        $skipped = 0;
        $now = now()->toDateTimeString();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];
        $seenReviewIds = [];

        foreach ((array) ($item['reviews'] ?? []) as $review) {
            if (! is_array($review)) {
                continue;
            }

            $reviewId = trim((string) ($review['reviewId'] ?? ''));
            $author = trim((string) ($review['name'] ?? '')) ?: 'Користувач Google';
            $rating = max(1, min(5, (int) ($review['stars'] ?? $review['rating'] ?? 0)));
            if ($reviewId === '' || (int) ($review['stars'] ?? $review['rating'] ?? 0) < 1) {
                continue;
            }

            // Дублікати того самого reviewId у межах одного місця (буває у видачі).
            if (isset($seenReviewIds[$reviewId])) {
                continue;
            }
            $seenReviewIds[$reviewId] = true;

            // Переклад українською від Google, якщо оригінал іншою мовою.
            // Оцінка без тексту — валідний відгук: лишаємо порожнє тіло,
            // фронтенд покаже саму зіркову оцінку.
            $body = trim((string) ($review['textTranslated'] ?? '')) ?: trim((string) ($review['text'] ?? ''));
            $hash = hash('sha256', $profile->id . '|google-maps|' . $reviewId);
            $publishedAt = ($this->parseDate($review['publishedAtDate'] ?? null) ?? now())->toDateTimeString();

            // На існуючому профілі дедуп по хешу потрібен (перезапуск/інші джерела);
            // новий профіль порожній — вставляємо без зайвих запитів.
            if (! $isNew && ProfileReview::query()->where('external_review_hash', $hash)->exists()) {
                $skipped++;
                continue;
            }

            $rows[] = [
                'profile_id' => $profile->id,
                'user_id' => null,
                'author_name' => $author,
                'rating' => $rating,
                'body' => $body,
                'status' => 'published',
                'published_at' => $publishedAt,
                'is_anonymous' => 0,
                'verification_type' => 'external_google_import',
                'external_source_type' => 'google',
                'external_source_url' => $this->nullIfBlank((string) ($review['reviewUrl'] ?? '')),
                'external_review_author' => $author,
                'external_review_author_avatar_url' => $this->nullIfBlank((string) ($review['reviewerPhotoUrl'] ?? '')),
                'external_review_date' => $publishedAt,
                'external_review_hash' => $hash,
                'moderation_note' => 'Імпортовано як зовнішній відгук Google (Apify, Google Maps).',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $created++;
        }

        // Один INSERT на профіль замість запиту на кожен відгук — критично
        // для швидкості на віддаленій БД.
        if ($rows !== []) {
            ProfileReview::insert($rows);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Регіон визначаємо з поля state, а якщо воно порожнє чи не збіглося —
     * з міста (Google для Києва часто лишає state порожнім).
     */
    private function resolveRegionId(string $state, string $city = ''): ?int
    {
        // Google: "місто Київ" / "м. Київ" → регіон "м. Київ" у нас.
        $normalizedState = preg_replace('/^\s*(місто|м\.?)\s+/u', 'м. ', trim($state));

        foreach ([trim($state), (string) $normalizedState] as $candidate) {
            $id = $this->regionByName($candidate);
            if ($id !== null) {
                return $id;
            }
        }

        $cityToRegion = [
            'київ' => 'м. Київ',
        ];
        $regionName = $cityToRegion[mb_strtolower(trim($city))] ?? null;

        return $regionName !== null ? $this->regionByName($regionName) : null;
    }

    private function regionByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (! array_key_exists($name, $this->regionIdCache)) {
            $id = Region::query()->where('name', $name)->value('id');
            $this->regionIdCache[$name] = $id !== null ? (int) $id : null;
        }

        return $this->regionIdCache[$name];
    }

    /**
     * Останні 9 цифр телефону (без коду країни) як ключ матчингу.
     */
    private function phoneKey(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }

    /**
     * Нормалізація як в UadvokatImportService: складає схожі укр/рос літери,
     * щоб "Адвокат Нікітінський" ↔ "Никитинский", і лишає тільки
     * ідентифікуючі токени (без загальних слів і назви міста).
     *
     * @return array<int, string>
     */
    private function normalizeNameTokens(string $name, string $cityToExclude = ''): array
    {
        // Місто відкидаємо за префіксом, щоб зняти і прикметникові форми:
        // "Одеса" → "одес" накриває "одеська"/"одесского".
        $cityPrefixes = [];
        foreach ($cityToExclude !== '' ? $this->rawNormalizedTokens($cityToExclude) : [] as $cityToken) {
            if (mb_strlen($cityToken) >= 4) {
                $cityPrefixes[] = mb_substr($cityToken, 0, max(4, mb_strlen($cityToken) - 2));
            }
        }

        return array_values(array_unique(array_filter(
            $this->rawNormalizedTokens($name),
            function (string $token) use ($cityPrefixes): bool {
                // Однолітерні токени — ініціали, вони матчать будь-кого.
                // Цифри лишаємо: "контора №1" і "контора №4" — різні місця.
                if ((mb_strlen($token) < 2 && ! ctype_digit($token)) || in_array($token, self::GENERIC_TOKENS, true)) {
                    return false;
                }

                foreach ([...self::GENERIC_TOKEN_PREFIXES, ...$cityPrefixes] as $prefix) {
                    if (str_starts_with($token, $prefix)) {
                        return false;
                    }
                }

                return true;
            }
        )));
    }

    /**
     * @return array<int, string>
     */
    private function rawNormalizedTokens(string $value): array
    {
        $normalized = mb_strtolower($value);
        $normalized = strtr($normalized, [
            'і' => 'и', 'ї' => 'и', 'й' => 'и', 'ы' => 'и',
            'є' => 'е', 'э' => 'е', 'ё' => 'е',
            'ґ' => 'г',
            'ь' => '', 'ъ' => '', '’' => '', "'" => '',
        ]);
        $normalized = (string) preg_replace('/[^а-яиеa-z0-9]+/ui', ' ', $normalized);

        return preg_split('/\s+/u', trim($normalized)) ?: [];
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

    private function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullIfBlank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

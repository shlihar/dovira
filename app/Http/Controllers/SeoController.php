<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Profile;
use App\Support\BlogPosts;
use App\Support\ProfileSeoIndexPolicy;
use App\Support\RegionCityDirectory;
use App\Support\SitemapCache;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SeoController extends Controller
{
    /** Максимум URL профілів в одному файлі sitemap (ліміт стандарту — 50 000). */
    private const PROFILES_PER_SITEMAP = 45000;

    /**
     * Кореневий sitemap — тепер це sitemap-index: перелік дочірніх файлів
     * (статика+блог, посадкові, рейтинги і пагіновані профілі). Так каталог
     * може рости за межу 50 000 URL без мовчазної обрізки.
     */
    public function sitemap(): Response
    {
        $xml = SitemapCache::remember('index', fn (): string => $this->buildSitemapIndexXml());

        return $this->xmlResponse($xml);
    }

    public function sitemapStatic(): Response
    {
        $xml = SitemapCache::remember('static', fn (): string => $this->renderUrlset($this->staticEntries()));

        return $this->xmlResponse($xml);
    }

    public function sitemapLanding(): Response
    {
        $xml = SitemapCache::remember('landing', fn (): string => $this->renderUrlset($this->landingEntries()));

        return $this->xmlResponse($xml);
    }

    public function sitemapRatings(): RedirectResponse
    {
        // Legacy submissions must lead to the canonical catalog URLs, not /top.
        return redirect()->route('seo.sitemap.landing', [], 301);
    }

    public function sitemapProfiles(string $page): Response
    {
        $page = (int) $page;
        abort_if($page < 1 || $page > SitemapCache::PROFILE_PAGES_MAX, 404);

        $xml = SitemapCache::remember('profiles-' . $page, function () use ($page): string {
            $all = $this->profileSitemapEntries();
            $chunk = array_slice($all, ($page - 1) * self::PROFILES_PER_SITEMAP, self::PROFILES_PER_SITEMAP);

            // Порожня сторінка (крім першої) означає, що такого файлу немає.
            if ($chunk === [] && $page > 1) {
                return '';
            }

            return $this->renderUrlset($chunk);
        });

        abort_if($xml === '', 404);

        return $this->xmlResponse($xml);
    }

    /**
     * Найсвіжіший lastmod серед URL усередині файлу. Раніше в індексі всім
     * трьом файлам проставлявся момент генерації — Google при кожному читанні
     * бачив «усе щойно змінилось» і перезавантажував багатомегабайтний файл
     * профілів, не отримуючи жодного сигналу про те, що саме оновилось.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function entriesLastmod(array $entries, ?string $fallback = null): string
    {
        $latest = null;
        foreach ($entries as $entry) {
            $value = $entry['lastmod'] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            if ($latest === null || strcmp($value, $latest) > 0) {
                $latest = $value;
            }
        }

        $value = $latest ?? $fallback ?? now()->toAtomString();

        // Округлюємо до ДНЯ. Усередині файлів lastmod береться з profiles.updated_at,
        // а його зачіпають навіть лічильники переглядів — без округлення індекс
        // показував би новий час щохвилини, і сигнал знову був би марним.
        return rescue(fn (): string => \Illuminate\Support\Carbon::parse($value)->toDateString(), $value, false);
    }

    private function buildSitemapIndexXml(): string
    {
        $lastmod = now()->toAtomString();

        // sitemap-ratings свідомо НЕ в індексі: сторінки /top канонікалізовані
        // на відповідні /catalog, а sitemap має містити лише канонічні URL —
        // інакше Google отримує суперечливий сигнал. Стара адреса файла
        // перенаправляє на sitemap канонічних посадкових каталогу.
        $sitemaps = [
            ['loc' => route('seo.sitemap.static'), 'lastmod' => $this->entriesLastmod($this->staticEntries(), $lastmod)],
            ['loc' => route('seo.sitemap.landing'), 'lastmod' => $this->entriesLastmod($this->landingEntries(), $lastmod)],
        ];

        $profileEntries = $this->profileSitemapEntries();
        $profileCount = count($profileEntries);
        $pages = (int) min(
            SitemapCache::PROFILE_PAGES_MAX,
            (int) ceil($profileCount / self::PROFILES_PER_SITEMAP)
        );

        for ($page = 1; $page <= $pages; $page++) {
            $sitemaps[] = [
                'loc' => route('seo.sitemap.profiles', ['page' => $page]),
                // No lastmod until content edits have a dedicated timestamp.
            ];
        }

        return $this->renderSitemapIndex($sitemaps);
    }

    /**
     * Статичні сторінки + блог — рідко змінюються, живуть в окремому файлі.
     *
     * @return array<int, array{loc:string, changefreq:string, priority:string, lastmod?:string}>
     */
    private function staticEntries(): array
    {
        $entries = [
            ['loc' => route('home'), 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => route('catalog'), 'changefreq' => 'daily', 'priority' => '0.9'],
            ['loc' => route('pro'), 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => route('lawyer.check'), 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => route('top.index'), 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => route('platform'), 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => route('editorial'), 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => route('contacts'), 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => route('faq'), 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => route('blog'), 'changefreq' => 'weekly', 'priority' => '0.7'],
        ];

        foreach (BlogPosts::all() as $post) {
            $entries[] = [
                'loc' => route('blog.show', ['slug' => $post['slug']]),
                'lastmod' => $post['updated_at'],
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        return $entries;
    }

    /**
     * Індексовані профілі для sitemap — окремо кешований масив, щоб і
     * sitemap-індекс (для підрахунку сторінок), і кожен файл профілів
     * бралися з одного сканування замість повторних обходів БД.
     *
     * @return array<int, array{loc:string, lastmod:?string, changefreq:string, priority:string}>
     */
    private function profileSitemapEntries(): array
    {
        // Окремий ключ кешу для суворого режиму: перемикання прапорця не має
        // 6 годин віддавати список, зібраний за іншими правилами.
        $variant = ProfileSeoIndexPolicy::strictEnabled() ? 'profile-entries-strict' : 'profile-entries';

        return SitemapCache::rememberEntries($variant, function (): array {
            $entries = [];

            // The site must stay up on hosting without a database, so profile
            // URLs degrade to an empty list instead of a 500.
            // Thin-content профілі (без опису й відгуків) не потрапляють у sitemap,
            // щоб не витрачати краул-бюджет на порожні сторінки. Фільтр довжини —
            // у PHP, щоб працювати однаково на MySQL і SQLite.
            // lazyById: описи важкі, десятки тисяч моделей разом не влазять у memory_limit.
            rescue(function () use (&$entries): void {
                $count = 0;
                $cap = self::PROFILES_PER_SITEMAP * SitemapCache::PROFILE_PAGES_MAX;
                // SUBSTR: для перевірки «опис ≥120 символів» повний текст не потрібен —
                // обрізка різко зменшує обсяг передачі з віддаленої БД хостингу.
                $profiles = Profile::query()
                    ->where('status', 'active')
                    ->where('is_published', true)
                    ->where('show_in_catalog', true)
                    // dossier_verdict і ai_review_summary_generated_at — легкі
                    // маркери унікального контенту для ProfileSeoIndexPolicy.
                    ->select(['id', 'slug', 'updated_at', 'reviews_count', 'dossier_verdict', 'ai_review_summary_generated_at',
                        'status', 'is_published', 'show_in_catalog', 'internal_note', 'phone', 'email', 'website', 'address', 'dossier'])
                    ->selectRaw('SUBSTR(description, 1, 4000) as description')
                    ->selectRaw('SUBSTR(short_description, 1, 400) as short_description')
                    ->withExists(['publishedReviews as has_published_reviews'])
                    ->lazyById(2000, 'id');

                foreach ($profiles as $profile) {
                    if (! $this->profileIsIndexableForSitemap($profile)) {
                        continue;
                    }

                    $entries[] = [
                        'loc' => route('profile.show', ['slug' => $profile->slug]),
                        // updated_at also changes on views/clicks. Omitting the
                        // optional date is more accurate than claiming an edit.
                        'changefreq' => 'weekly',
                        'priority' => '0.8',
                    ];

                    if (++$count >= $cap) {
                        break;
                    }
                }
            }, null, false);

            return $entries;
        });
    }

    /**
     * @param  array<int, array{loc:string, changefreq?:string, priority?:string, lastmod?:?string}>  $entries
     */
    private function renderUrlset(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($entries as $entry) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . e($entry['loc']) . "</loc>\n";

            if (!empty($entry['lastmod'])) {
                $xml .= '    <lastmod>' . e($entry['lastmod']) . "</lastmod>\n";
            }

            if (!empty($entry['changefreq'])) {
                $xml .= '    <changefreq>' . $entry['changefreq'] . "</changefreq>\n";
            }

            if (!empty($entry['priority'])) {
                $xml .= '    <priority>' . $entry['priority'] . "</priority>\n";
            }

            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * @param  array<int, array{loc:string, lastmod?:string}>  $sitemaps
     */
    private function renderSitemapIndex(array $sitemaps): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($sitemaps as $sitemap) {
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . e($sitemap['loc']) . "</loc>\n";

            if (!empty($sitemap['lastmod'])) {
                $xml .= '    <lastmod>' . e($sitemap['lastmod']) . "</lastmod>\n";
            }

            $xml .= "  </sitemap>\n";
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    private function xmlResponse(string $xml): Response
    {
        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * SEO-посадкові «категорія» і «категорія × місто»: у sitemap потрапляють
     * лише непорожні комбінації, дзеркально до noindex-логіки самої сторінки.
     * Профілі підкатегорій зараховуються і батьківським категоріям, бо
     * посадкова категорії показує і профілі нащадків.
     *
     * @return array<int, array{loc:string, changefreq:string, priority:string}>
     */
    private function landingEntries(): array
    {
        $categories = rescue(fn (): Collection => Category::query()
            ->where('is_active', true)
            ->where('is_indexable', true)
            ->whereNotNull('slug')
            ->get(['id', 'slug', 'parent_id']), collect(), false);

        if ($categories->isEmpty()) {
            return [];
        }

        $rows = rescue(fn (): Collection => DB::table('profile_category')
            ->join('profiles', 'profiles.id', '=', 'profile_category.profile_id')
            ->where('profiles.status', 'active')
            ->where('profiles.is_published', true)
            ->where('profiles.show_in_catalog', true)
            ->select('profile_category.category_id', 'profile_category.profile_id', 'profiles.city')
            ->get(), collect(), false);

        $parentById = Category::query()->pluck('parent_id', 'id')->all();
        $countedProfiles = [];
        $profileCounts = [];
        $cityCountsByCategory = [];

        foreach ($rows as $row) {
            $citySlug = RegionCityDirectory::citySlug((string) ($row->city ?? ''));

            // Піднімаємось по дереву категорій, щоб профіль «Нотаріуси»
            // враховувався і на посадковій «Юридичні послуги».
            $categoryId = (int) $row->category_id;
            $guard = 0;
            while ($categoryId && $guard < 10) {
                if (! isset($countedProfiles[$categoryId][$row->profile_id])) {
                    $countedProfiles[$categoryId][$row->profile_id] = true;
                    $profileCounts[$categoryId] = ($profileCounts[$categoryId] ?? 0) + 1;
                    if ($citySlug !== null) {
                        $cityCountsByCategory[$categoryId][$citySlug] = ($cityCountsByCategory[$categoryId][$citySlug] ?? 0) + 1;
                    }
                }
                $categoryId = (int) ($parentById[$categoryId] ?? 0);
                $guard++;
            }
        }

        $entries = [];
        $minProfiles = \App\Support\CatalogLandingSeo::MIN_INDEXABLE_PROFILES;

        foreach ($categories as $category) {
            if (($profileCounts[$category->id] ?? 0) < $minProfiles) {
                continue;
            }

            $entries[] = [
                'loc' => route('catalog.landing', ['category' => $category->slug]),
                'changefreq' => 'daily',
                'priority' => '0.8',
            ];

            foreach (($cityCountsByCategory[$category->id] ?? []) as $citySlug => $cityCount) {
                // Тонкі пари (1–2 профілі) noindex на самій сторінці —
                // дзеркально не потрапляють і в sitemap.
                if ($cityCount < $minProfiles) {
                    continue;
                }

                $entries[] = [
                    'loc' => route('catalog.landing.city', ['category' => $category->slug, 'city' => $citySlug]),
                    'changefreq' => 'daily',
                    'priority' => '0.7',
                ];
            }
        }

        // Посадкові рівня послуги («пластика носа київ відгуки») — з того ж
        // словника, що й сторінки: лише indexable трійки (≥ min_profiles).
        foreach (\App\Support\ServiceLandingSeo::sitemapEntries() as $entry) {
            $entries[] = [
                'loc' => $entry['city'] !== null
                    ? route('catalog.landing.service', ['category' => $entry['category'], 'city' => $entry['city'], 'service' => $entry['service']])
                    : route('catalog.landing.city', ['category' => $entry['category'], 'city' => $entry['service']]),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        return $entries;
    }

    /**
     * Programmatic-рейтинги «ТОП {категорія} [місто]»: у sitemap лише комбінації
     * з достатньою кількістю профілів З ВІДГУКАМИ (дзеркально до noindex-порогу
     * TopRatingController::MIN_INDEXABLE). Профілі нащадків зараховуються батькам.
     *
     * @return array<int, array{loc:string, changefreq:string, priority:string}>
     */
    private function topRatingEntries(): array
    {
        $min = \App\Http\Controllers\TopRatingController::MIN_INDEXABLE;

        $categories = rescue(fn (): Collection => Category::query()
            ->where('is_active', true)
            ->where('is_indexable', true)
            ->whereNotNull('slug')
            ->select(['id', 'slug', 'parent_id'])
            ->withCount([
                'children as active_children_count' => fn ($children) => $children
                    ->where('is_active', true)
                    ->where('is_indexable', true),
            ])
            ->get(), collect(), false);

        if ($categories->isEmpty()) {
            return [];
        }

        // Лише профілі з відгуками — рейтинг будується тільки на них.
        $rows = rescue(fn (): Collection => DB::table('profile_category')
            ->join('profiles', 'profiles.id', '=', 'profile_category.profile_id')
            ->where('profiles.status', 'active')
            ->where('profiles.is_published', true)
            ->where('profiles.show_in_catalog', true)
            ->where('profiles.reviews_count', '>=', 1)
            ->where(function ($q): void {
                $q->whereNull('profiles.rating_placement')->orWhere('profiles.rating_placement', '!=', 'hide');
            })
            ->select('profile_category.category_id', 'profiles.id as profile_id', 'profiles.city')
            ->get(), collect(), false);

        $parentById = $categories->pluck('parent_id', 'id')->all();
        $profileIdsByCategory = [];
        $cityProfileIdsByCategory = [];

        foreach ($rows as $row) {
            $profileId = (int) $row->profile_id;
            $citySlug = RegionCityDirectory::citySlug((string) ($row->city ?? ''));
            $categoryId = (int) $row->category_id;
            $guard = 0;
            while ($categoryId && $guard < 10) {
                $profileIdsByCategory[$categoryId][$profileId] = true;
                if ($citySlug !== null) {
                    $cityProfileIdsByCategory[$categoryId][$citySlug][$profileId] = true;
                }
                $categoryId = (int) ($parentById[$categoryId] ?? 0);
                $guard++;
            }
        }

        $entries = [];
        // Рейтинги перераховуються з кожним новим відгуком, а кеш sitemap
        // скидається в обсерверах відгуків/профілів — тож lastmod=now() чесно
        // відображає момент останнього можливого перерахунку.
        $lastmod = now()->toAtomString();

        foreach ($categories as $category) {
            if ($category->parent_id === null && (int) ($category->active_children_count ?? 0) > 0) {
                continue;
            }

            if (count($profileIdsByCategory[$category->id] ?? []) < $min) {
                continue;
            }

            $entries[] = [
                'loc' => route('top.rating', ['category' => $category->slug]),
                'lastmod' => $lastmod,
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];

            foreach (($cityProfileIdsByCategory[$category->id] ?? []) as $citySlug => $profileIds) {
                if (count($profileIds) < $min) {
                    continue;
                }
                $entries[] = [
                    'loc' => route('top.rating.city', ['category' => $category->slug, 'city' => $citySlug]),
                    'lastmod' => $lastmod,
                    'changefreq' => 'weekly',
                    'priority' => '0.6',
                ];
            }
        }

        return $entries;
    }

    private function profileIsIndexableForSitemap(Profile $profile): bool
    {
        if (ProfileSeoIndexPolicy::indexesAllPublicProfiles()) {
            return ProfileSeoIndexPolicy::isPublic($profile);
        }

        if (ProfileSeoIndexPolicy::usesReviewsDossierAi()) {
            return ProfileSeoIndexPolicy::passesReviewsDossierAi($profile);
        }

        if (ProfileSeoIndexPolicy::usesContentSignals()) {
            return ProfileSeoIndexPolicy::passesContentSignals($profile);
        }

        // Суворий режим: у sitemap лише сторінки з унікальним контентом
        // (критерії — ProfileSeoIndexPolicy, дзеркально до noindex сторінки).
        if (ProfileSeoIndexPolicy::strictEnabled()) {
            return ProfileSeoIndexPolicy::passes($profile);
        }

        if ((int) $profile->reviews_count > 0) {
            return true;
        }

        $description = trim(strip_tags((string) ($profile->description ?? '')));
        $shortDescription = trim(strip_tags((string) ($profile->short_description ?? '')));

        return mb_strlen($description) >= 120 || mb_strlen($shortDescription) >= 60;
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /dashboard',
            'Disallow: /profile$',
            'Disallow: /profile/',
            'Disallow: /pro/account',
            'Disallow: /app/',
            'Disallow: /search/suggest',
            'Disallow: /events/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            '',
            '# AI crawlers are welcome to index public content',
            'User-agent: GPTBot',
            'Allow: /',
            '',
            'User-agent: OAI-SearchBot',
            'Allow: /',
            '',
            'User-agent: ClaudeBot',
            'Allow: /',
            '',
            'User-agent: Claude-Web',
            'Allow: /',
            '',
            'User-agent: PerplexityBot',
            'Allow: /',
            '',
            'User-agent: Perplexity-User',
            'Allow: /',
            '',
            'User-agent: meta-externalagent',
            'Allow: /',
            '',
            'User-agent: Applebot',
            'Allow: /',
            '',
            'User-agent: Google-Extended',
            'Allow: /',
            '',
            'User-agent: CCBot',
            'Allow: /',
            '',
            'Sitemap: ' . url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function llms(): Response
    {
        // Те саме визначення «видимих у каталозі» профілів, що й на home/
        // platform/catalog, щоб число збігалося скрізь.
        $stats = rescue(fn (): array => [
            'profiles' => (int) Profile::query()
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true)
                ->count(),
        ], ['profiles' => 0], false);

        $profilesLine = $stats['profiles'] > 0
            ? "Наразі в каталозі понад {$stats['profiles']} профілів."
            : '';

        $proPrice = \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT);
        $futureProPrice = \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_FUTURE);

        $content = <<<TXT
# DOVIRA

> DOVIRA — українська платформа чесних відгуків про компанії, магазини, сервіси та спеціалістів. Каталог перевірених профілів із рейтингами, реальними відгуками клієнтів та офіційними відповідями бізнесу. {$profilesLine}

Мова сайту: українська. Валюта: гривня (UAH). Регіон: Україна.

## Основні сторінки

- [Головна]({$this->absoluteUrl('/')}): пошук компаній і спеціалістів, останні відгуки, статистика платформи
- [Каталог]({$this->absoluteUrl('/catalog')}): повний каталог компаній і спеціалістів із фільтрами за категоріями, містами, рейтингом
- [Про платформу]({$this->absoluteUrl('/platform')}): як працює модерація відгуків, перевірка профілів і право на публічну відповідь
- [DOVIRA PRO]({$this->absoluteUrl('/pro')}): платні можливості для бізнесу — офіційні відповіді на відгуки, повне керування профілем, контакти клієнтів із заявок, пріоритет у каталозі (тарифи: START — безкоштовно, PRO — {$proPrice} за 6 місяців за стартовою пропозицією, надалі — {$futureProPrice})
- [FAQ]({$this->absoluteUrl('/faq')}): часті питання про відгуки, рейтинги, модерацію та PRO
- [Блог]({$this->absoluteUrl('/blog')}): статті про перевірку компаній, роботу з відгуками та онлайн-репутацію бізнесу

## Профілі компаній

Кожен профіль доступний за адресою {$this->absoluteUrl('/profiles/{slug}')} і містить: назву, категорію, місто, рейтинг (1–5), кількість відгуків, відгуки клієнтів з відповідями бізнесу, послуги, контакти, фото та FAQ. Структуровані дані розмічені через Schema.org (LocalBusiness, AggregateRating, Review).

## Ключові факти

- Відгуки проходять модерацію перед публікацією
- Бізнес може офіційно відповідати на відгуки
- Профілі зі статусом «Перевірений акаунт» підтвердили право власності
- Повний список сторінок: {$this->absoluteUrl('/sitemap.xml')}
- Тарифи для агентів (машиночитано, markdown): {$this->absoluteUrl('/pricing.md')}

## Для бізнесу

- Профіль компанії або спеціаліста може вже бути публічним на DOVIRA, доступним у Google/Bing та відкритим для відгуків клієнтів: {$this->absoluteUrl('/pro')}
- Безкоштовна перевірка прав потрібна перед підпискою, щоб керування отримав реальний власник або представник профілю
- Телефон і сайт компанії видимі на сторінці безкоштовно; аналітика переглядів відкривається одразу після безкоштовного підтвердження прав
- Тариф PRO відкриває офіційні відповіді на відгуки, повне керування профілем (послуги, ціни, фото, досьє), контакти клієнтів із заявок і пріоритетні позиції в каталозі DOVIRA
TXT;

        return response($content . "\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Машиночитаний прайс для AI-агентів, які оцінюють «скільки коштує PRO на
     * DOVIRA» без рендера JS-сторінки. Джерело правди — App\Support\ProPricing.
     * Уся вітрина і списання — у гривні (UAH).
     */
    public function pricing(): Response
    {
        $displayCurrent = \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT);
        $displayFuture = \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_FUTURE);
        $periodLabel = \App\Support\ProPricing::PERIOD_LABEL;
        $periodMonths = \App\Support\ProPricing::PERIOD_MONTHS;
        $proUrl = $this->absoluteUrl('/pro');
        $llmsUrl = $this->absoluteUrl('/llms.txt');

        $content = <<<MD
        # Тарифи — DOVIRA

        > DOVIRA — українська платформа чесних відгуків про компанії, магазини, сервіси та спеціалістів. Публічний профіль, відгуки й підтвердження прав на профіль — безкоштовні. PRO — платні інструменти для власників бізнесу.

        Валюта: гривня (UAH). Регіон: Україна. Мова: українська.

        ## START
        - Ціна: безкоштовно
        - Кому: користувачі та власники, які підтверджують права на профіль
        - Можливості: публічний профіль у каталозі, відгуки клієнтів, рейтинг, телефон і сайт на сторінці, форма заявки від клієнтів, безкоштовне підтвердження прав, аналітика переглядів після підтвердження, віджет рейтингу для сайту

        ## PRO
        - Ціна: {$displayCurrent} {$periodLabel} (стартова пропозиція)
        - Ціна після завершення стартової пропозиції: {$displayFuture} {$periodLabel}
        - Період: {$periodMonths} місяців, підписка з автопродовженням (списання раз на {$periodMonths} місяців; вимкнути можна будь-коли в кабінеті)
        - Кому: власники компаній і спеціалісти, які керують профілем
        - Можливості: офіційні відповіді на відгуки від імені компанії, повне керування профілем (послуги, ціни, фото, FAQ, досьє), контакти клієнтів із заявок, email і соцмережі на сторінці, без блоку конкурентів на сторінці, пріоритет у каталозі

        ## Як підключити
        - Підтвердження прав на профіль — безкоштовне: {$proUrl}
        - Оплата PRO — у кабінеті власника після підтвердження прав
        - Повний опис можливостей і платформи: {$llmsUrl}
        MD;

        // <<<MD-героку має відступ — прибираємо його, щоб markdown був валідний.
        $content = preg_replace('/^        /m', '', $content);

        return response($content . "\n", 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim(url('/'), '/') . $path;
    }
}

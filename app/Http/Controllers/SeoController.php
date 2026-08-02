<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Profile;
use App\Support\BlogPosts;
use App\Support\RegionCityDirectory;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $entries = [
            ['loc' => route('home'), 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => route('catalog'), 'changefreq' => 'daily', 'priority' => '0.9'],
            ['loc' => route('pro'), 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => route('platform'), 'changefreq' => 'monthly', 'priority' => '0.6'],
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

        foreach ($this->landingEntries() as $entry) {
            $entries[] = $entry;
        }

        // The site must stay up on hosting without a database, so profile
        // URLs degrade to an empty list instead of a 500.
        // Thin-content профілі (без опису й відгуків) не потрапляють у sitemap,
        // щоб не витрачати краул-бюджет на порожні сторінки. Фільтр довжини —
        // у PHP, щоб працювати однаково на MySQL і SQLite.
        $profiles = rescue(fn (): Collection => Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->orderByDesc('updated_at')
            ->limit(45000)
            ->get(['slug', 'updated_at', 'reviews_count', 'description', 'short_description']), collect(), false);

        foreach ($profiles as $profile) {
            if (! $this->profileIsIndexableForSitemap($profile)) {
                continue;
            }

            $entries[] = [
                'loc' => route('profile.show', ['slug' => $profile->slug]),
                'lastmod' => optional($profile->updated_at)?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($entries as $entry) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . e($entry['loc']) . "</loc>\n";

            if (!empty($entry['lastmod'])) {
                $xml .= '    <lastmod>' . e($entry['lastmod']) . "</lastmod>\n";
            }

            $xml .= '    <changefreq>' . $entry['changefreq'] . "</changefreq>\n";
            $xml .= '    <priority>' . $entry['priority'] . "</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

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
            ->select('profile_category.category_id', 'profiles.city')
            ->get(), collect(), false);

        $parentById = $categories->pluck('parent_id', 'id')->all();
        $profileCounts = [];
        $citySlugsByCategory = [];

        foreach ($rows as $row) {
            $citySlug = RegionCityDirectory::citySlug((string) ($row->city ?? ''));

            // Піднімаємось по дереву категорій, щоб профіль «Нотаріуси»
            // враховувався і на посадковій «Юридичні послуги».
            $categoryId = (int) $row->category_id;
            $guard = 0;
            while ($categoryId && $guard < 10) {
                $profileCounts[$categoryId] = ($profileCounts[$categoryId] ?? 0) + 1;
                if ($citySlug !== null) {
                    $citySlugsByCategory[$categoryId][$citySlug] = true;
                }
                $categoryId = (int) ($parentById[$categoryId] ?? 0);
                $guard++;
            }
        }

        $entries = [];

        foreach ($categories as $category) {
            if (($profileCounts[$category->id] ?? 0) < 1) {
                continue;
            }

            $entries[] = [
                'loc' => route('catalog.landing', ['category' => $category->slug]),
                'changefreq' => 'daily',
                'priority' => '0.8',
            ];

            foreach (array_keys($citySlugsByCategory[$category->id] ?? []) as $citySlug) {
                $entries[] = [
                    'loc' => route('catalog.landing.city', ['category' => $category->slug, 'city' => $citySlug]),
                    'changefreq' => 'daily',
                    'priority' => '0.7',
                ];
            }
        }

        return $entries;
    }

    private function profileIsIndexableForSitemap(Profile $profile): bool
    {
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
        $stats = rescue(fn (): array => [
            'profiles' => (int) Profile::query()
                ->where('status', 'active')
                ->where('is_published', true)
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
- [DOVIRA PRO]({$this->absoluteUrl('/pro')}): платні можливості для бізнесу — керування профілем, контакти на сторінці, контакти клієнтів із заявок, офіційні відповіді на відгуки, аналітика, пріоритет у каталозі (тарифи: START — безкоштовно, PRO — {$proPrice} за 6 місяців за стартовою пропозицією з фіксацією ціни назавжди, надалі — {$futureProPrice})
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

- Компанія або спеціаліст може безкоштовно створити профіль чи підтвердити права на існуючий: {$this->absoluteUrl('/pro')}
- Публічні профілі індексуються пошуковими системами (Google, Bing) та доступні AI-асистентам — заповнений профіль з відгуками легше знайти за назвою компанії чи послугою
- Підтвердження прав безкоштовне; тариф PRO відкриває керування профілем, контакти на публічній сторінці, контакти клієнтів із заявок, офіційні відповіді на відгуки, аналітику звернень і пріоритетні позиції в каталозі DOVIRA
TXT;

        return response($content . "\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Машиночитаний прайс для AI-агентів, які оцінюють «скільки коштує PRO на
     * DOVIRA» без рендера JS-сторінки. Джерело правди — App\Support\ProPricing.
     * Вітрина в доларах (маркетингово), реальне списання — у гривні.
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

        Валюта вітрини: USD. Реальне списання — у гривні (UAH). Регіон: Україна. Мова: українська.

        ## START
        - Ціна: безкоштовно
        - Кому: користувачі та власники, які підтверджують права на профіль
        - Можливості: публічний профіль у каталозі, відгуки клієнтів, рейтинг, безкоштовне підтвердження прав на профіль, базові сигнали довіри

        ## PRO
        - Ціна: {$displayCurrent} {$periodLabel} (стартова пропозиція, ціна фіксується за передплатником назавжди при продовженні)
        - Ціна після завершення акції: {$displayFuture} {$periodLabel}
        - Період: {$periodMonths} місяців, разовий платіж без автосписання
        - Кому: власники компаній і спеціалісти, які керують профілем
        - Можливості: керування профілем, контакти на публічній сторінці, контакти клієнтів із заявок, офіційні відповіді на відгуки, аналітика переглядів і звернень, пріоритет у каталозі, віджет рейтингу для сайту

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

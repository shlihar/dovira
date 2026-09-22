<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Profile;
use App\Support\RegionCityDirectory;
use App\Support\ServiceLandingSeo;
use Illuminate\Contracts\View\View;

/**
 * Посадкова «{послуга} {у місті} — відгуки» (/catalog/{категорія}/{місто}/{послуга}).
 * Словник послуг і правила відбору — config/service_landings.php та
 * ServiceLandingSeo. Картки профілів — ті самі, що в каталозі.
 */
class ServiceLandingController extends Controller
{
    public function __construct(private readonly CatalogPageController $catalog)
    {
    }

    /** $citySlug = null → сторінка послуги по всій Україні (/catalog/{category}/{service}). */
    public function show(string $categorySlug, ?string $citySlug, string $serviceSlug): View
    {
        $category = Category::query()->where('is_active', true)->where('slug', $categorySlug)->first();
        abort_if($category === null, 404);

        $cityName = null;
        if ($citySlug !== null) {
            $cityName = RegionCityDirectory::cityFromSlug($citySlug);
            abort_if($cityName === null, 404);
        }

        $service = ServiceLandingSeo::service($categorySlug, $serviceSlug);
        abort_if($service === null, 404);

        $seo = ServiceLandingSeo::build($category, $cityName, $service);
        $indexable = $seo['profiles_count'] >= ServiceLandingSeo::minProfiles($service);

        // Профілі зі згадками послуги: ярус за показуваністю оцінки (як у
        // каталозі), потім за кількістю згадок, потім популярність.
        $profiles = collect();
        $cardsLimit = max(1, (int) config('service_landings.cards_limit', 48));
        if ($seo['profile_ids'] !== []) {
            $matches = $seo['matches_by_profile'];
            $visible = fn ($q) => $q
                ->whereIn('id', $seo['profile_ids'])
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true);

            // Спершу легкий список (id + лічильники) — сортуємо в PHP і беремо
            // лише $cardsLimit, а повні профілі з підвантаженнями тягнемо для них.
            $topIds = $visible(Profile::query())
                ->get(['id', 'reviews_count', 'popularity_score'])
                ->sortBy([
                    fn (Profile $a, Profile $b) => ($b->reviews_count >= 20 ? 1 : 0) <=> ($a->reviews_count >= 20 ? 1 : 0),
                    fn (Profile $a, Profile $b) => ($matches[$b->id] ?? 0) <=> ($matches[$a->id] ?? 0),
                    fn (Profile $a, Profile $b) => (float) $b->popularity_score <=> (float) $a->popularity_score,
                ])
                ->take($cardsLimit)
                ->pluck('id')
                ->all();

            $order = array_flip($topIds);
            $profiles = $visible(Profile::query())
                ->whereIn('id', $topIds)
                ->with(['services', 'categories' => fn ($q) => $q->select(['categories.id', 'categories.name'])->orderByDesc('profile_category.is_primary')])
                ->withCount('officialReplies')
                ->withProSubscriptionFlags()
                ->get()
                ->sortBy(fn (Profile $p) => $order[$p->id] ?? PHP_INT_MAX)
                ->values()
                ->map(fn (Profile $p) => $this->catalog->mapCatalogProfileCard($p) + ['service_matches' => (int) ($matches[$p->id] ?? 0)]);
        }

        return view('static.service-landing', [
            'category' => $category,
            'cityName' => $cityName,
            'citySlug' => $citySlug,
            'service' => $service,
            'seo' => $seo,
            'indexable' => $indexable,
            'profiles' => $profiles,
            'cardsLimit' => $cardsLimit,
            'canonical' => $citySlug !== null
                ? route('catalog.landing.service', ['category' => $categorySlug, 'city' => $citySlug, 'service' => $serviceSlug])
                : route('catalog.landing.city', ['category' => $categorySlug, 'city' => $serviceSlug]),
        ]);
    }
}

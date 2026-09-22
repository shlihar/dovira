<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Profile;
use App\Support\SitemapCache;
use App\Support\TopRatingIndexCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TopRatingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TopRatingIndexCache::forget();
        SitemapCache::forget();
    }

    public function test_top_index_lists_indexable_subcategory_ratings(): void
    {
        $parent = Category::query()->create([
            'name' => 'Юридичні послуги',
            'slug' => 'iurydychni-posluhy',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        $category = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Адвокати',
            'slug' => 'advokaty',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->createTopProfile($category, "Адвокат {$i}", "advokat-{$i}", reviews: 5 + $i, rating: 4.5);
        }

        $this
            ->get(route('top.index'))
            ->assertOk()
            ->assertSee('Рейтинги компаній і спеціалістів України')
            ->assertSee('Юридичні послуги')
            ->assertSee('Топ адвокатів')
            ->assertSee('fa-scale-balanced', false)
            ->assertSee(route('top.rating', ['category' => 'advokaty']), false)
            ->assertSee(route('top.rating.city', ['category' => 'advokaty', 'city' => 'kyiv']), false);
    }

    public function test_top_index_uses_cached_payload(): void
    {
        Cache::put(TopRatingIndexCache::KEY, [
            'groups' => [
                [
                    'name' => 'Кешована група',
                    'items' => [
                        [
                            'name' => 'Кешовані профілі',
                            'title' => 'Топ кешованих профілів',
                            'icon_class' => 'fa-solid fa-star',
                            'url' => url('/top/cached'),
                            'profiles_count' => 7,
                            'reviews_count' => 21,
                            'city_links' => [],
                        ],
                    ],
                ],
            ],
            'totalRatings' => 1,
            'totalProfiles' => 7,
            'updatedAt' => now(),
        ], now()->addHour());

        $this
            ->get(route('top.index'))
            ->assertOk()
            ->assertSee('Кешована група')
            ->assertSee('Топ кешованих профілів');
    }

    public function test_top_rating_respects_manual_profile_placement(): void
    {
        $category = Category::query()->create([
            'name' => 'Адвокати',
            'slug' => 'advokaty',
            'is_active' => true,
            'show_in_catalog' => true,
        ]);

        $forced = $this->createTopProfile($category, 'Ручний адвокат', 'manual-top-lawyer', reviews: 1, rating: 3.2);
        $forced->update([
            'rating_placement' => 'force',
            'rating_priority' => 30,
        ]);

        $auto = $this->createTopProfile($category, 'Автоматичний адвокат', 'auto-top-lawyer', reviews: 80, rating: 4.9);

        $hidden = $this->createTopProfile($category, 'Схований адвокат', 'hidden-top-lawyer', reviews: 200, rating: 5.0);
        $hidden->update(['rating_placement' => 'hide']);

        $content = $this
            ->get(route('top.rating', ['category' => 'advokaty']))
            ->assertOk()
            ->assertSee('Топ адвокатів України')
            ->assertSee('Ручний адвокат')
            ->assertSee('Автоматичний адвокат')
            ->assertDontSee('Схований адвокат')
            ->getContent();

        $this->assertLessThan(
            strpos($content, 'Автоматичний адвокат'),
            strpos($content, 'Ручний адвокат')
        );
    }

    public function test_top_rating_uses_only_primary_category(): void
    {
        $parent = Category::query()->create([
            'name' => 'Юридичні послуги',
            'slug' => 'iurydychni-posluhy',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        $lawyers = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Адвокати',
            'slug' => 'advokaty',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        $notaries = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Нотаріуси',
            'slug' => 'notariusy',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->createTopProfile($notaries, "Нотаріус {$i}", "notarius-{$i}", reviews: 5 + $i, rating: 4.8);
        }

        $lawyer = $this->createTopProfile($lawyers, 'Адвокат у другорядній рубриці', 'advokat-secondary-notarius', reviews: 200, rating: 5.0);
        $lawyer->categories()->attach($notaries->id, ['is_primary' => false]);

        $this
            ->get(route('top.rating', ['category' => 'notariusy']))
            ->assertOk()
            ->assertSee('Нотаріус 1')
            ->assertDontSee('Адвокат у другорядній рубриці');
    }

    public function test_sitemap_contains_top_index(): void
    {
        $this
            ->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee(route('top.index'), false);
    }

    public function test_sitemap_top_entries_count_unique_visible_profiles(): void
    {
        $parent = Category::query()->create([
            'name' => 'Юридичні послуги',
            'slug' => 'iurydychni-posluhy',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        $category = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Адвокати',
            'slug' => 'advokaty',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        $first = $this->createTopProfile($category, 'Перший адвокат', 'first-lawyer', reviews: 5, rating: 4.5);
        $first->categories()->attach($parent->id, ['is_primary' => false]);
        $this->createTopProfile($category, 'Другий адвокат', 'second-lawyer', reviews: 4, rating: 4.4);
        $hidden = $this->createTopProfile($category, 'Прихований адвокат', 'hidden-lawyer', reviews: 20, rating: 5.0);
        $hidden->update(['rating_placement' => 'hide']);

        $this
            ->get(route('seo.sitemap'))
            ->assertOk()
            ->assertDontSee(route('top.rating', ['category' => 'iurydychni-posluhy']), false)
            ->assertDontSee(route('top.rating', ['category' => 'advokaty']), false)
            ->assertDontSee(route('top.rating.city', ['category' => 'advokaty', 'city' => 'kyiv']), false);
    }

    public function test_sitemap_top_entries_skip_broad_parent_categories(): void
    {
        $parent = Category::query()->create([
            'name' => 'Юридичні послуги',
            'slug' => 'iurydychni-posluhy',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        $category = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Адвокати',
            'slug' => 'advokaty',
            'is_active' => true,
            'is_indexable' => true,
            'show_in_catalog' => true,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->createTopProfile($category, "Адвокат {$i}", "lawyer-{$i}", reviews: 5, rating: 4.5);
        }

        $this
            ->get(route('seo.sitemap'))
            ->assertOk()
            ->assertDontSee(route('top.rating', ['category' => 'iurydychni-posluhy']), false)
            ->assertSee(route('top.rating', ['category' => 'advokaty']), false)
            ->assertSee(route('top.rating.city', ['category' => 'advokaty', 'city' => 'kyiv']), false);
    }

    public function test_sitemap_uses_cached_xml(): void
    {
        SitemapCache::remember(url('/'), fn (): string => '<?xml version="1.0" encoding="UTF-8"?><cached-sitemap />');

        $this
            ->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee('<cached-sitemap />', false);
    }

    private function createTopProfile(Category $category, string $name, string $slug, int $reviews, float $rating): Profile
    {
        $profile = Profile::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'city' => 'Київ',
            'rating_avg' => $rating,
            'reviews_count' => $reviews,
        ]);

        $profile->categories()->attach($category->id, ['is_primary' => true]);

        return $profile;
    }
}

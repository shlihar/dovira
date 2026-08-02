<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileReview;
use App\Services\HomePageDataService;
use App\Support\MediaUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomePageDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_best_profiles_show_most_popular_lawyers_and_ignore_hidden_or_other_categories(): void
    {
        Cache::flush();

        $lawyers = \App\Models\Category::query()->create([
            'name' => 'Адвокати',
            'slug' => 'advokaty-test',
            'is_active' => true,
        ]);

        $lessPopularLawyer = Profile::query()->create([
            'name' => 'One Review Perfect',
            'slug' => 'one-review-perfect',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'rating_avg' => 5.0,
            'reviews_count' => 1,
            'views_count' => 10,
            'unique_views_count' => 10,
            'website_clicks_count' => 2,
            'contact_clicks_count' => 1,
            'popularity_score' => 25,
        ]);
        $lessPopularLawyer->categories()->attach($lawyers->id, ['is_primary' => true]);

        $mostPopularLawyer = Profile::query()->create([
            'name' => 'Trusted Volume',
            'slug' => 'trusted-volume',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'rating_avg' => 4.9,
            'reviews_count' => 40,
            'views_count' => 100,
            'unique_views_count' => 90,
            'website_clicks_count' => 15,
            'contact_clicks_count' => 10,
            'popularity_score' => 320,
        ]);
        $mostPopularLawyer->categories()->attach($lawyers->id, ['is_primary' => true]);

        $hiddenLawyer = Profile::query()->create([
            'name' => 'Hidden Strong Profile',
            'slug' => 'hidden-strong-profile',
            'status' => 'active',
            'is_published' => false,
            'show_in_catalog' => true,
            'rating_avg' => 5.0,
            'reviews_count' => 500,
            'views_count' => 1000,
            'unique_views_count' => 900,
            'website_clicks_count' => 150,
            'contact_clicks_count' => 100,
            'popularity_score' => 5000,
        ]);
        $hiddenLawyer->categories()->attach($lawyers->id, ['is_primary' => true]);

        // More popular than every lawyer above, but not a lawyer — must be excluded.
        Profile::query()->create([
            'name' => 'Popular Non-Lawyer',
            'slug' => 'popular-non-lawyer',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'rating_avg' => 5.0,
            'reviews_count' => 999,
            'views_count' => 9999,
            'unique_views_count' => 9999,
            'website_clicks_count' => 999,
            'contact_clicks_count' => 999,
            'popularity_score' => 99999,
        ]);

        $payload = app(HomePageDataService::class)->getPayload();
        $slugs = array_column($payload['bestProfiles'], 'slug');

        $this->assertSame('trusted-volume', $slugs[0] ?? null, 'Найпопулярніший адвокат має бути першим.');
        $this->assertNotContains('hidden-strong-profile', $slugs, 'Неопубліковані профілі не мають зʼявлятися.');
        $this->assertNotContains('popular-non-lawyer', $slugs, 'Профілі поза категорією «Адвокати» мають бути виключені.');
    }

    public function test_recommended_profiles_require_minimum_review_volume(): void
    {
        Cache::flush();

        Profile::query()->create([
            'name' => 'Thin Recommended',
            'slug' => 'thin-recommended',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'dovira_recommendation_status' => 'recommend',
            'rating_avg' => 5.0,
            'reviews_count' => 1,
            'views_count' => 20,
            'unique_views_count' => 18,
            'website_clicks_count' => 2,
            'contact_clicks_count' => 1,
            'popularity_score' => 40,
        ]);

        Profile::query()->create([
            'name' => 'Stable Recommended',
            'slug' => 'stable-recommended',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'dovira_recommendation_status' => 'recommend',
            'rating_avg' => 4.8,
            'reviews_count' => 6,
            'views_count' => 50,
            'unique_views_count' => 40,
            'website_clicks_count' => 10,
            'contact_clicks_count' => 5,
            'popularity_score' => 120,
        ]);

        $payload = app(HomePageDataService::class)->getPayload();
        $slugs = array_column($payload['recommendedProfiles'], 'slug');

        $this->assertSame(['stable-recommended'], $slugs);
    }

    public function test_latest_reviews_include_only_one_review_per_profile(): void
    {
        Cache::flush();

        $firstProfile = Profile::query()->create([
            'name' => 'Alpha Profile',
            'slug' => 'alpha-profile',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
        ]);

        $secondProfile = Profile::query()->create([
            'name' => 'Beta Profile',
            'slug' => 'beta-profile',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
        ]);

        ProfileReview::query()->create([
            'profile_id' => $firstProfile->id,
            'author_name' => 'First Old',
            'rating' => 5,
            'body' => 'Old review',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-01 10:00:00'),
        ]);

        ProfileReview::query()->create([
            'profile_id' => $firstProfile->id,
            'author_name' => 'First New',
            'rating' => 4,
            'body' => 'New review',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-03 10:00:00'),
        ]);

        ProfileReview::query()->create([
            'profile_id' => $secondProfile->id,
            'author_name' => 'Second New',
            'rating' => 5,
            'body' => 'Second review',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-02 10:00:00'),
        ]);

        $payload = app(HomePageDataService::class)->getPayload();
        $reviews = $payload['latestReviews'];

        $this->assertCount(2, $reviews);
        $this->assertSame(['alpha-profile', 'beta-profile'], array_column($reviews, 'profile_slug'));
        $this->assertSame(['New review', 'Second review'], array_column($reviews, 'text'));
    }

    public function test_latest_reviews_hide_placeholder_avatar_urls(): void
    {
        Cache::flush();
        Storage::disk('public')->put('catalog-media/profiles/demo-logo.png', 'demo');

        $profile = Profile::query()->create([
            'name' => 'Gamma Profile',
            'slug' => 'gamma-profile',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'logo_url' => 'catalog-media/profiles/demo-logo.png',
        ]);

        ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Gamma User',
            'rating' => 5,
            'body' => 'Avatar fallback review',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-03 11:00:00'),
            'external_review_author_avatar_url' => 'img/empty.png?5cae68064',
        ]);

        $payload = app(HomePageDataService::class)->getPayload();
        $review = $payload['latestReviews'][0] ?? null;

        $this->assertNotNull($review);
        $this->assertIsString($review['avatar_url']);
        $this->assertStringStartsWith('/media/catalog-media/avatar-fallbacks/', $review['avatar_url']);
        $this->assertSame(MediaUrl::thumbUrl('catalog-media/profiles/demo-logo.png', 160), $review['profile_logo_url']);
    }

    public function test_latest_reviews_do_not_inject_placeholder_profile_site(): void
    {
        Cache::flush();

        $blankWebsiteProfile = Profile::query()->create([
            'name' => 'Blank Website Profile',
            'slug' => 'blank-website-profile',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'website' => null,
        ]);

        $placeholderWebsiteProfile = Profile::query()->create([
            'name' => 'Placeholder Website Profile',
            'slug' => 'placeholder-website-profile',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'website' => 'https://example.com',
        ]);

        ProfileReview::query()->create([
            'profile_id' => $blankWebsiteProfile->id,
            'author_name' => 'Blank User',
            'rating' => 5,
            'body' => 'Blank website review',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-03 09:00:00'),
        ]);

        ProfileReview::query()->create([
            'profile_id' => $placeholderWebsiteProfile->id,
            'author_name' => 'Placeholder User',
            'rating' => 5,
            'body' => 'Placeholder website review',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-03 10:00:00'),
        ]);

        $payload = app(HomePageDataService::class)->getPayload();
        $reviewsBySlug = collect($payload['latestReviews'])->keyBy('profile_slug');

        $this->assertNull($reviewsBySlug['blank-website-profile']['profile_site'] ?? null);
        $this->assertNull($reviewsBySlug['placeholder-website-profile']['profile_site'] ?? null);
    }

    public function test_leaderboard_includes_only_profiles_with_real_logos(): void
    {
        Cache::flush();
        Storage::disk('public')->put('catalog-media/profiles/leader-a.png', 'demo');
        Storage::disk('public')->put('catalog-media/profiles/leader-b.png', 'demo');
        Storage::disk('public')->put('catalog-media/profiles/leader-c.png', 'demo');

        $category = $this->createLeaderboardCategory();

        $this->createLeaderboardProfile($category, 'real-logo-a', 'Київ', 'catalog-media/profiles/leader-a.png', rating: 4.9);
        $this->createLeaderboardProfile($category, 'real-logo-b', 'Київ', 'catalog-media/profiles/leader-b.png', rating: 4.8);
        $this->createLeaderboardProfile($category, 'real-logo-c', 'Київ', 'catalog-media/profiles/leader-c.png', rating: 4.7);
        $this->createLeaderboardProfile($category, 'generated-logo', 'Київ', '/media/catalog-media/profile-fallbacks/07/generated.svg', rating: 5.0);
        $this->createLeaderboardProfile($category, 'missing-logo', 'Київ', null, rating: 5.0);

        $payload = app(HomePageDataService::class)->getPayload();
        $rows = $payload['leaderboard'][0]['rows'] ?? [];
        $slugs = array_column($rows, 'slug');

        $this->assertEqualsCanonicalizing(['real-logo-a', 'real-logo-b', 'real-logo-c'], $slugs);
        $this->assertNotContains('generated-logo', $slugs);
        $this->assertNotContains('missing-logo', $slugs);

        foreach ($rows as $row) {
            $this->assertNotEmpty($row['logo_url']);
            $this->assertStringNotContainsString('profile-fallbacks', $row['logo_url']);
        }
    }

    public function test_city_sections_build_leaderboard_for_selected_city_only(): void
    {
        Cache::flush();
        Storage::disk('public')->put('catalog-media/profiles/city-logo.png', 'demo');

        $category = $this->createLeaderboardCategory();

        $this->createLeaderboardProfile($category, 'kyiv-a', 'Київ', 'catalog-media/profiles/city-logo.png', rating: 4.8);
        $this->createLeaderboardProfile($category, 'kyiv-b', 'Київ', 'catalog-media/profiles/city-logo.png', rating: 4.7);
        $this->createLeaderboardProfile($category, 'kyiv-c', 'Київ', 'catalog-media/profiles/city-logo.png', rating: 4.6);
        $this->createLeaderboardProfile($category, 'lviv-a', 'Львів', 'catalog-media/profiles/city-logo.png', rating: 5.0);
        $this->createLeaderboardProfile($category, 'lviv-b', 'Львів', 'catalog-media/profiles/city-logo.png', rating: 4.9);
        $this->createLeaderboardProfile($category, 'lviv-c', 'Львів', 'catalog-media/profiles/city-logo.png', rating: 4.8);

        $sections = app(HomePageDataService::class)->citySections('Київ')['sections'];
        $rows = $sections['leaderboard'][0]['rows'] ?? [];

        $this->assertEqualsCanonicalizing(['kyiv-a', 'kyiv-b', 'kyiv-c'], array_column($rows, 'slug'));
        $this->assertSame(['Київ', 'Київ', 'Київ'], array_column($rows, 'city'));
    }

    private function createLeaderboardCategory(): \App\Models\Category
    {
        $parent = \App\Models\Category::query()->create([
            'name' => 'Послуги',
            'slug' => 'services-root',
            'is_active' => true,
            'show_in_catalog' => true,
        ]);

        return \App\Models\Category::query()->create([
            'name' => 'Тестовий рейтинг',
            'slug' => 'test-leaderboard',
            'parent_id' => $parent->id,
            'is_active' => true,
            'show_in_catalog' => true,
        ]);
    }

    private function createLeaderboardProfile(
        \App\Models\Category $category,
        string $slug,
        string $city,
        ?string $logoUrl,
        int $reviews = 12,
        float $rating = 4.8,
    ): Profile {
        $profile = Profile::query()->create([
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'city' => $city,
            'logo_url' => $logoUrl,
            'rating_avg' => $rating,
            'reviews_count' => $reviews,
            'views_count' => 100,
            'unique_views_count' => 90,
            'website_clicks_count' => 10,
            'contact_clicks_count' => 8,
            'popularity_score' => 100,
        ]);

        $profile->categories()->attach($category->id, ['is_primary' => true]);

        return $profile;
    }

}

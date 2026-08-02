<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Services\Top20BulkImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

class Top20BulkImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_visible_top20_profile_data_prefers_ukrainian_page_content_over_microdata(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="uk-UA">
<head>
    <title>Адвокат Малик Олександр Володимирович Вінниця - 280 реальних відгуків</title>
    <meta property="og:title" content="Адвокат Малик Олександр Володимирович. Відгуки та оцінки вінничан - ТОП 20">
    <meta name="description" content="Адвокат Малик Олександр Володимирович у Вінниці - повний опис послуг, відгуки, фото">
</head>
<body>
    <script id="company-microdata" type="application/ld+json">
        {"name":"Адвокат Малык Александр Владимирович","address":{"addressLocality":"Винница","streetAddress":"Винница, ул. Николая Оводова, 29а"}}
    </script>
    <script>
        dataLayer.push({'city': 'Вінниця'});
        var addresses = [{"address":"вул. Миколи Оводова, 29а","company":{"name":"Адвокат Малик Олександр Володимирович"}}];
    </script>
</body>
</html>
HTML;

        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractTop20VisibleProfileData');
        $method->setAccessible(true);

        $parsed = $method->invoke($service, $html);

        $this->assertSame('Адвокат Малик Олександр Володимирович', $parsed['name']);
        $this->assertSame('Вінниця', $parsed['city']);
        $this->assertSame('вул. Миколи Оводова, 29а', $parsed['address']);
    }

    public function test_prune_imported_external_reviews_keeps_only_latest_hundred(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Top20 test profile',
            'slug' => 'top20-test-profile',
            'type' => 'company',
            'status' => 'active',
        ]);

        foreach (range(0, 119) as $index) {
            ProfileReview::query()->create([
                'profile_id' => $profile->id,
                'author_name' => 'Author ' . $index,
                'rating' => 5,
                'body' => 'Imported review ' . $index,
                'status' => 'published',
                'verification_type' => 'external_google_import',
                'external_source_type' => 'google',
                'external_review_hash' => 'hash-' . $index,
                'external_review_author' => 'Author ' . $index,
                'external_review_date' => now()->subDays($index)->toDateString(),
                'published_at' => now()->subDays($index),
            ]);
        }

        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('pruneImportedExternalReviews');
        $method->setAccessible(true);

        $deleted = $method->invoke($service, $profile, 100);

        $this->assertSame(20, $deleted);
        $this->assertSame(100, ProfileReview::query()->where('profile_id', $profile->id)->count());
        $this->assertDatabaseMissing('profile_reviews', ['profile_id' => $profile->id, 'external_review_hash' => 'hash-119']);
        $this->assertDatabaseHas('profile_reviews', ['profile_id' => $profile->id, 'external_review_hash' => 'hash-0']);
        $this->assertDatabaseHas('profile_reviews', ['profile_id' => $profile->id, 'external_review_hash' => 'hash-99']);
    }

    public function test_external_avatar_normalization_rejects_top20_empty_placeholder(): void
    {
        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeExternalAvatarUrl');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($service, 'img/empty.png?5cae68064'));
        $this->assertNull($method->invoke($service, 'https://top20.ua/img/empty.png?5cae68064'));
    }

    public function test_localize_imported_review_avatar_downloads_top20_media_to_local_storage(): void
    {
        Storage::fake('public');

        $image = file_get_contents(public_path('static/assets/home-hero-bg-2026-07-03.png'));
        $this->assertNotFalse($image);

        Http::fake([
            'https://top20.ua/media-resize-url/*' => Http::response($image, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('localizeImportedImageReference');
        $method->setAccessible(true);

        $localized = $method->invoke(
            $service,
            'https://top20.ua/media-resize-url/90,90/example.png',
            'review-avatars',
            'Test Author',
            2 * 1024 * 1024
        );

        $this->assertIsString($localized);
        $this->assertStringStartsWith('catalog-media/review-avatars/', $localized);
        Storage::disk('public')->assertExists($localized);
    }

    public function test_localize_imported_review_avatar_does_not_keep_failed_top20_media_url(): void
    {
        Storage::fake('public');

        Http::fake([
            'https://top20.ua/media-resize-url/*' => Http::response('', 500),
        ]);

        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('localizeImportedImageReference');
        $method->setAccessible(true);

        $localized = $method->invoke(
            $service,
            'https://top20.ua/media-resize-url/90,90/example.png',
            'review-avatars',
            'Test Author',
            2 * 1024 * 1024
        );

        $this->assertNull($localized);
    }

    public function test_build_top20_listing_page_url_uses_query_pagination(): void
    {
        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildTop20ListingPageUrl');
        $method->setAccessible(true);

        // Шляхова пагінація /page/N/ у Top20 обрізана на 10 сторінках (далі — 301 на першу),
        // тому будуємо query-варіант, який віддає всі сторінки для всіх типів лістингів.
        $this->assertSame(
            'https://top20.ua/vn/tag/kosmetolog.html?page=2',
            $method->invoke($service, 'https://top20.ua/vn/tag/kosmetolog.html', 2)
        );
        $this->assertSame(
            'https://top20.ua/od/biznes-poslugi/agenstva-neruhomosti/?page=11',
            $method->invoke($service, 'https://top20.ua/od/biznes-poslugi/agenstva-neruhomosti/', 11)
        );
        $this->assertSame(
            'https://top20.ua/od/biznes-poslugi/agenstva-neruhomosti/',
            $method->invoke($service, 'https://top20.ua/od/biznes-poslugi/agenstva-neruhomosti/page/3/', 1)
        );
    }

    public function test_localize_imported_image_reference_rejects_top20_placeholder_user_icons(): void
    {
        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('localizeImportedImageReference');
        $method->setAccessible(true);

        $localized = $method->invoke(
            $service,
            'img/avatars/icons_uzer_v5.png?17ccf019c',
            'review-avatars',
            'Test Author',
            2 * 1024 * 1024
        );

        $this->assertNull($localized);
    }

    public function test_find_existing_profile_matches_by_slug_and_city(): void
    {
        $profile = Profile::query()->create([
            'name' => 'ПРИКАРПАТАУДИТ',
            'slug' => 'prikarpataudit',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('findExistingProfile');
        $method->setAccessible(true);

        $found = $method->invoke($service, 'https://top20.ua/example.html', [
            'name' => 'ПРИКАРПАТАУДИТ',
        ], [
            'name' => 'ПРИКАРПАТАУДИТ',
        ]);

        $this->assertInstanceOf(Profile::class, $found);
        $this->assertSame($profile->id, $found->id);
    }

    public function test_find_existing_profile_does_not_merge_different_city_by_website(): void
    {
        Profile::query()->create([
            'name' => 'Clinic Vinnytsia',
            'slug' => 'clinic-vinnytsia',
            'type' => 'company',
            'status' => 'draft',
            'city' => 'Вінниця',
            'website' => 'https://example-clinic.ua',
        ]);

        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('findExistingProfile');
        $method->setAccessible(true);

        $found = $method->invoke($service, 'https://top20.ua/od/example.html', [
            'name' => 'Clinic Odesa',
            'city' => 'Одеса',
            'website' => 'https://example-clinic.ua',
        ], [
            'name' => 'Clinic Odesa',
            'city' => 'Одеса',
        ]);

        $this->assertNull($found);
    }

    public function test_imported_existing_profile_keeps_old_subcategory_and_adds_new_one(): void
    {
        $root = Category::query()->create([
            'name' => 'Краса',
            'slug' => 'beauty-root',
        ]);
        $oldChild = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'Косметолог',
            'slug' => 'cosmetologist',
        ]);
        $newChild = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'Косметологічні клініки',
            'slug' => 'cosmetology-clinics',
        ]);
        $profile = Profile::query()->create([
            'name' => 'Multi Category Clinic',
            'slug' => 'multi-category-clinic',
            'type' => 'company',
            'status' => 'draft',
        ]);
        $profile->categories()->sync([
            $root->id => ['is_primary' => false],
            $oldChild->id => ['is_primary' => true],
        ]);

        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('syncImportedProfileCategories');
        $method->setAccessible(true);
        $method->invoke($service, $profile, $root->id, $newChild->id, false);

        $profile->load('categories');
        $categoryIds = $profile->categories->pluck('id')->all();

        $this->assertContains($root->id, $categoryIds);
        $this->assertContains($oldChild->id, $categoryIds);
        $this->assertContains($newChild->id, $categoryIds);
        $this->assertDatabaseHas('profile_category', [
            'profile_id' => $profile->id,
            'category_id' => $oldChild->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('profile_category', [
            'profile_id' => $profile->id,
            'category_id' => $newChild->id,
            'is_primary' => false,
        ]);
    }

    public function test_duplicate_profile_category_exception_is_retryable(): void
    {
        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isRetryableDatabaseException');
        $method->setAccessible(true);

        $exception = new QueryException(
            'mysql',
            'insert into `profile_category`',
            [],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1962-93' for key 'profile_category.profile_category_profile_id_category_id_unique'")
        );

        $this->assertTrue($method->invoke($service, $exception));
    }

    public function test_extract_top20_listing_page_number_supports_real_pagination_urls(): void
    {
        $service = app(Top20BulkImportService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractTop20ListingPageNumber');
        $method->setAccessible(true);

        $this->assertSame(
            2,
            $method->invoke($service, 'https://top20.ua/vn/zdorove/kosmetologicheskie-kliniki/page/2/')
        );
        $this->assertSame(
            3,
            $method->invoke($service, 'https://top20.ua/vn/sport-krasota/kosmetologicheskie-kliniki.html?page=3')
        );
    }
}

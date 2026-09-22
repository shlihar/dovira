<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Profile;
use App\Support\CatalogLandingSeo;
use App\Support\ProfileSeoIndexPolicy;
use App\Support\SitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogLandingIndexingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.profile_indexing_mode' => 'legacy']);
        ProfileSeoIndexPolicy::flush();
        SitemapCache::forget();
    }

    private function fixture(int $count = 3, bool $visible = true): array
    {
        $parent = Category::withoutEvents(fn () => Category::create(['name' => 'Право', 'slug' => 'law-test', 'is_active' => true, 'is_indexable' => true]));
        $child = Category::withoutEvents(fn () => Category::create(['name' => 'Адвокати тест', 'slug' => 'lawyers-test', 'parent_id' => $parent->id, 'is_active' => true, 'is_indexable' => true]));
        $profiles = [];
        foreach (range(1, $count) as $n) {
            $p = Profile::withoutEvents(fn () => Profile::create([
                'name' => 'Тестовий адвокат '.$n, 'slug' => 'test-lawyer-'.$n, 'city' => 'Київ',
                'status' => 'active', 'is_published' => $visible, 'show_in_catalog' => $visible,
                'reviews_count' => 1, 'rating_avg' => 4, 'description' => str_repeat('Опис ', 30),
            ]));
            $p->categories()->attach([$parent->id => ['is_primary' => false], $child->id => ['is_primary' => true]]);
            $profiles[] = $p;
        }

        return [$parent, $child, $profiles];
    }

    #[DataProvider('landingCases')]
    public function test_route_category_is_not_a_user_filter(bool $city, array $query, bool $index): void
    {
        [, $child] = $this->fixture();
        $url = '/catalog/'.$child->slug.($city ? '/kyiv' : '');
        if ($query) {
            $url .= '?'.http_build_query($query);
        }
        $this->get($url)->assertOk()->assertSee('name="robots" content="'.($index ? 'index, follow' : 'noindex, follow'), false);
    }

    public static function landingCases(): array
    {
        return [
            [true, [], true], [false, [], true], [true, ['utm_source' => 'test'], true],
            [true, ['sort' => 'recommended'], true], [true, ['sort' => 'newest'], false],
            [true, ['q' => 'lawyer'], false], [true, ['rating' => '4'], false],
            [true, ['sub' => 'Адвокати тест'], false], [false, ['sub' => 'other'], false],
            [true, ['category' => 'other'], false], [true, ['categories' => ['other']], false],
            [true, ['tab' => 'verified'], false], [true, ['status' => 'verified'], false],
            [true, ['regions' => ['Львів']], false], [true, ['page' => 2], false],
        ];
    }

    public function test_parent_and_child_landings_agree_with_sitemap(): void
    {
        [$parent, $child] = $this->fixture();
        $sitemap = $this->get('/sitemap-landing.xml')->assertOk();
        foreach ([$parent, $child] as $cat) {
            foreach (['', '/kyiv'] as $suffix) {
                $url = url('/catalog/'.$cat->slug.$suffix);
                $sitemap->assertSee($url, false);
                $this->get($url)->assertOk()->assertSee('name="robots" content="index, follow', false);
            }
        }
    }

    public function test_one_profile_assigned_to_parent_and_child_does_not_satisfy_threshold_twice(): void
    {
        [$parent, $child] = $this->fixture(2);
        $sitemap = $this->get('/sitemap-landing.xml')->assertOk();
        foreach ([$parent, $child] as $cat) {
            $sitemap->assertDontSee(url('/catalog/'.$cat->slug), false);
            $this->get('/catalog/'.$cat->slug)->assertOk()->assertSee('name="robots" content="noindex, follow', false);
        }
    }

    public function test_unpublished_profiles_do_not_inflate_landing_content(): void
    {
        [$parent, $child] = $this->fixture(3, false);
        $data = CatalogLandingSeo::build($child, 'Київ');
        $this->assertSame(0, $data['profiles_count']);
        $this->assertSame([], $data['top_profiles']);
        $this->get('/sitemap-landing.xml')->assertDontSee(url('/catalog/'.$parent->slug), false);
        $this->get('/catalog/'.$child->slug.'/kyiv')->assertOk()->assertSee('name="robots" content="noindex, follow', false);
    }

    public function test_unpublished_native_review_does_not_qualify_profile(): void
    {
        $p = Profile::withoutEvents(fn () => Profile::create(['name' => 'Test', 'slug' => 'test-native']));
        $id = DB::table('profile_reviews')->insertGetId(['profile_id' => $p->id, 'author_name' => 'Test', 'body' => 'Review', 'rating' => 5, 'status' => 'pending']);
        $this->assertFalse(ProfileSeoIndexPolicy::passes($p));
        DB::table('profile_reviews')->where('id', $id)->update(['status' => 'hidden']);
        ProfileSeoIndexPolicy::flush();
        $this->assertFalse(ProfileSeoIndexPolicy::passes($p));
        DB::table('profile_reviews')->where('id', $id)->update(['status' => 'published']);
        ProfileSeoIndexPolicy::flush();
        $this->assertTrue(ProfileSeoIndexPolicy::passes($p));
    }

    public function test_reviewed_profile_is_added_and_removed_from_cached_sitemap(): void
    {
        config(['seo.strict_profile_indexing' => true]);
        $p = Profile::withoutEvents(fn () => Profile::create(['name' => 'Reviewed', 'slug' => 'reviewed-test', 'status' => 'active', 'is_published' => true, 'show_in_catalog' => true, 'description' => str_repeat('x', 150)]));
        foreach ([[], [$p->id], []] as $ids) {
            config(['seo.reviewed_profile_ids' => $ids]);
            $response = $this->get('/sitemap-profiles-1.xml')->assertOk();
            $ids ? $response->assertSee(route('profile.show', $p->slug), false) : $response->assertDontSee(route('profile.show', $p->slug), false);
            $this->get('/profiles/'.$p->slug)->assertOk()->assertSee('name="robots" content="'.($ids ? 'index, follow' : 'noindex, follow'), false);
        }
    }
}

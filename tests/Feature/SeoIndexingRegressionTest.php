<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Support\ProfileSeoIndexPolicy;
use App\Support\SitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeoIndexingRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.profile_indexing_mode' => 'legacy']);
        ProfileSeoIndexPolicy::flush();
        SitemapCache::forget();
    }

    #[DataProvider('catalogQueries')]
    public function test_catalog_only_indexes_the_unfiltered_first_page(array $query, bool $indexable): void
    {
        $this->get(route('catalog', $query))
            ->assertOk()
            ->assertSee('name="robots" content="'.($indexable ? 'index, follow' : 'noindex, follow'), false);
    }

    public static function catalogQueries(): array
    {
        return [
            'catalog' => [[], true],
            'tracking only' => [['utm_source' => 'newsletter'], true],
            'default sort' => [['sort' => 'recommended'], true],
            'search' => [['q' => 'дрім'], false],
            'rating filter' => [['rating' => '4.5'], false],
            'review count' => [['reviews_count' => '10'], false],
            'city filter' => [['regions' => ['Київ']], false],
            'category filter' => [['categories' => ['Адвокати']], false],
            'status filter' => [['status' => 'verified'], false],
            'legacy status filter' => [['tab' => 'verified'], false],
            'sort' => [['sort' => 'newest'], false],
            'pagination' => [['page' => 2], false],
        ];
    }

    public function test_sitemap_xml_follows_policy_changes_even_when_already_cached(): void
    {
        $profile = Profile::withoutEvents(fn () => Profile::query()->create([
            'name' => 'SEO test profile',
            'slug' => 'seo-policy-test',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'description' => str_repeat('a', 550),
        ]));
        $url = route('profile.show', ['slug' => $profile->slug]);
        $profilesSitemap = route('seo.sitemap.profiles', ['page' => 1]);

        foreach ([[false, 500, true], [true, 600, false], [true, 500, true], [false, 600, true]] as [$strict, $min, $included]) {
            config(['seo.strict_profile_indexing' => $strict, 'seo.strict_min_description' => $min]);

            $index = $this->get(route('seo.sitemap'))->assertOk();
            $profiles = $this->get($profilesSitemap)->assertOk();
            if ($included) {
                $index->assertSee($profilesSitemap, false);
                $profiles->assertSee($url, false);
            } else {
                $index->assertDontSee($profilesSitemap, false);
                $profiles->assertDontSee($url, false);
            }
        }
    }
}

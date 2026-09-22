<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Support\ProfileSeoIndexPolicy;
use App\Support\SitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileContentIndexingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.profile_indexing_mode' => 'reviews_contacts_dossier']);
        ProfileSeoIndexPolicy::flush();
        SitemapCache::forget();
    }

    #[DataProvider('signals')]
    public function test_page_and_sitemap_follow_content_signals(array $fields, ?string $reviewStatus, bool $expected): void
    {
        $p = Profile::withoutEvents(fn () => Profile::create(array_merge([
            'name' => 'Content rule test', 'slug' => 'content-rule-test', 'status' => 'active',
            'is_published' => true, 'show_in_catalog' => true,
        ], $fields)));
        if ($reviewStatus) {
            DB::table('profile_reviews')->insert(['profile_id' => $p->id, 'author_name' => 'Reviewer',
                'body' => 'Test review', 'rating' => 4, 'status' => $reviewStatus, 'external_source_type' => 'top20']);
        }
        $url = route('profile.show', $p->slug);
        $this->get($url)->assertOk()->assertSee('name="robots" content="'.($expected ? 'index, follow' : 'noindex, follow'), false);
        $response = $this->get('/sitemap-profiles-1.xml')->assertOk();
        $expected ? $response->assertSee($url, false) : $response->assertDontSee($url, false);
    }

    public static function signals(): array
    {
        return [
            'empty' => [[], null, false],
            'phone' => [['phone' => '+380501112233'], null, true],
            'email' => [['email' => 'test@example.com'], null, true],
            'website' => [['website' => 'https://example.com'], null, true],
            'address' => [['address' => 'Вулиця Тестова, 1'], null, true],
            'city is not contact' => [['city' => 'Київ'], null, false],
            'dossier' => [['dossier' => '<p>Зміст досьє</p>'], null, true],
            'empty markup dossier' => [['dossier' => '<p>&nbsp; </p>'], null, false],
            'verdict without dossier' => [['dossier_verdict' => 'green'], null, false],
            'description alone' => [['description' => str_repeat('Опис ', 150)], null, false],
            'published review despite stale count' => [['reviews_count' => 0], 'published', true],
            'hidden review despite stale count' => [['reviews_count' => 10], 'hidden', false],
            'pending review' => [[], 'pending', false],
            'stale count alone' => [['reviews_count' => 100], null, false],
            'quarantine overrides contact' => [['phone' => '+380501112233', 'internal_note' => '[IDENTITY_REPAIR_REVIEW]'], null, false],
            'unpublished contact' => [['phone' => '+380501112233', 'is_published' => false], null, false],
            'hidden from catalog' => [['phone' => '+380501112233', 'show_in_catalog' => false], null, false],
        ];
    }

    public function test_draft_profile_with_contact_cannot_be_revived(): void
    {
        $p = Profile::withoutEvents(fn () => Profile::create(['name' => 'Draft', 'slug' => 'draft-contact',
            'status' => 'draft', 'is_published' => true, 'show_in_catalog' => true, 'phone' => '+380501112233']));
        $this->assertFalse(ProfileSeoIndexPolicy::passesContentSignals($p));
        $this->get('/profiles/'.$p->slug)->assertNotFound();
        $this->get('/sitemap-profiles-1.xml')->assertDontSee(route('profile.show', $p->slug), false);
    }

    public function test_mode_change_updates_cached_sitemap_in_both_directions(): void
    {
        $p = Profile::withoutEvents(fn () => Profile::create(['name' => 'Contact only', 'slug' => 'contact-only',
            'status' => 'active', 'is_published' => true, 'show_in_catalog' => true, 'phone' => '+380501112233']));
        config(['seo.strict_profile_indexing' => true, 'seo.reviewed_profile_ids' => []]);
        foreach (['legacy', 'reviews_contacts_dossier', 'legacy'] as $mode) {
            config(['seo.profile_indexing_mode' => $mode]);
            $response = $this->get('/sitemap-profiles-1.xml')->assertOk();
            $mode === 'legacy' ? $response->assertDontSee(route('profile.show', $p->slug), false)
                : $response->assertSee(route('profile.show', $p->slug), false);
        }
    }

    public function test_all_public_mode_opens_empty_profiles_and_refreshes_cached_sitemap(): void
    {
        $p = Profile::withoutEvents(fn () => Profile::create(['name' => 'Empty public profile',
            'slug' => 'empty-public-profile', 'status' => 'active', 'is_published' => true, 'show_in_catalog' => true]));
        $url = route('profile.show', $p->slug);
        config(['seo.strict_profile_indexing' => true, 'seo.reviewed_profile_ids' => []]);
        foreach (['reviews_contacts_dossier', 'all_public', 'reviews_contacts_dossier'] as $mode) {
            config(['seo.profile_indexing_mode' => $mode]);
            $expected = $mode === 'all_public';
            $this->get($url)->assertOk()->assertSee('name="robots" content="'.($expected ? 'index, follow' : 'noindex, follow'), false);
            $response = $this->get('/sitemap-profiles-1.xml')->assertOk();
            $expected ? $response->assertSee($url, false) : $response->assertDontSee($url, false);
        }
    }

    #[DataProvider('nonPublicProfiles')]
    public function test_all_public_mode_preserves_publication_boundaries(array $fields): void
    {
        config(['seo.profile_indexing_mode' => 'all_public']);
        $p = Profile::withoutEvents(fn () => Profile::create(array_merge(['name' => 'Non-public profile',
            'slug' => 'non-public-profile', 'status' => 'active', 'is_published' => true, 'show_in_catalog' => true], $fields)));
        $this->assertFalse(ProfileSeoIndexPolicy::isPublic($p));
        $response = $this->get(route('profile.show', $p->slug));
        if ($p->status === 'draft') {
            $response->assertNotFound();
        } else {
            $response->assertOk()->assertSee('name="robots" content="noindex, follow', false);
        }
        $this->get('/sitemap-profiles-1.xml')->assertOk()->assertDontSee(route('profile.show', $p->slug), false);
    }

    public static function nonPublicProfiles(): array
    {
        return [
            'draft' => [['status' => 'draft']],
            'unpublished' => [['is_published' => false]],
            'hidden' => [['show_in_catalog' => false]],
            'quarantined' => [['internal_note' => '[IDENTITY_REPAIR_REVIEW]']],
        ];
    }
}

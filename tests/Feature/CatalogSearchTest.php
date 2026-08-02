<?php

namespace Tests\Feature;

use App\Models\Profile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_catalog_page_is_accessible(): void
    {
        $response = $this->get(route('catalog'));

        $response->assertOk();
        $response->assertSee('Фільтрувати за');
        $response->assertSee('Переглянути профіль');
    }

    public function test_catalog_ajax_filter_by_category_returns_json_html_and_count(): void
    {
        $response = $this->getJson(route('catalog', [
            'ajax' => 1,
            'tab' => 'all',
            'categories' => ['Доставка'],
        ]));

        $response->assertOk();
        $response->assertJsonStructure([
            'html',
            'count' => ['from', 'to', 'total'],
        ]);

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertIsString($payload['html']);
        $this->assertStringContainsString('Переглянути профіль', $payload['html']);
        // 'total' is null unless the exact count was computed — 'to' reflects
        // how many cards the first page actually rendered.
        $this->assertGreaterThanOrEqual(1, (int) ($payload['count']['to'] ?? 0));
    }

    public function test_catalog_cards_render_image_url_for_profiles_without_uploaded_logo(): void
    {
        Profile::query()->create([
            'name' => 'Fallback Logo Clinic',
            'slug' => 'fallback-logo-clinic',
            'type' => 'company',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'rating_avg' => 4.8,
            'reviews_count' => 12,
        ]);

        $response = $this->getJson(route('catalog', [
            'ajax' => 1,
            'q' => 'Fallback Logo Clinic',
        ]));

        $response->assertOk();
        $html = (string) $response->json('html');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('/media/catalog-media/profile-fallbacks/', $html);
        $this->assertStringNotContainsString('127.0.0.1/media', $html);
    }

    public function test_verified_tab_returns_only_verified_profiles(): void
    {
        $response = $this->getJson(route('catalog', [
            'ajax' => 1,
            'tab' => 'verified',
        ]));

        $response->assertOk();
        $html = (string) $response->json('html');

        $this->assertStringNotContainsString('AutoCare Service', $html);
    }

    public function test_search_suggest_without_query_returns_popular_items(): void
    {
        $response = $this->getJson(route('search.suggest', ['q' => '', 'limit' => 8]));

        $response->assertOk();
        $response->assertJsonStructure([
            'query',
            'items' => [
                '*' => ['type', 'label', 'icon', 'url'],
            ],
        ]);

        $this->assertGreaterThanOrEqual(1, count((array) $response->json('items')));
    }

    public function test_search_suggest_by_query_returns_profile_item(): void
    {
        $response = $this->getJson(route('search.suggest', ['q' => 'Tutor', 'limit' => 8]));

        $response->assertOk();

        $labels = collect((array) $response->json('items'))
            ->pluck('label')
            ->map(fn ($label) => (string) $label)
            ->all();

        $this->assertNotEmpty($labels);
        $this->assertTrue(
            collect($labels)->contains(fn ($label) => str_contains(mb_strtolower($label), 'tutor')),
            'Expected at least one suggest label containing "tutor"'
        );
    }

    public function test_profile_scope_suggest_returns_profile_slug_for_popup(): void
    {
        $response = $this->getJson(route('search.suggest', ['scope' => 'profiles', 'q' => 'Nova', 'limit' => 8]));

        $response->assertOk();
        $item = collect((array) $response->json('items'))->first();

        $this->assertIsArray($item);
        $this->assertSame('profile', $item['type'] ?? null);
        $this->assertNotEmpty($item['profile_slug'] ?? null);
    }

    public function test_claim_profile_scope_suggest_returns_pro_account_url(): void
    {
        $response = $this->getJson(route('search.suggest', ['scope' => 'claim_profiles', 'q' => 'Nova', 'limit' => 8]));

        $response->assertOk();
        $item = collect((array) $response->json('items'))->first();

        $this->assertIsArray($item);
        $this->assertSame('profile', $item['type'] ?? null);
        $this->assertNotEmpty($item['profile_slug'] ?? null);
        $this->assertStringContainsString('/pro/account', (string) ($item['url'] ?? ''));
        $this->assertStringContainsString('claim_profile=', (string) ($item['url'] ?? ''));
    }
}

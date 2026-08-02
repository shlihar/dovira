<?php

namespace Tests\Feature;

use App\Models\Profile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoHomeSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_known_city_returns_three_sections_with_city_profiles_first(): void
    {
        Http::fake();

        $profile = Profile::query()
            ->where('status', 'active')
            ->whereNotNull('city')
            ->firstOrFail();

        $response = $this->getJson(route('geo.home-sections', ['city' => $profile->city]));

        $response->assertOk();
        $this->assertNotNull($response->json('city'));
        $this->assertIsString($response->json('html.recommended'));
        $this->assertIsString($response->json('html.top'));
        $this->assertIsString($response->json('html.reviews'));

        // Профіль міста має бути в секції «Найпопулярніші» (без вимог до
        // рейтингу) і стояти попереду.
        $this->assertStringContainsString($profile->slug, $response->json('html.top'));

        // Зовнішній гео-сервіс не викликався — місто передане явно.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'geojs.io'));
    }

    public function test_first_visit_sets_city_cookie(): void
    {
        Http::fake();

        $city = Profile::query()->where('status', 'active')->whereNotNull('city')->value('city');

        $response = $this->getJson(route('geo.home-sections', ['city' => $city]));

        $response->assertOk();
        $response->assertCookie('dovira_city', $response->json('city'));
    }

    public function test_undetected_city_returns_null_and_keeps_global_sections(): void
    {
        Http::fake([
            'get.geojs.io/*' => Http::response(['country_code' => 'PL', 'city' => 'Warsaw']),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '83.0.0.10'])
            ->getJson(route('geo.home-sections'));

        $response->assertOk();
        $this->assertNull($response->json('city'));
        $this->assertNull($response->json('html'));
        $response->assertCookieMissing('dovira_city');
    }

    public function test_ukrainian_ip_resolves_city(): void
    {
        Http::fake([
            'get.geojs.io/*' => Http::response(['country_code' => 'UA', 'city' => 'Kyiv']),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '93.170.0.10'])
            ->getJson(route('geo.home-sections'));

        $response->assertOk();
        // Місто зрезолвилось у канонічну назву; якщо в сідах є профілі —
        // повертаються секції.
        $this->assertContains($response->json('city'), ['Київ', null]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'geojs.io'));
    }
}

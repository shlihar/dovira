<?php

namespace Tests\Feature;

use App\Models\Profile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuestReviewTurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function reviewPayload(): array
    {
        $profile = Profile::query()->where('status', 'active')->firstOrFail();

        return [
            'profile_slug' => $profile->slug,
            'rating' => 5,
            'body' => 'Дуже задоволений сервісом, все було швидко і професійно.',
            'author_email' => 'guest@example.com',
        ];
    }

    public function test_guest_review_without_token_is_rejected_when_turnstile_enabled(): void
    {
        config()->set('services.turnstile.site_key', 'test-site-key');
        config()->set('services.turnstile.secret_key', 'test-secret');
        Http::fake();

        $response = $this->postJson(route('reviews.store'), $this->reviewPayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['body']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'challenges.cloudflare.com'));
    }

    public function test_guest_review_with_valid_token_passes(): void
    {
        config()->set('services.turnstile.site_key', 'test-site-key');
        config()->set('services.turnstile.secret_key', 'test-secret');
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->postJson(route('reviews.store'), [
            ...$this->reviewPayload(),
            'cf-turnstile-response' => 'valid-token',
        ]);

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'challenges.cloudflare.com'));
    }

    public function test_guest_review_with_invalid_token_is_rejected(): void
    {
        config()->set('services.turnstile.site_key', 'test-site-key');
        config()->set('services.turnstile.secret_key', 'test-secret');
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false]),
        ]);

        $response = $this->postJson(route('reviews.store'), [
            ...$this->reviewPayload(),
            'cf-turnstile-response' => 'bad-token',
        ]);

        $response->assertUnprocessable();
    }

    public function test_guest_review_works_without_turnstile_configured(): void
    {
        Http::fake();

        $response = $this->postJson(route('reviews.store'), $this->reviewPayload());

        $response->assertOk();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'challenges.cloudflare.com'));
    }

    public function test_legal_pages_are_accessible(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Політика конфіденційності');
        $this->get('/terms')->assertOk()->assertSee('Умови використання');
    }
}

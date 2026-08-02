<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
    }

    public function test_media_route_blocks_path_traversal(): void
    {
        $this->get('/media/..%2F..%2F..%2F.env')->assertNotFound();
        $this->get('/media/' . urlencode('../../../.env'))->assertNotFound();
    }

    public function test_html_in_profile_description_is_not_rendered_raw(): void
    {
        $this->seed(DatabaseSeeder::class);

        $profile = Profile::query()->where('status', 'active')->firstOrFail();
        $profile->update([
            'description' => 'Опис компанії <script>alert(document.cookie)</script> кінець.',
        ]);

        $response = $this->get(route('profile.show', ['slug' => $profile->slug]));

        $response->assertOk();
        // Тег скрипта має бути екранований, а не виконуваний.
        $response->assertDontSee('<script>alert(document.cookie)</script>', false);
        $response->assertSee('Опис компанії', false);
    }

    public function test_guest_review_endpoint_is_rate_limited(): void
    {
        $this->seed(DatabaseSeeder::class);
        $profile = Profile::query()->where('status', 'active')->firstOrFail();

        $payload = [
            'profile_slug' => $profile->slug,
            'rating' => 5,
            'body' => 'Достатньо довгий текст відгуку для проходження валідації.',
            'author_email' => 'guest@example.com',
        ];

        $tooMany = false;
        for ($i = 0; $i < 14; $i++) {
            $status = $this->postJson(route('reviews.store'), $payload)->status();
            if ($status === 429) {
                $tooMany = true;
                break;
            }
        }

        $this->assertTrue($tooMany, 'Гостьовий відгук мав спрацювати rate limit (429).');
    }
}

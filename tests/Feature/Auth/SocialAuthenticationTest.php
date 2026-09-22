<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_callback_can_create_and_authenticate_a_user(): void
    {
        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect', 'http://localhost/auth/google/callback');

        $providerUser = new SocialiteUser();
        $providerUser->id = 'google-123';
        $providerUser->name = 'Google User';
        $providerUser->email = 'google@example.com';
        $providerUser->avatar = 'https://example.com/google-avatar.jpg';

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn($providerUser);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));

        $user = User::query()->where('email', 'google@example.com')->firstOrFail();

        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('google', $user->auth_provider);
        $this->assertSame('https://example.com/google-avatar.jpg', $user->avatar_url);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_google_callback_links_existing_user_by_email(): void
    {
        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect', 'http://localhost/auth/google/callback');

        $user = User::factory()->create([
            'email' => 'linked@example.com',
            'avatar_url' => null,
            'google_id' => null,
        ]);

        $providerUser = new SocialiteUser();
        $providerUser->id = 'google-987';
        $providerUser->name = 'Linked User';
        $providerUser->email = 'linked@example.com';
        $providerUser->avatar = 'https://example.com/google-avatar.jpg';

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn($providerUser);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($user->fresh());
        $response->assertRedirect(route('home', absolute: false));

        $user->refresh();

        $this->assertSame('google-987', $user->google_id);
        $this->assertSame('https://example.com/google-avatar.jpg', $user->avatar_url);
    }

    public function test_telegram_auth_route_is_removed(): void
    {
        // Telegram-авторизацію прибрано з сайту: іменований маршрут і POST-хендлер
        // більше не існують (лишається тільки GET-редірект/колбек для OAuth).
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('auth.social.telegram'));

        $this->post('/auth/telegram/callback', [
            'id' => '555666777',
            'auth_date' => (string) time(),
            'hash' => str_repeat('a', 64),
        ])->assertStatus(405);

        $this->assertGuest();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}

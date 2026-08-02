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

    public function test_telegram_callback_can_create_and_authenticate_a_user(): void
    {
        config()->set('services.telegram.bot_name', 'dovira_test_bot');
        config()->set('services.telegram.bot_token', 'telegram-bot-token');
        config()->set('services.telegram.login_max_age', 86400);

        $payload = [
            'id' => '555666777',
            'first_name' => 'Tele',
            'last_name' => 'Gram',
            'username' => 'telegram_user',
            'photo_url' => 'https://example.com/telegram-avatar.jpg',
            'auth_date' => (string) time(),
        ];

        $payload['hash'] = $this->telegramHash($payload, 'telegram-bot-token');

        $response = $this->post('/auth/telegram/callback', $payload);

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));

        $user = User::query()->where('telegram_id', '555666777')->firstOrFail();

        $this->assertSame('telegram', $user->auth_provider);
        $this->assertSame('telegram_user', $user->telegram_username);
        $this->assertSame('telegram-555666777@social.dovira.local', $user->email);
    }

    public function test_telegram_callback_accepts_user_without_optional_fields(): void
    {
        config()->set('services.telegram.bot_name', 'dovira_test_bot');
        config()->set('services.telegram.bot_token', 'telegram-bot-token');
        config()->set('services.telegram.login_max_age', 86400);

        // Telegram підписує лише надіслані поля: користувач без username,
        // прізвища і фото має валідний hash тільки по id/first_name/auth_date.
        $signedPayload = [
            'id' => '888999000',
            'first_name' => 'NoUsername',
            'auth_date' => (string) time(),
        ];

        $hash = $this->telegramHash($signedPayload, 'telegram-bot-token');

        // Форма на сайті завжди сабмітить усі поля — відсутні порожніми.
        $response = $this->post('/auth/telegram/callback', [
            ...$signedPayload,
            'last_name' => '',
            'username' => '',
            'photo_url' => '',
            'hash' => $hash,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));

        $user = User::query()->where('telegram_id', '888999000')->firstOrFail();
        $this->assertSame('telegram', $user->auth_provider);
    }

    public function test_telegram_callback_rejects_invalid_hash(): void
    {
        config()->set('services.telegram.bot_name', 'dovira_test_bot');
        config()->set('services.telegram.bot_token', 'telegram-bot-token');
        config()->set('services.telegram.login_max_age', 86400);

        $response = $this->from('/login')->post('/auth/telegram/callback', [
            'id' => '1',
            'first_name' => 'Bad',
            'last_name' => 'Hash',
            'username' => 'bad_hash',
            'photo_url' => 'https://example.com/avatar.jpg',
            'auth_date' => (string) time(),
            'hash' => str_repeat('a', 64),
        ]);

        $response->assertSessionHasErrors('social');
        $this->assertGuest();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function telegramHash(array $payload, string $botToken): string
    {
        ksort($payload);

        $dataCheckString = collect($payload)
            ->map(static fn (string $value, string $key): string => $key . '=' . $value)
            ->implode("\n");

        return hash_hmac('sha256', $dataCheckString, hash('sha256', $botToken, true));
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SocialAuthService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const OAUTH_PROVIDERS = ['google'];

    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {
    }

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        $this->storeIntendedUrl($request, $request->query('next'));
        $this->ensureProviderIsConfigured($provider);

        $driver = Socialite::driver($provider);

        if ($provider === 'google') {
            $driver->scopes(['openid', 'profile', 'email']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        try {
            $this->ensureProviderIsConfigured($provider);
            $result = $this->socialAuthService->resolveOauthUser(
                $provider,
                Socialite::driver($provider)->user(),
            );
        } catch (\Throwable $exception) {
            Log::warning('Social auth callback failed.', [
                'provider' => $provider,
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('login')
                ->withErrors([
                    'social' => 'Не вдалося увійти через ' . $this->providerLabel($provider) . '. Спробуйте ще раз.',
                ]);
        }

        if ($result['created']) {
            event(new Registered($result['user']));
        }

        Auth::login($result['user'], true);
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }

    public function telegram(Request $request): RedirectResponse
    {
        $this->storeIntendedUrl($request, $request->input('next'));
        $this->ensureProviderIsConfigured('telegram');

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'photo_url' => ['nullable', 'url', 'max:2048'],
            'auth_date' => ['required', 'integer'],
            'hash' => ['required', 'string', 'size:64'],
            'next' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! $this->telegramPayloadIsValid($validated)) {
            throw ValidationException::withMessages([
                'social' => 'Не вдалося підтвердити вхід через Telegram. Спробуйте ще раз.',
            ]);
        }

        $result = $this->socialAuthService->resolveTelegramUser($validated);

        if ($result['created']) {
            event(new Registered($result['user']));
        }

        Auth::login($result['user'], true);
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }

    private function ensureProviderIsConfigured(string $provider): void
    {
        $isConfigured = match ($provider) {
            'google' => filled(config("services.{$provider}.client_id"))
                && filled(config("services.{$provider}.client_secret"))
                && filled(config("services.{$provider}.redirect")),
            'telegram' => filled(config('services.telegram.bot_name'))
                && filled(config('services.telegram.bot_token')),
            default => false,
        };

        if (! $isConfigured) {
            throw ValidationException::withMessages([
                'social' => 'Вхід через ' . $this->providerLabel($provider) . ' ще не налаштований.',
            ]);
        }
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'google' => 'Google',
            'telegram' => 'Telegram',
            default => Str::title($provider),
        };
    }

    private function storeIntendedUrl(Request $request, mixed $next): void
    {
        if (! is_string($next) || $next === '') {
            return;
        }

        if (str_starts_with($next, url('/'))) {
            $request->session()->put('url.intended', $next);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function telegramPayloadIsValid(array $payload): bool
    {
        $maxAge = max(60, (int) config('services.telegram.login_max_age', 86400));
        $authDate = (int) ($payload['auth_date'] ?? 0);

        if ($authDate <= 0 || (time() - $authDate) > $maxAge) {
            return false;
        }

        $hash = (string) ($payload['hash'] ?? '');
        unset($payload['hash'], $payload['next']);

        ksort($payload);

        $dataCheckString = collect($payload)
            // Telegram підписує лише реально надіслані поля, а наша форма
            // завжди сабмітить усі сім (порожні стають null). Без цього
            // фільтра вхід ламався для користувачів без username чи фото.
            ->filter(static fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(static fn (mixed $value, string $key): string => $key . '=' . trim((string) $value))
            ->implode("\n");

        $secretKey = hash('sha256', (string) config('services.telegram.bot_token'), true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($expectedHash, $hash);
    }
}

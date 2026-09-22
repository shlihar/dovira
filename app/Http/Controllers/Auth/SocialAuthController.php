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

    private function ensureProviderIsConfigured(string $provider): void
    {
        $isConfigured = match ($provider) {
            'google' => filled(config("services.{$provider}.client_id"))
                && filled(config("services.{$provider}.client_secret"))
                && filled(config("services.{$provider}.redirect")),
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
}

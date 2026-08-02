<?php

namespace App\Providers;

use App\Models\OfficialReply;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Observers\OfficialReplyObserver;
use App\Observers\ProfileObserver;
use App\Observers\ProfileReviewObserver;
use App\Services\AiEnrichment\Contracts\ProfileEnrichmentProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProfileEnrichmentProvider::class, function ($app): ProfileEnrichmentProvider {
            $provider = (string) config('ai_enrichment.provider', 'local');
            $providerClass = (string) (config("ai_enrichment.providers.{$provider}") ?: $provider);

            return $app->make($providerClass);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! app()->isLocal()) {
            $viteHotFile = public_path('hot');

            if (is_file($viteHotFile)) {
                @unlink($viteHotFile);
            }
        }

        $forceHttps = app()->environment('production');

        if (! $forceHttps && ! app()->runningInConsole()) {
            $host = strtolower((string) request()->getHost());
            $forceHttps = request()->isSecure()
                || str_contains($host, 'ngrok-free.dev')
                || str_contains($host, 'ngrok-free.app');
        }

        if (! $forceHttps) {
            $forceHttps = (bool) env('APP_FORCE_HTTPS', false);
        }

        if ($forceHttps) {
            URL::forceScheme('https');
        }

        ProfileReview::observe(ProfileReviewObserver::class);
        OfficialReply::observe(OfficialReplyObserver::class);
        Profile::observe(ProfileObserver::class);

        $this->localizeAuthMail();
    }

    /**
     * Ukrainian copy for the built-in auth notifications (password reset,
     * email verification) — the framework defaults are English-only.
     */
    private function localizeAuthMail(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $expireMinutes = (int) config('auth.passwords.users.expire', 60);

            return (new MailMessage())
                ->subject('Відновлення пароля — DOVIRA')
                ->greeting('Вітаємо!')
                ->line('Ми отримали запит на відновлення пароля для вашого акаунта на DOVIRA.')
                ->action('Відновити пароль', $url)
                ->line("Посилання дійсне протягом {$expireMinutes} хвилин.")
                ->line('Якщо ви не надсилали цей запит — просто проігноруйте лист, ваш пароль залишиться без змін.')
                ->salutation('З повагою, команда DOVIRA');
        });

        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            return (new MailMessage())
                ->subject('Підтвердіть email — DOVIRA')
                ->greeting('Вітаємо на DOVIRA!')
                ->line('Натисніть кнопку нижче, щоб підтвердити вашу email-адресу і завершити реєстрацію.')
                ->action('Підтвердити email', $url)
                ->line('Якщо ви не створювали акаунт на DOVIRA — просто проігноруйте цей лист.')
                ->salutation('З повагою, команда DOVIRA');
        });
    }
}

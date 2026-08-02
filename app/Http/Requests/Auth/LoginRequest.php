<?php

namespace App\Http\Requests\Auth;

use App\Support\Turnstile;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Адаптивна капча: після кількох невдалих спроб з цього IP вимагаємо
        // Turnstile, перш ніж узагалі пробувати креденшели.
        if ($this->captchaRequired()
            && ! Turnstile::passes($this->input('cf-turnstile-response'), $this->ip())) {
            RateLimiter::hit($this->captchaThrottleKey(), self::CAPTCHA_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'Підтвердіть, що ви не робот, і спробуйте ще раз.',
            ]);
        }

        // Сесію запамʼятовуємо завжди: remember-кукі тримає вхід і після
        // закінчення звичайної сесії — користувача не «викидає».
        if (! Auth::attempt($this->only('email', 'password'), true)) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->captchaThrottleKey(), self::CAPTCHA_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->captchaThrottleKey());
    }

    /**
     * Скільки невдалих спроб з IP дозволяємо, перш ніж показати капчу.
     */
    public const CAPTCHA_AFTER_ATTEMPTS = 2;

    /**
     * Через скільки секунд без спроб вимога капчі скидається.
     */
    public const CAPTCHA_DECAY_SECONDS = 900;

    /**
     * Чи потрібна капча для цього запиту (лише коли Turnstile увімкнено
     * і з IP уже було достатньо невдалих спроб).
     */
    public function captchaRequired(): bool
    {
        return Turnstile::isEnabled()
            && RateLimiter::attempts($this->captchaThrottleKey()) >= self::CAPTCHA_AFTER_ATTEMPTS;
    }

    /**
     * Ключ лічильника невдалих спроб входу по IP (окремо від email|ip,
     * щоб рішення «показати капчу» не залежало від конкретного email).
     */
    public function captchaThrottleKey(): string
    {
        return self::captchaThrottleKeyFor($this->ip());
    }

    public static function captchaThrottleKeyFor(?string $ip): string
    {
        return 'login-captcha:'.$ip;
    }

    /**
     * Версія captchaRequired() для GET-сторінки логіну (де ще немає
     * повного LoginRequest) — вирішує, чи рендерити віджет капчі.
     */
    public static function captchaRequiredForIp(?string $ip): bool
    {
        return Turnstile::isEnabled()
            && RateLimiter::attempts(self::captchaThrottleKeyFor($ip)) >= self::CAPTCHA_AFTER_ATTEMPTS;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}

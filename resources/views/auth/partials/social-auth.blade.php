@php
    $next = request()->query('next');
    $query = is_string($next) && $next !== '' ? ['next' => $next] : [];
    $telegramBotName = trim((string) config('services.telegram.bot_name'));
    $googleEnabled = filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    $telegramEnabled = $telegramBotName !== '' && filled(config('services.telegram.bot_token'));
@endphp

<div class="auth-social-list" aria-label="{{ $actionLabel }} за допомогою інших сервісів">
    <div class="auth-social-grid">
        @if ($googleEnabled)
        <a class="auth-social-icon auth-social-icon--google" href="{{ route('auth.social.redirect', ['provider' => 'google'] + $query) }}" aria-label="{{ $actionLabel }} через Google" title="Google" data-auth-transition-link>
            <svg width="18" height="18" viewBox="0 0 46 47" fill="none" aria-hidden="true">
                <path d="M46 24.0287C46 22.09 45.8533 20.68 45.5013 19.2112H23.4694V27.9356H36.4069C36.1429 30.1094 34.7347 33.37 31.5957 35.5731L31.5663 35.8669L38.5191 41.2719L38.9885 41.3306C43.4477 37.2181 46 31.1669 46 24.0287Z" fill="#4285F4"/>
                <path d="M23.4694 47C29.8061 47 35.1161 44.9144 39.0179 41.3012L31.625 35.5437C29.6301 36.9244 26.9898 37.8937 23.4987 37.8937C17.2793 37.8937 12.0281 33.7812 10.1505 28.1412L9.88649 28.1706L2.61097 33.7812L2.52296 34.0456C6.36608 41.7125 14.287 47 23.4694 47Z" fill="#34A853"/>
                <path d="M10.1212 28.1413C9.62245 26.6725 9.32908 25.1156 9.32908 23.5C9.32908 21.8844 9.62245 20.3275 10.0918 18.8588V18.5356L2.75765 12.8369L2.52296 12.9544C0.909439 16.1269 0 19.7106 0 23.5C0 27.2894 0.909439 30.8731 2.49362 34.0456L10.1212 28.1413Z" fill="#FBBC05"/>
                <path d="M23.4694 9.07688C27.8699 9.07688 30.8622 10.9863 32.5344 12.5725L39.1645 6.11C35.0867 2.32063 29.8061 0 23.4694 0C14.287 0 6.36607 5.2875 2.49362 12.9544L10.0918 18.8588C11.9987 13.1894 17.25 9.07688 23.4694 9.07688Z" fill="#EB4335"/>
            </svg>
        </a>
        @else
        <button type="button" class="auth-social-icon" disabled aria-disabled="true" aria-label="Google недоступний" title="Google">
            <i class="fa-brands fa-google" aria-hidden="true"></i>
        </button>
        @endif

        @if ($telegramEnabled)
        <button type="button" class="auth-social-icon auth-social-icon--telegram" aria-label="{{ $actionLabel }} через Telegram" title="Telegram" data-auth-telegram-toggle aria-expanded="false">
            <i class="fa-brands fa-telegram" aria-hidden="true"></i>
        </button>
        @else
        <button type="button" class="auth-social-icon" disabled aria-disabled="true" aria-label="Telegram недоступний" title="Telegram">
            <i class="fa-brands fa-telegram" aria-hidden="true"></i>
        </button>
        @endif
    </div>

    @if ($telegramEnabled)
        <div class="auth-telegram-panel" data-auth-telegram-panel hidden>
            <div class="auth-telegram" data-telegram-login-root>
                <form method="POST" action="{{ route('auth.social.telegram') }}" class="auth-telegram__form" data-telegram-login-form>
                    @csrf
                    @foreach (['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash'] as $field)
                        <input type="hidden" name="{{ $field }}" value="">
                    @endforeach
                    @if ($next)
                        <input type="hidden" name="next" value="{{ $next }}">
                    @endif
                </form>
                <script async src="https://telegram.org/js/telegram-widget.js?22"
                    data-telegram-login="{{ $telegramBotName }}"
                    data-size="large"
                    data-radius="12"
                    data-userpic="false"
                    data-request-access="write"
                    data-onauth="window.doviraTelegramAuth(user)"></script>
            </div>
        </div>
    @endif
</div>

@if ($telegramEnabled)
    @push('scripts')
        <script>
            window.doviraTelegramAuth = function (user) {
                const form = document.querySelector('[data-telegram-login-form]');

                if (!form || !user) {
                    return;
                }

                ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash'].forEach((field) => {
                    const input = form.querySelector(`[name="${field}"]`);

                    if (input) {
                        input.value = user[field] ?? '';
                    }
                });

                form.submit();
            };
        </script>
    @endpush
@endif

@extends('static.layout')

@section('title', 'Вхід | DOVIRA')
@section('body_class', 'page-auth')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/auth.css') }}">
@endpush

@section('content')
    <section class="auth-shell">
        <div class="container">
            <div class="auth-wrap">
                <div class="auth-card">
                    <div class="auth-head">
                        <h1 class="auth-title">Увійти</h1>
                        <p class="auth-subtitle">
                            Ще не маєте акаунта?
                            <a href="{{ route('register', request()->query('next') ? ['next' => request()->query('next')] : []) }}" data-auth-transition-link>Зареєструйтесь</a>
                        </p>
                    </div>

                    @if (session('status'))
                        <p class="auth-status">{{ session('status') }}</p>
                    @endif

                    @if ($errors->any())
                        <div class="auth-global-errors">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @include('auth.partials.social-auth', ['actionLabel' => 'Увійти'])

                    <div class="auth-divider">Або</div>

                    <form method="POST" action="{{ route('login') }}" class="auth-form">
                        @csrf
                        @if (request()->query('next'))
                            <input type="hidden" name="next" value="{{ request()->query('next') }}">
                        @endif

                        <div class="auth-group">
                            <label for="email" class="auth-label">Email</label>
                            <input id="email" name="email" type="email" class="auth-input" value="{{ old('email') }}" required autofocus autocomplete="username">
                            @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="auth-group">
                            <div class="auth-row">
                                <label for="password" class="auth-label">Пароль</label>
                                @if (Route::has('password.request'))
                                    <a href="{{ route('password.request') }}" class="auth-link">Забули пароль?</a>
                                @endif
                            </div>
                            <input id="password" name="password" type="password" class="auth-input" required autocomplete="current-password">
                            @error('password')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        {{-- Адаптивна капча: зʼявляється лише після кількох невдалих спроб
                             входу з цього IP (див. LoginRequest::captchaRequiredForIp). --}}
                        @if (($captchaRequired ?? false) && \App\Support\Turnstile::isEnabled())
                            <div class="auth-group auth-group--captcha">
                                {{-- Turnstile сам додає приховане поле cf-turnstile-response у форму. --}}
                                <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-language="uk"></div>
                            </div>
                        @endif

                        <button type="submit" class="auth-submit">Увійти</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection

@include('auth.partials.interactions')

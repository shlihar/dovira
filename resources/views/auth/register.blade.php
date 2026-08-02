@extends('static.layout')

@section('title', 'Реєстрація | DOVIRA')
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
                        <h1 class="auth-title">Реєстрація</h1>
                        <p class="auth-subtitle">
                            Вже маєте акаунт?
                            <a href="{{ route('login', request()->query('next') ? ['next' => request()->query('next')] : []) }}" data-auth-transition-link>Увійти</a>
                        </p>
                    </div>

                    @if ($errors->any())
                        <div class="auth-global-errors">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @include('auth.partials.social-auth', ['actionLabel' => 'Зареєструватися'])

                    <div class="auth-divider">Або</div>

                    <form method="POST" action="{{ route('register') }}" class="auth-form">
                        @csrf
                        @if (request()->query('next'))
                            <input type="hidden" name="next" value="{{ request()->query('next') }}">
                        @endif

                        <div class="auth-group">
                            <label for="name" class="auth-label">Ім’я</label>
                            <input id="name" name="name" type="text" class="auth-input" value="{{ old('name') }}" required autocomplete="name">
                            @error('name')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="auth-group">
                            <label for="email" class="auth-label">Email</label>
                            <input id="email" name="email" type="email" class="auth-input" value="{{ old('email') }}" required autocomplete="username">
                            @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="auth-group">
                            <label for="password" class="auth-label">Пароль</label>
                            <input id="password" name="password" type="password" class="auth-input" required autocomplete="new-password">
                            @error('password')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="auth-group">
                            <label for="password_confirmation" class="auth-label">Підтвердіть пароль</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" class="auth-input" required autocomplete="new-password">
                            @error('password_confirmation')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <label class="auth-checkbox" for="terms">
                            <input id="terms" type="checkbox" required>
                            <span>Я приймаю умови використання платформи</span>
                        </label>

                        <button type="submit" class="auth-submit">Створити акаунт</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection

@include('auth.partials.interactions')

@extends('static.layout')

@section('title', 'Підтвердження email | DOVIRA')
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
                        <h1 class="auth-title">Підтвердіть email</h1>
                        <p class="auth-subtitle">
                            Ми надіслали лист із посиланням для підтвердження на
                            <strong>{{ auth()->user()?->email }}</strong>.
                            Перейдіть за ним, щоб завершити реєстрацію.
                        </p>
                    </div>

                    @if (session('status') == 'verification-link-sent')
                        <p class="auth-status">Новий лист із посиланням надіслано на вашу адресу.</p>
                    @endif
                    @if (session('status') == 'verification-link-failed')
                        <p class="auth-error">Не вдалося надіслати лист. Перевірте поштові налаштування або спробуйте пізніше.</p>
                    @endif

                    <form method="POST" action="{{ route('verification.send') }}" class="auth-form">
                        @csrf
                        <button type="submit" class="auth-submit">Надіслати лист ще раз</button>
                    </form>

                    <form method="POST" action="{{ route('logout') }}" class="auth-form" style="margin-top: 10px;">
                        @csrf
                        <button type="submit" class="auth-submit auth-submit--ghost">Вийти з акаунта</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection

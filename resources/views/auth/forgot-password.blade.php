@extends('static.layout')

@section('title', 'Відновлення паролю | DOVIRA')
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
                        <h1 class="auth-title">Відновлення паролю</h1>
                        <p class="auth-subtitle">
                            Згадали пароль?
                            <a href="{{ route('login') }}">Увійти</a>
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

                    <form method="POST" action="{{ route('password.email') }}" class="auth-form">
                        @csrf

                        <div class="auth-group">
                            <label for="email" class="auth-label">Email</label>
                            <input id="email" name="email" type="email" class="auth-input" value="{{ old('email') }}" required autofocus autocomplete="username">
                            @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="auth-submit">Надіслати лист для скидання</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection

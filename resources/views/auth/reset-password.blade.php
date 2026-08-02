@extends('static.layout')

@section('title', 'Новий пароль | DOVIRA')
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
                        <h1 class="auth-title">Новий пароль</h1>
                        <p class="auth-subtitle">
                            Після оновлення паролю ви зможете увійти в акаунт.
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

                    <form method="POST" action="{{ route('password.store') }}" class="auth-form">
                        @csrf
                        <input type="hidden" name="token" value="{{ $request->route('token') }}">

                        <div class="auth-group">
                            <label for="email" class="auth-label">Email</label>
                            <input id="email" name="email" type="email" class="auth-input" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username">
                            @error('email')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="auth-group">
                            <label for="password" class="auth-label">Новий пароль</label>
                            <input id="password" name="password" type="password" class="auth-input" required autocomplete="new-password">
                            @error('password')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="auth-group">
                            <label for="password_confirmation" class="auth-label">Підтвердіть пароль</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" class="auth-input" required autocomplete="new-password">
                            @error('password_confirmation')<p class="auth-error">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="auth-submit">Оновити пароль</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection

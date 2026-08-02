@extends('static.layout')

@section('title', 'Обране — збережені профілі | DOVIRA')
@section('description', 'Ваші збережені профілі компаній і спеціалістів на DOVIRA.')
@section('body_class', 'page-favorites')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/profile-card-catalog.css') }}?v={{ @filemtime(public_path('static/css/profile-card-catalog.css')) }}">
    <link rel="stylesheet" href="{{ asset('static/css/pages/favorites.css') }}?v={{ @filemtime(public_path('static/css/pages/favorites.css')) }}">
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
@php
    $favoriteProfiles = $favoriteProfiles ?? [];
    $profileUrl = fn (string $slug) => route('profile.show', ['slug' => $slug]);
    $hasServerCards = count($favoriteProfiles) > 0;
@endphp
<section class="section favorites-page">
    <div class="container">
        <div class="favorites-head">
            <h1><i class="fa-solid fa-bookmark" aria-hidden="true"></i> Обране</h1>
            <p>Збережені профілі компаній і спеціалістів — щоб швидко повернутись і порівняти.</p>
            @guest
                <p class="favorites-head__note">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    Список зберігається у цьому браузері. <a href="{{ route('login', ['next' => route('favorites.index')]) }}">Увійдіть</a>, щоб мати його на всіх пристроях.
                </p>
            @endguest
        </div>

        <div class="favorites-grid" data-favorites-grid @unless ($hasServerCards || ! auth()->check()) hidden @endunless>
            @foreach ($favoriteProfiles as $profile)
                @include('static.partials.profile-card-catalog', ['profile' => $profile, 'profileUrl' => $profileUrl])
            @endforeach
        </div>

        <div class="favorites-empty" data-favorites-empty @if ($hasServerCards) hidden @endif>
            <span class="favorites-empty__icon"><i class="fa-regular fa-bookmark" aria-hidden="true"></i></span>
            <strong>В обраному поки порожньо</strong>
            <p>Натискайте закладку на картці профілю в каталозі — і він з'явиться тут.</p>
            <a class="btn btn--primary" href="{{ route('catalog') }}">
                <span>Перейти в каталог</span>
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>
@endsection

@extends('static.layout')

@section('title', 'Особистий кабінет | DOVIRA')
@section('body_class', 'page-account')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/account.css') }}">
    {{-- Стилі PRO-кабінету для секційних форм налаштувань (.pro-profile-section,
         .pro-profile-live-field, .pro-notifications-switch). Компонентні правила
         .pro-* не прив'язані до .page-pro-account, тож безпечні для цієї сторінки. --}}
    <link rel="stylesheet" href="{{ asset('static/css/pages/pro-account.css') }}">
@endpush

@section('content')
    <section class="account-shell section">
        <div class="container account-layout">
            <div class="account-mobile-overlay" data-account-menu-overlay></div>
            <aside class="account-sidebar card" data-account-sidebar id="account-sidebar">
                <h1 class="account-sidebar__title">Мій кабінет</h1>
                <button class="account-sidebar__close" type="button" aria-label="Закрити меню" data-account-menu-close>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
                @php
                    $activeTab = $tab === 'profile' ? 'dashboard' : $tab;
                @endphp
                <nav class="account-nav pro-account-nav">
                    <button type="button" class="account-nav__item {{ $activeTab === 'dashboard' ? 'is-active' : '' }}" data-account-tab-trigger data-account-tab-target="dashboard">
                        <span class="account-nav__icon"><i class="fa-solid fa-house"></i></span>
                        <span class="account-nav__text">Профіль</span>
                    </button>
                    <button type="button" class="account-nav__item {{ $activeTab === 'reviews' ? 'is-active' : '' }}" data-account-tab-trigger data-account-tab-target="reviews">
                        <span class="account-nav__icon"><i class="fa-solid fa-comments"></i></span>
                        <span class="account-nav__text">Мої відгуки</span>
                    </button>
                    <button type="button" class="account-nav__item {{ $activeTab === 'notifications' ? 'is-active' : '' }}" data-account-tab-trigger data-account-tab-target="notifications">
                        <span class="account-nav__icon account-nav__icon--with-badge">
                            <i class="fa-solid fa-bell"></i>
                            @if ($notifications->count() > 0)
                                <span class="account-nav__badge">{{ min($notifications->count(), 99) }}</span>
                            @endif
                        </span>
                        <span class="account-nav__text">Сповіщення</span>
                    </button>
                    <button type="button" class="account-nav__item {{ $activeTab === 'saved' ? 'is-active' : '' }}" data-account-tab-trigger data-account-tab-target="saved">
                        <span class="account-nav__icon"><i class="fa-solid fa-heart"></i></span>
                        <span class="account-nav__text">Обране</span>
                    </button>
                    <button type="button" class="account-nav__item {{ $activeTab === 'settings' ? 'is-active' : '' }}" data-account-tab-trigger data-account-tab-target="settings">
                        <span class="account-nav__icon"><i class="fa-solid fa-gear"></i></span>
                        <span class="account-nav__text"><span class="account-nav__text-full">Налаштування</span><span class="account-nav__text-compact">Опції</span></span>
                    </button>
                </nav>
                <a class="user-menu__pro-btn account-sidebar__pro-btn" href="{{ route('pro.account') }}">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    <span>Відкрити PRO кабінет</span>
                </a>
            </aside>

            <div class="account-main">
                @php
                    $avatar = trim((string) ($user->avatar_url ?? ''));
                    $avatarUrl = $avatar !== '' ? (str_starts_with($avatar, 'http://') || str_starts_with($avatar, 'https://') ? $avatar : asset(ltrim($avatar, '/'))) : null;
                    $tabTitle = match ($tab) {
                        'reviews' => 'Мої відгуки',
                        'notifications' => 'Сповіщення',
                        'saved' => 'Обране',
                        'settings' => 'Налаштування',
                        default => 'Профіль',
                    };
                @endphp

                <div class="account-mobile-toolbar">
                    <button class="account-mobile-menu-btn" type="button" data-account-menu-toggle aria-expanded="false" aria-controls="account-sidebar">
                        <i class="fa-solid fa-bars" aria-hidden="true"></i>
                        <span>Меню</span>
                    </button>
                    <p class="account-mobile-toolbar__title" data-account-tab-title>{{ $tabTitle }}</p>
                </div>

                @php
                    $statusMessages = [
                        'profile-updated' => 'Профіль оновлено.',
                        'password-updated' => 'Пароль успішно оновлено.',
                        'review-updated' => 'Відгук оновлено і відправлено на модерацію.',
                        'review-withdrawn' => 'Відгук відкликано.',
                    ];
                @endphp
                @if (session('status'))
                    <p class="account-alert account-alert--success">{{ $statusMessages[session('status')] ?? session('status') }}</p>
                @endif
                @if ($errors->any())
                    <div class="account-alert account-alert--error">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="account-tab-panel" data-account-tab-panel="dashboard" @if ($activeTab !== 'dashboard') hidden @endif>
                    <section class="pro-profile-editor account-dashboard">
                        <section class="card account-hero account-user-card">
                            <div class="account-hero__avatar {{ $avatarUrl ? '' : 'is-fallback' }}">
                                @if ($avatarUrl)
                                    <img src="{{ $avatarUrl }}" alt="{{ $user->name }}">
                                @else
                                    <i class="fa-solid fa-user"></i>
                                @endif
                            </div>
                            <div>
                                <h2 class="account-hero__name">{{ $user->name }}</h2>
                                <p class="account-hero__email">{{ $user->email }}</p>
                            </div>
                        </section>

                        <section class="pro-overview-metrics account-dashboard-metrics">
                            <article class="card pro-overview-metric-card tone-blue">
                                <span class="pro-overview-metric-card__icon"><i class="fa-solid fa-pen-nib" aria-hidden="true"></i></span>
                                <div class="pro-overview-metric-card__content">
                                    <div class="pro-overview-metric-card__value-row"><strong>{{ $allReviewsCount }}</strong></div>
                                    <p>відгуків написано</p>
                                </div>
                            </article>
                            <article class="card pro-overview-metric-card tone-gold">
                                <span class="pro-overview-metric-card__icon"><i class="fa-solid fa-clock" aria-hidden="true"></i></span>
                                <div class="pro-overview-metric-card__content">
                                    <div class="pro-overview-metric-card__value-row"><strong>{{ $pendingReviewsCount }}</strong></div>
                                    <p>на модерації</p>
                                </div>
                            </article>
                            <article class="card pro-overview-metric-card tone-green">
                                <span class="pro-overview-metric-card__icon"><i class="fa-solid fa-reply" aria-hidden="true"></i></span>
                                <div class="pro-overview-metric-card__content">
                                    <div class="pro-overview-metric-card__value-row"><strong>{{ $repliedCount }}</strong></div>
                                    <p>відповідей від профілів</p>
                                </div>
                            </article>
                            <article class="card pro-overview-metric-card tone-violet">
                                <span class="pro-overview-metric-card__icon"><i class="fa-solid fa-heart" aria-hidden="true"></i></span>
                                <div class="pro-overview-metric-card__content">
                                    <div class="pro-overview-metric-card__value-row"><strong>{{ $savedProfilesCount }}</strong></div>
                                    <p>збережених профілів</p>
                                </div>
                            </article>
                        </section>
                    </section>
                </div>

                <div class="account-tab-panel" data-account-tab-panel="reviews" @if ($activeTab !== 'reviews') hidden @endif>
                    <section class="pro-profile-editor account-reviews-editor">
                        <div class="account-reviews">
                            @forelse ($reviews as $review)
                                @php
                                    $statusLabel = match ($review->status) {
                                        'published' => 'Опубліковано',
                                        'pending' => 'На модерації',
                                        'rejected' => 'Відхилено',
                                        'withdrawn' => 'Відкликано',
                                        default => 'Змінено',
                                    };
                                    $statusClass = match ($review->status) {
                                        'published' => 'is-published',
                                        'pending' => 'is-pending',
                                        'rejected' => 'is-rejected',
                                        default => 'is-muted',
                                    };
                                    $reviewName = $review->profile?->name ?? 'Профіль видалено';
                                @endphp
                                <article class="card account-review-item">
                                    <div class="account-review-item__top">
                                        <h3 class="account-review-item__title">{{ $reviewName }}</h3>
                                        <span class="account-status {{ $statusClass }}">{{ $statusLabel }}</span>
                                    </div>
                                    <div class="account-review-item__meta">
                                        <span class="account-review-item__rating"><i class="fa-solid fa-star" aria-hidden="true"></i> {{ number_format((float) $review->rating, 1) }}</span>
                                        <span class="account-review-item__sep" aria-hidden="true">•</span>
                                        <span>{{ optional($review->created_at)->format('d.m.Y') }}</span>
                                    </div>
                                    <p class="account-review-item__text">{{ \Illuminate\Support\Str::limit($review->body, 220) }}</p>
                                    <div class="account-review-item__actions">
                                        @if ($review->profile?->slug)
                                            <a class="btn btn--ghost account-btn--mini" href="{{ route('profile.show', ['slug' => $review->profile->slug]) }}"><i class="fa-solid fa-up-right-from-square"></i>Переглянути</a>
                                            <button
                                                type="button"
                                                class="btn btn--ghost account-btn--mini"
                                                data-open-review-popup
                                                data-review-mode="edit"
                                                data-review-id="{{ $review->id }}"
                                                data-profile-slug="{{ $review->profile->slug }}"
                                                data-profile-name="{{ $review->profile->name }}"
                                                data-review-rating="{{ (int) $review->rating }}"
                                                data-review-body="{{ e($review->body) }}"
                                            >
                                                <i class="fa-solid fa-pen"></i>Редагувати
                                            </button>
                                        @endif
                                        {{-- Кошик показуємо лише коли є що відкликати:
                                             published відкликати не можна, а withdrawn уже відкликано. --}}
                                        @if (in_array($review->status, ['pending', 'rejected'], true))
                                            <form class="account-review-item__delete" method="POST" action="{{ route('profile.reviews.withdraw', $review) }}" onsubmit="return confirm('Відкликати цей відгук?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="account-icon-btn account-icon-btn--danger" aria-label="{{ $review->status === 'pending' ? 'Видалити запит' : 'Відкликати' }}">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <div class="card account-empty-state">
                                    <span class="account-empty-state__icon"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i></span>
                                    <p>У вас ще немає відгуків.</p>
                                    <a class="btn btn--primary account-btn--mini" href="{{ route('catalog') }}"><i class="fa-solid fa-magnifying-glass"></i>Знайти компанію</a>
                                </div>
                            @endforelse
                        </div>

                        <div class="account-pagination">{{ $reviews->links() }}</div>
                    </section>
                </div>

                <div class="account-tab-panel" data-account-tab-panel="notifications" @if ($activeTab !== 'notifications') hidden @endif>
                    <section class="pro-profile-editor">
                        <section class="card pro-notifications-card">
                            <div class="pro-notifications-list">
                                @forelse ($notifications as $note)
                                    <article class="pro-notification-item tone-info">
                                        <div class="pro-notification-item__dot" aria-hidden="true"></div>
                                        <div class="pro-notification-item__body">
                                            <div class="pro-notification-item__top">
                                                <h3>{{ $note['title'] }}</h3>
                                                <time>{{ optional($note['date'])->format('d.m.Y H:i') }}</time>
                                            </div>
                                            <p>{{ $note['text'] }}</p>
                                        </div>
                                    </article>
                                @empty
                                    <p class="account-empty">Поки немає сповіщень.</p>
                                @endforelse
                            </div>
                        </section>
                    </section>
                </div>

                <div class="account-tab-panel" data-account-tab-panel="saved" @if ($activeTab !== 'saved') hidden @endif>
                    <section class="pro-profile-editor">
                        <div class="card account-empty-state">
                            <span class="account-empty-state__icon"><i class="fa-regular fa-heart" aria-hidden="true"></i></span>
                            <p>Поки що список обраного порожній.</p>
                            <a class="btn btn--primary account-btn--mini" href="{{ route('catalog') }}"><i class="fa-solid fa-magnifying-glass"></i>Знайти компанію</a>
                        </div>
                    </section>
                </div>

                <div class="account-tab-panel" data-account-tab-panel="settings" @if ($activeTab !== 'settings') hidden @endif>
                    <section class="pro-profile-editor account-settings-editor">
                        {{-- Особисті дані --}}
                        <form method="POST" action="{{ route('profile.update') }}" class="card account-block pro-account-form pro-profile-form-card">
                            @csrf
                            @method('PATCH')
                            <section class="pro-profile-section">
                                <div class="pro-profile-section__head">
                                    <h3><i class="fa-solid fa-user" aria-hidden="true"></i> Особисті дані</h3>
                                </div>

                                <div class="pro-profile-section__view">
                                    <div class="pro-profile-live-grid">
                                        <label class="pro-profile-live-field">
                                            <span>Ім’я</span>
                                            <input type="text" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name">
                                        </label>
                                        <label class="pro-profile-live-field">
                                            <span>Email</span>
                                            <input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email">
                                        </label>
                                        <label class="pro-profile-live-field pro-profile-live-field--wide">
                                            <span>Фото (URL)</span>
                                            <input type="text" name="avatar_url" value="{{ old('avatar_url', $user->avatar_url) }}" placeholder="https://...">
                                        </label>
                                    </div>

                                    <label class="pro-notifications-switch pro-profile-live-field--wide account-settings-switch">
                                        <div class="pro-notifications-switch__copy">
                                            <strong>Email-сповіщення</strong>
                                            <span>Отримувати листи про відповіді на відгуки та важливі події акаунта.</span>
                                        </div>
                                        <span class="pro-notifications-switch__control">
                                            <input type="checkbox" name="email_notifications_enabled" value="1" @checked((bool) old('email_notifications_enabled', $user->email_notifications_enabled))>
                                            <span class="pro-notifications-switch__track"></span>
                                        </span>
                                    </label>
                                </div>

                                <div class="pro-profile-section__foot">
                                    <button type="submit" class="btn btn--primary account-btn--mini pro-profile-section__save">
                                        <i class="fa-solid fa-check" aria-hidden="true"></i><span>Зберегти</span>
                                    </button>
                                </div>
                            </section>
                        </form>

                        {{-- Зміна пароля --}}
                        <form method="POST" action="{{ route('password.update') }}" class="card account-block pro-account-form pro-profile-form-card">
                            @csrf
                            @method('PUT')
                            <section class="pro-profile-section">
                                <div class="pro-profile-section__head">
                                    <h3><i class="fa-solid fa-lock" aria-hidden="true"></i> Зміна пароля</h3>
                                </div>

                                <div class="pro-profile-section__view">
                                    <div class="pro-profile-live-grid">
                                        <label class="pro-profile-live-field pro-profile-live-field--wide">
                                            <span>Поточний пароль</span>
                                            <input type="password" name="current_password" required autocomplete="current-password">
                                        </label>
                                        <label class="pro-profile-live-field">
                                            <span>Новий пароль</span>
                                            <input type="password" name="password" required autocomplete="new-password">
                                        </label>
                                        <label class="pro-profile-live-field">
                                            <span>Підтвердіть пароль</span>
                                            <input type="password" name="password_confirmation" required autocomplete="new-password">
                                        </label>
                                    </div>
                                </div>

                                <div class="pro-profile-section__foot">
                                    <button type="submit" class="btn btn--primary account-btn--mini pro-profile-section__save">
                                        <i class="fa-solid fa-key" aria-hidden="true"></i><span>Оновити</span>
                                    </button>
                                </div>
                            </section>
                        </form>

                        {{-- Видалення акаунту --}}
                        <form method="POST" action="{{ route('profile.destroy') }}" class="card account-block pro-account-form pro-profile-form-card account-danger-zone">
                            @csrf
                            @method('DELETE')
                            <section class="pro-profile-section">
                                <div class="pro-profile-section__head">
                                    <h3><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Видалення акаунту</h3>
                                </div>

                                <div class="pro-profile-section__view">
                                    <p class="account-danger-zone__note">
                                        Акаунт буде видалено назавжди разом із доступом до кабінету. Профілі, які ви створили чи привʼязали, залишаться в каталозі, але втратять звʼязок із вами та статус PRO. Цю дію не можна скасувати.
                                    </p>
                                    <label class="pro-profile-live-field pro-profile-live-field--wide">
                                        <span>Підтвердіть паролем</span>
                                        <input type="password" name="password" required autocomplete="current-password" placeholder="Ваш поточний пароль">
                                        @error('password', 'userDeletion')<small class="claim-wizard__field-error">{{ $message }}</small>@enderror
                                    </label>
                                </div>

                                <div class="pro-profile-section__foot">
                                    <button type="submit" class="btn btn--danger account-btn--mini pro-profile-section__save" onclick="return confirm('Видалити акаунт назавжди? Цю дію не можна скасувати.')">
                                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span>Видалити акаунт</span>
                                    </button>
                                </div>
                            </section>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </section>
@endsection

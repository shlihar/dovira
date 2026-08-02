@php
    $reviewAuthor = $review->external_review_author ?: ($review->author_name ?: 'Користувач DOVIRA');
    $reviewAvatarUrl = \App\Support\MediaUrl::avatarUrl(
        $review->external_review_author_avatar_url ?: $review->author?->avatar_url,
        $review->author_name ?: $review->author?->name,
        96
    );
    $reviewAvatarInitial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($reviewAuthor, 0, 1));
    $reviewDate = $review->external_review_date ?: ($review->published_at ?: $review->created_at);
    $reviewDateLabel = $reviewDate ? $reviewDate->translatedFormat('d F Y') : 'Без дати';
    $reviewTimeLabel = $review->created_at ? $review->created_at->format('H:i') : null;
    $reviewSourceLabel = match ($review->external_source_type) {
        'google' => 'Відгук з Google',
        'facebook' => 'Відгук з Facebook',
        default => $review->external_source_type ? 'Зовнішній відгук' : 'Відгук на DOVIRA',
    };
    $reviewFull = (int) floor((float) $review->rating);
    $reviewEmpty = max(0, 5 - $reviewFull);
    $reviewText = trim((string) ($review->body ?: $review->title));
@endphp

<div class="pro-reviews-detail {{ $review->status === 'hidden' ? 'is-hidden-review' : '' }}">
    <div class="pro-reviews-detail__head">
        <h2>Відгук</h2>
        <button type="button" class="account-icon-btn pro-reviews-detail__close" data-review-detail-close aria-label="Закрити деталі">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>

    <div class="pro-reviews-detail__reviewer">
        <div class="pro-reviews-avatar {{ $reviewAvatarUrl ? '' : 'has-random-gradient' }}" data-review-avatar data-seed="{{ $reviewAuthor }}">
            @if ($reviewAvatarUrl)
                <img src="{{ $reviewAvatarUrl }}" alt="{{ $reviewAuthor }}" loading="lazy" data-review-avatar-image>
            @endif
            <span class="pro-review-avatar-fallback" data-review-avatar-fallback @if ($reviewAvatarUrl) hidden @endif>{{ $reviewAvatarInitial }}</span>
        </div>
        <div>
            <strong>{{ $reviewAuthor }}</strong>
            <span class="pro-reviews-source">
                @if ($review->external_source_type === 'google')
                    <b aria-hidden="true">G</b>
                @else
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                @endif
                {{ $reviewSourceLabel }}
            </span>
            <time datetime="{{ optional($reviewDate)->toDateString() }}">{{ $reviewDateLabel }} @if($reviewTimeLabel) · {{ $reviewTimeLabel }} @endif</time>
        </div>
    </div>

    <div class="pro-reviews-detail__rating">
        <div class="rating-stars">
            @for ($i = 0; $i < $reviewFull; $i++)
                <i class="fa-solid fa-star" aria-hidden="true"></i>
            @endfor
            @for ($i = 0; $i < $reviewEmpty; $i++)
                <i class="fa-regular fa-star" aria-hidden="true"></i>
            @endfor
        </div>
        <strong>{{ number_format((float) $review->rating, 1) }}</strong>
    </div>

    <p class="pro-reviews-detail__text">{{ $reviewText ?: 'Текст відгуку відсутній.' }}</p>

    <dl class="pro-reviews-detail__meta">
        @if ($currentProfile?->city)
            <div>
                <dt><i class="fa-solid fa-location-dot" aria-hidden="true"></i></dt>
                <dd>{{ $currentProfile->city }}</dd>
            </div>
        @endif
        <div>
            <dt><i class="fa-solid fa-briefcase" aria-hidden="true"></i></dt>
            <dd>{{ $currentProfile->name }}</dd>
        </div>
    </dl>

    <form method="POST" action="{{ route('pro.account.reviews.reply', $review) }}" class="pro-reviews-reply-form" data-review-reply-form data-review-id="{{ $review->id }}">
        @csrf
        <input type="hidden" name="review_id" value="{{ $review->id }}">
        <div class="pro-reviews-reply-form__top">
            <span>Ваша офіційна відповідь</span>
            <small>Залишилось <output data-review-reply-left>1000</output> символів</small>
        </div>
        <textarea name="body" rows="5" maxlength="1000" placeholder="Напишіть вашу відповідь клієнту..." data-review-reply-text @disabled(!($canManageReviewModeration ?? false))>{{ (string) old('review_id') === (string) $review->id ? old('body') : $review->officialReply?->body }}</textarea>

        @if ($canManageReviewModeration ?? false)
            <button type="submit" class="btn btn--primary pro-reviews-detail__primary">
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                <span>{{ $review->officialReply ? 'Оновити відповідь' : 'Опублікувати відповідь' }}</span>
            </button>
        @else
            <button type="button" class="btn btn--primary pro-reviews-detail__primary" data-pro-tab-trigger data-pro-tab-target="billing">
                <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                <span>Підключити PRO для відповіді</span>
            </button>
            <p class="pro-account-field-note">Офіційні відповіді та приховування відгуків доступні після активації PRO.</p>
        @endif
    </form>

    @if ($canManageReviewModeration ?? false)
        <form method="POST" action="{{ route('pro.account.reviews.visibility', $review) }}" data-review-visibility-form data-review-id="{{ $review->id }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="status" value="{{ $review->status === 'hidden' ? 'published' : 'hidden' }}">
            <button type="submit" class="btn btn--ghost pro-reviews-detail__danger">
                <i class="fa-solid {{ $review->status === 'hidden' ? 'fa-eye' : 'fa-eye-slash' }}" aria-hidden="true"></i>
                <span>{{ $review->status === 'hidden' ? 'Показати відгук' : 'Сховати відгук' }}</span>
            </button>
        </form>
    @endif

    @php
        $hasOpenReport = \App\Models\ReviewReport::query()
            ->where('profile_review_id', $review->id)
            ->where('status', 'open')
            ->exists();
    @endphp
    <div class="pro-reviews-report" data-review-report-block>
        @if ($hasOpenReport)
            <p class="pro-reviews-report__sent">
                <i class="fa-regular fa-flag" aria-hidden="true"></i>
                Скаргу надіслано — модерація розгляне її найближчим часом.
            </p>
        @else
            <button type="button" class="btn btn--ghost pro-reviews-detail__report-toggle" data-review-report-toggle>
                <i class="fa-regular fa-flag" aria-hidden="true"></i>
                <span>Поскаржитись на відгук</span>
            </button>
            <form method="POST" action="{{ route('pro.account.reviews.report', $review) }}" class="pro-reviews-report-form" data-review-report-form data-review-id="{{ $review->id }}" hidden>
                @csrf
                <label>
                    <span>Причина скарги</span>
                    <select name="reason" required>
                        <option value="Неправдива інформація">Неправдива інформація</option>
                        <option value="Образливий вміст">Образливий вміст</option>
                        <option value="Спам або реклама">Спам або реклама</option>
                        <option value="Замовний або фейковий відгук">Замовний або фейковий відгук</option>
                        <option value="Інше">Інше</option>
                    </select>
                </label>
                <label>
                    <span>Деталі (необовʼязково)</span>
                    <textarea name="details" rows="3" maxlength="1000" placeholder="Опишіть, що саме не так із цим відгуком."></textarea>
                </label>
                <button type="submit" class="btn btn--primary">
                    <i class="fa-regular fa-flag" aria-hidden="true"></i>
                    <span>Надіслати скаргу</span>
                </button>
            </form>
        @endif
    </div>
</div>

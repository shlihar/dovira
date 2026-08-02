@php
    $catalogProfileUrl = $catalogProfileUrl ?? fn (string $slug) => route('profile.show', [
        'slug' => $slug,
    ]);
    $categoryUrl = $categoryUrl ?? fn (?string $category) => route('catalog', [
        'category' => $category ?: null,
    ]);
@endphp

@php
    $currentPage = method_exists($profiles, 'currentPage') ? (int) $profiles->currentPage() : 1;
    $itemsCount = $profiles->getCollection()->count();
    $perPage = method_exists($profiles, 'perPage') ? (int) $profiles->perPage() : $itemsCount;
    $loadedCount = $itemsCount > 0 ? (($currentPage - 1) * $perPage) + $itemsCount : 0;
    $hasMorePages = method_exists($profiles, 'hasMorePages') ? $profiles->hasMorePages() : false;
    $nextPageUrl = $hasMorePages && method_exists($profiles, 'nextPageUrl') ? $profiles->nextPageUrl() : null;
    $totalCount = method_exists($profiles, 'total') ? (int) $profiles->total() : null;
    $loadbarLabel = $loadedCount === 0
        ? 'Нічого не знайдено'
        : ($totalCount
            ? 'Показано ' . $loadedCount . ' з ' . $totalCount
            : 'Показано ' . $loadedCount . ($hasMorePages ? '+ профілів' : ' профілів'));
@endphp

<div
    class="catalog-results-stack"
    data-catalog-infinite
    data-loaded-count="{{ $loadedCount }}"
    data-total-count="{{ $totalCount ?? '' }}"
    data-has-more="{{ $hasMorePages ? 1 : 0 }}"
    data-next-url="{{ $nextPageUrl ?? '' }}"
    data-current-page="{{ $currentPage }}"
>
    <div class="reviews-list" data-catalog-results-list>
        @include('static.partials.catalog-results-items', [
            'profiles' => $profiles,
            'catalogProfileUrl' => $catalogProfileUrl,
        ])
    </div>

    <div class="catalog-results-append-skeleton is-hidden" data-catalog-append-skeleton aria-hidden="true">
        @for ($i = 0; $i < 3; $i++)
            <article class="catalog-skeleton-card" aria-hidden="true">
                <div class="catalog-skeleton-card__head">
                    <span class="catalog-skeleton-card__avatar"></span>
                    <div class="catalog-skeleton-card__meta">
                        <span class="catalog-skeleton-card__line line-lg"></span>
                        <span class="catalog-skeleton-card__line line-md"></span>
                    </div>
                </div>
                <div class="catalog-skeleton-card__rating">
                    <span class="catalog-skeleton-card__line line-xs"></span>
                    <span class="catalog-skeleton-card__line line-sm"></span>
                </div>
                <div class="catalog-skeleton-card__body">
                    <span class="catalog-skeleton-card__line line-md"></span>
                    <span class="catalog-skeleton-card__line line-lg"></span>
                    <span class="catalog-skeleton-card__line line-full"></span>
                    <span class="catalog-skeleton-card__line line-mid"></span>
                </div>
            </article>
        @endfor
    </div>

    <div class="catalog-infinite" data-catalog-loadbar>
        <p class="catalog-infinite__count" data-catalog-infinite-count>{{ $loadbarLabel }}</p>
        <button
            type="button"
            class="catalog-infinite__button @if(!$hasMorePages) is-hidden @endif"
            data-catalog-load-more
        >
            Показати ще
        </button>
    </div>

    <div class="catalog-infinite__sentinel" data-catalog-load-sentinel aria-hidden="true"></div>
</div>

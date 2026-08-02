@php
    $currentQ = mb_strtolower((string) request('q'));
    $currentCategory = mb_strtolower((string) request('category'));
    $currentSub = mb_strtolower((string) request('sub'));
    $categoryFilters = $categoryFilters ?? [];
    $regionFilters = $regionFilters ?? ['Київ', 'Львів', 'Дніпро', 'Одеса', 'Харків'];

    $selectedCategories = collect((array) request()->query('categories', []))
        ->map(fn ($value) => mb_strtolower(trim((string) $value)))
        ->filter()
        ->values()
        ->all();
    if ($currentCategory !== '') {
        $selectedCategories[] = $currentCategory;
    }
    if ($currentSub !== '') {
        $selectedCategories = [$currentSub];
    }

    $selectedRegions = collect((array) request()->query('regions', []))
        ->map(fn ($value) => mb_strtolower(trim((string) $value)))
        ->filter()
        ->values()
        ->all();

    $selectedRating = (string) request()->query('rating', '');
    $selectedReviewsCount = (string) request()->query('reviews_count', '');
    $selectedStatus = (string) request()->query('status', '');
    $catalogCategoryUrl = function (?string $categoryName = null, ?string $subcategoryName = null): string {
        $query = request()->except('ajax', 'page', 'categories', 'category', 'sub');

        if ($categoryName !== null && $categoryName !== '') {
            $query['category'] = $categoryName;
        }

        if ($subcategoryName !== null && $subcategoryName !== '') {
            $query['sub'] = $subcategoryName;
        } else {
            unset($query['sub']);
        }

        return route('catalog', $query);
    };

    // Normalised category groups for the mega / drill-down filter.
    $catGroups = collect($categoryFilters)->values()->map(function ($ci, $i) use ($currentSub, $currentCategory, $currentQ) {
        $name = (string) ($ci['name'] ?? '');
        $value = mb_strtolower(trim($name));
        $kids = collect($ci['subs'] ?? [])
            ->filter(fn ($item) => is_array($item) && filled($item['name'] ?? null))
            ->map(fn ($item) => [
                'name' => (string) $item['name'],
                'count' => (int) ($item['count'] ?? 0),
                'active' => mb_strtolower((string) $item['name']) === $currentSub,
            ])
            ->values();

        $hasActiveKid = $kids->contains(fn ($k) => $k['active']);
        $isActive = ($currentSub !== '' && $currentSub === $value)
            || ($currentSub === '' && ($currentCategory === $value || ($currentQ !== '' && $currentQ === $value)));

        return [
            'name' => $name,
            'value' => $value,
            'key' => 'cg' . $i,
            'count' => (int) ($ci['count'] ?? 0),
            'kids' => $kids,
            'hasKids' => $kids->isNotEmpty(),
            'hasActiveKid' => $hasActiveKid,
            'isActive' => $isActive,
        ];
    })->values();

    $firstGroup = $catGroups->first(fn ($g) => $g['hasKids']);
    $activeGroup = $catGroups->first(fn ($g) => $g['hasActiveKid'])
        ?? $catGroups->first(fn ($g) => $g['isActive'] && $g['hasKids']);
    $megaActiveKey = $activeGroup['key'] ?? ($firstGroup['key'] ?? null);

    $selectedGroup = $catGroups->first(fn ($g) => $g['isActive'] || $g['hasActiveKid']);
    $selectedSubName = optional($selectedGroup)['kids'] ? collect($selectedGroup['kids'])->firstWhere('active', true)['name'] ?? '' : '';
    $categoryTriggerLabel = $selectedGroup
        ? ($selectedSubName !== '' ? $selectedSubName : $selectedGroup['name'])
        : 'Усі категорії';
    $hasCategorySelection = (bool) $selectedGroup;
@endphp

<div class="reviews-filters-accordion reviews-filters-minimal">
    <input type="hidden" name="category" value="{{ request('category') }}" data-filter-input>
    <input type="hidden" name="sub" value="{{ request('sub') }}" data-filter-input>

    <section class="minimal-filter-group cat-filter" data-cat-filter>
        <h4 class="minimal-filter-group__title">
            <span class="minimal-filter-group__title-icon" aria-hidden="true"><i class="fa-solid fa-briefcase"></i></span>
            <span>Категорія</span>
        </h4>

        <button type="button" class="cat-filter__trigger @if($hasCategorySelection) is-selected @endif" data-cat-trigger aria-haspopup="true" aria-expanded="false">
            <span class="cat-filter__trigger-main">
                <i class="fa-solid fa-layer-group cat-filter__trigger-icon" aria-hidden="true"></i>
                <span class="cat-filter__trigger-label">{{ $categoryTriggerLabel }}</span>
            </span>
            <i class="fa-solid fa-chevron-down cat-filter__trigger-caret" aria-hidden="true"></i>
        </button>

        <div class="cat-filter__mega" data-cat-mega hidden>
            <div class="cat-filter__mega-inner">
                <div class="cat-filter__cats" data-cat-cats>
                    <a href="{{ $catalogCategoryUrl() }}" class="cat-filter__cat cat-filter__cat--all @if(!$hasCategorySelection) is-selected @endif" data-catalog-category-link>
                        <span class="cat-filter__cat-name"><i class="fa-solid fa-grip cat-filter__cat-ico" aria-hidden="true"></i>Усі категорії</span>
                    </a>
                    @foreach ($catGroups as $group)
                        @if ($group['hasKids'])
                            <button
                                type="button"
                                class="cat-filter__cat @if($group['key'] === $megaActiveKey) is-active @endif @if($group['isActive'] || $group['hasActiveKid']) is-selected @endif"
                                data-cat-cat="{{ $group['key'] }}"
                                aria-expanded="false"
                            >
                                <span class="cat-filter__cat-name">{{ $group['name'] }}</span>
                                <span class="cat-filter__cat-meta">
                                    <small>{{ $group['count'] }}</small>
                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                </span>
                            </button>
                        @else
                            <a href="{{ $catalogCategoryUrl($group['name']) }}" class="cat-filter__cat cat-filter__cat--leaf @if($group['isActive']) is-selected @endif" data-catalog-category-link>
                                <span class="cat-filter__cat-name">{{ $group['name'] }}</span>
                                <span class="cat-filter__cat-meta"><small>{{ $group['count'] }}</small></span>
                            </a>
                        @endif
                    @endforeach
                </div>

                <div class="cat-filter__subs" data-cat-subs>
                    @foreach ($catGroups as $group)
                        @if ($group['hasKids'])
                            <div class="cat-filter__panel @if($group['key'] === $megaActiveKey) is-active @endif" data-cat-panel="{{ $group['key'] }}">
                                <button type="button" class="cat-filter__back" data-cat-back>
                                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                                    <span>{{ $group['name'] }}</span>
                                </button>
                                <a href="{{ $catalogCategoryUrl($group['name']) }}" class="cat-filter__sub cat-filter__sub--all @if($group['isActive']) is-selected @endif" data-catalog-category-link>
                                    <span>Усі: {{ $group['name'] }}</span>
                                    <small>{{ $group['count'] }}</small>
                                </a>
                                @foreach ($group['kids'] as $child)
                                    <a href="{{ $catalogCategoryUrl($group['name'], $child['name']) }}" class="cat-filter__sub @if($child['active']) is-selected @endif" data-catalog-category-link>
                                        <span>{{ $child['name'] }}</span>
                                        <small>{{ $child['count'] }}</small>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="minimal-filter-group cat-filter cat-filter--single" data-cat-filter>
        @php
            $selectedCity = collect($regionFilters)->first(fn ($r) => in_array(mb_strtolower($r), $selectedRegions, true));
            $cityTriggerLabel = $selectedCity ?: 'Усі міста';
            $hasCitySelection = (bool) $selectedCity;
        @endphp
        <h4 class="minimal-filter-group__title">
            <span class="minimal-filter-group__title-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
            <span>Місто</span>
        </h4>

        <button type="button" class="cat-filter__trigger @if($hasCitySelection) is-selected @endif" data-cat-trigger aria-haspopup="true" aria-expanded="false">
            <span class="cat-filter__trigger-main">
                <i class="fa-solid fa-location-dot cat-filter__trigger-icon" aria-hidden="true"></i>
                <span class="cat-filter__trigger-label">{{ $cityTriggerLabel }}</span>
            </span>
            <i class="fa-solid fa-chevron-down cat-filter__trigger-caret" aria-hidden="true"></i>
        </button>

        <div class="cat-filter__mega" data-cat-mega hidden>
            <div class="cat-filter__mega-inner">
                <div class="cat-filter__cats" data-cat-cats>
                    <button type="button" class="cat-filter__cat cat-filter__cat--all @if(!$hasCitySelection) is-selected @endif" data-cat-city-clear>
                        <span class="cat-filter__cat-name"><i class="fa-solid fa-grip cat-filter__cat-ico" aria-hidden="true"></i>Усі міста</span>
                    </button>
                    @foreach ($regionFilters as $region)
                        @php $isChecked = in_array(mb_strtolower($region), $selectedRegions, true); @endphp
                        <label class="cat-filter__cat cat-filter__cat--leaf cat-filter__city @if($isChecked) is-selected @endif">
                            <input type="checkbox" name="regions[]" value="{{ $region }}" @checked($isChecked) data-filter-input hidden>
                            <span class="cat-filter__cat-name">{{ $region }}</span>
                            <span class="cat-filter__cat-meta"><i class="fa-solid fa-check cat-filter__city-check" aria-hidden="true"></i></span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="minimal-filter-group">
        <h4 class="minimal-filter-group__title">
            <span class="minimal-filter-group__title-icon" aria-hidden="true"><i class="fa-solid fa-star"></i></span>
            <span>Рейтинг</span>
        </h4>
        <div class="minimal-filter-options">
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="rating" value="5.0" @checked($selectedRating === '5.0') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>5.0</span>
            </label>
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="rating" value="4.5+" @checked($selectedRating === '4.5+') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>4.5+</span>
            </label>
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="rating" value="4.0+" @checked($selectedRating === '4.0+') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>4.0+</span>
            </label>
        </div>
    </section>

    <section class="minimal-filter-group">
        <h4 class="minimal-filter-group__title">
            <span class="minimal-filter-group__title-icon" aria-hidden="true"><i class="fa-solid fa-comments"></i></span>
            <span>Кількість відгуків</span>
        </h4>
        <div class="minimal-filter-options">
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="reviews_count" value="10+" @checked($selectedReviewsCount === '10+') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>10+</span>
            </label>
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="reviews_count" value="25+" @checked($selectedReviewsCount === '25+') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>25+</span>
            </label>
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="reviews_count" value="50+" @checked($selectedReviewsCount === '50+') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>50+</span>
            </label>
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="reviews_count" value="100+" @checked($selectedReviewsCount === '100+') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>100+</span>
            </label>
        </div>
    </section>

    <section class="minimal-filter-group">
        <h4 class="minimal-filter-group__title">
            <span class="minimal-filter-group__title-icon" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
            <span>Статус</span>
        </h4>
        <div class="minimal-filter-options">
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="status" value="any" @checked($selectedStatus === 'any') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>Будь-який</span>
            </label>
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="status" value="owner_verified" @checked($selectedStatus === 'owner_verified') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>Підтверджений власником</span>
            </label>
            <label class="minimal-control minimal-control--checkbox">
                <input type="checkbox" name="status" value="recommended" @checked($selectedStatus === 'recommended') data-filter-input>
                <span class="minimal-control__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>Довіра рекомендує</span>
            </label>
        </div>
    </section>
</div>

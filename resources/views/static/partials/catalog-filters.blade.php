<div class="reviews-filters-accordion">
    <details class="filter-block filter-block--main" open>
        <summary class="filter-block__summary">
            <span>Категорія</span>
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="filter-block__panel">
            @php
                $currentQ = mb_strtolower((string) request('q'));
                $currentSub = mb_strtolower((string) request('sub'));
                $categoryFilters = [
                    ['name' => 'Банки', 'subs' => ['Кредитування', 'Депозити', 'Платіжні сервіси']],
                    ['name' => 'Туризм', 'subs' => ['Туроператори', 'Страхування подорожей', 'Готелі']],
                    ['name' => 'Автодилери', 'subs' => ['Нові авто', 'Вживані авто', 'Сервіс та ТО']],
                    ['name' => 'Меблі', 'subs' => ['Корпусні меблі', "М'які меблі", 'Офісні меблі']],
                    ['name' => 'Ювелірні магазини', 'subs' => ['Каблучки', 'Срібло', 'Золото']],
                    ['name' => 'Одяг', 'subs' => ['Жіночий одяг', 'Чоловічий одяг', 'Дитячий одяг']],
                    ['name' => 'Техніка', 'subs' => ['Смартфони', 'Ноутбуки', 'Побутова техніка']],
                    ['name' => 'Фітнес', 'subs' => ['Фітнес-клуби', 'Персональні тренери', 'Спортивне харчування']],
                    ['name' => 'Ресторани', 'subs' => ['Кафе', 'Доставка їжі', 'Фастфуд']],
                    ['name' => 'Аптеки', 'subs' => ['Онлайн-аптеки', 'Медичні товари', 'Вітаміни']],
                    ['name' => 'Доставка', 'subs' => ["Кур'єрські служби", 'Поштові служби', 'Міжнародна доставка']],
                    ['name' => 'Освіта', 'subs' => ['Онлайн-курси', 'Школи', 'Мовні курси']],
                    ['name' => 'Краса', 'subs' => ['Салони краси', 'Косметологія', 'Барбершопи']],
                    ['name' => 'Будівництво', 'subs' => ['Ремонт', 'Будматеріали', 'Проєктування']],
                ];
            @endphp
            <div class="filter-categories">
                @foreach ($categoryFilters as $category)
                    @php
                        $categoryName = $category['name'];
                        $isParentActive = $currentQ !== '' && $currentQ === mb_strtolower($categoryName);
                        $isSubActive = false;
                        foreach ($category['subs'] as $subName) {
                            if (
                                $isParentActive &&
                                $currentSub !== '' &&
                                $currentSub === mb_strtolower($subName)
                            ) {
                                $isSubActive = true;
                                break;
                            }
                        }
                        $isActive = $isParentActive || $isSubActive;
                    @endphp
                    <details class="filter-category {{ $isActive ? 'is-active' : '' }}" {{ ($isActive || ($currentQ === '' && $loop->first)) ? 'open' : '' }}>
                        <summary class="filter-category__summary">
                            <a class="filter-category__link {{ $isParentActive ? 'is-active' : '' }}"
                               href="{{ route('catalog', ['q' => $categoryName]) }}">
                                {{ $categoryName }}
                            </a>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="filter-category__subs">
                            @foreach ($category['subs'] as $subName)
                                <a href="{{ route('catalog', ['q' => $categoryName, 'sub' => $subName]) }}"
                                   class="{{ $isParentActive && $currentSub === mb_strtolower($subName) ? 'is-active' : '' }}">
                                    {{ $subName }}
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </details>

    <details class="filter-block">
        <summary class="filter-block__summary">
            <span>Регіон</span>
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="filter-block__panel filter-options">
            <button class="filter-option is-active" type="button">Київ</button>
            <button class="filter-option" type="button">Львів</button>
            <button class="filter-option" type="button">Дніпро</button>
            <button class="filter-option" type="button">Одеса</button>
            <button class="filter-option" type="button">Харків</button>
            <button class="filter-option" type="button">Вінниця</button>
            <button class="filter-option" type="button">Івано-Франківськ</button>
        </div>
    </details>

    <details class="filter-block">
        <summary class="filter-block__summary">
            <span>Рейтинг</span>
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="filter-block__panel filter-options">
            <button class="filter-option" type="button"><i class="fa-solid fa-star" aria-hidden="true"></i> 5</button>
            <button class="filter-option" type="button"><i class="fa-solid fa-star" aria-hidden="true"></i> 4+</button>
            <button class="filter-option" type="button"><i class="fa-solid fa-star" aria-hidden="true"></i> 3+</button>
        </div>
    </details>

    <details class="filter-block">
        <summary class="filter-block__summary">
            <span>Кількість відгуків</span>
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="filter-block__panel filter-options">
            <button class="filter-option" type="button">10+</button>
            <button class="filter-option" type="button">25+</button>
            <button class="filter-option" type="button">50+</button>
            <button class="filter-option" type="button">100+</button>
        </div>
    </details>

    <details class="filter-block">
        <summary class="filter-block__summary">
            <span>Сортування</span>
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="filter-block__panel filter-options">
            <button class="filter-option is-active" type="button">За актуальністю</button>
            <button class="filter-option" type="button">За рейтингом</button>
            <button class="filter-option" type="button">За кількістю відгуків</button>
            <button class="filter-option" type="button">Спочатку нові</button>
        </div>
    </details>
</div>

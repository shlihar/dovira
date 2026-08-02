@php
    $tpNegative = (int) ($trustStats['negative_published'] ?? 0);
    $tpNegativeFmt = number_format($tpNegative, 0, '', "\u{00A0}");
@endphp

{{-- «Чому нам довіряти» — принципи з доказами, не гасла: головна цифра —
     скільки НЕГАТИВУ опубліковано й видимо (його не ховають за гроші). --}}
<section class="section trust-principles" aria-labelledby="trust-principles-title">
    <div class="container">
        <div class="trust-principles__head">
            <div class="trust-principles__head-text">
                <h2 id="trust-principles-title" class="trust-principles__title">Чому нам довіряти</h2>
                <p class="trust-principles__sub">Принципи, які не залежать від тарифів</p>
            </div>
            <a class="trust-principles__all" href="{{ route('trust') }}">Докладніше <span aria-hidden="true">→</span></a>
        </div>

        <div class="trust-principles__grid">
            <div class="trust-principles__card trust-principles__card--proof">
                <span class="trust-principles__icon" style="background: #fdecef; color: #bf4560;"><i class="fa-solid fa-eye" aria-hidden="true"></i></span>
                <strong>Негатив не видаляється за гроші</strong>
                @if ($tpNegative > 0)
                    <p><b>{{ $tpNegativeFmt }}</b> відгуків з оцінкою 1–2★ опубліковані й видимі. Приховування критики не купиш.</p>
                @else
                    <p>Компанія не може оплатити видалення чи приховування чесної критики.</p>
                @endif
            </div>

            <div class="trust-principles__card">
                <span class="trust-principles__icon" style="background: #e9f7ef; color: #2c8a55;"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
                <strong>Кожен відгук — через модерацію</strong>
                <p>Автоматичні фільтри проти накруток і ботів, спірні випадки перевіряються вручну.</p>
            </div>

            <div class="trust-principles__card">
                <span class="trust-principles__icon" style="background: #e8effc; color: #3a5da8;"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
                <strong>Формула рейтингу відкрита</strong>
                <p>Оцінка з поправкою на кількість відгуків, без платних множників.</p>
                <a class="trust-principles__link" href="{{ route('rating.method') }}">Методологія <span aria-hidden="true">→</span></a>
            </div>

            <div class="trust-principles__card">
                <span class="trust-principles__icon" style="background: #eceafd; color: #6450b8;"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
                <strong>Бізнес відповідає публічно</strong>
                <p>Відповідь компанії назавжди лишається під відгуком — діалог видно всім.</p>
            </div>
        </div>
    </div>
</section>

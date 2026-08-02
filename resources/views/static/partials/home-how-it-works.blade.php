@php
    use App\Support\Plural;

    // Реальні цифри в підписах кроків: блок не тільки пояснює, а й доводить.
    $hiwNb = "\u{00A0}";
    $hiwProfiles = (int) ($totalProfiles ?? 0);
    $hiwReviews = (int) ($totalReviews ?? 0);
    $hiwProfilesLabel = $hiwProfiles > 0
        ? ' · ' . number_format($hiwProfiles, 0, '', $hiwNb) . ' ' . Plural::uk($hiwProfiles, 'профіль', 'профілі', 'профілів')
        : '';
    $hiwReviewsLabel = $hiwReviews > 0
        ? ' · ' . number_format($hiwReviews, 0, '', $hiwNb) . ' ' . Plural::uk($hiwReviews, 'відгук', 'відгуки', 'відгуків')
        : '';
    $hiwCategories = count($categoryCards ?? []);
    $hiwCategoriesLabel = $hiwCategories > 0
        ? ' · ' . $hiwCategories . ' ' . Plural::uk($hiwCategories, 'категорія', 'категорії', 'категорій')
        : '';
@endphp

<section class="section hiw" aria-labelledby="hiw-title" data-hiw>
    <div class="container">
        <div class="hiw__head">
            <div class="hiw__head-text">
                <h2 id="hiw-title" class="hiw__title">Як працює DOVIRA</h2>
                <p class="hiw__sub">Чотири кроки від пошуку до впевненого вибору.</p>
            </div>
            <a class="hiw__rules" href="{{ route('trust') }}">Правила модерації <span aria-hidden="true">→</span></a>
        </div>

        <ol class="hiw__steps">
            <li class="hiw__step">
                <span class="hiw__dot-row" aria-hidden="true"><span class="hiw__dot"></span></span>
                <span class="hiw__label">Крок 01{{ $hiwProfilesLabel }}</span>
                <h3 class="hiw__step-title">Знаходите профіль</h3>
                <p class="hiw__step-text">Пошук за назвою, послугою або містом. Читати можна без реєстрації.</p>
            </li>
            <li class="hiw__step">
                <span class="hiw__dot-row" aria-hidden="true"><span class="hiw__dot"></span></span>
                <span class="hiw__label">Крок 02{{ $hiwReviewsLabel }}</span>
                <h3 class="hiw__step-title">Читаєте відгуки</h3>
                <p class="hiw__step-text">Розподіл оцінок, дати й публічні відповіді компаній.</p>
            </li>
            <li class="hiw__step">
                <span class="hiw__dot-row" aria-hidden="true"><span class="hiw__dot"></span></span>
                <span class="hiw__label">Крок 03{{ $hiwCategoriesLabel }}</span>
                <h3 class="hiw__step-title">Обираєте впевнено</h3>
                <p class="hiw__step-text">Видно не лише середню оцінку, а й з чого вона складається.</p>
            </li>
            {{-- Крок 4 — ДІЯ (єдиний, що наповнює базу): акцентна крапка
                 з кільцем + посилання на форму відгуку. Це ієрархія, не декор. --}}
            <li class="hiw__step hiw__step--action">
                <span class="hiw__dot-row" aria-hidden="true"><span class="hiw__dot hiw__dot--action"></span></span>
                <span class="hiw__label">Крок 04 · 2 хвилини</span>
                <h3 class="hiw__step-title">Ділитесь досвідом</h3>
                <p class="hiw__step-text">Дві хвилини — і наступна людина обере впевненіше.</p>
                <a class="hiw__cta" href="{{ route('catalog') }}" data-open-review-popup>Залишити відгук <span aria-hidden="true">→</span></a>
            </li>
        </ol>
    </div>
</section>

@push('scripts')
<script>
(() => {
    // Послідовна поява кроків при скролі (зліва направо, крок 80мс).
    const section = document.querySelector('[data-hiw]');
    if (!section) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
        section.classList.add('is-revealed');
        return;
    }
    // Ховаємо стартовий стан лише тепер, коли впевнені, що зможемо його показати.
    section.classList.add('hiw--armed');
    const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                section.classList.add('is-revealed');
                io.disconnect();
            }
        });
    }, { threshold: 0.3 });
    io.observe(section);
})();
</script>
@endpush

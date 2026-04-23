@extends('static.layout')

@section('title', 'Trust Center FAQ — DOVIRA')
@section('body_class', 'page-faq')

@push('head')
    <link rel="stylesheet" href="{{ asset('static/css/pages/faq.css') }}">
@endpush

@section('content')
<section class="section faq faq-page" aria-labelledby="faq-page-title">
    <div class="container">
        <div class="faq-page__hero">
            <span class="badge faq-page__badge">Trust Center</span>
            <h1 class="faq-page__title" id="faq-page-title">Як ми перевіряємо профілі та працюємо з відгуками</h1>
            <div style="width: 60%;" class="section-divider"></div>
        </div>

        <div class="faq-hub" data-faq-categories>
            <aside class="faq-hub__sidebar" aria-label="Категорії FAQ">
               

                <div class="faq-hub__categories" role="tablist" aria-label="Категорії довідки">
                    <button class="faq-hub__category is-active" type="button" role="tab" aria-selected="true" data-faq-category="verification">
                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        <span>Перевірка профілів</span>
                    </button>
                    <button class="faq-hub__category" type="button" role="tab" aria-selected="false" data-faq-category="review-rules">
                        <i class="fa-solid fa-file-circle-check" aria-hidden="true"></i>
                        <span>Правила для відгуків</span>
                    </button>
                    <button class="faq-hub__category" type="button" role="tab" aria-selected="false" data-faq-category="moderation">
                        <i class="fa-solid fa-gavel" aria-hidden="true"></i>
                        <span>Модерація</span>
                    </button>
                    <button class="faq-hub__category" type="button" role="tab" aria-selected="false" data-faq-category="public-replies">
                        <i class="fa-solid fa-comments" aria-hidden="true"></i>
                        <span>Публічні відповіді</span>
                    </button>
                    <button class="faq-hub__category" type="button" role="tab" aria-selected="false" data-faq-category="appeals">
                        <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                        <span>Оскарження</span>
                    </button>
                    <button class="faq-hub__category" type="button" role="tab" aria-selected="false" data-faq-category="pro-account">
                        <i class="fa-solid fa-star" aria-hidden="true"></i>
                        <span>PRO-акаунт</span>
                    </button>
                </div>
                <p class="faq-hub__tabs-hint" aria-hidden="true">
                    <i class="fa-solid fa-arrows-left-right"></i>
                    <span>Гортайте категорії вліво/вправо</span>
                </p>

                <div class="faq-hub__support card">
                    <h3 class="faq-hub__support-title">Залишились питання?</h3>
                    <p class="faq-hub__support-text">Не знайшли потрібної відповіді? Зв’яжіться з командою DOVIRA.</p>
                    <a class="btn btn--primary btn--full faq-hub__support-btn" href="{{ route('login') }}">
                        <span>Звернутися в підтримку</span>
                        <i class="fa-solid fa-headset" aria-hidden="true"></i>
                    </a>
                </div>
            </aside>

            <div class="faq-hub__content" aria-live="polite">
                <section class="faq-hub__panel is-active" data-faq-category-panel="verification">
                    <div class="faq__list">
                        <details class="faq__item" open>
                            <summary class="faq__question">Що означає “перевірений профіль”?</summary>
                            <div class="faq__panel"><div class="faq__answer">Це профіль, який пройшов перевірку ключових ідентифікаційних даних і має публічну позначку перевірки в каталозі DOVIRA.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Які дані перевіряються?</summary>
                            <div class="faq__panel"><div class="faq__answer">Залежно від типу профілю, перевіряються базові реєстраційні дані, контактні реквізити та документи, що підтверджують статус спеціаліста або компанії.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи означає перевірка рекомендацію платформи?</summary>
                            <div class="faq__panel"><div class="faq__answer">Ні. Перевірка підтверджує автентичність даних профілю, але не є рекомендацією чи гарантією якості послуг.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Скільки триває перевірка?</summary>
                            <div class="faq__panel"><div class="faq__answer">Термін залежить від повноти наданих даних та завантаження модерації. Зазвичай процес займає від кількох годин до кількох робочих днів.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Що відбувається, поки профіль на перевірці?</summary>
                            <div class="faq__panel"><div class="faq__answer">Профіль може бути доступним у базовому режимі, а статус перевірки відображається як “в обробці” до завершення перевірки.</div></div>
                        </details>
                    </div>
                </section>

                <section class="faq-hub__panel" data-faq-category-panel="review-rules" hidden>
                    <div class="faq__list">
                        <details class="faq__item" open>
                            <summary class="faq__question">Хто може залишити відгук?</summary>
                            <div class="faq__panel"><div class="faq__answer">Відгук можуть залишати зареєстровані користувачі, які мають власний досвід взаємодії з профілем або послугою.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Які відгуки дозволені?</summary>
                            <div class="faq__panel"><div class="faq__answer">Дозволені фактичні, коректні, змістовні відгуки без персональних образ і без маніпулятивного або рекламного характеру.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Який контент заборонений?</summary>
                            <div class="faq__panel"><div class="faq__answer">Заборонено мову ворожнечі, персональні дані третіх осіб, наклеп, погрози, спам, а також незаконний або оманливий контент.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи можна залишати негативний відгук?</summary>
                            <div class="faq__panel"><div class="faq__answer">Так, якщо він описує реальний досвід і відповідає правилам публікації, без порушень стандартів платформи.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи можна залишити відгук без доказів?</summary>
                            <div class="faq__panel"><div class="faq__answer">У спірних випадках модерація може запросити додаткові підтвердження. Відсутність базового контексту може вплинути на рішення щодо публікації.</div></div>
                        </details>
                    </div>
                </section>

                <section class="faq-hub__panel" data-faq-category-panel="moderation" hidden>
                    <div class="faq__list">
                        <details class="faq__item" open>
                            <summary class="faq__question">Як працює модерація на DOVIRA?</summary>
                            <div class="faq__panel"><div class="faq__answer">Модерація перевіряє контент на відповідність правилам платформи: достовірність формулювань, відсутність порушень і коректність публічного формату.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Коли відгук можуть не опублікувати?</summary>
                            <div class="faq__panel"><div class="faq__answer">Коли матеріал порушує правила: містить образи, персональні дані, неправдиві звинувачення, спам або інші заборонені елементи.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи всі відгуки проходять перевірку?</summary>
                            <div class="faq__panel"><div class="faq__answer">Так, усі публікації проходять базовий модераційний контроль, а спірні кейси - розширену перевірку.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Хто приймає рішення щодо спірного контенту?</summary>
                            <div class="faq__panel"><div class="faq__answer">Рішення приймає модераційна команда DOVIRA відповідно до правил платформи і наданих сторонами матеріалів.</div></div>
                        </details>
                    </div>
                </section>

                <section class="faq-hub__panel" data-faq-category-panel="public-replies" hidden>
                    <div class="faq__list">
                        <details class="faq__item" open>
                            <summary class="faq__question">Чи можуть профілі відповідати на відгуки?</summary>
                            <div class="faq__panel"><div class="faq__answer">Так, профілі можуть надавати публічні відповіді, щоб пояснити свою позицію та надати додатковий контекст по ситуації.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Хто може публікувати офіційні відповіді?</summary>
                            <div class="faq__panel"><div class="faq__answer">Офіційні відповіді публікують власники профілю або уповноважені представники, які мають доступ до кабінету.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи можна редагувати відповідь?</summary>
                            <div class="faq__panel"><div class="faq__answer">Так, у межах доступного функціоналу кабінету відповідь можна оновити, щоб уточнити формулювання або додати нові деталі.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи доступні відповіді в безкоштовному акаунті?</summary>
                            <div class="faq__panel"><div class="faq__answer">Базовий функціонал може бути обмежений. Розширені можливості публічних відповідей доступні у PRO-акаунті.</div></div>
                        </details>
                    </div>
                </section>

                <section class="faq-hub__panel" data-faq-category-panel="appeals" hidden>
                    <div class="faq__list">
                        <details class="faq__item" open>
                            <summary class="faq__question">Як подати скаргу на відгук?</summary>
                            <div class="faq__panel"><div class="faq__answer">У кабінеті профілю доступна форма звернення. Опишіть причину, додайте пояснення та релевантні матеріали для перевірки.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">У яких випадках відгук можуть переглянути?</summary>
                            <div class="faq__panel"><div class="faq__answer">Коли є ознаки порушення правил, помилки у фактах, наклепу, публікації забороненого контенту або зловживання механікою відгуків.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи можна видалити відгук, якщо він просто негативний?</summary>
                            <div class="faq__panel"><div class="faq__answer">Ні. Негативний, але коректний і допустимий відгук не видаляється лише через емоційне сприйняття або незгоду з оцінкою.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Як довго розглядається звернення?</summary>
                            <div class="faq__panel"><div class="faq__answer">Термін залежить від складності кейсу. Зазвичай первинна відповідь на звернення надається протягом кількох робочих днів.</div></div>
                        </details>
                    </div>
                </section>

                <section class="faq-hub__panel" data-faq-category-panel="pro-account" hidden>
                    <div class="faq__list">
                        <details class="faq__item" open>
                            <summary class="faq__question">Що дає PRO-акаунт?</summary>
                            <div class="faq__panel"><div class="faq__answer">PRO-акаунт надає розширений контроль репутації: пріоритет у видимості, публічні відповіді, інструменти модерації та робочий кабінет для процесів.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Чи дає PRO право видаляти відгуки?</summary>
                            <div class="faq__panel"><div class="faq__answer">Ні. PRO не дає автоматичного права видалення. Рішення щодо контенту приймає модерація за правилами платформи.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Як PRO впливає на видимість у каталозі?</summary>
                            <div class="faq__panel"><div class="faq__answer">PRO-профілі отримують кращу публічну представленість у релевантних сценаріях пошуку та сторінках каталогу.</div></div>
                        </details>
                        <details class="faq__item">
                            <summary class="faq__question">Що входить у тариф?</summary>
                            <div class="faq__panel"><div class="faq__answer">Набір можливостей залежить від плану: публічні відповіді, пріоритет у пошуку, додаткові модераційні інструменти та підтримка кабінетних процесів.</div></div>
                        </details>
                    </div>
                </section>
            </div>
        </div>
    </div>
</section>
@endsection

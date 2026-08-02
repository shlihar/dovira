<div class="review-popup" data-review-popup hidden>
    <div class="review-popup__backdrop" data-review-popup-close></div>
    <div class="review-popup__dialog" role="dialog" aria-modal="true" aria-label="Додати відгук">
        <header class="review-popup__top">
            <button type="button" class="review-popup__close" aria-label="Закрити" data-review-popup-close>
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <div class="review-popup__head">
                <p class="review-popup__eyebrow">DOVIRA</p>
                <p class="review-popup__title" data-review-popup-title>Додати відгук</p>
                <p class="review-popup__lead" data-review-popup-lead>Оберіть профіль, поставте оцінку та поділіться досвідом.</p>
            </div>
            <div class="review-popup__progress" data-review-progress aria-hidden="true">
                <span class="review-popup__progress-step is-active" data-review-progress-dot="1"></span>
                <span class="review-popup__progress-step" data-review-progress-dot="2"></span>
            </div>
        </header>

        <form class="review-popup__form" data-review-popup-form>
            @csrf
            <input type="hidden" name="profile_slug" data-review-profile-slug>
            {{-- Honeypot: hidden from users, catches bots. --}}
            <input type="text" name="website" class="review-popup__hp" tabindex="-1" autocomplete="off" aria-hidden="true">

            <div class="review-popup__body" data-review-popup-body>
                {{-- STEP 1 — profile + rating --}}
                <section class="review-popup__step is-active" data-review-step="1">
                    <label class="review-popup__label" for="review-popup-profile-input">Профіль</label>
                    <div class="review-popup__search-shell" data-review-profile-search-wrap>
                        <div class="review-popup__search-wrap">
                            <div class="review-popup__search" data-review-profile-search>
                                <i class="fa-solid fa-magnifying-glass review-popup__search-icon" aria-hidden="true"></i>
                                <input id="review-popup-profile-input" type="text" placeholder="Назва компанії або спеціаліста" autocomplete="off" data-review-profile-input data-review-open-suggest>
                            </div>
                            <div class="hero__search-suggest review-popup__suggest" data-review-profile-suggest hidden></div>
                        </div>
                    </div>

                    <div class="review-popup__selected" data-review-selected-profile hidden>
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                        <span data-review-selected-profile-name></span>
                        <button type="button" class="review-popup__selected-clear" data-review-clear-profile aria-label="Очистити обраний профіль" title="Змінити профіль">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="review-popup__rating" data-review-rating>
                        <p class="review-popup__label review-popup__label--inline">Ваша оцінка</p>
                        <div class="review-popup__stars">
                            @for ($rate = 5; $rate >= 1; $rate--)
                                <button type="button" class="review-popup__star" data-rating-value="{{ $rate }}" aria-label="{{ $rate }} з 5">
                                    <i class="fa-solid fa-star" aria-hidden="true"></i>
                                </button>
                            @endfor
                        </div>
                        <input type="hidden" name="rating" value="0" data-review-rating-input>
                        <span class="review-popup__rating-value" data-review-rating-value></span>
                    </div>
                    <p class="review-popup__field-error" data-review-step-error hidden></p>
                </section>

                {{-- STEP 2 — review text + media + (optional) name/email --}}
                <section class="review-popup__step" data-review-step="2" hidden>
                    <label class="review-popup__field">
                        <span>Текст відгуку <small>(необов'язково)</small></span>
                        <textarea name="body" rows="6" maxlength="3000" placeholder="Можна залишити лише оцінку або описати ваш досвід..." data-review-input data-review-body></textarea>
                    </label>
                    <p class="review-popup__field-error" data-review-step-error hidden></p>
                    <div class="review-popup__composer-bar">
                        <label class="review-popup__attach-btn">
                            <i class="fa-regular fa-image" aria-hidden="true"></i>
                            <span>Додати фото / відео</span>
                            <input type="file" name="media[]" accept="image/*,video/*" multiple data-review-media-input>
                        </label>
                        <small class="review-popup__media-note" data-review-media-note></small>
                        <button type="submit" class="btn btn--primary review-popup__submit" data-review-submit hidden>
                            <span data-review-popup-submit-label>Опублікувати</span>
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="review-popup__media-preview" data-review-media-preview hidden></div>

                    {{-- Optional identity — never blocks publishing. Hidden for logged-in users. --}}
                    @guest
                        <div class="review-popup__optional" data-review-optional>
                            <div class="review-popup__grid">
                                <label class="review-popup__field">
                                    <span>Ім'я <small>(необов'язково)</small></span>
                                    <input type="text" name="author_name" maxlength="120" placeholder="Ваше ім'я" data-review-input>
                                </label>
                                <label class="review-popup__field">
                                    <span>Email <small>(необов'язково)</small></span>
                                    <input type="email" name="author_email" maxlength="255" placeholder="you@example.com" data-review-input data-review-contact>
                                </label>
                            </div>
                            <p class="review-popup__optional-hint">Email — лише щоб сповістити, коли відгук опублікують. Можна не залишати.</p>
                        </div>

                        @if (\App\Support\Turnstile::isEnabled())
                            {{-- Turnstile сам додає приховане поле cf-turnstile-response у форму. --}}
                            <div class="cf-turnstile review-popup__turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-language="uk"></div>
                        @endif
                    @endguest
                </section>
            </div>

            <p class="review-popup__error" data-review-error hidden></p>
            <p class="review-popup__success" data-review-success hidden></p>

            {{-- Soft post-publish upsell — shown to guests after a successful submit. --}}
            @guest
                <div class="review-popup__upsell" data-review-upsell hidden>
                    <div class="review-popup__upsell-text">
                        <strong>Хочете керувати відгуками?</strong>
                        <small>Створіть акаунт — редагуйте відгуки й бачте відповіді.</small>
                    </div>
                    <button type="button" class="btn btn--primary review-popup__upsell-btn" data-review-upsell-register>
                        <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                        <span>Створити акаунт</span>
                    </button>
                </div>
            @endguest

            <footer class="review-popup__foot">
                <button type="button" class="btn btn--ghost review-popup__back" data-review-back hidden>
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    <span>Назад</span>
                </button>
                <button type="button" class="btn btn--primary review-popup__next" data-review-next>
                    <span>Далі</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>
            </footer>
        </form>
    </div>
</div>

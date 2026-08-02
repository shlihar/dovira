{{-- Попап «Залишити заявку»: відкривається кнопкою [data-open-lead-popup]
     у контактах профілю (режим CTA «форма заявки» або коли лінк не заданий).
     Сабміт і показ подяки — lead-form.js, відкриття/закриття — lead-popup.js. --}}
@if (\Illuminate\Support\Facades\Schema::hasTable('profile_leads'))
<div class="profile-lead-popup" data-lead-popup hidden>
    <div class="profile-lead-popup__backdrop" data-lead-popup-close></div>
    <div class="profile-lead-popup__dialog profile-lead-card" role="dialog" aria-modal="true" aria-label="Залишити заявку" data-lead-card>
        <button type="button" class="profile-lead-popup__close" data-lead-popup-close aria-label="Закрити">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>

        <div class="profile-lead-card__head">
            <h3><i class="fa-regular fa-paper-plane" aria-hidden="true"></i> Залишити заявку</h3>
            <span class="profile-lead-card__badge">Безкоштовно</span>
        </div>
        <p class="profile-lead-card__sub">Залиште контакти — і {{ $profile['name'] }} звʼяжеться з вами.</p>

        <form class="profile-lead-form" action="{{ route('profile.lead.store', ['slug' => $profile['slug']]) }}" method="POST" data-lead-form>
            @csrf
            <input type="text" name="company" tabindex="-1" autocomplete="off" class="profile-lead-form__hp" aria-hidden="true">
            <div class="profile-lead-form__row">
                <input type="text" name="name" placeholder="Ваше імʼя" required maxlength="120" autocomplete="name">
                <input type="tel" name="phone" placeholder="+38 (0__) ___-__-__" required maxlength="40" autocomplete="tel" inputmode="tel" data-phone-mask>
            </div>
            <textarea name="message" rows="2" maxlength="1000" placeholder="Коротко про запит (необовʼязково)"></textarea>
            <button type="submit" class="btn btn--primary profile-lead-form__submit">
                <i class="fa-regular fa-paper-plane" aria-hidden="true"></i>
                <span data-lead-submit-label>Надіслати заявку</span>
            </button>
            <p class="profile-lead-form__note"><i class="fa-solid fa-lock" aria-hidden="true"></i> Дані бачить лише виконавець. Надсилаючи, ви погоджуєтесь на звʼязок.</p>
        </form>
        <div class="profile-lead-success" data-lead-success hidden>
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <p data-lead-success-text>Дякуємо! Заявку надіслано.</p>
        </div>
    </div>
</div>
@endif

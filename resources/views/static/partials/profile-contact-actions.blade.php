@php
    $primaryPhone = $profilePhoneLinks->first();
    $extraPhones = $profilePhoneLinks->skip(1);
    // Owner-configured CTA: any link (messenger, social, booking form,
    // site) — the icon, label and analytics event are auto-detected.
    $contactCta = \App\Support\ContactCta::resolve($profile['contact_cta_url'] ?? null);
    // Гейтинг з 01.08.2026: прямі контакти (телефон, сайт, email, соцмережі,
    // власний CTA-лінк) публічно бачать лише PRO-профілі. Не-PRO отримує
    // тільки лід-форму — заявки падають у кабінет і мотивують підключити PRO.
    $showDirectContacts = ! empty($profile['pro']);
    // Режим «форма заявки»: головна кнопка відкриває попап з лід-формою.
    // Так само чинимо, коли власник обрав лінк, але не вставив його,
    // і завжди — для не-PRO профілів.
    $leadPopupAvailable = \Illuminate\Support\Facades\Schema::hasTable('profile_leads');
    $useLeadPopup = $leadPopupAvailable
        && (! $showDirectContacts || (($profile['contact_cta_mode'] ?? 'link') === 'lead_form') || ! $contactCta);
@endphp

<div class="profile-contact-actions">
    @if ($useLeadPopup)
        <button type="button" class="profile-contact-action profile-contact-action--primary" data-open-lead-popup data-profile-track="lead_cta_click">
            <span class="profile-contact-action__icon"><i class="fa-regular fa-paper-plane" aria-hidden="true"></i></span>
            <span class="profile-contact-action__text">
                <strong>Залишити заявку</strong>
                <span>виконавець звʼяжеться з вами</span>
            </span>
        </button>
    @elseif ($contactCta && $showDirectContacts)
        <a class="profile-contact-action profile-contact-action--primary" href="{{ $contactCta['href'] }}" target="_blank" rel="noopener noreferrer" data-profile-track="{{ $contactCta['track'] }}">
            <span class="profile-contact-action__icon"><i class="{{ $contactCta['icon'] }}" aria-hidden="true"></i></span>
            <span class="profile-contact-action__text">
                <strong>{{ $contactCta['label'] }}</strong>
                <span>{{ $contactCta['detail'] }}</span>
            </span>
        </a>
    @endif
    @if ($primaryPhone && $showDirectContacts)
        <a class="profile-contact-action {{ ($contactCta || $useLeadPopup) ? '' : 'profile-contact-action--primary' }}" href="tel:{{ $primaryPhone['href'] }}" data-profile-track="phone_click">
            <span class="profile-contact-action__icon"><i class="fa-solid fa-phone" aria-hidden="true"></i></span>
            <span class="profile-contact-action__text">
                <strong>Подзвонити</strong>
                <span>{{ $primaryPhone['display'] }}</span>
            </span>
        </a>
    @endif
    @if ($showDirectContacts && $profileWebsiteHref && $profileWebsiteDisplay && (!$contactCta || $contactCta['href'] !== $profileWebsiteHref))
        <a class="profile-contact-action" href="{{ $profileWebsiteHref }}" target="_blank" rel="noopener noreferrer" data-profile-track="website_click">
            <span class="profile-contact-action__icon"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
            <span class="profile-contact-action__text">
                <strong>Веб-сайт</strong>
                <span>{{ $profileWebsiteDisplay }}</span>
            </span>
        </a>
    @endif
    @if (!empty($profile['email']) && $showDirectContacts)
        <a class="profile-contact-action" href="mailto:{{ $profile['email'] }}" data-profile-track="email_click">
            <span class="profile-contact-action__icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
            <span class="profile-contact-action__text">
                <strong>Email</strong>
                <span>{{ $profile['email'] }}</span>
            </span>
        </a>
    @endif
    {{-- Маршрут — теж контактна дія: для не-PRO лишаємо тільки лід-форму. --}}
    @if (!empty($profile['address']) && !empty($profile['pro']))
        <a class="profile-contact-action" href="https://maps.google.com/?q={{ urlencode($profile['address']) }}" target="_blank" rel="noopener noreferrer" data-profile-track="map_click">
            <span class="profile-contact-action__icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
            <span class="profile-contact-action__text">
                <strong>Маршрут</strong>
                <span>{{ $profile['address'] }}</span>
            </span>
        </a>
    @endif
</div>

@if ($extraPhones->isNotEmpty() && $showDirectContacts)
    <p class="profile-contact-extra-phones">
        <span>Ще номери:</span>
        @foreach ($extraPhones as $phoneLink)
            <a href="tel:{{ $phoneLink['href'] }}" data-profile-track="phone_click">{{ $phoneLink['display'] }}</a>
        @endforeach
    </p>
@endif

@if ($profileSocialLinks->isNotEmpty() && $showDirectContacts)
    <div class="profile-contact-socials" aria-label="Соцмережі профілю">
        @foreach ($profileSocialLinks as $socialLink)
            <a
                class="profile-contact-socials__link profile-contact-socials__link--{{ $socialLink['network'] }}"
                href="{{ $socialLink['url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="{{ $socialLink['label'] }}"
                title="{{ $socialLink['label'] }}"
            >
                <i class="{{ $socialLink['icon'] }}" aria-hidden="true"></i>
            </a>
        @endforeach
    </div>
@endif

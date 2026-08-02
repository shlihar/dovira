@php
    /**
     * Картка «Збір відгуків»: пряме посилання /r/{slug}, кнопки «надіслати в
     * месенджер» з готовим текстом і QR-код. Посилання відкриває профіль з уже
     * відкритою формою відгуку (?review=1), utm_source = dovira_invite.
     */
    $reviewInviteUrl = route('profile.review-link', ['slug' => $currentProfile->slug]);
    $reviewInviteText = 'Будемо вдячні за відгук про «' . $currentProfile->name . '» на DOVIRA — це займе хвилину:';

    $reviewShareLinks = [
        [
            'key' => 'telegram',
            'label' => 'Telegram',
            'icon' => 'fa-brands fa-telegram',
            'href' => 'https://t.me/share/url?url=' . urlencode($reviewInviteUrl) . '&text=' . urlencode($reviewInviteText),
        ],
        [
            'key' => 'viber',
            'label' => 'Viber',
            'icon' => 'fa-brands fa-viber',
            'href' => 'viber://forward?text=' . urlencode($reviewInviteText . ' ' . $reviewInviteUrl),
        ],
        [
            'key' => 'whatsapp',
            'label' => 'WhatsApp',
            'icon' => 'fa-brands fa-whatsapp',
            'href' => 'https://wa.me/?text=' . urlencode($reviewInviteText . ' ' . $reviewInviteUrl),
        ],
        [
            'key' => 'email',
            'label' => 'Email',
            'icon' => 'fa-regular fa-envelope',
            'href' => 'mailto:?subject=' . rawurlencode('Відгук про ' . $currentProfile->name) . '&body=' . rawurlencode($reviewInviteText . "\n" . $reviewInviteUrl),
        ],
    ];
@endphp

<section class="card pro-overview-card pro-invite-card">
    <div class="pro-overview-card__head">
        <h2>Збір відгуків від клієнтів</h2>
    </div>

    <p class="pro-widget-card__lead">
        Попросіть про відгук одразу після наданої послуги — форма відкриється
        без пошуку профілю і займе близько хвилини.
    </p>

    <div class="pro-invite">
        <div class="pro-invite__main">
            <span class="pro-invite__label">Пряме посилання на форму відгуку</span>
            <div class="pro-invite__linkbox">
                <i class="fa-solid fa-link" aria-hidden="true"></i>
                <input
                    id="review-invite-link"
                    class="pro-invite__input"
                    type="text"
                    readonly
                    value="{{ $reviewInviteUrl }}"
                    aria-label="Посилання на форму відгуку"
                >
                <button type="button" class="pro-invite__copy" data-widget-copy data-widget-copy-target="review-invite-link">
                    <i class="fa-regular fa-copy" aria-hidden="true"></i>
                    <span data-widget-copy-label>Копіювати</span>
                </button>
            </div>

            <div class="pro-invite__share" data-invite-share-row>
                <span class="pro-invite__share-label">Надіслати клієнту:</span>
                @foreach ($reviewShareLinks as $share)
                    <a
                        class="pro-invite__share-btn pro-invite__share-btn--{{ $share['key'] }}"
                        href="{{ $share['href'] }}"
                        target="_blank"
                        rel="noopener"
                        data-invite-share="{{ $share['key'] }}"
                        data-no-prefetch
                    >
                        <i class="{{ $share['icon'] }}" aria-hidden="true"></i>
                        <span>{{ $share['label'] }}</span>
                    </a>
                @endforeach
            </div>

            <p class="pro-invite__hint">
                <i class="fa-regular fa-lightbulb" aria-hidden="true"></i>
                Повідомлення з текстом запрошення вже підставлене — залишиться тільки обрати чат.
            </p>
        </div>

        <div class="pro-invite__qr">
            <div class="pro-invite__qr-code" data-review-qr data-review-qr-url="{{ $reviewInviteUrl }}"></div>
            <button type="button" class="pro-invite__qr-btn" data-review-qr-download title="Для візиток, чеків і наліпок">
                <i class="fa-solid fa-download" aria-hidden="true"></i>
                PNG
            </button>
        </div>
    </div>
</section>

@push('scripts')
    <script src="{{ asset('static/js/vendor/qrcode.min.js') }}"></script>
    <script>
        (() => {
            const container = document.querySelector('[data-review-qr]');
            if (!container || typeof QRCode === 'undefined') return;

            new QRCode(container, {
                text: container.getAttribute('data-review-qr-url'),
                width: 116,
                height: 116,
                correctLevel: QRCode.CorrectLevel.M,
            });

            document.querySelector('[data-review-qr-download]')?.addEventListener('click', () => {
                const canvas = container.querySelector('canvas');
                const dataUrl = canvas
                    ? canvas.toDataURL('image/png')
                    : container.querySelector('img')?.src;
                if (!dataUrl) return;
                const link = document.createElement('a');
                link.href = dataUrl;
                link.download = 'dovira-review-qr-{{ $currentProfile->slug }}.png';
                link.click();
                window.doviraTrack?.('cta_click', 'invite_qr_download');
            });

            document.querySelector('[data-invite-share-row]')?.addEventListener('click', (event) => {
                const share = event.target.closest('[data-invite-share]');
                if (share) window.doviraTrack?.('cta_click', 'invite_share_' + share.getAttribute('data-invite-share'));
            });
        })();
    </script>
@endpush

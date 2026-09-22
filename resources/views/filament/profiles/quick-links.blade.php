{{--
    Модалка «Посилання» на сторінці редагування профілю в адмінці:
    оплата PRO, signed-підтвердження прав (30 днів), збір відгуків + QR.
    Alpine (є у Filament) — копіювання і ліниве підвантаження qrcode.min.js.
--}}
@php
    $rows = [
        ['key' => 'pay', 'label' => 'Оплата PRO', 'hint' => 'Сторінка оплати підписки (попросить увійти в акаунт власника).', 'url' => $links['pay'], 'message' => $messages['pay'] ?? null, 'messageHint' => 'Лист власнику: оплата з реальною ціною і поштою для входу.'],
        ['key' => 'claim', 'label' => 'Підтвердження профілю', 'hint' => 'Магічне посилання без логіну. Діє 30 днів від моменту відкриття цієї модалки.', 'url' => $links['claim'], 'message' => $messages['claim'] ?? null, 'messageHint' => 'Лист власнику: запрошення підтвердити профіль.'],
        ['key' => 'review', 'label' => 'Збір відгуків', 'hint' => 'Одразу відкриває форму відгуку. QR нижче — те саме посилання.', 'url' => $links['review'], 'message' => $messages['review'] ?? null, 'messageHint' => 'Текст, який бізнес надсилає СВОЇМ клієнтам після послуги.'],
    ];
@endphp

<div
    x-data="{
        copied: null,
        copy(key, text) {
            navigator.clipboard.writeText(text).then(() => {
                this.copied = key;
                setTimeout(() => { this.copied = null; }, 1500);
            });
        },
        initQr() {
            const draw = () => {
                this.$refs.qr.innerHTML = '';
                new QRCode(this.$refs.qr, {
                    text: @js($links['review']),
                    width: 170,
                    height: 170,
                    correctLevel: QRCode.CorrectLevel.M,
                });
            };
            if (window.QRCode) { draw(); return; }
            const s = document.createElement('script');
            s.src = '/static/js/vendor/qrcode.min.js';
            s.onload = draw;
            document.head.appendChild(s);
        },
    }"
    x-init="initQr()"
    style="display: flex; flex-direction: column; gap: 18px;"
>
    @foreach ($rows as $row)
        <div>
            <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px;">{{ $row['label'] }}</div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <input
                    type="text"
                    readonly
                    value="{{ $row['url'] }}"
                    onclick="this.select()"
                    style="flex: 1 1 auto; min-width: 0; font-size: 12px; padding: 8px 10px; border: 1px solid rgba(120,130,150,.35); border-radius: 8px; background: rgba(120,130,150,.06); color: inherit;"
                >
                <button
                    type="button"
                    x-on:click="copy(@js($row['key']), @js($row['url']))"
                    style="flex: 0 0 auto; padding: 8px 14px; border: 1px solid rgba(120,130,150,.35); border-radius: 8px; background: transparent; color: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;"
                >
                    <span x-show="copied !== @js($row['key'])">Копіювати</span>
                    <span x-show="copied === @js($row['key'])" x-cloak style="color: #16a34a;">Скопійовано ✓</span>
                </button>
            </div>
            <div style="font-size: 11.5px; opacity: .65; margin-top: 3px;">{{ $row['hint'] }}</div>

            @if (!empty($row['message']))
                <details style="margin-top: 7px;">
                    <summary style="cursor: pointer; font-size: 12px; font-weight: 600; opacity: .8;">Готове повідомлення — {{ $row['messageHint'] }}</summary>
                    <div style="display: flex; gap: 8px; align-items: flex-start; margin-top: 6px;">
                        <textarea
                            readonly
                            rows="5"
                            onclick="this.select()"
                            style="flex: 1 1 auto; min-width: 0; font-size: 12px; line-height: 1.5; padding: 8px 10px; border: 1px solid rgba(120,130,150,.35); border-radius: 8px; background: rgba(120,130,150,.06); color: inherit; resize: vertical;"
                        >{{ $row['message'] }}</textarea>
                        <button
                            type="button"
                            x-on:click="copy(@js($row['key'] . '_msg'), @js($row['message']))"
                            style="flex: 0 0 auto; padding: 8px 14px; border: 1px solid rgba(120,130,150,.35); border-radius: 8px; background: transparent; color: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;"
                        >
                            <span x-show="copied !== @js($row['key'] . '_msg')">Копіювати</span>
                            <span x-show="copied === @js($row['key'] . '_msg')" x-cloak style="color: #16a34a;">Скопійовано ✓</span>
                        </button>
                    </div>
                </details>
            @endif
        </div>
    @endforeach

    <div style="display: flex; gap: 16px; align-items: center;">
        <div x-ref="qr" style="flex: 0 0 auto; width: 170px; height: 170px; padding: 8px; background: #fff; border: 1px solid rgba(120,130,150,.35); border-radius: 10px; box-sizing: content-box;"></div>
        <div style="font-size: 12px; opacity: .7; max-width: 260px;">
            QR веде на форму відгуку профілю «{{ $profile->name }}». Правий клік → «Зберегти зображення», щоб віддати клієнту для друку чи наліпки.
        </div>
    </div>
</div>

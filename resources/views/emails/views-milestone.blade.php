<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#2a4f9a;font-weight:700;font-size:13px;">
                    📈 Вашу сторінку дивляться
                </p>
                <h1 style="margin:0 0 14px;font-size:19px;line-height:1.3;">
                    «{{ $profileName }}» переглянули вже {{ $views }} разів
                </h1>

                <div style="margin:0 0 18px;padding:16px;background:#eef4ff;border-radius:10px;text-align:center;">
                    <div style="font-size:34px;font-weight:800;color:#2a4f9a;line-height:1;">{{ $views }}</div>
                    <div style="font-size:12px;color:#5a6b93;margin-top:4px;">переглядів профілю на Dovira</div>
                </div>

                @if ($forOwner)
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#42537a;">
                        Стільки людей уже бачили вашу сторінку — деталі є в аналітиці вашого
                        кабінету. Кожен із цих переглядів — людина, яка перевіряла вас перед
                        зверненням. PRO дає вплинути на те, що вона побачить: офіційні відповіді
                        на відгуки, оформлена сторінка й контакти клієнтів із заявок.
                    </p>
                    <a href="{{ $ctaUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Увімкнути PRO →</a>
                @else
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#42537a;">
                        Стільки клієнтів уже бачили вашу публічну сторінку на Dovira.
                        Її також можуть знаходити в Google за назвою. Підтвердьте права на профіль —
                        це безкоштовно й одразу відкриє аналітику переглядів. А щоб відповідати
                        на відгуки, редагувати інформацію й отримувати контакти клієнтів із заявок,
                        оформіть підписку.
                        {{ \App\Support\ProPricing::launchCohortPriceLine() }}
                        Розмістите бейдж DOVIRA на сайті або в соцмережах — отримаєте знижку 20%.
                    </p>
                    <a href="{{ $ctaUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Керувати сторінкою →</a>
                @endif
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            Ви отримали цей лист, бо профіль «{{ $profileName }}» на платформі Dovira набрав {{ $views }} переглядів.
            @if (! $forOwner && $unsubscribeUrl)
                <br><a href="{{ $unsubscribeUrl }}" style="color:#8a97b4;">Не надсилати такі листи</a>.
            @else
                Вимкнути такі листи можна в налаштуваннях сповіщень PRO-кабінету.
            @endif
        </p>
    </div>
</body>
</html>

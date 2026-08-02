<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#1c8a56;font-weight:700;font-size:13px;">
                    ⚡ Нова заявка з вашого профілю
                </p>
                <h1 style="margin:0 0 14px;font-size:19px;line-height:1.3;">
                    Клієнт залишив заявку для «{{ $profileName }}»
                </h1>

                <div style="margin:0 0 18px;padding:14px 16px;background:#f3f9f5;border-left:3px solid #4cc38a;border-radius:8px;">
                    <p style="margin:0 0 6px;font-weight:700;font-size:14px;color:#1f5b3e;">{{ $leadName }}</p>
                    <p style="margin:0 0 {{ $leadMessage !== '' ? '8px' : '0' }};font-size:15px;font-weight:700;color:#1f2f52;letter-spacing:0.02em;">
                        {{ $leadPhone }}
                    </p>
                    @if ($leadMessage !== '')
                        <p style="margin:0;font-size:14px;line-height:1.5;color:#3c4f45;">{{ $leadMessage }}</p>
                    @endif
                </div>

                @if ($contactsUnlocked)
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#42537a;">
                        Швидкість вирішує: клієнти зазвичай пишуть кільком виконавцям одразу.
                        Передзвоніть якнайшвидше — і заявка стане вашим клієнтом.
                    </p>
                    <a href="{{ $ctaUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Відкрити заявку в кабінеті →</a>
                @else
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#42537a;">
                        Контакти клієнта приховані на безкоштовному тарифі.
                        Підключіть <b>PRO</b> — і побачите телефон цієї та всіх наступних заявок одразу.
                    </p>
                    <a href="{{ $ctaUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Розблокувати контакти →</a>
                @endif
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            Ви отримали цей лист, бо клієнт залишив заявку на вашому профілі «{{ $profileName }}» на платформі Dovira.
            Вимкнути такі листи можна в налаштуваннях сповіщень PRO-кабінету.
        </p>
    </div>
</body>
</html>

<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#b7364a;font-weight:700;font-size:13px;">
                    ⚠ Негативний відгук ({{ number_format($rating, 1) }}★)
                </p>
                <h1 style="margin:0 0 14px;font-size:19px;line-height:1.3;">
                    @if ($forOwner)
                        Новий негативний відгук про «{{ $profileName }}»
                    @else
                        Про «{{ $profileName }}» залишили негативний відгук
                    @endif
                </h1>

                <div style="margin:0 0 18px;padding:14px 16px;background:#fbf3f4;border-left:3px solid #e0899a;border-radius:8px;">
                    <p style="margin:0 0 6px;font-weight:700;font-size:13px;color:#8a3548;">{{ $authorName }}</p>
                    <p style="margin:0;font-size:14px;line-height:1.5;color:#4a3238;">{{ $reviewBody }}</p>
                </div>

                @if ($forOwner)
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#42537a;">
                        Швидка й ввічлива офіційна відповідь показує майбутнім клієнтам, що ви дбаєте про сервіс.
                        Відповісти можна у вашому PRO-кабінеті.
                    </p>
                    <a href="{{ $ctaUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Відповісти на відгук →</a>
                @else
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#42537a;">
                        Ваш профіль на Dovira бачать клієнти, коли шукають вас у Google. Підтвердіть право
                        власності <b>безкоштовно</b>, а PRO-підписка відкриє офіційну відповідь на цей відгук
                        і керування репутацією.
                    </p>
                    <a href="{{ $ctaUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Підтвердити профіль →</a>
                @endif
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            Ви отримали цей лист, бо на профіль «{{ $profileName }}» на платформі Dovira залишили відгук.
            @if (!$forOwner && $unsubscribeUrl)
                <br><a href="{{ $unsubscribeUrl }}" style="color:#8a97b4;">Не надсилати такі листи</a>.
            @endif
        </p>
    </div>
</body>
</html>

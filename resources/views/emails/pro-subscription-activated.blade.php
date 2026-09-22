<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#16a34a;font-weight:700;font-size:13px;">✅ Оплату отримано</p>
                <h1 style="margin:0 0 14px;font-size:19px;line-height:1.3;">
                    @if ($isRenewal)
                        PRO-підписку для «{{ $profileName }}» продовжено
                    @else
                        PRO для «{{ $profileName }}» активовано
                    @endif
                </h1>

                <table style="width:100%;margin:0 0 18px;border-collapse:collapse;font-size:14px;color:#42537a;">
                    <tr>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;">Сума</td>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;text-align:right;font-weight:700;color:#1f2f52;">{{ $amountDisplay }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;">Період</td>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;text-align:right;font-weight:700;color:#1f2f52;">{{ $periodMonths }} місяців</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;">Діє до</td>
                        <td style="padding:8px 0;text-align:right;font-weight:700;color:#1f2f52;">{{ $endsAtDisplay }}</td>
                    </tr>
                </table>

                <p style="margin:0 0 10px;font-size:14px;line-height:1.6;color:#42537a;">
                    Тепер вам доступні: офіційні відповіді на відгуки від імені компанії,
                    повне керування сторінкою (послуги, ціни, фото, FAQ, досьє),
                    контакти клієнтів із заявок і пріоритет у каталозі.
                </p>

                <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#42537a;">
                    З чого почати: перегляньте відгуки без відповіді — саме вони найбільше
                    впливають на рішення клієнта, який вас перевіряє.
                </p>

                <a href="{{ $reviewsUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Відповісти на відгуки →</a>

                <p style="margin:18px 0 0;font-size:13px;line-height:1.6;color:#8a97b4;">
                    Кабінет: <a href="{{ $accountUrl }}" style="color:#2f6df4;">керування профілем</a> ·
                    Ваша сторінка: <a href="{{ $profileUrl }}" style="color:#2f6df4;">{{ $profileName }}</a>
                </p>
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            @if ($autoRenew)
                Підписка продовжується автоматично: кожні {{ $periodMonths }} місяців списується {{ $amountDisplay }}
                зі збереженої картки. Вимкнути автопродовження можна будь-коли в кабінеті — доступ діє до кінця сплаченого періоду.
            @else
                Автопродовження вимкнене: щоб зберегти PRO після {{ $endsAtDisplay }}, підписку треба буде продовжити вручну в кабінеті.
            @endif
            <br>Документ про оплату доступний у кабінеті, у розділі «Підписка».
        </p>
    </div>
</body>
</html>

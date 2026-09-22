<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#2a4f9a;font-weight:700;font-size:13px;">Ваш профіль · перевірений акаунт</p>

                <h1 style="margin:0 0 14px;font-size:19px;line-height:1.35;">
                    @if ($negativeUnanswered > 0)
                        Про «{{ $profileName }}» є {{ $negativeUnanswered }} {{ \App\Support\Plural::uk($negativeUnanswered, 'негативний відгук', 'негативні відгуки', 'негативних відгуків') }} без вашої відповіді
                    @elseif ($reviews > 0)
                        {{ $reviews }} {{ \App\Support\Plural::uk($reviews, 'відгук', 'відгуки', 'відгуків') }} про «{{ $profileName }}» — і жодної відповіді від компанії
                    @else
                        Сторінка «{{ $profileName }}» працює — час зібрати перші відгуки
                    @endif
                </h1>

                @if ($impressions90 || $reviews > 0)
                    <table style="width:100%;margin:0 0 18px;border-collapse:collapse;font-size:14px;color:#42537a;">
                        @if ($impressions90)
                            <tr>
                                <td style="padding:8px 0;border-bottom:1px solid #eef2f9;">Показів сторінки за 3 місяці</td>
                                <td style="padding:8px 0;border-bottom:1px solid #eef2f9;text-align:right;font-weight:700;color:#1f2f52;">{{ $impressions90 }}</td>
                            </tr>
                        @endif
                        @if ($reviews > 0)
                            <tr>
                                <td style="padding:8px 0;{{ $negativeUnanswered > 0 ? 'border-bottom:1px solid #eef2f9;' : '' }}">Відгуків на сторінці</td>
                                <td style="padding:8px 0;{{ $negativeUnanswered > 0 ? 'border-bottom:1px solid #eef2f9;' : '' }}text-align:right;font-weight:700;color:#1f2f52;">{{ $reviews }}</td>
                            </tr>
                        @endif
                        {{-- Показуємо саме НЕГАТИВНІ без відповіді: загальне число
                             «без відповіді» включає весь імпорт з Google і читалося б
                             як докір людині, яка там уже відповіла. --}}
                        @if ($negativeUnanswered > 0)
                            <tr>
                                <td style="padding:8px 0;">Негативних без відповіді на DOVIRA</td>
                                <td style="padding:8px 0;text-align:right;font-weight:700;color:#b7364a;">{{ $negativeUnanswered }}</td>
                            </tr>
                        @endif
                    </table>
                @endif

                @if ($reviews > 0)
                    <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#42537a;">
                        Ви підтвердили права на цю сторінку — дякуємо. Її вже показують людям, які шукають
                        фахівця й читають відгуки перед зверненням. Але поруч із відгуками поки немає жодного
                        вашого слова: клієнт бачить лише чужу оцінку.
                    </p>
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#42537a;">
                        Спокійна офіційна відповідь працює довше за сам відгук — її читає кожен наступний
                        відвідувач. Якщо ви вже відповідали на інших майданчиках, ці відповіді сюди не
                        переносяться: їх треба опублікувати на DOVIRA окремо.
                    </p>
                @else
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#42537a;">
                        Ви підтвердили права на сторінку — дякуємо. Тепер вона працює на вас у каталозі
                        й у Google. Наступний крок — оформити її (послуги, ціни, фото) і попросити кількох
                        клієнтів залишити відгук: саме відгуки вирішують, до кого звернуться.
                    </p>
                @endif

                <a href="{{ $reviews > 0 ? $billingUrl : $profileUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">{{ $reviews > 0 ? 'Відповісти на відгуки →' : 'Відкрити свою сторінку →' }}</a>

                <p style="margin:18px 0 0;padding-top:16px;border-top:1px solid #eef1f7;font-size:13px;line-height:1.6;color:#8a97b4;">
                    Офіційні відповіді від імені компанії, керування сторінкою (послуги, ціни, фото, досьє)
                    і контакти клієнтів із заявок відкриваються з PRO — {{ $proPriceLine }}.
                    Аналітика переглядів і всі відгуки у вашому кабінеті доступні безкоштовно:
                    <a href="{{ $reviewsUrl }}" style="color:#2f6df4;">переглянути відгуки</a>.
                </p>
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            Ви отримали цей лист, бо підтвердили права на профіль «{{ $profileName }}» на платформі DOVIRA.
            Вимкнути такі листи можна в налаштуваннях сповіщень кабінету.
        </p>
    </div>
</body>
</html>

<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:560px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#2a4f9a;font-weight:700;font-size:13px;">Для власників підтверджених профілів</p>
                <h1 style="margin:0 0 14px;font-size:19px;line-height:1.35;">
                    Покажіть свої відгуки просто на {{ $siteHost }}
                </h1>

                <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#42537a;">
                    @if ($hasRating)
                        На DOVIRA у «{{ $profileName }}» вже {{ $reviews }} {{ \App\Support\Plural::uk($reviews, 'відгук', 'відгуки', 'відгуків') }}
                        і рейтинг {{ $ratingLabel }}. Ці відгуки зараз працюють тільки на нашому сайті —
                        а можуть працювати на вашому, там де клієнт ухвалює рішення.
                    @else
                        Відгуки про «{{ $profileName }}» на DOVIRA зараз працюють тільки на нашому сайті —
                        а можуть працювати на вашому, там де клієнт ухвалює рішення.
                    @endif
                </p>

                <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#42537a;">
                    Ми підготували код саме для вашого профілю — просто скопіюйте й вставте.
                </p>

                <p style="margin:18px 0 6px;font-size:13px;font-weight:700;color:#1f2f52;">1. Бейдж із рейтингом у кутку сайту</p>
                <p style="margin:0 0 8px;font-size:13px;color:#8a97b4;">Вставити перед закриваючим тегом &lt;/body&gt;</p>
                <div style="margin:0 0 18px;padding:12px 14px;background:#f6f8fc;border:1px solid #e4e9f2;border-radius:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.5;color:#1f2f52;word-break:break-all;">{{ $floatSnippet }}</div>

                <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#1f2f52;">2. Блок відгуків на сторінці</p>
                <p style="margin:0 0 8px;font-size:13px;color:#8a97b4;">Вставити туди, де має зʼявитися блок — наприклад у секцію «Відгуки»</p>
                <div style="margin:0 0 18px;padding:12px 14px;background:#f6f8fc;border:1px solid #e4e9f2;border-radius:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.5;color:#1f2f52;word-break:break-all;">{{ $reviewsSnippet }}</div>

                <p style="margin:0 0 18px;font-size:13px;line-height:1.6;color:#8a97b4;">
                    Під стиль сайту: додайте до тегу <code>data-theme="dark"</code> для темного фону
                    і <code>data-accent="#ваш-колір"</code> — віджет перефарбується під ваш бренд.
                    На WordPress це блок «HTML», на Tilda — «T123», на Wix — «Вбудувати HTML».
                </p>

                <div style="margin:0 0 18px;padding:14px 16px;background:#f0f9f4;border:1px solid rgba(102,207,149,.45);border-radius:10px;font-size:14px;line-height:1.6;color:#1a5c3a;">
                    <b>Знижка 20% за розміщення.</b> Поставите бейдж на сайті або в соцмережах — напишіть нам,
                    і підписка коштуватиме {{ $discountLine }} за 6 місяців.
                </div>

                <a href="{{ $accountUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Відкрити кабінет</a>

                <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#8a97b4;">
                    Код також є в кабінеті на вкладці «Огляд» — там же QR і посилання для збору нових відгуків.
                    Якщо потрібна допомога зі вставкою — відповідайте на цей лист, підкажемо.
                    <br>Ваша сторінка: <a href="{{ $profileUrl }}" style="color:#2f6df4;">{{ $profileName }}</a>
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

<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#2a4f9a;font-weight:700;font-size:13px;">Ваша сторінка на DOVIRA</p>
                <h1 style="margin:0 0 16px;font-size:19px;line-height:1.3;">
                    «{{ $profileName }}» — {{ $reviews }} відгуків, рейтинг {{ $rating }}
                </h1>

                <table style="width:100%;margin:0 0 18px;border-collapse:collapse;font-size:14px;color:#42537a;">
                    <tr>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;">Відгуків клієнтів</td>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;text-align:right;font-weight:700;color:#1f2f52;">{{ $reviews }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;">Середня оцінка</td>
                        <td style="padding:8px 0;border-bottom:1px solid #eef2f9;text-align:right;font-weight:700;color:#1f2f52;">{{ $rating }} з 5</td>
                    </tr>
                    @if ($category || $city)
                    <tr>
                        <td style="padding:8px 0;{{ $rankLine ? 'border-bottom:1px solid #eef2f9;' : '' }}">Категорія</td>
                        <td style="padding:8px 0;{{ $rankLine ? 'border-bottom:1px solid #eef2f9;' : '' }}text-align:right;font-weight:700;color:#1f2f52;">{{ $category }}{{ $category && $city ? ' · ' : '' }}{{ $city }}</td>
                    </tr>
                    @endif
                    @if ($rankLine)
                    <tr>
                        <td style="padding:8px 0;">Місце</td>
                        <td style="padding:8px 0;text-align:right;font-weight:700;color:#1f2f52;">{{ $rankLine }}</td>
                    </tr>
                    @endif
                </table>

                <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#42537a;">
                    Це одна з найбільших сторінок у своїй категорії на DOVIRA. Вона публічна — її показує
                    каталог та індексує Google. Але зараз нею ніхто не керує від імені компанії:
                    дані ніхто не перевіряв, а біля назви немає позначки «Перевірений акаунт».
                </p>

                <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#42537a;">
                    Це виправляється одним кліком. Посилання нижче підтверджує, що сторінка ваша, —
                    без реєстрації й без кодів, бо цей лист прийшов на контактну адресу компанії.
                    Одразу після цього безкоштовно: позначка «Перевірений акаунт», аналітика переглядів
                    і всі відгуки в одному кабінеті.
                </p>

                <a href="{{ $claimUrl }}" style="display:inline-block;background:#0a8f4f;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Підтвердити, що це моя сторінка →</a>

                <p style="margin:14px 0 0;font-size:13px;color:#8a97b4;">
                    Спершу подивитися: <a href="{{ $profileUrl }}" style="color:#2f6df4;">відкрити сторінку</a>
                </p>

                <p style="margin:18px 0 0;padding-top:16px;border-top:1px solid #eef1f7;font-size:13px;line-height:1.6;color:#8a97b4;">
                    Офіційні відповіді на відгуки від імені компанії та повне керування сторінкою відкриваються
                    з PRO ({{ $proPriceLine }}). Вирішите потім — підтвердження ні до чого не зобовʼязує.
                </p>
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            Ви отримали цей лист, бо профіль «{{ $profileName }}» представлений на платформі відгуків DOVIRA
            (mydovira.com), а ця адреса вказана як контактна у відкритих джерелах.
            <br><a href="{{ $unsubscribeUrl }}" style="color:#8a97b4;">Більше не надсилати мені такі листи</a>.
        </p>
    </div>
</body>
</html>

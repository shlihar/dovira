<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#2a4f9a;font-weight:700;font-size:13px;">
                    📊 Звіт за {{ $periodLabel }}
                </p>
                <h1 style="margin:0 0 16px;font-size:19px;line-height:1.3;">
                    Вашу сторінку «{{ $profileName }}» шукають
                </h1>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-collapse:separate;border-spacing:8px;">
                    <tr>
                        <td style="width:50%;padding:14px;background:#eef4ff;border-radius:10px;text-align:center;">
                            <div style="font-size:26px;font-weight:800;color:#2a4f9a;line-height:1;">{{ $stats['views'] }}</div>
                            <div style="font-size:11px;color:#5a6b93;margin-top:4px;">переглядів</div>
                        </td>
                        <td style="width:50%;padding:14px;background:#eef4ff;border-radius:10px;text-align:center;">
                            <div style="font-size:26px;font-weight:800;color:#2a4f9a;line-height:1;">{{ $stats['unique'] }}</div>
                            <div style="font-size:11px;color:#5a6b93;margin-top:4px;">унікальних відвідувачів</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="width:50%;padding:14px;background:#f3f9f5;border-radius:10px;text-align:center;">
                            <div style="font-size:26px;font-weight:800;color:#1c8a56;line-height:1;">{{ $stats['contact_clicks'] }}</div>
                            <div style="font-size:11px;color:#4a6b57;margin-top:4px;">кліків по контактах</div>
                        </td>
                        <td style="width:50%;padding:14px;background:#faf2dd;border-radius:10px;text-align:center;">
                            <div style="font-size:26px;font-weight:800;color:#916b0d;line-height:1;">{{ $stats['reviews_count'] }}</div>
                            <div style="font-size:11px;color:#7a5f1a;margin-top:4px;">відгуків, рейтинг {{ number_format($stats['rating_avg'], 1) }}</div>
                        </td>
                    </tr>
                </table>

                <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#42537a;">
                    Люди дивляться вашу сторінку, але зараз ви не бачите, <b>хто</b> саме заходив
                    і скільки з них намагалися звʼязатися. PRO відкриває аналітику, контакти клієнтів
                    із заявок і відповіді на відгуки.
                </p>
                <a href="{{ $ctaUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Увімкнути PRO →</a>
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            Ви отримали цей звіт як власник профілю «{{ $profileName }}» на платформі Dovira.
            Вимкнути дайджест можна в налаштуваннях сповіщень PRO-кабінету.
        </p>
    </div>
</body>
</html>

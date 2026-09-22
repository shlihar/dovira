<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                <p style="margin:0 0 6px;color:#2a4f9a;font-weight:700;font-size:13px;">
                    @if ($stage === 'day1')
                        ⏳ Останній день PRO
                    @elseif ($stage === 'days3')
                        ⏳ Залишилося 3 дні
                    @else
                        🔔 Підписка завершується
                    @endif
                </p>
                <h1 style="margin:0 0 14px;font-size:19px;line-height:1.3;">
                    @if ($stage === 'day1')
                        Завтра «{{ $profileName }}» втратить PRO-можливості
                    @else
                        PRO для «{{ $profileName }}» діє до {{ $endsAtDisplay }}
                    @endif
                </h1>

                @if ($manualMode)
                    <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#42537a;">
                        Ми оновили платіжну систему monobank, тому цього разу автоматичне списання
                        зі збереженої картки не спрацює — продовження треба підтвердити вручну.
                        Після оплати картка збережеться, і наступні продовження знову будуть автоматичними.
                    </p>
                @else
                    <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#42537a;">
                        Автоматичне продовження для цієї підписки не налаштоване, тому {{ $endsAtDisplay }}
                        вона завершиться. Щоб не втратити PRO-можливості, продовжіть її заздалегідь.
                    </p>
                @endif

                <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#42537a;">
                    Без PRO закриються: офіційні відповіді на відгуки, керування сторінкою,
                    контакти клієнтів із заявок і пріоритет у каталозі.
                    Сторінка, рейтинг, відгуки, телефон і аналітика лишаться працювати.
                </p>

                <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#42537a;">
                    Продовження: <b>{{ $amountDisplay }} за {{ $periodMonths }} місяців</b>.
                </p>

                <a href="{{ $payUrl }}" style="display:inline-block;background:#2f6df4;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Продовжити PRO →</a>
            </div>
        </div>

        <p style="margin:16px 8px 0;font-size:11px;line-height:1.5;color:#8a97b4;">
            Ви отримали цей лист, бо PRO-підписка профілю «{{ $profileName }}» на Dovira
            завершується {{ $endsAtDisplay }}. Це сервісне повідомлення про стан підписки.
        </p>
    </div>
</body>
</html>

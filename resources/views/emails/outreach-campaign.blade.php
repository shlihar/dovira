<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f2f5fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2f52;">
    <div style="max-width:520px;margin:0 auto;padding:24px 16px;">
        <div style="background:#fff;border:1px solid #e2e9f7;border-radius:16px;overflow:hidden;">
            <div style="background:#eef4ff;padding:18px 24px;font-size:20px;font-weight:800;color:#2a4f9a;">dovira</div>
            <div style="padding:24px;">
                {{-- $bodyHtml зібрано безпечно у команді: текст екранований, лінк — інлайн-<a>. --}}
                <div style="font-size:15px;line-height:1.7;color:#2b3a5e;white-space:pre-line;">{!! $bodyHtml !!}</div>

                @if (!empty($claimUrl))
                    {{-- Магічний перехід у керування профілем: підтвердження лишається технічним кроком. --}}
                    <div style="margin-top:22px;padding-top:18px;border-top:1px solid #eef1f7;">
                        <p style="margin:0 0 10px;font-size:14px;color:#42537a;">Профіль уже публічний: його можуть бачити клієнти на Dovira й знаходити в Google. Якщо це ваш профіль, перейдіть до керування — перед підпискою ми безкоштовно перевіримо права.</p>
                        <p style="margin:0 0 14px;font-size:14px;line-height:1.55;color:#42537a;">
                            {{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortLockLine() }} {{ \App\Support\ProPricing::launchCohortCounterLine() }} За бейдж DOVIRA на сайті або в соцмережах діє знижка 20%.
                        </p>
                        <a href="{{ $claimUrl }}" style="display:inline-block;background:#0a8f4f;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:12px;">Перейти до керування →</a>
                    </div>
                @endif
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

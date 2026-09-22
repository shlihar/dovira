{!! $bodyText !!}

@if (!empty($claimUrl))

Профіль уже публічний: його можуть бачити клієнти на Dovira й знаходити в Google. Якщо це ваш профіль, перейдіть до керування — перед підпискою ми безкоштовно перевіримо права:
{!! $claimUrl !!}

{{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortLockLine() }} {{ \App\Support\ProPricing::launchCohortCounterLine() }} За бейдж DOVIRA на сайті або в соцмережах діє знижка 20%.
@endif

—
Ви отримали цей лист, бо профіль «{{ $profileName }}» представлений на платформі відгуків DOVIRA (mydovira.com), а ця адреса вказана як контактна у відкритих джерелах.
Відписатися: {!! $unsubscribeUrl !!}

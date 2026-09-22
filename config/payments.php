<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PRO payments: monopay / monobank acquiring
    |--------------------------------------------------------------------------
    |
    | MONOPAY_API_TOKEN comes from the monobank merchant cabinet. The webhook
    | signature is verified against MONOPAY_WEBHOOK_PUBLIC_KEY when provided,
    | otherwise the app fetches the public key from /api/merchant/pubkey.
    |
    */

    'monopay' => [
        'token' => env('MONOPAY_API_TOKEN'),
        'base_url' => env('MONOPAY_BASE_URL', 'https://api.monobank.ua/api/merchant'),
        'webhook_public_key' => env('MONOPAY_WEBHOOK_PUBLIC_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Вимикач нових оплат PRO
    |--------------------------------------------------------------------------
    |
    | PRO_CHECKOUT_ENABLED=false ховає кнопку оплати на сторінці pay/pro і
    | блокує створення інвойсів на сервері (ensureMonopayAvailable). Вебхуки
    | та автопродовження ВЖЕ активних підписок не зачіпає. Повідомлення можна
    | змінити через env без правки коду.
    |
    */

    'checkout' => [
        'enabled' => (bool) env('PRO_CHECKOUT_ENABLED', true),
        'paused_message' => env(
            'PRO_CHECKOUT_PAUSED_MESSAGE',
            'Оплата тимчасово недоступна — запрацює завтра. Ваш профіль і кабінет працюють як зазвичай.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Нагадування про продовження підписки (dovira:pro:renewal-reminders)
    |--------------------------------------------------------------------------
    |
    | manual_mode=true — режим міграції на новий мерчант-рахунок monobank
    | (вересень 2026): збережені картки старого рахунку не спишуться, тому
    | нагадування йдуть УСІМ активним підпискам, що закінчуються за ≤7 днів,
    | з поясненням «продовжіть вручну, далі знову автоматично».
    | manual_mode=false — штатний режим: нагадування лише тим, у кого
    | автопродовження не спрацює (вимкнене або без збереженої картки).
    |
    */

    'renewal_reminders' => [
        'manual_mode' => (bool) env('PRO_RENEWAL_MANUAL_MODE', true),
    ],

    'pro' => [
        'name' => env('PAY_PRO_NAME', 'DOVIRA PRO — підписка на 6 місяців'),
        'description' => env('PAY_PRO_DESCRIPTION', 'PRO-можливості профілю на DOVIRA: розширена сторінка, відповіді на відгуки, аналітика та заявки клієнтів.'),
        'amount' => (int) env('PAY_PRO_AMOUNT', \App\Support\ProPricing::CURRENT_AMOUNT),
        'currency' => env('PAY_PRO_CURRENCY', 'UAH'),
        'currency_code' => (int) env('PAY_PRO_CURRENCY_CODE', 980),
        'validity' => (int) env('PAY_PRO_VALIDITY_SECONDS', 3600),
    ],

];

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

    'pro' => [
        'name' => env('PAY_PRO_NAME', 'DOVIRA PRO — підписка на 6 місяців'),
        'description' => env('PAY_PRO_DESCRIPTION', 'PRO-можливості профілю на DOVIRA: розширена сторінка, відповіді на відгуки, аналітика та заявки клієнтів.'),
        'amount' => (int) env('PAY_PRO_AMOUNT', \App\Support\ProPricing::CURRENT_AMOUNT),
        'currency' => env('PAY_PRO_CURRENCY', 'UAH'),
        'currency_code' => (int) env('PAY_PRO_CURRENCY_CODE', 980),
        'validity' => (int) env('PAY_PRO_VALIDITY_SECONDS', 3600),
    ],

];

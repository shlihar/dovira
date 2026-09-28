<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Microsoft Clarity
    |--------------------------------------------------------------------------
    | Теплові карти й записи сесій публічного сайту. Порожній CLARITY_ID —
    | скрипт не підключається взагалі (локально, на тестах).
    */
    'clarity' => [
        'id' => env('CLARITY_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plerdy — кліки, теплові карти й відеозаписи сесій публічного сайту.
    | Порожній PLERDY_SITE_HASH вимикає скрипт повністю (локально, на тестах).
    */
    'plerdy' => [
        'site_hash' => env('PLERDY_SITE_HASH'),
        'suid' => env('PLERDY_SUID'),
    ],


    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Тригер-лист бізнесу про негативний відгук. Власникам заявлених профілів
    // шлемо завжди (вони підписались); холодний аутріч на НЕзаявлені профілі —
    // окремий прапорець, OFF за замовчанням (вмикати свідомо, підтвердивши
    // доставку SMTP і готовність до cold-email).
    'review_outreach' => [
        'enabled' => (bool) env('REVIEW_OUTREACH_ENABLED', false),
        'throttle_days' => (int) env('REVIEW_OUTREACH_THROTTLE_DAYS', 30),
    ],

    // Заявка клієнта на НЕзаявлений профіль → холодний лист на email профілю.
    'lead_outreach' => [
        'enabled' => (bool) env('LEAD_OUTREACH_ENABLED', false),
        'throttle_days' => (int) env('LEAD_OUTREACH_THROTTLE_DAYS', 14),
    ],

    // Профіль перетнув поріг переглядів → лист-тригер (крок кратний threshold).
    'views_milestone' => [
        'enabled' => (bool) env('VIEWS_MILESTONE_ENABLED', false),
        'threshold' => max(1, (int) env('VIEWS_MILESTONE_THRESHOLD', 100)),
    ],

    'telegram' => [
        'bot_name' => env('TELEGRAM_BOT_NAME'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'login_max_age' => env('TELEGRAM_LOGIN_MAX_AGE', 86400),
        // Куди слати адмін-сповіщення (новий відгук, профіль, заявка тощо).
        // Один або кілька chat_id через кому. Порожньо = сповіщення вимкнені.
        'admin_chat_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEGRAM_ADMIN_CHAT_ID', ''))
        ))),
        // Секрет вебхука бота (заголовок X-Telegram-Bot-Api-Secret-Token).
        // Реєстрація: php artisan dovira:telegram-set-webhook
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    // SMS для OTP-підтвердження профілю телефоном. Поки токен порожній —
    // канал «телефон» недоступний, працює лише email.
    'sms' => [
        'turbosms' => [
            'token' => env('TURBOSMS_TOKEN'),
            'sender' => env('TURBOSMS_SENDER', 'Dovira'),
        ],
    ],

];

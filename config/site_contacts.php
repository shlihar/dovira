<?php

$contactPhone = env('DOVIRA_CONTACT_PHONE');
$contactPhoneDigits = preg_replace('/\D+/', '', (string) $contactPhone);
$removedContactPhoneDigits = implode('', ['380', '937', '269', '578']);

return [
    'email' => env('DOVIRA_CONTACT_EMAIL', 'info@mydovira.com'),
    'telegram_username' => env('DOVIRA_SUPPORT_TELEGRAM', 'dovira_support'),
    'phone' => $contactPhoneDigits === $removedContactPhoneDigits ? null : $contactPhone,
];

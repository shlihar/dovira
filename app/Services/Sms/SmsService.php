<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Надсилання SMS через TurboSMS (UA). Увімкнено лише коли заданий токен —
 * інакше канал «телефон» для OTP не пропонується.
 */
class SmsService
{
    public function enabled(): bool
    {
        return filled(config('services.sms.turbosms.token'));
    }

    /**
     * Нормалізує номер до E.164-подібного (тільки цифри, з кодом країни).
     */
    public function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        // 0XXXXXXXXX → 380XXXXXXXXX (Україна)
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '38' . $digits;
        }
        if (strlen($digits) < 10) {
            return null;
        }

        return $digits;
    }

    public function send(string $phone, string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $recipient = $this->normalize($phone);
        if ($recipient === null) {
            return false;
        }

        try {
            $response = Http::withToken((string) config('services.sms.turbosms.token'))
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post('https://api.turbosms.ua/message/send.json', [
                    'recipients' => [$recipient],
                    'sms' => [
                        'sender' => (string) config('services.sms.turbosms.sender', 'Dovira'),
                        'text' => $text,
                    ],
                ]);

            // TurboSMS: успіх = HTTP 2xx + top-level response_code 0 + перший
            // результат по номеру теж 0 (0 = «прийнято до відправлення»).
            if ($response->successful()
                && (int) $response->json('response_code') === 0
                && (int) $response->json('response_result.0.response_code') === 0) {
                return true;
            }

            Log::warning('TurboSMS send failed', ['status' => $response->status(), 'body' => $response->json()]);
        } catch (\Throwable $e) {
            Log::warning('TurboSMS exception: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Баланс акаунта (для перевірки токена). null = не вдалося отримати.
     */
    public function balance(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('services.sms.turbosms.token'))
                ->acceptJson()
                ->timeout(15)
                ->get('https://api.turbosms.ua/user/balance.json');

            if ($response->successful() && (int) $response->json('response_code') === 0) {
                return (string) $response->json('response_result.balance');
            }
        } catch (\Throwable $e) {
            Log::warning('TurboSMS balance exception: ' . $e->getMessage());
        }

        return null;
    }
}

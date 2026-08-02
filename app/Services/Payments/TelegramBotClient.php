<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Мінімальний клієнт Telegram Bot API для адмін-сповіщень.
 */
class TelegramBotClient
{
    public function __construct(private readonly string $token)
    {
    }

    public static function make(): self
    {
        return new self((string) config('services.telegram.bot_token'));
    }

    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
            'parse_mode' => $parseMode,
        ], fn ($value) => $value !== null));
    }

    /**
     * Останні апдейти бота — щоб дізнатись chat_id адмінів (getUpdates
     * працює лише коли вебхук не встановлено).
     *
     * @return array<int, mixed>
     */
    public function getUpdates(): array
    {
        return (array) $this->call('getUpdates', []);
    }

    private function call(string $method, array $params = []): mixed
    {
        $response = Http::timeout(15)->post(
            "https://api.telegram.org/bot{$this->token}/{$method}",
            $params
        );

        $json = $response->json();

        if (! $response->successful() || ! ($json['ok'] ?? false)) {
            throw new RuntimeException("Telegram Bot API error on {$method}: ".$response->body());
        }

        return $json['result'];
    }
}

<?php

namespace App\Jobs;

use App\Services\Payments\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Надсилає одне адмін-повідомлення в Telegram. Кожен chat отримує окрему
 * job — щоб збій доставки одному адміну не блокував інших.
 */
class SendTelegramAdminMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $backoff = 10;

    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
    ) {
    }

    public function handle(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (blank(config('services.telegram.bot_token'))) {
            return;
        }

        try {
            TelegramBotClient::make()->sendMessage($this->chatId, $this->text, 'HTML');
        } catch (Throwable $exception) {
            Log::warning('telegram_admin_message_failed', [
                'chat_id' => $this->chatId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}

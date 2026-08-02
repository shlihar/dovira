<?php

namespace App\Console\Commands;

use App\Services\Payments\TelegramBotClient;
use Illuminate\Console\Command;
use Throwable;

class TelegramAdminTest extends Command
{
    protected $signature = 'dovira:telegram-admin-test
        {--chat-ids : Показати chat_id з останніх повідомлень боту (для налаштування)}';

    protected $description = 'Тестує адмін-сповіщення в Telegram: надсилає пробне повідомлення або показує chat_id.';

    public function handle(): int
    {
        if (blank(config('services.telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN не налаштовано в .env.');

            return self::FAILURE;
        }

        $client = TelegramBotClient::make();

        // Режим пошуку chat_id: напишіть боту будь-що і запустіть з --chat-ids.
        if ($this->option('chat-ids')) {
            try {
                $updates = $client->getUpdates();
            } catch (Throwable $exception) {
                $this->error('Не вдалося отримати апдейти (можливо, встановлено вебхук): '.$exception->getMessage());

                return self::FAILURE;
            }

            if ($updates === []) {
                $this->warn('Апдейтів немає. Напишіть боту будь-яке повідомлення і запустіть команду знову.');

                return self::SUCCESS;
            }

            $this->info('Знайдені чати (додайте потрібний у TELEGRAM_ADMIN_CHAT_ID):');
            $rows = [];
            foreach ($updates as $update) {
                $chat = data_get($update, 'message.chat') ?? data_get($update, 'my_chat_member.chat');
                if ($chat) {
                    $rows[data_get($chat, 'id')] = [
                        data_get($chat, 'id'),
                        data_get($chat, 'type'),
                        trim((string) (data_get($chat, 'title')
                            ?: data_get($chat, 'username')
                            ?: (data_get($chat, 'first_name').' '.data_get($chat, 'last_name')))),
                    ];
                }
            }
            $this->table(['chat_id', 'тип', 'назва / користувач'], array_values($rows));

            return self::SUCCESS;
        }

        $chatIds = (array) config('services.telegram.admin_chat_ids', []);

        if ($chatIds === []) {
            $this->error('TELEGRAM_ADMIN_CHAT_ID порожній. Дізнайтесь chat_id: напишіть боту, потім `--chat-ids`.');

            return self::FAILURE;
        }

        $text = "✅ <b>DOVIRA</b>: тестове адмін-сповіщення.\nЯкщо ви це бачите — Telegram-сповіщення працюють.";
        $ok = 0;

        foreach ($chatIds as $chatId) {
            try {
                $client->sendMessage((string) $chatId, $text, 'HTML');
                $this->line("  → надіслано в {$chatId}");
                $ok++;
            } catch (Throwable $exception) {
                $this->error("  → помилка для {$chatId}: ".$exception->getMessage());
            }
        }

        $this->info("Надіслано в {$ok} з ".count($chatIds).' чатів.');

        return self::SUCCESS;
    }
}

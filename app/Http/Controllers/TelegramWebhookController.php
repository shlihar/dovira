<?php

namespace App\Http\Controllers;

use App\Jobs\SendTelegramAdminMessage;
use App\Models\User;
use App\Services\Notifications\TelegramOwnerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Вебхук Telegram-бота: єдине призначення — підключення сповіщень власника.
 * /start {підписаний токен із кабінету} → зберігаємо chat_id користувача;
 * /stop → відключаємо. Решту повідомлень ігноруємо.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $message = $request->input('message', []);
        $chatId = (string) data_get($message, 'chat.id', '');
        $text = trim((string) data_get($message, 'text', ''));

        if ($chatId === '' || $text === '') {
            return response()->json(['ok' => true]);
        }

        if (str_starts_with($text, '/start')) {
            $payload = trim((string) substr($text, strlen('/start')));
            $userId = $payload !== '' ? TelegramOwnerNotifier::parseConnectPayload($payload) : null;
            $user = $userId ? User::query()->find($userId) : null;

            if ($user) {
                $user->forceFill(['telegram_notify_chat_id' => $chatId])->save();
                SendTelegramAdminMessage::dispatchAfterResponse(
                    $chatId,
                    "✅ <b>Сповіщення DOVIRA підключено</b>\nСюди приходитимуть нові заявки й відгуки по ваших профілях. Вимкнути: /stop або в налаштуваннях кабінету."
                );
            } else {
                SendTelegramAdminMessage::dispatchAfterResponse(
                    $chatId,
                    'Щоб підключити сповіщення, перейдіть за кнопкою «Підключити Telegram» у кабінеті DOVIRA — посилання містить ваш персональний код.'
                );
            }

            return response()->json(['ok' => true]);
        }

        if (str_starts_with($text, '/stop')) {
            User::query()->where('telegram_notify_chat_id', $chatId)->update(['telegram_notify_chat_id' => null]);
            SendTelegramAdminMessage::dispatchAfterResponse($chatId, 'Сповіщення вимкнено. Підключити знову можна з кабінету DOVIRA.');
        }

        return response()->json(['ok' => true]);
    }
}

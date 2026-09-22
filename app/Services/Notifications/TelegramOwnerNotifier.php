<?php

namespace App\Services\Notifications;

use App\Jobs\SendTelegramAdminMessage;
use App\Models\Profile;
use App\Models\ProfileLead;
use App\Models\ProfileReview;
use App\Models\User;
use App\Services\ProProfileNotificationService;

/**
 * Telegram-сповіщення ВЛАСНИКУ профілю (не адмінам): нова заявка, новий
 * відгук. Працює лише коли власник підключив бота в кабінеті (deep-link
 * /start → зберігаємо chat_id) і не вимкнув Telegram у налаштуваннях
 * сповіщень профілю.
 */
class TelegramOwnerNotifier
{
    /**
     * Підпис deep-link-а підключення: /start c{userId}x{sig}. Бот звіряє
     * підпис у вебхуку, тож чужий user_id підставити не можна.
     */
    public static function connectToken(User $user): string
    {
        return 'c' . $user->id . 'x' . self::signature((int) $user->id);
    }

    public static function connectUrl(User $user): ?string
    {
        $botName = trim((string) config('services.telegram.bot_name'));
        if ($botName === '') {
            return null;
        }

        return 'https://t.me/' . ltrim($botName, '@') . '?start=' . self::connectToken($user);
    }

    public static function parseConnectPayload(string $payload): ?int
    {
        if (! preg_match('/^c(\d+)x([0-9a-f]{16})$/', trim($payload), $matches)) {
            return null;
        }

        $userId = (int) $matches[1];

        return hash_equals(self::signature($userId), $matches[2]) ? $userId : null;
    }

    private static function signature(int $userId): string
    {
        return substr(hash_hmac('sha256', 'tg-owner-connect|' . $userId, (string) config('app.key')), 0, 16);
    }

    public function newLead(ProfileLead $lead): void
    {
        $profile = $lead->profile;
        if (! $profile || ! $this->channelEnabled($profile, 'lead_new_enabled')) {
            return;
        }

        $chatId = $this->ownerChatId($profile);
        if ($chatId === null) {
            return;
        }

        $isPro = $profile->hasActiveProSubscription() || (bool) ($profile->is_pro ?? false);
        $cabinetUrl = route('pro.account', ['tab' => 'leads', 'profile' => $profile->id]);

        // Контакти лідів без PRO приховані і в кабінеті — тож і в Telegram
        // не розкриваємо, лише факт заявки (той самий гейт монетизації).
        $text = "📥 <b>Нова заявка</b> — " . $this->escape((string) $profile->name) . "\n"
            . ($isPro
                ? ('Імʼя: ' . $this->escape((string) $lead->name) . "\nТелефон: " . $this->escape((string) $lead->phone)
                    . (filled($lead->message) ? "\nКоментар: " . $this->escape(mb_substr((string) $lead->message, 0, 300)) : ''))
                : 'Контакти доступні з підпискою PRO.')
            . "\n\n<a href=\"{$cabinetUrl}\">Відкрити заявки в кабінеті</a>";

        SendTelegramAdminMessage::dispatchAfterResponse($chatId, $text);
    }

    public function newReview(ProfileReview $review): void
    {
        $profile = $review->profile;
        if (! $profile) {
            return;
        }

        $isNegative = (float) $review->rating <= 2;
        $prefKey = $isNegative ? 'review_negative_enabled' : 'review_new_enabled';
        if (! $this->channelEnabled($profile, $prefKey)) {
            return;
        }

        $chatId = $this->ownerChatId($profile);
        if ($chatId === null) {
            return;
        }

        $stars = str_repeat('⭐', max(1, min(5, (int) round((float) $review->rating))));
        $cabinetUrl = route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]);
        $body = trim((string) $review->body);

        $text = ($isNegative ? '🔴 <b>Новий негативний відгук</b>' : '💬 <b>Новий відгук</b>')
            . ' — ' . $this->escape((string) $profile->name) . "\n"
            . $stars . ' ' . number_format((float) $review->rating, 0)
            . ($body !== '' ? "\n«" . $this->escape(mb_substr($body, 0, 300)) . '»' : '')
            . "\n\n<a href=\"{$cabinetUrl}\">Відповісти в кабінеті</a>";

        SendTelegramAdminMessage::dispatchAfterResponse($chatId, $text);
    }

    private function ownerChatId(Profile $profile): ?string
    {
        $owner = $profile->owner_user_id ? User::query()->find($profile->owner_user_id) : null;
        $chatId = trim((string) ($owner?->telegram_notify_chat_id ?? ''));

        return $chatId !== '' ? $chatId : null;
    }

    private function channelEnabled(Profile $profile, string $eventKey): bool
    {
        $prefs = app(ProProfileNotificationService::class)->resolve($profile);

        return ($prefs['telegram_enabled'] ?? true) && ($prefs[$eventKey] ?? true);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

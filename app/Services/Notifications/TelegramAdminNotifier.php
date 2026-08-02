<?php

namespace App\Services\Notifications;

use App\Jobs\SendTelegramAdminMessage;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\ProfileReview;
use App\Models\ProSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Адмін-сповіщення в Telegram про ключові події платформи: новий відгук,
 * новий профіль, заявка на профіль, PRO-підписка. Реальна доставка йде
 * через чергу (SendTelegramAdminMessage), тож виклики не блокують запит.
 */
class TelegramAdminNotifier
{
    /**
     * @return array<int, string>
     */
    private function chatIds(): array
    {
        return array_values(array_filter((array) config('services.telegram.admin_chat_ids', [])));
    }

    public function send(string $text): void
    {
        if (blank(config('services.telegram.bot_token'))) {
            return;
        }

        foreach ($this->chatIds() as $chatId) {
            SendTelegramAdminMessage::dispatch((string) $chatId, $text)->afterCommit();
        }
    }

    public function newReview(ProfileReview $review): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $review->loadMissing('profile');
        $profileName = $review->profile?->name ?? 'Профіль';
        $rating = (int) $review->rating;
        $stars = str_repeat('⭐', max(1, min(5, $rating)));
        $author = trim((string) ($review->author_name ?: 'Анонім'));
        $body = Str::limit(trim((string) $review->body), 400, '…');
        $icon = $rating <= 2 ? '🔴' : ($rating <= 3 ? '🟡' : '🟢');

        $lines = [
            "{$icon} <b>Новий відгук</b> — ".$this->escape($profileName),
            "{$stars} ({$rating}/5) від ".$this->escape($author),
        ];
        if ($body !== '') {
            $lines[] = '';
            $lines[] = $this->escape($body);
        }
        if ($url = $this->profileUrl($review->profile)) {
            $lines[] = '';
            $lines[] = $url;
        }

        $this->send(implode("\n", $lines));
    }

    public function newProfile(Profile $profile): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $lines = [
            '🆕 <b>Новий профіль створено власником</b>',
            $this->escape((string) $profile->name),
        ];
        if ($city = trim((string) $profile->city)) {
            $lines[] = '📍 '.$this->escape($city);
        }
        if ($profile->owner_user_id) {
            $profile->loadMissing('owner');
            $ownerEmail = trim((string) ($profile->owner?->email ?? ''));
            if ($ownerEmail !== '') {
                $lines[] = '👤 '.$this->escape($ownerEmail);
            }
        }

        $this->send(implode("\n", $lines));
    }

    public function newClaim(ProfileClaim $claim): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $claim->loadMissing(['profile', 'user']);

        $lines = [
            '📨 <b>Нова заявка на профіль</b>',
            $this->escape((string) ($claim->profile?->name ?? 'Профіль')),
        ];
        if ($email = trim((string) ($claim->user?->email ?? ''))) {
            $lines[] = '👤 '.$this->escape($email);
        }
        $lines[] = '';
        $lines[] = 'Перевірити у модерації заявок.';

        $this->send(implode("\n", $lines));
    }

    public function newProSubscription(ProSubscription $subscription): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $subscription->loadMissing('profile');

        $lines = [
            '💎 <b>Нова PRO-підписка</b>',
            $this->escape((string) ($subscription->profile?->name ?? 'Профіль')),
            'План: '.$this->escape((string) ($subscription->plan ?? '—')),
        ];

        $this->send(implode("\n", $lines));
    }

    private function profileUrl(?Profile $profile): ?string
    {
        if (! $profile || blank($profile->slug)) {
            return null;
        }

        return rescue(fn () => route('profile.show', ['slug' => $profile->slug]), null, false);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

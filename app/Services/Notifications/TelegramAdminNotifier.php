<?php

namespace App\Services\Notifications;

use App\Jobs\SendTelegramAdminMessage;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\ProfileReview;
use App\Models\ProSubscription;
use App\Models\ReviewReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Адмін-сповіщення в Telegram про ключові події платформи: новий відгук,
 * новий профіль, заявка на профіль, PRO-підписка. Доставляємо after response,
 * щоб повідомлення не залежали від окремого queue worker.
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
            SendTelegramAdminMessage::dispatchAfterResponse((string) $chatId, $text);
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

    public function newReviewReport(ReviewReport $report): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $report->loadMissing(['review.profile', 'reporter']);
        $review = $report->review;
        $profile = $review?->profile;
        $rating = (int) ($review?->rating ?? 0);

        $lines = [
            '🚩 <b>Нова скарга на відгук</b> — '.$this->escape($profile?->name ?? 'Профіль'),
        ];

        if ($review) {
            $stars = $rating > 0 ? str_repeat('⭐', max(1, min(5, $rating))) : '';
            $author = trim((string) ($review->author_name ?: 'Анонім'));
            $lines[] = trim("Відгук {$stars} ({$rating}/5) від ".$this->escape($author));
            $body = Str::limit(trim((string) $review->body), 200, '…');
            if ($body !== '') {
                $lines[] = '«'.$this->escape($body).'»';
            }
        }

        $lines[] = 'Причина: '.$this->escape(trim((string) $report->reason) ?: '—');
        if ($details = trim((string) $report->details)) {
            $lines[] = 'Деталі: '.$this->escape(Str::limit($details, 300, '…'));
        }
        if ($email = trim((string) ($report->reporter?->email ?? ''))) {
            $lines[] = '👤 '.$this->escape($email);
        }

        $lines[] = '';
        $lines[] = 'Розглянути у розділі «Скарги на відгуки» адмінки.';
        if ($url = $this->profileUrl($profile)) {
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

    public function newClaim(ProfileClaim $claim, bool $updated = false): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $claim->loadMissing(['profile', 'user']);

        $lines = [
            $updated ? '📨 <b>Заявку на профіль оновлено</b>' : '📨 <b>Нова заявка на профіль</b>',
            $this->escape((string) ($claim->profile?->name ?? 'Профіль')),
        ];
        if ($email = trim((string) ($claim->user?->email ?? ''))) {
            $lines[] = '👤 '.$this->escape($email);
        }
        if ($role = trim((string) $claim->company_role)) {
            $lines[] = 'Роль: '.$this->escape($role);
        }
        $lines[] = '';
        $lines[] = 'Перевірити у модерації заявок.';

        $this->send(implode("\n", $lines));
    }

    public function claimApproved(ProfileClaim $claim, string $source): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $claim->loadMissing(['profile', 'user']);

        $lines = [
            '✅ <b>Профіль підтверджено</b>',
            $this->escape((string) ($claim->profile?->name ?? 'Профіль')),
            'Джерело: '.$this->escape($source),
        ];
        if ($email = trim((string) ($claim->user?->email ?? ''))) {
            $lines[] = '👤 '.$this->escape($email);
        }
        if ($url = $this->profileUrl($claim->profile)) {
            $lines[] = '';
            $lines[] = $url;
        }

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

    public function newDossierRevisionRequest(\App\Models\DossierRevisionRequest $request): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $profile = $request->profile;
        $lines = [
            '📝 <b>Запит на перегляд досьє</b>',
            $this->escape((string) ($profile?->name ?? 'Профіль #' . $request->profile_id)),
            'Від: ' . $this->escape((string) ($request->user?->email ?? '—')),
            '',
            '«' . $this->escape(mb_substr((string) $request->message, 0, 700)) . '»',
        ];

        if ($url = $this->profileUrl($profile)) {
            $lines[] = $url;
        }

        $this->send(implode("\n", $lines));
    }

    /** Власник опублікував свою позицію щодо досьє — чекає на модерацію. */
    public function newOwnerStatement(Profile $profile): void
    {
        if ($this->chatIds() === []) {
            return;
        }

        $lines = [
            '🗣 <b>Позиція компанії щодо досьє</b>',
            $this->escape((string) $profile->name),
            '',
            '«' . $this->escape(mb_substr((string) $profile->owner_statement, 0, 700)) . '»',
            '',
            'Схвалити або відхилити — у картці профілю в адмінці.',
        ];

        if ($url = $this->profileUrl($profile)) {
            $lines[] = $url;
        }

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

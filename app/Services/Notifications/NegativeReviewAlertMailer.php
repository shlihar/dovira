<?php

namespace App\Services\Notifications;

use App\Mail\NegativeReviewAlert;
use App\Models\EmailLog;
use App\Models\Profile;
use App\Models\ProfileReview;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Надсилає бізнесу лист-тригер про негативний відгук.
 *  - Заявлений профіль → власнику (поважає перемикач review_negative_enabled).
 *  - Незаявлений профіль → холодний аутріч (лише коли увімкнено конфігом),
 *    з тротлінгом і opt-out.
 */
class NegativeReviewAlertMailer
{
    public function handle(ProfileReview $review): void
    {
        if ((float) $review->rating > 2) {
            return;
        }
        // Імпортовані (Google) відгуки не тригеримо — бізнес їх уже знає.
        if (filled($review->external_source_type)) {
            return;
        }

        $review->loadMissing('profile');
        $profile = $review->profile;
        if (! $profile) {
            return;
        }

        $prefs = (array) ($profile->notification_preferences ?? []);

        // --- Заявлений профіль: лист власнику ---
        if ($profile->owner_user_id) {
            $profile->loadMissing('owner');
            $ownerEmail = trim((string) ($profile->owner?->email ?? ''));
            if ($ownerEmail === '' || ($prefs['review_negative_enabled'] ?? true) === false) {
                return;
            }

            $this->send(
                $profile,
                $review,
                $ownerEmail,
                forOwner: true,
                userId: $profile->owner_user_id,
                ctaUrl: route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
            );

            return;
        }

        // --- Незаявлений профіль: холодний аутріч (за прапорцем) ---
        $this->sendOutreachFor($profile, $review);
    }

    /**
     * Аутріч-лист про негативний відгук на НЕзаявлений профіль. Усі гарди
     * (прапорець, opt-out, валідний email, тротлінг) всередині. Публічний,
     * бо його використовує і backlog-кампанія (dovira:outreach:negative-backlog),
     * якій треба обійти лише гард «імпортований відгук» у handle().
     */
    public function sendOutreachFor(Profile $profile, ProfileReview $review): bool
    {
        if (! config('services.review_outreach.enabled')) {
            return false;
        }

        $prefs = (array) ($profile->notification_preferences ?? []);
        if (! empty($prefs['outreach_opted_out'])) {
            return false;
        }

        $email = trim((string) ($profile->email ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($this->recentlyEmailed($email)) {
            return false;
        }

        $this->send(
            $profile,
            $review,
            $email,
            forOwner: false,
            userId: null,
            ctaUrl: route('profile.show', ['slug' => $profile->slug]),
            unsubscribeUrl: URL::signedRoute('review-outreach.unsubscribe', ['profile' => $profile->id]),
        );

        return true;
    }

    private function recentlyEmailed(string $email): bool
    {
        $days = max(1, (int) config('services.review_outreach.throttle_days', 30));

        // Дві теми: історична «Про ваш бізнес…» і чинна «Негативний відгук про…».
        return (bool) rescue(fn (): bool => EmailLog::query()
            ->where('to_email', $email)
            ->where(function ($q): void {
                $q->where('subject', 'like', 'Про ваш бізнес%')
                    ->orWhere('subject', 'like', 'Негативний відгук про%');
            })
            ->where('created_at', '>=', now()->subDays($days))
            ->exists(), false);
    }

    private function send(
        Profile $profile,
        ProfileReview $review,
        string $to,
        bool $forOwner,
        ?int $userId,
        string $ctaUrl,
        ?string $unsubscribeUrl = null,
    ): void {
        $mailable = new NegativeReviewAlert($profile, $review, $forOwner, $ctaUrl, $unsubscribeUrl);
        $status = 'sent';
        $error = null;

        try {
            Mail::to($to)->send($mailable);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::warning('Negative review alert failed to send.', [
                'profile_id' => $profile->id,
                'error' => $e->getMessage(),
            ]);
        }

        rescue(fn () => EmailLog::create([
            'user_id' => $userId,
            'to_email' => $to,
            'subject' => $mailable->envelope()->subject,
            'status' => $status,
            'error_message' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]));
    }
}

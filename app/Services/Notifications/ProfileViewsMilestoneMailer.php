<?php

namespace App\Services\Notifications;

use App\Mail\ProfileViewsMilestone;
use App\Models\EmailLog;
use App\Models\Profile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Надсилає лист-тригер, коли профіль перетнув черговий поріг переглядів
 * (кратний services.views_milestone.threshold, напр. 100/200/300).
 *  - Незаявлений профіль → холодний аутріч на email профілю.
 *  - Заявлений без PRO   → власнику (аргумент увімкнути PRO).
 *  - Заявлений з PRO     → пропускаємо: аналітика вже у кабінеті.
 * Кожен конкретний поріг шлеться рівно раз на адресу (дедуп за темою листа).
 */
class ProfileViewsMilestoneMailer
{
    public function handle(Profile $profile, int $views): void
    {
        if (! config('services.views_milestone.enabled')) {
            return;
        }

        // --- Заявлений профіль ---
        if ($profile->owner_user_id) {
            // З PRO аналітика вже доступна в кабінеті — не шумимо.
            if ($profile->hasActiveProSubscription()) {
                return;
            }

            $profile->loadMissing('owner');
            $ownerEmail = trim((string) ($profile->owner?->email ?? ''));
            if ($ownerEmail === '' || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $prefs = (array) ($profile->notification_preferences ?? []);
            if (($prefs['email_enabled'] ?? true) === false) {
                return;
            }

            $this->send(
                $profile,
                $views,
                $ownerEmail,
                forOwner: true,
                userId: $profile->owner_user_id,
                ctaUrl: route('pro.account.billing.pay', ['profile' => $profile->id]),
            );

            return;
        }

        // --- Незаявлений профіль: холодний аутріч ---
        $prefs = (array) ($profile->notification_preferences ?? []);
        if (! empty($prefs['outreach_opted_out'])) {
            return;
        }

        $email = trim((string) ($profile->email ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $this->send(
            $profile,
            $views,
            $email,
            forOwner: false,
            userId: null,
            ctaUrl: route('profile.show', ['slug' => $profile->slug]),
            unsubscribeUrl: URL::signedRoute('review-outreach.unsubscribe', ['profile' => $profile->id]),
        );
    }

    private function send(
        Profile $profile,
        int $views,
        string $to,
        bool $forOwner,
        ?int $userId,
        string $ctaUrl,
        ?string $unsubscribeUrl = null,
    ): void {
        $mailable = new ProfileViewsMilestone($profile, $views, $forOwner, $ctaUrl, $unsubscribeUrl);
        $subject = $mailable->envelope()->subject;

        // Дедуп: цей конкретний поріг уже слали на цю адресу — виходимо.
        $already = (bool) rescue(fn (): bool => EmailLog::query()
            ->where('to_email', $to)
            ->where('subject', $subject)
            ->exists(), false);
        if ($already) {
            return;
        }

        $status = 'sent';
        $error = null;

        try {
            Mail::to($to)->send($mailable);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::warning('Views milestone alert failed to send.', [
                'profile_id' => $profile->id,
                'views' => $views,
                'error' => $e->getMessage(),
            ]);
        }

        rescue(fn () => EmailLog::create([
            'user_id' => $userId,
            'to_email' => $to,
            'subject' => $subject,
            'status' => $status,
            'error_message' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]));
    }
}

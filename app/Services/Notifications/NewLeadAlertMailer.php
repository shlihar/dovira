<?php

namespace App\Services\Notifications;

use App\Mail\NewLeadAlert;
use App\Models\EmailLog;
use App\Models\Profile;
use App\Models\ProfileLead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Email-тригер про нову заявку клієнта.
 *  - Заявлений профіль → власнику (поважає email_enabled + lead_new_enabled;
 *    телефон клієнта видно лише PRO).
 *  - Незаявлений профіль → холодний аутріч на email профілю (лише коли увімкнено
 *    services.lead_outreach.enabled), з тротлінгом і opt-out — щоб перетворити
 *    вхідну заявку на привід підтвердити профіль.
 */
class NewLeadAlertMailer
{
    public function handle(ProfileLead $lead): void
    {
        $lead->loadMissing('profile');
        $profile = $lead->profile;

        if (! $profile) {
            return;
        }

        // --- Заявлений профіль: лист власнику ---
        if ($profile->owner_user_id) {
            $profile->loadMissing('owner');
            $ownerEmail = trim((string) ($profile->owner?->email ?? ''));
            if ($ownerEmail === '' || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $prefs = (array) ($profile->notification_preferences ?? []);
            if (($prefs['email_enabled'] ?? true) === false || ($prefs['lead_new_enabled'] ?? true) === false) {
                return;
            }

            $this->send(
                $profile,
                $lead,
                $ownerEmail,
                forOwner: true,
                userId: $profile->owner_user_id,
                contactsUnlocked: (bool) $profile->is_pro,
                ctaUrl: route('pro.account', ['tab' => 'leads', 'profile' => $profile->id]),
            );

            return;
        }

        // --- Незаявлений профіль: холодний аутріч (за прапорцем) ---
        if (! config('services.lead_outreach.enabled')) {
            return;
        }

        $prefs = (array) ($profile->notification_preferences ?? []);
        if (! empty($prefs['outreach_opted_out'])) {
            return;
        }

        $email = trim((string) ($profile->email ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if ($this->recentlyEmailed($email)) {
            return;
        }

        $this->send(
            $profile,
            $lead,
            $email,
            forOwner: false,
            userId: null,
            contactsUnlocked: false,
            ctaUrl: route('profile.show', ['slug' => $profile->slug]),
            unsubscribeUrl: URL::signedRoute('review-outreach.unsubscribe', ['profile' => $profile->id]),
        );
    }

    private function recentlyEmailed(string $email): bool
    {
        $days = max(1, (int) config('services.lead_outreach.throttle_days', 14));

        return (bool) rescue(fn (): bool => EmailLog::query()
            ->where('to_email', $email)
            ->where('subject', 'like', 'Вам залишили заявку%')
            ->where('created_at', '>=', now()->subDays($days))
            ->exists(), false);
    }

    private function send(
        Profile $profile,
        ProfileLead $lead,
        string $to,
        bool $forOwner,
        ?int $userId,
        bool $contactsUnlocked,
        string $ctaUrl,
        ?string $unsubscribeUrl = null,
    ): void {
        $mailable = new NewLeadAlert(
            $profile,
            $lead,
            contactsUnlocked: $contactsUnlocked,
            ctaUrl: $ctaUrl,
            forOwner: $forOwner,
            unsubscribeUrl: $unsubscribeUrl,
        );

        $status = 'sent';
        $error = null;

        try {
            Mail::to($to)->send($mailable);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::warning('New lead alert failed to send.', [
                'profile_id' => $profile->id,
                'lead_id' => $lead->id,
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

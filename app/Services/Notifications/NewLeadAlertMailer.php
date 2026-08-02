<?php

namespace App\Services\Notifications;

use App\Mail\NewLeadAlert;
use App\Models\EmailLog;
use App\Models\Profile;
use App\Models\ProfileLead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email-тригер власнику профілю про нову заявку клієнта.
 * Надсилається лише для заявлених профілів; поважає перемикачі
 * email_enabled та lead_new_enabled у налаштуваннях сповіщень.
 * Телефон клієнта видно тільки PRO-власникам (як у кабінеті).
 */
class NewLeadAlertMailer
{
    public function handle(ProfileLead $lead): void
    {
        $lead->loadMissing('profile');
        $profile = $lead->profile;

        if (! $profile || ! $profile->owner_user_id) {
            return;
        }

        $profile->loadMissing('owner');
        $ownerEmail = trim((string) ($profile->owner?->email ?? ''));
        if ($ownerEmail === '' || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $prefs = (array) ($profile->notification_preferences ?? []);
        if (($prefs['email_enabled'] ?? true) === false || ($prefs['lead_new_enabled'] ?? true) === false) {
            return;
        }

        $this->send($profile, $lead, $ownerEmail);
    }

    private function send(Profile $profile, ProfileLead $lead, string $to): void
    {
        $mailable = new NewLeadAlert(
            $profile,
            $lead,
            contactsUnlocked: (bool) $profile->is_pro,
            ctaUrl: route('pro.account', ['tab' => 'leads', 'profile' => $profile->id]),
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
            'user_id' => $profile->owner_user_id,
            'to_email' => $to,
            'subject' => $mailable->envelope()->subject,
            'status' => $status,
            'error_message' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]));
    }
}

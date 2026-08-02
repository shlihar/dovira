<?php

namespace App\Services;

use App\Models\PlatformNotification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class ProProfileNotificationService
{
    /**
     * @return array<string, bool>
     */
    public function defaults(): array
    {
        return [
            'in_app_enabled' => true,
            'email_enabled' => true,
            'digest_weekly_enabled' => true,
            'review_new_enabled' => true,
            'review_negative_enabled' => true,
            'review_unanswered_enabled' => true,
            'lead_new_enabled' => true,
            'profile_status_enabled' => true,
            'profile_completeness_enabled' => true,
            'analytics_drop_enabled' => false,
            'analytics_zero_conversion_enabled' => false,
            'analytics_digest_enabled' => true,
            'billing_payment_success_enabled' => true,
            'billing_payment_failed_enabled' => true,
            'billing_expiring_enabled' => true,
            'verification_status_enabled' => true,
            'verification_action_required_enabled' => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function resolve(Profile $profile): array
    {
        $stored = collect((array) ($profile->notification_preferences ?? []))
            ->mapWithKeys(fn ($value, $key) => [(string) $key => (bool) $value])
            ->all();

        return [
            ...$this->defaults(),
            ...$stored,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, bool>
     */
    public function normalizeSubmitted(array $values): array
    {
        $defaults = $this->defaults();

        return collect($defaults)
            ->mapWithKeys(fn ($default, $key) => [$key => (bool) ($values[$key] ?? false)])
            ->all();
    }

    public function update(Profile $profile, array $values): array
    {
        $normalized = $this->normalizeSubmitted($values);
        $profile->forceFill([
            'notification_preferences' => $normalized,
        ])->save();

        return $normalized;
    }

    public function enabled(Profile $profile, string $key): bool
    {
        return (bool) ($this->resolve($profile)[$key] ?? false);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function create(Profile $profile, string $type, string $title, ?string $body = null, array $meta = []): ?PlatformNotification
    {
        $profile = $profile->fresh() ?? $profile;
        $owner = $profile->owner;
        if (! $owner instanceof User) {
            return null;
        }

        if (! $this->isNotificationTypeEnabled($profile, $type)) {
            return null;
        }

        $notification = null;

        if ($this->enabled($profile, 'in_app_enabled')) {
            $notification = PlatformNotification::query()->create([
                'user_id' => $owner->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'meta' => [
                    'profile_id' => $profile->id,
                    ...$meta,
                ],
            ]);
        }

        $this->sendEmailIfEnabled($profile, $owner, $title, $body, (string) ($meta['action_url'] ?? ''));

        return $notification;
    }

    /**
     * @return Collection<int, PlatformNotification>
     */
    public function notificationsForProfile(Profile $profile, int $limit = 24): Collection
    {
        return PlatformNotification::query()
            ->where('user_id', (int) $profile->owner_user_id)
            ->where('meta->profile_id', $profile->id)
            // Заявки живуть у власній вкладці «Заявки» — тут не дублюємо.
            ->where('type', '!=', 'pro_lead_new')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function unreadCount(Profile $profile): int
    {
        return PlatformNotification::query()
            ->where('user_id', (int) $profile->owner_user_id)
            ->whereNull('read_at')
            ->where('meta->profile_id', $profile->id)
            // Заявки мають власний лічильник біля пункту «Заявки» —
            // у дзвіночку їх не дублюємо (включно з легасі-записами).
            ->where('type', '!=', 'pro_lead_new')
            ->count();
    }

    public function markAllAsRead(Profile $profile): void
    {
        PlatformNotification::query()
            ->where('user_id', (int) $profile->owner_user_id)
            ->whereNull('read_at')
            ->where('meta->profile_id', $profile->id)
            ->update([
                'read_at' => now(),
            ]);
    }

    private function isNotificationTypeEnabled(Profile $profile, string $type): bool
    {
        $preferenceKey = match ($type) {
            'pro_review_new' => 'review_new_enabled',
            'pro_review_negative' => 'review_negative_enabled',
            'pro_review_unanswered' => 'review_unanswered_enabled',
            'pro_profile_published', 'pro_profile_hidden' => 'profile_status_enabled',
            'pro_profile_incomplete' => 'profile_completeness_enabled',
            'pro_billing_paid', 'pro_billing_renewed' => 'billing_payment_success_enabled',
            'pro_billing_failed' => 'billing_payment_failed_enabled',
            'pro_billing_expiring', 'pro_billing_canceled' => 'billing_expiring_enabled',
            'pro_claim_approved', 'pro_claim_rejected', 'pro_claim_need_more_info' => 'verification_status_enabled',
            'pro_claim_action_required' => 'verification_action_required_enabled',
            default => null,
        };

        return $preferenceKey === null ? true : $this->enabled($profile, $preferenceKey);
    }

    private function sendEmailIfEnabled(Profile $profile, User $owner, string $title, ?string $body, string $actionUrl): void
    {
        if (! $owner->email_notifications_enabled || ! filled($owner->email) || ! $this->enabled($profile, 'email_enabled')) {
            return;
        }

        $messageBody = trim(implode("\n\n", array_filter([
            $title,
            $body,
            $actionUrl !== '' ? 'Відкрити: ' . $actionUrl : null,
        ])));

        try {
            Mail::raw($messageBody, function ($message) use ($owner, $title): void {
                $message->to($owner->email)->subject('DOVIRA PRO: ' . $title);
            });
        } catch (\Throwable) {
            // In-app notification remains the primary channel even if email delivery fails.
        }
    }
}

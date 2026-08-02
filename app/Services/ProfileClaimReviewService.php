<?php

namespace App\Services;

use App\Models\ProfileClaim;
use App\Services\Notifications\TelegramAdminNotifier;
use App\Services\ProProfileNotificationService;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfileClaimReviewService
{
    public function approve(ProfileClaim $claim): void
    {
        $this->grantOwnership($claim, Auth::id(), 'claim.approved');
    }

    /**
     * Автоматичне підтвердження після OTP (email/телефон, вказаний у профілі).
     * Модератор не потрібен — володіння контактом і є доказом.
     */
    public function approveViaVerification(ProfileClaim $claim, string $channel): void
    {
        $this->grantOwnership($claim, null, 'claim.approved_otp', ['channel' => $channel]);

        if ($claim->profile) {
            app(TelegramAdminNotifier::class)->send(
                "✅ <b>Профіль підтверджено автоматично (OTP)</b>\n"
                . htmlspecialchars((string) $claim->profile->name, ENT_QUOTES, 'UTF-8')
                . "\nКанал: {$channel}"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $extraAudit
     */
    private function grantOwnership(ProfileClaim $claim, ?int $reviewerId, string $auditEvent, array $extraAudit = []): void
    {
        DB::transaction(function () use ($claim, $reviewerId, $auditEvent, $extraAudit): void {
            $claim->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $claim->profile()->update([
                'owner_user_id' => $claim->user_id,
                'is_owner_verified' => true,
            ]);

            ProfileClaim::query()
                ->where('profile_id', $claim->profile_id)
                ->whereKeyNot($claim->id)
                ->whereIn('status', ['pending', 'need_more_info'])
                ->update([
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $reviewerId,
                    'reviewed_at' => now(),
                ]);

            AuditLogger::log($auditEvent, $claim, [
                'profile_id' => $claim->profile_id,
                'claimed_user_id' => $claim->user_id,
            ] + $extraAudit);

            if ($claim->profile) {
                app(ProProfileNotificationService::class)->create(
                    $claim->profile,
                    'pro_claim_approved',
                    'Профіль привʼязано до вашого акаунта',
                    'Заявку підтверджено. Тепер ви можете керувати профілем у PRO-кабінеті.',
                    [
                        'severity' => 'success',
                        'action_url' => route('pro.account', ['tab' => 'overview', 'profile' => $claim->profile_id]),
                        'claim_id' => $claim->id,
                    ]
                );
            }
        });
    }

    public function markNeedMoreInfo(ProfileClaim $claim): void
    {
        $claim->update([
            'status' => 'need_more_info',
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        AuditLogger::log('claim.need_more_info', $claim, [
            'profile_id' => $claim->profile_id,
            'claimed_user_id' => $claim->user_id,
        ]);

        if ($claim->profile) {
            app(ProProfileNotificationService::class)->create(
                $claim->profile,
                'pro_claim_need_more_info',
                'Потрібно доповнити заявку на привʼязку',
                'Перевірте заявку та дозавантажте потрібні дані або документи.',
                [
                    'severity' => 'warning',
                    'action_url' => route('pro.account', ['tab' => 'claims', 'claim_profile' => $claim->profile_id]),
                    'claim_id' => $claim->id,
                ]
            );
        }
    }

    public function reject(ProfileClaim $claim): void
    {
        $claim->update([
            'status' => 'rejected',
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        AuditLogger::log('claim.rejected', $claim, [
            'profile_id' => $claim->profile_id,
            'claimed_user_id' => $claim->user_id,
        ]);

        if ($claim->profile) {
            app(ProProfileNotificationService::class)->create(
                $claim->profile,
                'pro_claim_rejected',
                'Заявку на привʼязку відхилено',
                'Перевірте причину відхилення та за потреби подайте оновлену заявку.',
                [
                    'severity' => 'danger',
                    'action_url' => route('pro.account', ['tab' => 'claims', 'claim_profile' => $claim->profile_id]),
                    'claim_id' => $claim->id,
                ]
            );
        }
    }
}

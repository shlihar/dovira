<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\User;
use App\Services\Notifications\TelegramAdminNotifier;
use App\Services\ProProfileNotificationService;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfileClaimReviewService
{
    public function approve(ProfileClaim $claim): void
    {
        $claim->loadMissing(['profile', 'user']);

        $this->grantOwnership(
            $claim,
            Auth::id(),
            'claim.approved',
            [],
            'ручний апрув адміном',
        );
    }

    /**
     * Автоматичне підтвердження після OTP (email/телефон, вказаний у профілі).
     * Модератор не потрібен — володіння контактом і є доказом.
     */
    public function approveViaVerification(ProfileClaim $claim, string $channel): void
    {
        $claim->loadMissing(['profile', 'user']);

        $this->grantOwnership(
            $claim,
            null,
            'claim.approved_otp',
            ['channel' => $channel],
            $channel === 'email_link' ? 'магічний email-link' : "OTP: {$channel}",
        );
    }

    /**
     * Ручна привʼязка профілю до користувача адміном/підтримкою (без заявки від
     * користувача): з форми профілю в адмінці або з картки користувача. Проходить
     * через ту саму видачу володіння, тож власник отримує in-app + email, адмін —
     * Telegram, і лишається слід у «Заявках».
     */
    public function linkOwnerManually(Profile $profile, User $user): void
    {
        // Уже привʼязаний до цього ж юзера — нічого не робимо (уникаємо дублю
        // сповіщень при звичайному збереженні профілю).
        if ((int) $profile->owner_user_id === (int) $user->id && $profile->is_owner_verified) {
            return;
        }

        $claim = ProfileClaim::query()->firstOrNew([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
        ]);
        $claim->status = 'pending';
        $claim->save();
        $claim->setRelation('profile', $profile);
        $claim->setRelation('user', $user);

        $this->grantOwnership(
            $claim,
            Auth::id(),
            'claim.linked_manually',
            ['linked_by_admin' => Auth::id()],
            'ручна привʼязка адміном',
        );
    }

    /**
     * @param  array<string, mixed>  $extraAudit
     */
    private function grantOwnership(
        ProfileClaim $claim,
        ?int $reviewerId,
        string $auditEvent,
        array $extraAudit = [],
        ?string $adminTelegramSource = null,
    ): void {
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
                    'Профіль уже публічний на Dovira й може знаходитися в Google. Щоб редагувати інформацію, контакти, досьє та відповіді на відгуки, оформіть підписку.',
                    [
                        'severity' => 'success',
                        'force_email' => true,
                        'action_url' => route('pro.account.billing.pay', ['profile' => $claim->profile_id]),
                        'claim_id' => $claim->id,
                    ]
                );
            }
        });

        // Адмін-сповіщення в Telegram (доставка через чергу, afterCommit).
        if ($adminTelegramSource !== null && $adminTelegramSource !== '') {
            app(TelegramAdminNotifier::class)->claimApproved($claim, $adminTelegramSource);
        }

        // Вітальний лист post-claim nurture-послідовності (day 0). Решту кроків
        // (day 2/5/9/14) розсилає dovira:pro:nurture-claimed. rescue: збій листа
        // не має впливати на підтвердження прав.
        if ($profile = $claim->profile()->first()) {
            rescue(fn () => app(\App\Services\Notifications\ProNurtureMailer::class)->sendByKey($profile, 'welcome'));
        }
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

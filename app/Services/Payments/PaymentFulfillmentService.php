<?php

namespace App\Services\Payments;

use App\Models\PaymentOrder;
use App\Models\Profile;
use App\Models\ProSubscription;
use App\Services\Notifications\TelegramAdminNotifier;
use App\Services\ProProfileNotificationService;
use App\Support\ProPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentFulfillmentService
{
    public function markOrderPaid(PaymentOrder $order, array $attributes = []): PaymentOrder
    {
        return DB::transaction(function () use ($order, $attributes): PaymentOrder {
            /** @var PaymentOrder $lockedOrder */
            $lockedOrder = PaymentOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! $lockedOrder->markPaid($attributes)) {
                return $lockedOrder;
            }

            if ($lockedOrder->product_type === PaymentOrder::PRODUCT_PRO_SUBSCRIPTION) {
                $this->activateProSubscription($lockedOrder);
            }

            return $lockedOrder->refresh();
        });
    }

    private function activateProSubscription(PaymentOrder $order): void
    {
        if (! $order->profile_id || ! $order->user_id) {
            return;
        }

        /** @var Profile|null $profile */
        $profile = Profile::query()
            ->lockForUpdate()
            ->find($order->profile_id);

        if (! $profile) {
            return;
        }

        // Оплатити може власник або заявник (потік «оплата до підтвердження»):
        // заявка на привʼязку розглядається лише після активації підписки.
        $isOwner = (int) $profile->owner_user_id === (int) $order->user_id;
        $isClaimant = $profile->owner_user_id === null
            && \App\Models\ProfileClaim::query()
                ->where('profile_id', $profile->id)
                ->where('user_id', $order->user_id)
                ->exists();

        if (! $isOwner && ! $isClaimant) {
            return;
        }

        $now = now();
        $subscription = $profile->proSubscriptions()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('started_at')
            ->latest('id')
            ->lockForUpdate()
            ->first() ?? new ProSubscription(['profile_id' => $profile->id]);

        $isActive = $subscription->exists
            && $subscription->status === 'active'
            && (! $subscription->ends_at || $subscription->ends_at->isFuture());

        $billingAnchor = $isActive && $subscription->ends_at
            ? $subscription->ends_at->copy()
            : $now->copy();

        $configuredAmount = (int) config('payments.pro.amount', ProPricing::CURRENT_AMOUNT);
        $lockedAmount = $isActive && (int) $subscription->price_monthly > 0
            ? (int) $subscription->price_monthly
            : $configuredAmount;

        $subscription->fill([
            'plan' => 'pro',
            'billing_period' => ProPricing::PERIOD_KEY,
            'status' => 'active',
            'price_monthly' => $lockedAmount,
            'price_yearly' => $lockedAmount,
            'currency' => ProPricing::CURRENCY,
            'started_at' => $isActive && $subscription->started_at ? $subscription->started_at : $now,
            'ends_at' => $billingAnchor->copy()->addMonths(ProPricing::PERIOD_MONTHS),
            'canceled_at' => null,
        ])->save();

        $profile->forceFill(['is_pro' => true])->save();

        $provider = 'monopay';
        $reference = Str::upper($order->provider_charge_id ?: $order->provider_invoice_id ?: $order->token);

        $subscription->payments()->firstOrCreate(
            ['reference' => $reference],
            [
                'profile_id' => $profile->id,
                'user_id' => $order->user_id,
                'provider' => $provider,
                'plan' => 'pro',
                'billing_period' => ProPricing::PERIOD_KEY,
                'description' => sprintf(
                    'Оплата PRO (%s) — %s',
                    ProPricing::PERIOD_LABEL,
                    $now->translatedFormat('d F Y, H:i')
                ),
                'amount' => $lockedAmount,
                'currency' => ProPricing::CURRENCY,
                'status' => 'paid',
                'paid_at' => $order->paid_at ?: $now,
                'meta' => [
                    'payment_order_id' => $order->id,
                    'provider_amount' => $order->amount,
                    'provider_currency' => $order->currency,
                    'provider' => $provider,
                ],
            ]
        );

        if (! $isActive) {
            app(TelegramAdminNotifier::class)->newProSubscription($subscription);
        }

        app(ProProfileNotificationService::class)->create(
            $profile,
            $isActive ? 'pro_billing_renewed' : 'pro_billing_paid',
            $isActive ? 'PRO-підписку продовжено' : 'PRO-підписку активовано',
            $isActive
                ? 'Період підписки продовжено. Перевірте нову дату завершення в оплаті.'
                : 'Підписка активна. Тепер вам доступні відповіді на відгуки та PRO-інструменти.',
            [
                'severity' => 'success',
                'action_url' => route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
            ]
        );
    }

    /**
     * Повернення коштів (monopay status=reversed): ордер позначається reversed,
     * платіж — refunded, підписка скасовується, is_pro знімається, якщо в
     * профілю немає іншої активної підписки. Рефанд продовження свідомо
     * скасовує підписку цілком — спірні випадки адмін розбирає вручну
     * (приходить Telegram-сповіщення).
     */
    public function markOrderReversed(PaymentOrder $order, array $meta = []): PaymentOrder
    {
        return DB::transaction(function () use ($order, $meta): PaymentOrder {
            /** @var PaymentOrder $lockedOrder */
            $lockedOrder = PaymentOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            $wasPaid = $lockedOrder->isPaid();

            $lockedOrder->forceFill([
                'status' => PaymentOrder::STATUS_REVERSED,
                'meta' => array_merge((array) ($lockedOrder->meta ?? []), $meta),
            ])->save();

            if (! $wasPaid || $lockedOrder->product_type !== PaymentOrder::PRODUCT_PRO_SUBSCRIPTION) {
                return $lockedOrder;
            }

            $reference = Str::upper($lockedOrder->provider_charge_id ?: $lockedOrder->provider_invoice_id ?: $lockedOrder->token);

            $payment = \App\Models\ProSubscriptionPayment::query()
                ->where('reference', $reference)
                ->first();

            if ($payment) {
                $payment->forceFill(['status' => 'refunded'])->save();
            }

            $subscription = $payment?->subscription
                ?? ProSubscription::query()
                    ->where('profile_id', $lockedOrder->profile_id)
                    ->where('status', 'active')
                    ->latest('id')
                    ->first();

            if ($subscription && $subscription->status === 'active') {
                $subscription->forceFill([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ])->save();
            }

            /** @var Profile|null $profile */
            $profile = $lockedOrder->profile_id
                ? Profile::query()->lockForUpdate()->find($lockedOrder->profile_id)
                : null;

            if ($profile) {
                $hasOtherActive = $profile->proSubscriptions()
                    ->where('status', 'active')
                    ->where(function ($query): void {
                        $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                    })
                    ->exists();

                if (! $hasOtherActive && $profile->is_pro) {
                    $profile->forceFill(['is_pro' => false])->save();
                }

                app(ProProfileNotificationService::class)->create(
                    $profile,
                    'pro_billing_reversed',
                    'Оплату PRO повернено',
                    'Платіж скасовано (повернення коштів), PRO-підписку деактивовано. Якщо це помилка — зверніться в підтримку.',
                    ['severity' => 'warning']
                );
            }

            app(TelegramAdminNotifier::class)->send(sprintf(
                '↩️ Рефанд monopay: ордер #%d (%s), профіль #%s. Підписку скасовано.',
                $lockedOrder->id,
                $reference,
                (string) $lockedOrder->profile_id
            ));

            return $lockedOrder->refresh();
        });
    }
}

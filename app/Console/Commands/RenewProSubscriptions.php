<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Models\ProSubscription;
use App\Services\Notifications\TelegramOwnerNotifier;
use App\Services\Payments\MonopayClient;
use App\Services\Payments\PaymentFulfillmentService;
use App\Services\ProProfileNotificationService;
use App\Support\ProPricing;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Автопродовження PRO: списує наступні 6 місяців зі збереженої картки
 * (monopay wallet/payment) за день до завершення підписки. Невдале списання
 * ретраїться щодня, грейс — 3 дні після ends_at, далі автопродовження
 * вимикається і підписка згасає природно.
 *
 * Свідомо синхронно, без jobs: черга на хостингу спільна.
 */
class RenewProSubscriptions extends Command
{
    public const MAX_ATTEMPTS = 4;

    /** Грейс після ends_at, поки ретраїться списання. Його ж поважає
     *  dovira:pro:expire-subscriptions — інакше експірація вбила б ретраї. */
    public const GRACE_DAYS = 3;

    protected $signature = 'dovira:pro:renew-subscriptions {--dry-run : Показати, кого списали б, без реальних списань}';

    protected $description = 'Автосписання PRO-підписок зі збереженої картки monopay';

    public function handle(): int
    {
        $due = ProSubscription::query()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('monopay_card_token')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addDay())
            ->where('ends_at', '>=', now()->subDays(self::GRACE_DAYS))
            // Одна спроба на добу: якщо сьогодні вже фейлилось — не довбимо.
            ->where(function ($query): void {
                $query->whereNull('last_renew_failed_at')
                    ->orWhere('last_renew_failed_at', '<', now()->startOfDay());
            })
            ->with('profile')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Немає підписок до продовження.');

            return self::SUCCESS;
        }

        foreach ($due as $subscription) {
            $profile = $subscription->profile;
            $amount = (int) ($subscription->price_monthly ?: ProPricing::CURRENT_AMOUNT);
            $label = sprintf('#%d %s — %d грн', $subscription->id, $profile?->name ?? '?', $amount);

            if ($this->option('dry-run')) {
                $this->line('[dry-run] Списали б: ' . $label);

                continue;
            }

            if ($subscription->renew_attempts >= self::MAX_ATTEMPTS) {
                $this->disableAutoRenew($subscription, 'вичерпано спроби списання');
                $this->warn('Вимкнено автопродовження (спроби): ' . $label);

                continue;
            }

            try {
                $this->charge($subscription, $amount);
                $this->info('Продовжено: ' . $label);
            } catch (Throwable $exception) {
                report($exception);
                $subscription->forceFill([
                    'renew_attempts' => $subscription->renew_attempts + 1,
                    'last_renew_failed_at' => now(),
                ])->save();
                $this->notifyChargeFailed($subscription);
                $this->error('Не вдалося списати: ' . $label . ' — ' . $exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function charge(ProSubscription $subscription, int $amount): void
    {
        $profile = $subscription->profile;

        $order = PaymentOrder::create([
            'user_id' => (int) $profile->owner_user_id,
            'profile_id' => $profile->id,
            'token' => (string) Str::uuid(),
            'method' => PaymentOrder::METHOD_MONOPAY,
            'product_type' => PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => PaymentOrder::STATUS_PENDING,
            'amount' => (string) ($amount * 100),
            'currency' => (string) config('payments.pro.currency'),
            'description' => 'Автопродовження DOVIRA PRO — 6 місяців',
            'meta' => [
                'price_uah' => $amount,
                'auto_renewal' => true,
                'subscription_id' => $subscription->id,
                'monopay_wallet_id' => (string) $subscription->monopay_wallet_id,
            ],
        ]);

        $payment = MonopayClient::make()->walletPayment([
            'cardToken' => (string) $subscription->monopay_card_token,
            'amount' => (int) $order->amount,
            'ccy' => (int) config('payments.pro.currency_code'),
            // Merchant-ініційоване списання без участі клієнта (підписка).
            'initiationKind' => 'merchant',
            'merchantPaymInfo' => [
                'reference' => $order->token,
                'destination' => 'Оплата за інформаційні послуги, без ПДВ.',
                'comment' => 'Автопродовження PRO-підписки DOVIRA (6 місяців)',
            ],
            'webHookUrl' => route('webhooks.monopay'),
        ]);

        $invoiceId = (string) ($payment['invoiceId'] ?? '');
        if ($invoiceId !== '') {
            $order->update(['provider_invoice_id' => $invoiceId]);
        }

        $status = (string) ($payment['status'] ?? '');
        if ($status === 'success') {
            // Синхронний успіх — фулфілимо одразу, вебхук стане no-op.
            app(PaymentFulfillmentService::class)->markOrderPaid($order, [
                'provider_charge_id' => $invoiceId !== '' ? $invoiceId : null,
                'meta' => array_merge((array) ($order->meta ?? []), ['monopay_wallet_payment' => $payment]),
            ]);

            return;
        }

        if (in_array($status, ['failure', 'expired'], true)) {
            $order->forceFill([
                'status' => PaymentOrder::STATUS_FAILED,
                'meta' => array_merge((array) ($order->meta ?? []), ['monopay_wallet_payment' => $payment]),
            ])->save();

            throw new \RuntimeException('monopay wallet payment failed: ' . ($payment['failureReason'] ?? $status));
        }

        // processing / created: чекаємо вебхук — він зафулфілить продовження.
        $order->forceFill([
            'meta' => array_merge((array) ($order->meta ?? []), ['monopay_wallet_payment' => $payment]),
        ])->save();
    }

    private function disableAutoRenew(ProSubscription $subscription, string $reason): void
    {
        $subscription->forceFill(['auto_renew' => false])->save();

        if ($subscription->profile) {
            app(ProProfileNotificationService::class)->create(
                $subscription->profile,
                'pro_billing_renew_failed',
                'Автопродовження вимкнено',
                'Не вдалося списати оплату за підписку (' . $reason . '). Продовжіть PRO вручну, щоб не втратити інструменти.',
                [
                    'severity' => 'danger',
                    'action_url' => route('pro.account', ['tab' => 'billing', 'profile' => $subscription->profile_id]),
                ]
            );
        }
    }

    private function notifyChargeFailed(ProSubscription $subscription): void
    {
        $profile = $subscription->profile;
        if (! $profile) {
            return;
        }

        app(ProProfileNotificationService::class)->create(
            $profile,
            'pro_billing_renew_failed',
            'Не вдалося продовжити PRO',
            'Списання за підписку не пройшло. Перевірте картку — спробуємо ще раз завтра.',
            [
                'severity' => 'warning',
                'action_url' => route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
            ]
        );
    }
}

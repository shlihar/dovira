<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Services\Payments\MonopayClient;
use App\Services\Payments\PaymentFulfillmentService;
use Illuminate\Console\Command;

/**
 * Страховка від загублених вебхуків monopay: періодично звіряємо всі
 * pending-замовлення зі справжнім статусом інвойсу. success → той самий
 * штатний фулфілмент, що й у вебхука (ідемпотентний, під локом);
 * expired/failure → закриваємо замовлення як failed, щоб не звіряти вічно.
 *
 * Приклад появи: оплата профілю 553 (27.08) пройшла в monopay за 22 секунди,
 * але вебхук не оновив замовлення — PRO не активувався, поки не звірили руками.
 */
final class ReconcilePaymentOrders extends Command
{
    protected $signature = 'dovira:payments:reconcile
        {--min-age=10 : Мінімальний вік замовлення у хвилинах (свіжі лишаємо вебхуку)}
        {--dry-run : Лише показати розбіжності, нічого не змінювати}';

    protected $description = 'Звіряє pending-замовлення monopay: пропущений success → фулфілмент, expired/failure → failed';

    public function handle(PaymentFulfillmentService $fulfillment): int
    {
        $minAge = max(0, (int) $this->option('min-age'));
        $dryRun = (bool) $this->option('dry-run');

        $orders = PaymentOrder::query()
            ->where('status', PaymentOrder::STATUS_PENDING)
            ->where('method', 'monopay')
            ->whereNotNull('provider_invoice_id')
            ->where('created_at', '<=', now()->subMinutes($minAge))
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Pending-замовлень для звірки немає.');

            return self::SUCCESS;
        }

        $client = MonopayClient::make();
        $fulfilled = 0;
        $closed = 0;
        $untouched = 0;

        foreach ($orders as $order) {
            $invoice = rescue(fn (): ?array => $client->getInvoice((string) $order->provider_invoice_id), null, false);
            $status = (string) ($invoice['status'] ?? '');

            if ($status === 'success') {
                $this->warn("#{$order->id} (profile {$order->profile_id}): ОПЛАЧЕНО в monopay, а в нас pending — "
                    . ($dryRun ? 'потребує фулфілменту' : 'виконую фулфілмент'));
                if (! $dryRun) {
                    $fulfillment->markOrderPaid($order, [
                        'provider_charge_id' => (string) $order->provider_invoice_id,
                        'meta' => array_merge((array) ($order->meta ?? []), [
                            'monopay_webhook' => $invoice,
                            'fulfilled_via' => 'reconcile_' . now()->toDateString(),
                        ]),
                    ]);
                }
                $fulfilled++;

                continue;
            }

            if (in_array($status, ['expired', 'failure', 'reversed'], true)) {
                $this->line("#{$order->id} (profile {$order->profile_id}): monopay={$status} → "
                    . ($dryRun ? 'буде закрито як failed' : 'закрито як failed'));
                if (! $dryRun) {
                    $order->update([
                        'status' => PaymentOrder::STATUS_FAILED,
                        'meta' => array_merge((array) ($order->meta ?? []), [
                            'monopay_final_status' => $status,
                            'reconciled_at' => now()->toIso8601String(),
                        ]),
                    ]);
                }
                $closed++;

                continue;
            }

            // created/processing/holded або недоступний API — лишаємо на наступну звірку.
            $untouched++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')
            . "Звірено: {$orders->count()} | фулфілмент: {$fulfilled} | закрито failed: {$closed} | без змін: {$untouched}");

        return self::SUCCESS;
    }
}

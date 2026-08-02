<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use App\Services\Payments\MonopayClient;
use App\Services\Payments\PaymentFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentWebhookController extends Controller
{
    /**
     * Webhook monobank acquiring / monopay.
     *
     * Mono sends the invoice status payload signed in the X-Sign header. The
     * PRO subscription is activated only for the final successful status.
     */
    public function monopay(Request $request): Response
    {
        $rawBody = $request->getContent();

        if (! MonopayClient::make()->verifyWebhookSignature($rawBody, $request->header('X-Sign'))) {
            abort(403);
        }

        $payload = $request->json()->all();
        $status = (string) ($payload['status'] ?? '');

        if (! in_array($status, ['success', 'reversed', 'failure', 'expired'], true)) {
            return response('OK');
        }

        $reference = (string) ($payload['reference'] ?? '');
        $invoiceId = (string) ($payload['invoiceId'] ?? '');

        $order = PaymentOrder::query()
            ->where('method', PaymentOrder::METHOD_MONOPAY)
            ->where(function ($query) use ($reference, $invoiceId): void {
                $query->where('token', $reference);

                if ($invoiceId !== '') {
                    $query->orWhere('provider_invoice_id', $invoiceId);
                }
            })
            ->first();

        if (! $order) {
            return response('OK');
        }

        if ($status === 'success') {
            app(PaymentFulfillmentService::class)->markOrderPaid($order, [
                'provider_charge_id' => $invoiceId !== '' ? $invoiceId : null,
                'meta' => array_merge((array) ($order->meta ?? []), [
                    'monopay_webhook' => $payload,
                ]),
            ]);
        } elseif ($status === 'reversed') {
            app(PaymentFulfillmentService::class)->markOrderReversed($order, [
                'monopay_webhook_reversed' => $payload,
            ]);
        } elseif ($order->status === PaymentOrder::STATUS_PENDING) {
            // failure / expired: фіксуємо провал лише для неоплачених ордерів,
            // щоб пізній ретрай webhook не перетер оплачений статус.
            $order->forceFill([
                'status' => PaymentOrder::STATUS_FAILED,
                'meta' => array_merge((array) ($order->meta ?? []), [
                    'monopay_webhook_failed' => $payload,
                ]),
            ])->save();
        }

        return response('OK');
    }
}

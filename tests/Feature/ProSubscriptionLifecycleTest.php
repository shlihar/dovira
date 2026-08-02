<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\Profile;
use App\Models\ProSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProSubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfile(array $attributes = []): Profile
    {
        return Profile::query()->create(array_merge([
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-lifecycle-' . uniqid(),
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ], $attributes));
    }

    public function test_expired_subscription_no_longer_grants_pro_access(): void
    {
        $profile = $this->makeProfile(['is_pro' => true]);

        $profile->proSubscriptions()->create([
            'plan' => 'pro',
            'billing_period' => 'halfyear',
            'status' => 'active',
            'started_at' => now()->subMonths(7),
            'ends_at' => now()->subDay(),
        ]);

        // is_pro ще true (команда експірації не бігала), але доступ уже ні.
        $this->assertFalse($profile->hasActiveProSubscription());
    }

    public function test_admin_granted_pro_without_subscriptions_keeps_access(): void
    {
        $profile = $this->makeProfile(['is_pro' => true]);

        $this->assertTrue($profile->hasActiveProSubscription());
    }

    public function test_valid_subscription_grants_pro_access(): void
    {
        $profile = $this->makeProfile(['is_pro' => true]);

        $profile->proSubscriptions()->create([
            'plan' => 'pro',
            'billing_period' => 'halfyear',
            'status' => 'active',
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(5),
        ]);

        $this->assertTrue($profile->hasActiveProSubscription());
    }

    public function test_expire_command_deactivates_overdue_subscriptions(): void
    {
        $expiredProfile = $this->makeProfile(['is_pro' => true]);
        $expiredProfile->proSubscriptions()->create([
            'plan' => 'pro',
            'billing_period' => 'halfyear',
            'status' => 'active',
            'started_at' => now()->subMonths(7),
            'ends_at' => now()->subDay(),
        ]);

        $activeProfile = $this->makeProfile(['is_pro' => true, 'slug' => 'still-active-' . uniqid()]);
        $activeProfile->proSubscriptions()->create([
            'plan' => 'pro',
            'billing_period' => 'halfyear',
            'status' => 'active',
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(5),
        ]);

        $this->artisan('dovira:pro:expire-subscriptions')->assertSuccessful();

        $expiredProfile->refresh();
        $activeProfile->refresh();

        $this->assertFalse($expiredProfile->is_pro);
        $this->assertSame('expired', $expiredProfile->proSubscriptions()->first()->status);
        $this->assertTrue($activeProfile->is_pro);
        $this->assertSame('active', $activeProfile->proSubscriptions()->first()->status);
    }

    public function test_monopay_reversed_webhook_revokes_pro(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $details = openssl_pkey_get_details($privateKey);

        config([
            'payments.monopay.webhook_public_key' => base64_encode((string) $details['key']),
            'payments.pro.amount' => 1999,
        ]);

        $user = User::factory()->create();
        $profile = $this->makeProfile(['owner_user_id' => $user->id]);

        $order = PaymentOrder::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'token' => '22222222-2222-4222-8222-222222222222',
            'method' => PaymentOrder::METHOD_MONOPAY,
            'product_type' => PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => PaymentOrder::STATUS_PENDING,
            'amount' => '199900',
            'currency' => 'UAH',
            'provider_invoice_id' => 'mono-invoice-rev-1',
        ]);

        $sign = function (array $payload) use ($privateKey): array {
            $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            openssl_sign($rawBody, $signature, $privateKey, OPENSSL_ALGO_SHA256);

            return [$rawBody, [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGN' => base64_encode($signature),
            ]];
        };

        [$successBody, $successServer] = $sign([
            'invoiceId' => 'mono-invoice-rev-1',
            'status' => 'success',
            'amount' => 199900,
            'ccy' => 980,
            'reference' => $order->token,
        ]);

        $this
            ->call('POST', route('webhooks.monopay'), [], [], [], $successServer, $successBody)
            ->assertOk();

        $profile->refresh();
        $this->assertTrue($profile->is_pro);

        [$reversedBody, $reversedServer] = $sign([
            'invoiceId' => 'mono-invoice-rev-1',
            'status' => 'reversed',
            'amount' => 199900,
            'ccy' => 980,
            'reference' => $order->token,
        ]);

        $this
            ->call('POST', route('webhooks.monopay'), [], [], [], $reversedServer, $reversedBody)
            ->assertOk();

        $profile->refresh();
        $order->refresh();

        $this->assertSame(PaymentOrder::STATUS_REVERSED, $order->status);
        $this->assertFalse($profile->is_pro);
        $this->assertFalse($profile->hasActiveProSubscription());
        $this->assertDatabaseHas('pro_subscriptions', [
            'profile_id' => $profile->id,
            'status' => 'canceled',
        ]);
        $this->assertDatabaseHas('pro_subscription_payments', [
            'profile_id' => $profile->id,
            'reference' => 'MONO-INVOICE-REV-1',
            'status' => 'refunded',
        ]);
    }

    public function test_monopay_failure_webhook_marks_pending_order_failed(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $details = openssl_pkey_get_details($privateKey);

        config(['payments.monopay.webhook_public_key' => base64_encode((string) $details['key'])]);

        $user = User::factory()->create();
        $profile = $this->makeProfile(['owner_user_id' => $user->id]);

        $order = PaymentOrder::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'token' => '33333333-3333-4333-8333-333333333333',
            'method' => PaymentOrder::METHOD_MONOPAY,
            'product_type' => PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => PaymentOrder::STATUS_PENDING,
            'amount' => '199900',
            'currency' => 'UAH',
            'provider_invoice_id' => 'mono-invoice-fail-1',
        ]);

        $rawBody = json_encode([
            'invoiceId' => 'mono-invoice-fail-1',
            'status' => 'failure',
            'reference' => $order->token,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        openssl_sign($rawBody, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $this
            ->call('POST', route('webhooks.monopay'), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGN' => base64_encode($signature),
            ], $rawBody)
            ->assertOk();

        $order->refresh();
        $this->assertSame(PaymentOrder::STATUS_FAILED, $order->status);
        $this->assertFalse($profile->refresh()->is_pro);
    }
}

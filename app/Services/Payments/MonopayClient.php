<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MonopayClient
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly ?string $webhookPublicKey,
    ) {
    }

    public static function make(): self
    {
        return new self(
            (string) config('payments.monopay.token'),
            rtrim((string) config('payments.monopay.base_url'), '/'),
            filled(config('payments.monopay.webhook_public_key'))
                ? (string) config('payments.monopay.webhook_public_key')
                : null,
        );
    }

    public function createInvoice(array $params): array
    {
        return $this->call('invoice/create', $params);
    }

    public function getInvoice(string $invoiceId): ?array
    {
        return $this->call("invoice/status?invoiceId={$invoiceId}", [], 'get');
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $signatureBytes = base64_decode($signature, true);
        $publicKeyPem = $this->webhookPublicKeyPem();

        if ($signatureBytes === false || $publicKeyPem === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        return openssl_verify($rawBody, $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function webhookPublicKeyPem(): ?string
    {
        if ($this->webhookPublicKey !== null) {
            return $this->decodePublicKey($this->webhookPublicKey);
        }

        if ($this->token === '') {
            return null;
        }

        return Cache::remember('payments:monopay:webhook-public-key', now()->addDay(), function (): ?string {
            $result = $this->call('pubkey', [], 'get');

            return $this->decodePublicKey((string) ($result['key'] ?? ''));
        });
    }

    private function decodePublicKey(string $publicKey): ?string
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '') {
            return null;
        }

        if (str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
            return $publicKey;
        }

        $decoded = base64_decode($publicKey, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    private function call(string $method, array $params = [], string $httpMethod = 'post'): array
    {
        if ($this->token === '') {
            throw new RuntimeException('Monopay API token is not configured.');
        }

        $request = Http::withHeaders(['X-Token' => $this->token])
            ->timeout(15);

        $response = $httpMethod === 'get'
            ? $request->get("{$this->baseUrl}/{$method}")
            : $request->post("{$this->baseUrl}/{$method}", $params);

        if (! $response->successful()) {
            throw new RuntimeException("Monopay API error on {$method}: " . $response->body());
        }

        return (array) $response->json();
    }
}

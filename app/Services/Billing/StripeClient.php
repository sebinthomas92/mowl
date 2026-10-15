<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** A thin wrapper over the Stripe REST API, enough for Checkout, the customer portal and webhooks. */
class StripeClient
{
    private const BASE_URL = 'https://api.stripe.com/v1/';

    /** Pinned so fetched charges still carry their `invoice`, which later API versions dropped. */
    private const API_VERSION = '2025-02-24.acacia';

    public function configured(): bool
    {
        return filled(config('billing.stripe.secret'));
    }

    /** Payments can start only when webhooks can also be verified, or renewals would never land. */
    public function readyForCheckout(): bool
    {
        return $this->configured() && filled(config('billing.stripe.webhook_secret'));
    }

    public function testMode(): bool
    {
        return str_starts_with((string) config('billing.stripe.secret'), 'sk_test_') || str_starts_with((string) config('billing.stripe.secret'), 'rk_test_');
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $path, $params, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path, []);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $params, ?string $idempotencyKey = null): array
    {
        if (! $this->configured()) {
            throw new StripeException('Stripe is not configured. Add STRIPE_SECRET to the environment.');
        }

        $request = $this->request();
        if ($idempotencyKey) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        try {
            $response = match ($method) {
                'GET' => $request->get(self::BASE_URL.$path, $params),
                'DELETE' => $request->delete(self::BASE_URL.$path),
                default => $request->asForm()->post(self::BASE_URL.$path, $params),
            };
        } catch (ConnectionException $exception) {
            throw new StripeException('Stripe could not be reached. Try again in a moment.', 0, 'connection_error');
        }

        if ($response->failed()) {
            throw new StripeException((string) ($response->json('error.message') ?: 'Stripe request failed.'), $response->status(), (string) $response->json('error.code'));
        }

        return (array) $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('billing.stripe.secret'))
            ->withHeaders(['Stripe-Version' => self::API_VERSION])
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(8);
    }
}

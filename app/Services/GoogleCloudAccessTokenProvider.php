<?php

namespace App\Services;

use Google\Auth\FetchAuthTokenInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCloudAccessTokenProvider implements FetchAuthTokenInterface
{
    private ?array $lastToken = null;

    public function fetchAuthToken(?callable $httpHandler = null): array
    {
        if (($this->lastToken['expires_at'] ?? 0) > time() + 60) {
            return $this->lastToken;
        }

        $subjectToken = $this->vercelOidcToken();
        if (! $subjectToken) {
            throw new RuntimeException('A Vercel OIDC token is required to authenticate with Google Cloud.');
        }
        $subjectToken = $this->exchangeVercelAudience($subjectToken);

        $audience = config('campaigns.google.sts_audience');
        $stsUrl = config('campaigns.google.sts_token_url');
        $impersonationUrl = config('campaigns.google.service_account_impersonation_url');
        if (! $audience || ! $stsUrl || ! $impersonationUrl) {
            throw new RuntimeException('Google Cloud Workload Identity Federation is not fully configured.');
        }

        $federated = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($stsUrl, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'audience' => $audience,
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
                'subject_token' => $subjectToken,
            ]);

        if ($federated->failed() || ! $federated->json('access_token')) {
            throw new RuntimeException('Google Security Token Service rejected the Vercel production identity.');
        }

        $impersonated = Http::withToken($federated->json('access_token'))
            ->acceptJson()
            ->timeout(30)
            ->post($impersonationUrl, [
                'scope' => ['https://www.googleapis.com/auth/cloud-platform'],
                'lifetime' => '3600s',
            ]);

        if ($impersonated->failed() || ! $impersonated->json('accessToken')) {
            throw new RuntimeException('Google IAM could not impersonate the Marketing Owl service account.');
        }

        $expiresAt = strtotime((string) $impersonated->json('expireTime')) ?: time() + 3500;

        return $this->lastToken = [
            'access_token' => $impersonated->json('accessToken'),
            'expires_at' => $expiresAt,
            'expires_in' => max(0, $expiresAt - time()),
        ];
    }

    public function getCacheKey(): string
    {
        return 'marketing-owl-google-cloud:'.config('campaigns.google.service_account_email');
    }

    public function getLastReceivedToken(): ?array
    {
        return $this->lastToken;
    }

    private function vercelOidcToken(): ?string
    {
        $requestToken = app()->bound('request') ? request()->header('x-vercel-oidc-token') : null;

        return $requestToken ?: config('services.vercel.oidc_token');
    }

    private function exchangeVercelAudience(string $token): string
    {
        $audience = config('campaigns.google.vercel_audience');
        if (! $audience) {
            return $token;
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->post(config('campaigns.google.vercel_token_exchange_url'), [
                'token' => $token,
                'aud' => $audience,
            ]);

        if ($response->failed() || ! $response->json('token')) {
            throw new RuntimeException('Vercel could not issue the Google-specific OIDC token.');
        }

        return $response->json('token');
    }
}

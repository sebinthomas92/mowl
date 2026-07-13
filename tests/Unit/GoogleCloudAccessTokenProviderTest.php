<?php

namespace Tests\Unit;

use App\Services\GoogleCloudAccessTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GoogleCloudAccessTokenProviderTest extends TestCase
{
    public function test_it_exchanges_vercel_oidc_for_an_impersonated_google_access_token(): void
    {
        $this->configureGoogleAuth();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response([
                'access_token' => 'federated-token',
                'expires_in' => 3600,
            ]),
            'iamcredentials.googleapis.com/*' => Http::response([
                'accessToken' => 'google-access-token',
                'expireTime' => now()->addHour()->toIso8601String(),
            ]),
        ]);

        $provider = app(GoogleCloudAccessTokenProvider::class);
        $token = $provider->fetchAuthToken();

        $this->assertSame('google-access-token', $token['access_token']);
        $this->assertGreaterThan(time(), $token['expires_at']);
        $this->assertSame($token, $provider->fetchAuthToken());
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oidc.vercel.com/~token'
            && $request['token'] === 'vercel-production-jwt'
            && $request['aud'] === 'https://iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider'
        );
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sts.googleapis.com/v1/token'
            && $request['subject_token'] === 'vercel-google-audience-jwt'
            && $request['audience'] === '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider'
        );
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'iamcredentials.googleapis.com')
            && $request->hasHeader('Authorization', 'Bearer federated-token')
        );
    }

    public function test_it_refuses_to_authenticate_without_a_vercel_oidc_token(): void
    {
        $this->configureGoogleAuth();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vercel OIDC token');

        app(GoogleCloudAccessTokenProvider::class)->fetchAuthToken();
    }

    private function configureGoogleAuth(): void
    {
        config()->set('campaigns.google.sts_audience', '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_audience', 'https://iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_token_exchange_url', 'https://oidc.vercel.com/~token');
        config()->set('campaigns.google.sts_token_url', 'https://sts.googleapis.com/v1/token');
        config()->set('campaigns.google.service_account_impersonation_url', 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/test@example.iam.gserviceaccount.com:generateAccessToken');
    }
}

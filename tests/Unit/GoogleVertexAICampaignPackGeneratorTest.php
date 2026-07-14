<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\GoogleVertexAICampaignPackGenerator;
use App\Services\GoogleVertexAIClient;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleVertexAICampaignPackGeneratorTest extends TestCase
{
    public function test_it_generates_a_structured_campaign_pack_with_vertex_ai(): void
    {
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => Http::response([
                'modelVersion' => 'gemini-3.5-flash',
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => json_encode($this->campaignOutput())]]],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 1200,
                    'cachedContentTokenCount' => 200,
                    'candidatesTokenCount' => 500,
                ],
            ], 200, ['x-request-id' => 'vertex-request-123']),
        ]);

        $result = app(GoogleVertexAICampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            [
                'description' => 'Everyday tote',
                'content' => 'A roomy canvas tote.',
                'product_truth' => ['name' => 'Canvas Tote'],
            ],
        );

        $this->assertSame('google', $result->provider);
        $this->assertSame('gemini-3.5-flash', $result->model);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(200, $result->cachedInputTokens);
        $this->assertSame(500, $result->outputTokens);
        $this->assertStringContainsString('Canvas Tote', $result->content['overview']['summary']);
        $this->assertSame('vertex-request-123', $result->providerRequestId);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'aiplatform.googleapis.com')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer google-access-token')
                && str_contains($request->url(), '/models/gemini-3.5-flash:generateContent')
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && $request['generationConfig']['responseSchema']['additionalProperties'] === false
                && count($request['contents'][0]['parts']) === 1
                && ! str_contains($request['contents'][0]['parts'][0]['text'], 'MEDIA ANALYSIS');
        });
    }

    public function test_it_transcribes_video_audio_with_the_same_vertex_credentials(): void
    {
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'A clear product demonstration.']]]]],
            ]),
        ]);
        $audio = tempnam(sys_get_temp_dir(), 'marketing-owl-audio-');
        file_put_contents($audio, 'test audio bytes');

        try {
            $transcript = app(GoogleVertexAIClient::class)->transcribeAudio($audio);
        } finally {
            @unlink($audio);
        }

        $this->assertSame('A clear product demonstration.', $transcript);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'aiplatform.googleapis.com')
            && $request['contents'][0]['parts'][1]['inlineData']['mimeType'] === 'audio/wav'
        );
    }

    private function configureGoogle(): void
    {
        config()->set('campaigns.google.project', 'callingdesk-marketingowl');
        config()->set('campaigns.google.location', 'global');
        config()->set('campaigns.google.model', 'gemini-3.5-flash');
        config()->set('campaigns.google.base_url', 'https://aiplatform.googleapis.com/v1');
        config()->set('filesystems.disks.gcs.bucket', 'marketing-owl-test-bucket');
        config()->set('campaigns.google.sts_audience', '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_audience', 'https://iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_token_exchange_url', 'https://oidc.vercel.com/~token');
        config()->set('campaigns.google.sts_token_url', 'https://sts.googleapis.com/v1/token');
        config()->set('campaigns.google.service_account_impersonation_url', 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/test@example.iam.gserviceaccount.com:generateAccessToken');
        config()->set('campaigns.google.retry_attempts', 1);
        config()->set('campaigns.google.retry_backoff_ms', [0]);
    }

    private function campaignOutput(): array
    {
        $result = app(MockCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']],
        );

        return $result->content + ['evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags];
    }
}

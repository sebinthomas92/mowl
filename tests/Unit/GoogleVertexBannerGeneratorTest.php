<?php

namespace Tests\Unit;

use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Services\GoogleVertexBannerGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleVertexBannerGeneratorTest extends TestCase
{
    public function test_it_requests_one_k_4_by_5_image_with_only_the_first_product_reference_and_maps_usage(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        Storage::disk('local')->put('second-product.png', 'second-product-bytes');
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $generated = base64_encode('generated-image-bytes');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => Http::response([
                'responseId' => 'vertex-banner-1',
                'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => $generated]]]]]],
                'usageMetadata' => [
                    'promptTokenCount' => 400,
                    'candidatesTokenCount' => 1300,
                    'candidatesTokensDetails' => [
                        ['modality' => 'TEXT', 'tokenCount' => 10],
                        ['modality' => 'IMAGE', 'tokenCount' => 1290],
                    ],
                ],
            ]),
        ]);
        $creative = new BannerCreative(['prompt' => 'No text or logos.']);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);
        $secondAsset = new MediaAsset(['disk' => 'local', 'path' => 'second-product.png', 'mime_type' => 'image/png']);

        $result = app(GoogleVertexBannerGenerator::class)->generate($creative, collect([$asset, $secondAsset]));

        $this->assertSame('generated-image-bytes', $result->imageBytes);
        $this->assertSame(400, $result->inputTokens);
        $this->assertSame(10, $result->outputTextTokens);
        $this->assertSame(1290, $result->outputImageTokens);
        $this->assertSame('vertex-banner-1', $result->providerRequestId);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/models/gemini-3.1-flash-image:generateContent')
            && $request['generationConfig']['responseModalities'] === ['TEXT', 'IMAGE']
            && $request['generationConfig']['imageConfig'] === ['aspectRatio' => '4:5', 'imageSize' => '1K']
            && count($request['contents'][0]['parts']) === 2
            && $request['contents'][0]['parts'][1]['inlineData']['data'] === base64_encode('product-bytes'));
    }

    private function configureGoogle(): void
    {
        config()->set('campaigns.banners.model', 'gemini-3.1-flash-image');
        config()->set('campaigns.google.project', 'callingdesk-marketingowl');
        config()->set('campaigns.google.location', 'global');
        config()->set('campaigns.google.base_url', 'https://aiplatform.googleapis.com/v1');
        config()->set('campaigns.google.sts_audience', '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_audience', 'https://iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_token_exchange_url', 'https://oidc.vercel.com/~token');
        config()->set('campaigns.google.sts_token_url', 'https://sts.googleapis.com/v1/token');
        config()->set('campaigns.google.service_account_impersonation_url', 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/test@example.iam.gserviceaccount.com:generateAccessToken');
    }
}

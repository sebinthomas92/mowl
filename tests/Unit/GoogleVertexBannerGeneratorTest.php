<?php

namespace Tests\Unit;

use App\Exceptions\BannerAttemptFailed;
use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Services\GoogleVertexBannerGenerator;
use App\Services\ProviderCostCalculator;
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
            'aiplatform.googleapis.com/*gemini-3.5-flash:*' => Http::response(self::inspection([])),
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
        // The inspection is priced at the inspector model's rates, not the image model's.
        $this->assertEqualsWithDelta((300 * 2.70 + 12 * 16.20) / 1_000_000, $result->additionalCost, 0.000001);
        $this->assertSame(1290, $result->outputImageTokens);
        $this->assertSame('vertex-banner-1', $result->providerRequestId);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/models/gemini-3.1-flash-image:generateContent')
            && $request['generationConfig']['responseModalities'] === ['TEXT', 'IMAGE']
            && $request['generationConfig']['imageConfig'] === ['aspectRatio' => '4:5', 'imageSize' => '1K']
            && count($request['contents'][0]['parts']) === 2
            && $request['contents'][0]['parts'][1]['inlineData']['data'] === base64_encode('product-bytes'));
    }

    public function test_a_background_with_a_real_book_cover_is_regenerated_and_every_attempt_is_costed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $inspections = [self::inspection(['The Hobbit', 'J.R.R. Tolkien', 'KINFOLK']), self::inspection([])];
        $images = [self::image('cover-on-screen'), self::image('blank-screen')];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            // One callback for both models: Http::fake evaluates every matching stub, so two
            // sequences on overlapping patterns would both advance on each request.
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$inspections, &$images) {
                return str_contains($request->url(), 'gemini-3.5-flash:')
                    ? Http::response(array_shift($inspections))
                    : Http::response(array_shift($images));
            },
        ]);
        $creative = new BannerCreative(['prompt' => 'No text or logos.']);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        $result = app(GoogleVertexBannerGenerator::class)->generate($creative, collect([$asset]));

        $this->assertSame('blank-screen', $result->imageBytes);
        $this->assertSame(2 * 1290, $result->outputImageTokens);
        $this->assertSame(2 * 400, $result->inputTokens);
        $this->assertEqualsWithDelta(2 * (300 * 2.70 + 12 * 16.20) / 1_000_000, $result->additionalCost, 0.000001);
    }

    public function test_it_gives_up_when_every_background_shows_third_party_text(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*gemini-3.5-flash:*' => Http::response(self::inspection(['The Hobbit'])),
            'aiplatform.googleapis.com/*' => Http::response(self::image('cover-on-screen')),
        ]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        try {
            app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text.']), collect([$asset]));
            $this->fail('A background with a real book cover must not be used.');
        } catch (BannerAttemptFailed $exception) {
            $this->assertSame('banner_third_party_text', $exception->errorCode);
            $this->assertStringContainsString('The Hobbit', $exception->getMessage());
            // Both rejected rounds were paid for, and that spend travels with the failure.
            $expected = app(ProviderCostCalculator::class)->calculateBanner(800, 20, 2580) + 2 * (300 * 2.70 + 12 * 16.20) / 1_000_000;
            $this->assertEqualsWithDelta($expected, $exception->spentCost, 0.000001);
        }
        Http::assertSentCount(3 + 2 * 2);
    }

    private static function image(string $bytes): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($bytes)]]]]]],
            'usageMetadata' => ['promptTokenCount' => 400, 'candidatesTokenCount' => 1300, 'candidatesTokensDetails' => [
                ['modality' => 'TEXT', 'tokenCount' => 10],
                ['modality' => 'IMAGE', 'tokenCount' => 1290],
            ]],
        ];
    }

    private static function inspection(array $marks): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['marks' => $marks])]]]]],
            'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 12],
        ];
    }

    private function configureGoogle(): void
    {
        config()->set('campaigns.google.model', 'gemini-3.5-flash');
        config()->set('campaigns.google.prices_per_million.gemini-3.5-flash', ['input' => 2.70, 'cached_input' => 0.27, 'output' => 16.20]);
        config()->set('campaigns.banners.inspect_images', true);
        config()->set('campaigns.banners.inspection_attempts', 2);
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

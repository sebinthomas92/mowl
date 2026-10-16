<?php

namespace Tests\Unit;

use App\Exceptions\BannerAttemptFailed;
use App\Exceptions\VertexAIResponseException;
use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Services\GoogleVertexBannerGenerator;
use App\Services\ProviderCostCalculator;
use App\Support\GenerationDeadline;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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
        // The inspector sees the real product photo, so text printed on the product itself is not a mark.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/models/gemini-3.5-flash:generateContent')
            && count($request['contents'][0]['parts']) === 3
            && $request['contents'][0]['parts'][1]['inlineData']['data'] === $generated
            && $request['contents'][0]['parts'][2]['inlineData']['data'] === base64_encode('product-bytes'));
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

    public function test_no_second_round_starts_when_the_time_budget_cannot_fit_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.min_round_seconds', 70);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*gemini-3.5-flash:*' => Http::response(self::inspection(['The Hobbit'])),
            'aiplatform.googleapis.com/*' => Http::response(self::image('cover-on-screen')),
        ]);
        // A caller's deadline with less left than one more round needs.
        app(GenerationDeadline::class)->start(60);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        try {
            app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text.']), collect([$asset]));
            $this->fail('A rejected background must not be used.');
        } catch (BannerAttemptFailed $exception) {
            $this->assertSame('banner_third_party_text', $exception->errorCode);
        } finally {
            app(GenerationDeadline::class)->clear();
        }
        Http::assertSentCount(3 + 2);
    }

    public function test_a_rate_limited_call_waits_and_retries_instead_of_failing_the_attempt(): void
    {
        Sleep::fake();
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $images = [Http::response(['error' => ['message' => 'Resource has been exhausted (e.g. check quota).']], 429), Http::response(self::image('blank-screen'))];
        $inspections = [Http::response(['error' => ['message' => 'Resource has been exhausted (e.g. check quota).']], 429), Http::response(self::inspection([]))];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$images, &$inspections) {
                return str_contains($request->url(), 'gemini-3.5-flash:') ? array_shift($inspections) : array_shift($images);
            },
        ]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text.']), collect([$asset]));

        $this->assertSame('blank-screen', $result->imageBytes);
        Sleep::assertSleptTimes(2);
    }

    public function test_a_rate_limit_fails_fast_when_the_deadline_cannot_fit_a_wait(): void
    {
        Sleep::fake();
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => Http::response(['error' => ['message' => 'Resource has been exhausted (e.g. check quota).']], 429),
        ]);
        app(GenerationDeadline::class)->start(45);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        try {
            app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text.']), collect([$asset]));
            $this->fail('A 429 with no time left must fail the attempt.');
        } catch (VertexAIResponseException $exception) {
            $this->assertSame('vertex_http_429', $exception->errorCode);
        } finally {
            app(GenerationDeadline::class)->clear();
        }
        Sleep::assertNeverSlept();
    }

    public function test_the_product_photo_is_cleaned_of_props_once_and_the_clean_copy_is_the_reference(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.clean_reference', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $images = [self::image('clean-product'), self::image('banner-background')];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$images) {
                return str_contains($request->url(), 'gemini-3.5-flash:')
                    ? Http::response(self::inspection([]))
                    : Http::response(array_shift($images));
            },
        ]);
        $creative = new BannerCreative(['prompt' => 'No text or logos.']);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        $result = app(GoogleVertexBannerGenerator::class)->generate($creative, collect([$asset]));

        $this->assertSame('banner-background', $result->imageBytes);
        $calculator = app(ProviderCostCalculator::class);
        $this->assertEqualsWithDelta(
            $calculator->calculateBanner(400, 10, 1290) + (300 * 2.70 + 12 * 16.20) / 1_000_000,
            $result->additionalCost,
            0.000001,
        );
        $clean = base64_encode('clean-product');
        $vertex = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'aiplatform.googleapis.com'))->values();
        $this->assertCount(3, $vertex);
        // First the cleanup of the original photo, then the banner and its inspection on the clean copy.
        $this->assertStringContainsString('/models/gemini-3.1-flash-image:generateContent', $vertex[0]->url());
        $this->assertStringContainsString('Remove everything that is not the product itself', $vertex[0]['contents'][0]['parts'][0]['text']);
        $this->assertSame(base64_encode('product-bytes'), $vertex[0]['contents'][0]['parts'][1]['inlineData']['data']);
        $this->assertStringContainsString('/models/gemini-3.1-flash-image:generateContent', $vertex[1]->url());
        $this->assertSame($clean, $vertex[1]['contents'][0]['parts'][1]['inlineData']['data']);
        $this->assertStringContainsString('/models/gemini-3.5-flash:generateContent', $vertex[2]->url());
        $this->assertSame($clean, $vertex[2]['contents'][0]['parts'][2]['inlineData']['data']);
    }

    public function test_a_stored_clean_reference_is_reused_without_another_cleanup_call(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        Storage::disk('local')->put('banner-reference-2.png', 'stored-clean-product');
        $this->configureGoogle();
        config()->set('campaigns.banners.clean_reference', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) {
                return str_contains($request->url(), 'gemini-3.5-flash:')
                    ? Http::response(self::inspection([]))
                    : Http::response(self::image('banner-background'));
            },
        ]);
        $creative = new BannerCreative(['prompt' => 'No text or logos.']);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png', 'derivatives' => ['banner_reference' => 'banner-reference-2.png']]);

        app(GoogleVertexBannerGenerator::class)->generate($creative, collect([$asset]));

        $this->assertSame(1, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'gemini-3.1-flash-image:'))->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/models/gemini-3.1-flash-image:generateContent')
            && $request['contents'][0]['parts'][1]['inlineData']['data'] === base64_encode('stored-clean-product'));
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
        config()->set('campaigns.banners.rate_limit_backoff_seconds', [10, 25, 45]);
        config()->set('campaigns.banners.model', 'gemini-3.1-flash-image');
        config()->set('campaigns.banners.clean_reference', false);
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

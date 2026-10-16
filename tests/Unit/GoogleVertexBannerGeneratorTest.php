<?php

namespace Tests\Unit;

use App\Exceptions\BannerAttemptFailed;
use App\Exceptions\VertexAIResponseException;
use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Models\SourceSnapshot;
use App\Services\BannerCopySelector;
use App\Services\BannerReferenceImage;
use App\Services\GoogleVertexBannerGenerator;
use App\Services\ProviderCostCalculator;
use App\Support\GenerationDeadline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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
        // The inspector sees only the generated image: given the product photo too, it listed the photo's props.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/models/gemini-3.5-flash:generateContent')
            && count($request['contents'][0]['parts']) === 2
            && $request['contents'][0]['parts'][1]['inlineData']['data'] === $generated);
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

    public function test_the_product_is_cut_out_of_its_photo_once_and_the_scene_is_drawn_without_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $cleanPng = self::keyed();
        $images = [self::image($cleanPng), self::image('banner-background')];
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
        $creative = new BannerCreative(['prompt' => self::SCENE]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        $result = app(GoogleVertexBannerGenerator::class)->generate($creative, collect([$asset]));

        $this->assertSame('banner-background', $result->imageBytes);
        $calculator = app(ProviderCostCalculator::class);
        $this->assertEqualsWithDelta(
            // The cutout, its text check and the scene's text check (the product match is off here).
            $calculator->calculateBanner(400, 10, 1290) + 2 * $calculator->calculate('gemini-3.5-flash', 300, 0, 12),
            $result->additionalCost,
            0.000001,
        );
        $cutout = imagecreatefromstring((string) $result->productCutout);
        // Trimmed to the product, with the green key turned transparent.
        $this->assertSame([20, 16], [imagesx($cutout), imagesy($cutout)]);
        $this->assertSame(0, (imagecolorat($cutout, 5, 5) >> 24) & 127);
        $vertex = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'aiplatform.googleapis.com'))->values();
        $this->assertCount(4, $vertex);
        // First the cutout of the original photo and its text check, then the scene, which never sees the product.
        $this->assertStringContainsString('/models/gemini-3.1-flash-image:generateContent', $vertex[0]->url());
        $this->assertStringContainsString('Remove everything that is not the product itself', $vertex[0]['contents'][0]['parts'][0]['text']);
        $this->assertStringContainsString('pure green (#00FF00)', $vertex[0]['contents'][0]['parts'][0]['text']);
        $this->assertSame(base64_encode('product-bytes'), $vertex[0]['contents'][0]['parts'][1]['inlineData']['data']);
        $this->assertStringContainsString('/models/gemini-3.5-flash:generateContent', $vertex[1]->url());
        $this->assertStringContainsString('/models/gemini-3.1-flash-image:generateContent', $vertex[2]->url());
        $this->assertCount(1, $vertex[2]['contents'][0]['parts']);
        $this->assertStringContainsString('/models/gemini-3.5-flash:generateContent', $vertex[3]->url());
        $this->assertCount(2, $vertex[3]['contents'][0]['parts']);
    }

    public function test_a_studio_product_photo_is_cut_out_from_its_own_pixels_without_a_redraw(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.jpg', ProductPhotoCutoutTest::studio());
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        config()->set('campaigns.banners.check_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $this->fakeVertex(['banner-background']);

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([new MediaAsset(['disk' => 'local', 'path' => 'product.jpg', 'mime_type' => 'image/jpeg'])]));

        $this->assertSame('banner-background', $result->imageBytes);
        $this->assertStudioCutout($result->productCutout);
        // Only the cutout's text check, the scene and the scene's text check: no redraw, no AI product match.
        $vertex = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'aiplatform.googleapis.com'))->values();
        $this->assertSame(['gemini-3.5-flash', 'gemini-3.1-flash-image', 'gemini-3.5-flash'], $vertex->map(fn (Request $request) => str_contains($request->url(), 'flash-image') ? 'gemini-3.1-flash-image' : 'gemini-3.5-flash')->all());
    }

    public function test_a_studio_shot_from_the_products_gallery_is_used_when_the_imported_photo_is_a_styled_scene(): void
    {
        Storage::fake('local');
        $styled = imagecreatetruecolor(300, 300);
        for ($x = 0; $x < 300; $x += 20) {
            imagefilledrectangle($styled, $x, 0, $x + 9, 299, imagecolorallocate($styled, 140, 100, 60));
        }
        ob_start();
        imagepng($styled);
        Storage::disk('local')->put('product.png', (string) ob_get_clean());
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $page = '<html><head><title>Stand</title></head><body><div class="woocommerce-product-gallery__image"><a href="/uploads/styled.png"><img src="/uploads/styled-300.png"></a></div>'
            .'<div class="woocommerce-product-gallery__image"><a href="/uploads/studio.jpg"><img src="/uploads/studio-300.jpg"></a></div></body></html>';
        Http::fake([
            '93.184.216.34/product/stand' => Http::response($page, 200, ['Content-Type' => 'text/html']),
            '93.184.216.34/uploads/studio.jpg' => Http::response(ProductPhotoCutoutTest::studio(), 200, ['Content-Type' => 'image/jpeg']),
            '93.184.216.34/uploads/styled.png' => Http::response(Storage::disk('local')->get('product.png'), 200, ['Content-Type' => 'image/png']),
        ]);
        $this->fakeVertex(['banner-background']);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png', 'metadata' => ['source_url' => 'https://93.184.216.34/uploads/og-share.png']]);
        $asset->setRelation('sourceSnapshot', new SourceSnapshot(['url' => 'https://93.184.216.34/product/stand']));

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([$asset]));

        $this->assertStudioCutout($result->productCutout);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'flash-image') && count($request['contents'][0]['parts']) > 1);
    }

    public function test_a_studio_shot_with_a_vase_beside_the_product_is_skipped_for_the_next_gallery_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.jpg', ProductPhotoCutoutTest::studio());
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $page = '<html><head><title>Stand</title></head><body><button data-full-src="/uploads/set-1.jpg"></button></body></html>';
        $answers = [self::inspection([], false, ['vase', 'candle']), self::inspection([]), self::inspection([])];
        Http::fake([
            '93.184.216.34/product/stand' => Http::response($page, 200, ['Content-Type' => 'text/html']),
            '93.184.216.34/uploads/set-1.jpg' => Http::response(ProductPhotoCutoutTest::studio(), 200, ['Content-Type' => 'image/jpeg']),
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$answers) {
                return Http::response(str_contains($request->url(), 'gemini-3.5-flash:') ? array_shift($answers) : self::image('banner-background'));
            },
        ]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.jpg', 'mime_type' => 'image/jpeg', 'metadata' => ['source_url' => 'https://93.184.216.34/uploads/stand-1.jpg']]);
        $asset->setRelation('sourceSnapshot', new SourceSnapshot(['url' => 'https://93.184.216.34/product/stand']));

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([$asset]));

        $this->assertStudioCutout($result->productCutout);
        // The gallery photo was used; the product was never redrawn by the image model.
        Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.34/uploads/set-1.jpg');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'flash-image') && count($request['contents'][0]['parts']) > 1);
        $calculator = app(ProviderCostCalculator::class);
        $this->assertEqualsWithDelta(3 * $calculator->calculate('gemini-3.5-flash', 300, 0, 12), $result->additionalCost, 0.000001);
    }

    public function test_a_studio_shot_with_readable_text_is_skipped_and_its_check_still_costed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.jpg', ProductPhotoCutoutTest::studio());
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $answers = [self::inspection(['THE HOBBIT']), self::inspection([]), self::inspection([])];
        $images = [self::image(self::keyed()), self::image('banner-background')];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$answers, &$images) {
                return Http::response(str_contains($request->url(), 'gemini-3.5-flash:') ? array_shift($answers) : array_shift($images));
            },
        ]);

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([new MediaAsset(['disk' => 'local', 'path' => 'product.jpg', 'mime_type' => 'image/jpeg'])]));

        // With no other studio shot, the product is lifted out of the photo by the image model instead.
        $this->assertSame([20, 16], array_slice(getimagesizefromstring((string) $result->productCutout), 0, 2));
        $calculator = app(ProviderCostCalculator::class);
        $this->assertEqualsWithDelta(
            $calculator->calculateBanner(400, 10, 1290) + 3 * $calculator->calculate('gemini-3.5-flash', 300, 0, 12),
            $result->additionalCost,
            0.000001,
        );
    }

    private function fakeVertex(array $scenes): void
    {
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$scenes) {
                return str_contains($request->url(), 'gemini-3.5-flash:')
                    ? Http::response(self::inspection([]))
                    : Http::response(self::image(array_shift($scenes)));
            },
        ]);
    }

    private function assertStudioCutout(?string $png): void
    {
        $cutout = imagecreatefromstring((string) $png);
        // The photo's own red-orange base, trimmed to the stand.
        $base = imagecolorat($cutout, intdiv(imagesx($cutout), 2), imagesy($cutout) - 15);
        $this->assertEqualsWithDelta(214, ($base >> 16) & 255, 8);
        $this->assertSame(127, (imagecolorat($cutout, 0, 0) >> 24) & 127);
    }

    public function test_a_stored_clean_reference_is_reused_without_another_cleanup_call(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        Storage::disk('local')->put('banner-reference-2.png', 'stored-clean-product');
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
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
        $creative = new BannerCreative(['prompt' => self::SCENE]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png', 'derivatives' => ['banner_reference' => 'banner-reference-2.png', 'banner_reference_key' => BannerReferenceImage::key('')]]);
        $asset->id = 2;
        $asset->exists = true;

        $result = app(GoogleVertexBannerGenerator::class)->generate($creative, collect([$asset]));

        $this->assertSame(1, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'gemini-3.1-flash-image:'))->count());
        $this->assertSame('stored-clean-product', $result->productCutout);
    }

    public function test_a_stored_clean_reference_made_for_another_product_name_is_redone(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        Storage::disk('local')->put('banner-reference-2.png', 'stored-clean-product');
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        config()->set('campaigns.banners.inspect_images', false);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $images = [self::image(self::keyed()), self::image('banner-background')];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function () use (&$images) {
                return Http::response(array_shift($images));
            },
        ]);
        // A stored asset without a database: refresh and save are no-ops.
        $asset = new class(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png', 'derivatives' => ['banner_reference' => 'banner-reference-2.png', 'banner_reference_key' => BannerReferenceImage::key('Old name')]]) extends MediaAsset
        {
            public function refresh()
            {
                return $this;
            }

            public function save(array $options = [])
            {
                return true;
            }
        };
        $asset->id = 2;
        $asset->exists = true;

        app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([$asset]));

        $this->assertSame(2, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'gemini-3.1-flash-image:'))->count());
        $this->assertSame(BannerReferenceImage::key(''), $asset->derivatives['banner_reference_key']);
        $this->assertNotSame('stored-clean-product', Storage::disk('local')->get('banner-reference-2.png'));
    }

    public function test_a_cleanup_answer_without_an_image_still_records_its_spend(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $filtered = self::image('unused');
        $filtered['candidates'] = [['finishReason' => 'IMAGE_SAFETY']];
        $notAnImage = self::image('not-an-image');
        $answers = [$filtered, $notAnImage];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function () use (&$answers) {
                return Http::response(array_shift($answers));
            },
        ]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        // A safety-filtered answer, then bytes that are not an image: both fail, both keep their spend.
        foreach ([1, 2] as $round) {
            try {
                app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([$asset]));
                $this->fail("Cleanup answer {$round} must fail the attempt.");
            } catch (BannerAttemptFailed $exception) {
                $this->assertSame('vertex_missing_image', $exception->errorCode);
                $this->assertEqualsWithDelta(app(ProviderCostCalculator::class)->calculateBanner(400, 10, 1290), $exception->spentCost, 0.000001);
            }
        }
    }

    public function test_a_banner_waits_for_another_banners_cleanup_instead_of_paying_for_its_own(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        config()->set('campaigns.banners.reference_timeout_seconds', 1);
        Http::fake();
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);
        $asset->id = 7;
        $asset->exists = true;
        $held = Cache::lock('banner-reference.7', 60);
        $this->assertTrue($held->get());
        app(GenerationDeadline::class)->start(72);

        try {
            app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([$asset]));
            $this->fail('A banner must not start its own cleanup while another one holds the lock.');
        } catch (VertexAIResponseException $exception) {
            $this->assertSame('banner_reference_busy', $exception->errorCode);
            $this->assertTrue($exception->retryable);
        } finally {
            $held->release();
            app(GenerationDeadline::class)->clear();
        }
        Http::assertNothingSent();
    }

    public function test_an_inspection_that_hangs_is_retried_once_and_large_backgrounds_are_shrunk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $inspections = 0;
        $big = imagecreatetruecolor(1024, 1280);
        ob_start();
        imagepng($big);
        $bigPng = (string) ob_get_clean();
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$inspections, $bigPng) {
                if (! str_contains($request->url(), 'gemini-3.5-flash:')) {
                    return Http::response(self::image($bigPng));
                }
                if (++$inspections === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received');
                }

                return Http::response(self::inspection([]));
            },
        ]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text or logos.']), collect([$asset]));

        $this->assertSame($bigPng, $result->imageBytes);
        $this->assertSame(2, $inspections);
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'gemini-3.5-flash:')) {
                return false;
            }
            $part = $request['contents'][0]['parts'][1]['inlineData'];

            return $part['mimeType'] === 'image/jpeg' && imagesx(imagecreatefromstring(base64_decode($part['data']))) === 768;
        });
    }

    public function test_a_background_showing_a_different_object_is_redrawn_and_checked_against_the_store_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.check_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $images = [self::image('paper-book'), self::image('sculpted-stand')];
        $matches = [self::match(false, 'Shows a real paper book with pages, not the white moulded stand.'), self::match(true, '')];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$images, &$matches) {
                if (! str_contains($request->url(), 'gemini-3.5-flash:')) {
                    return Http::response(array_shift($images));
                }

                return Http::response(isset($request['generationConfig']['responseSchema']['properties']['matches']) ? array_shift($matches) : self::inspection([]));
            },
        ]);
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text or logos.']), collect([$asset]));

        $this->assertSame('sculpted-stand', $result->imageBytes);
        // Two text checks and two product checks, each priced at the inspector's rates.
        $this->assertEqualsWithDelta(4 * app(ProviderCostCalculator::class)->calculate('gemini-3.5-flash', 300, 0, 12), $result->additionalCost, 0.000001);
        Http::assertSent(fn (Request $request): bool => isset($request['generationConfig']['responseSchema']['properties']['matches'])
            && count($request['contents'][0]['parts']) === 3
            && $request['contents'][0]['parts'][2]['inlineData']['data'] === base64_encode('product-bytes'));
    }

    /** A panel edge drawn into the photo is redrawn; if every round has one, the last correct product is still used. */
    public function test_a_background_split_into_panels_is_redrawn(): void
    {
        $this->assertSame('seamless', $this->generateWithSplits(true, false));
    }

    public function test_a_split_background_is_kept_when_every_round_is_split(): void
    {
        $this->assertSame('seamless', $this->generateWithSplits(true, true));
    }

    private function generateWithSplits(bool $first, bool $second): string
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.check_product', true);
        config()->set('campaigns.banners.inspection_attempts', 2);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $images = [self::image('panelled'), self::image('seamless')];
        $matches = [self::match(true, '', $first), self::match(true, '', $second)];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$images, &$matches) {
                if (! str_contains($request->url(), 'gemini-3.5-flash:')) {
                    return Http::response(array_shift($images));
                }

                return Http::response(isset($request['generationConfig']['responseSchema']['properties']['matches']) ? array_shift($matches) : self::inspection([]));
            },
        ]);

        return app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text or logos.']), collect([new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png'])]))->imageBytes;
    }

    public function test_a_cutout_that_does_not_match_the_store_photo_is_never_kept_or_used(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.check_product', true);
        config()->set('campaigns.banners.composite_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) {
                if (! str_contains($request->url(), 'gemini-3.5-flash:')) {
                    return Http::response(self::image(self::keyed()));
                }

                return Http::response(isset($request['generationConfig']['responseSchema']['properties']['matches']) ? self::match(false, 'Brown wooden base, not red-orange.') : self::inspection([]));
            },
        ]);
        $asset = new class(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png', 'derivatives' => []]) extends MediaAsset
        {
            public function refresh()
            {
                return $this;
            }

            public function save(array $options = [])
            {
                return true;
            }
        };
        $asset->id = 2;
        $asset->exists = true;

        try {
            app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([$asset]));
            $this->fail('A banner must not use a cutout that is not the real product.');
        } catch (BannerAttemptFailed $exception) {
            $this->assertSame('banner_product_mismatch', $exception->errorCode);
            $this->assertStringContainsString('Brown wooden base, not red-orange.', $exception->getMessage());
            $this->assertNotNull($exception->rejectedImage);
            // The cutout, its text check and its product check are all paid for.
            $calculator = app(ProviderCostCalculator::class);
            $this->assertEqualsWithDelta($calculator->calculateBanner(400, 10, 1290) + 2 * $calculator->calculate('gemini-3.5-flash', 300, 0, 12), $exception->spentCost, 0.000001);
        }
        $this->assertArrayNotHasKey('banner_reference', $asset->derivatives);
        $this->assertFalse(Storage::disk('local')->exists('banner-reference-2.png'));
        // No scene was drawn for a product that failed its check.
        $this->assertSame(1, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'gemini-3.1-flash-image:'))->count());
    }

    private const SCENE = BannerCopySelector::SCENE_OPENING.'Kindle Stand. An empty set. No text or logos.';

    public function test_a_banner_queued_with_a_product_prompt_is_drawn_from_the_photo_even_when_compositing_is_on(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        config()->set('campaigns.banners.inspect_images', false);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => Http::response(self::image('drawn-with-product')),
        ]);

        // Product copy inside the prompt can contain any phrase, the scene prompt's opening included.
        $prompt = 'Show the product, "'.BannerCopySelector::SCENE_OPENING.'". No text or logos.';
        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => $prompt]), collect([new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png'])]));

        // No cutout is made or overlaid, so the product is never shown twice.
        $this->assertNull($result->productCutout);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'gemini-3.1-flash-image:')
            && $request['contents'][0]['parts'][1]['inlineData']['data'] === base64_encode('product-bytes'));
    }

    public function test_a_scene_that_draws_the_product_anyway_is_redrawn_so_it_is_never_shown_twice(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        $this->configureGoogle();
        config()->set('campaigns.banners.composite_product', true);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        $images = [self::image(self::keyed()), self::image('scene-with-a-stand'), self::image('empty-scene')];
        $answers = [self::inspection([]), self::inspection([], true), self::inspection([])];
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*' => function (Request $request) use (&$images, &$answers) {
                return Http::response(str_contains($request->url(), 'gemini-3.5-flash:') ? array_shift($answers) : array_shift($images));
            },
        ]);

        $result = app(GoogleVertexBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png'])]));

        $this->assertSame('empty-scene', $result->imageBytes);
        // Scene checks ask whether the product was drawn; the cutout's own check does not.
        $schemas = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'gemini-3.5-flash:'))
            ->map(fn (Request $request) => array_key_exists('product_shown', $request['generationConfig']['responseSchema']['properties']))->values()->all();
        $this->assertSame([false, true, true], $schemas);
    }

    public function test_cutouts_made_without_a_check_are_remade_once_that_check_is_turned_on(): void
    {
        config()->set('campaigns.banners.inspect_images', true);
        config()->set('campaigns.banners.check_product', false);
        $unchecked = BannerReferenceImage::key('Kindle Stand');
        config()->set('campaigns.banners.check_product', true);

        $this->assertNotSame($unchecked, BannerReferenceImage::key('Kindle Stand'));
    }

    public function test_cutouts_are_remade_for_a_renamed_brand_because_its_own_mark_is_exempt(): void
    {
        $this->assertNotSame(BannerReferenceImage::key('Kindle Stand', 'Old Brand'), BannerReferenceImage::key('Kindle Stand', 'New Brand'));
    }

    public function test_a_very_narrow_photo_is_sampled_for_its_key_colour_without_being_upscaled(): void
    {
        $image = imagecreatetruecolor(2, 6000);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 200, 0));
        ob_start();
        imagepng($image);
        $photo = (string) ob_get_clean();

        $key = (new \ReflectionMethod(BannerReferenceImage::class, 'keyColour'))->invoke(app(BannerReferenceImage::class), $photo);

        // A green product is keyed on magenta.
        $this->assertSame(['magenta', '#FF00FF'], $key);
    }

    public function test_a_rate_limited_cutout_check_is_not_retried_when_the_deadline_cannot_fit_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        Sleep::fake();
        $this->configureGoogle();
        config()->set('campaigns.banners.inspection_timeout_seconds', 60);
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');
        Http::fake([
            'oidc.vercel.com/~token' => Http::response(['token' => 'vercel-google-audience-jwt']),
            'sts.googleapis.com/v1/token' => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
            'aiplatform.googleapis.com/*gemini-3.1-flash-image:*' => Http::response(self::image(self::keyed())),
            'aiplatform.googleapis.com/*gemini-3.5-flash:*' => Http::response(['error' => ['message' => 'Resource exhausted']], 429),
        ]);
        // Room for one 60s check, not for a 10s pause and a second one.
        app(GenerationDeadline::class)->start(70);

        try {
            app(BannerReferenceImage::class)->for(new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']), 'Kindle Stand', 60, 1);
            $this->fail('The check should have failed.');
        } catch (BannerAttemptFailed $exception) {
            $this->assertSame('vertex_http_429', $exception->errorCode);
            $this->assertGreaterThan(0, $exception->spentCost);
        } finally {
            app(GenerationDeadline::class)->clear();
        }
        Sleep::assertNeverSlept();
    }

    private static function match(bool $matches, string $differences, bool $split = false): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['matches' => $matches, 'differences' => $differences, 'split' => $split])]]]]],
            'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 12],
        ];
    }

    /** A product on a pure green key, as the cutout step asks the image model for. */
    private static function keyed(): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 10, 12, 29, 27, imagecolorallocate($image, 240, 240, 240));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function png(?string $bytes = null): string
    {
        $image = $bytes === null ? imagecreatetruecolor(4, 4) : imagecreatefromstring($bytes);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
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

    private static function inspection(array $marks, bool $productShown = false, array $otherObjects = []): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['marks' => $marks, 'product_shown' => $productShown, 'other_objects' => $otherObjects])]]]]],
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
        config()->set('campaigns.banners.composite_product', false);
        config()->set('campaigns.banners.check_product', false);
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

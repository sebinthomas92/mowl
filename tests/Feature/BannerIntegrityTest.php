<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Models\BannerCreative;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BannerComposer;
use App\Services\BannerCopySelector;
use App\Services\CampaignPackSafetyValidator;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use App\Services\ProductKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BannerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'Kindle Stand. ₹750₹699 Inclusive of all taxes. Dispatches within 48 hours. Free shipping Orders over ₹499.';

    public function test_pack_qa_blocks_a_price_the_product_page_does_not_show(): void
    {
        $source = new SourceSnapshot(['extracted_content' => self::PAGE, 'extracted_truth' => ['price' => '699.00', 'currency' => 'INR']]);
        $content = ['creative_routes' => [['platform_assets' => ['meta_ads' => ['headlines' => ['INR 899 + Free Shipping', 'Now ₹699', 'Free shipping over Rs. 499', 'Only 699 INR']]]]]];

        $issues = collect(app(CampaignPackSafetyValidator::class)->issues($content, [], [], $source))->whereNotNull('path');

        $this->assertSame(['INR 899 + Free Shipping states a price the product page does not show (899).'], $issues->pluck('message')->values()->all());
        $this->assertSame('creative_routes.0.platform_assets.meta_ads.headlines.0', $issues->first()['path']);
    }

    public function test_a_banner_with_an_unsupported_price_is_flagged_and_cannot_be_approved(): void
    {
        config(['campaigns.banners.enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Banner Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'Plush'])->products()->create(['name' => 'Kindle Stand']);
        $source = $product->sourceSnapshots()->create(['url' => 'https://example.com/stand', 'content_hash' => 'h', 'status' => 'ready', 'extracted_content' => self::PAGE, 'extracted_truth' => ['price' => '699.00', 'currency' => 'INR']]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Pack', 'status' => 'approved', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => 'Stand']);
        $version = $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => [], 'review_status' => 'approved']);
        $batch = $workspace->bannerGenerationBatches()->create([
            'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id,
            'requested_by_user_id' => $owner->id, 'kind' => 'included', 'requested_count' => 2, 'status' => 'completed',
        ]);
        Storage::fake('local');
        $creatives = [];
        foreach (['INR 899 + Free Shipping', 'A stand for one more chapter'] as $index => $headline) {
            Storage::disk('local')->put('creatives/'.($index + 1).'.png', 'png');
            $creatives[] = $batch->creatives()->create([
                'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id, 'sequence' => $index + 1,
                'direction' => 'Product first', 'layout' => 'bottom_panel', 'headline' => $headline, 'cta' => 'Shop now',
                'prompt' => 'p', 'status' => 'completed', 'disk' => 'local', 'output_path' => 'creatives/'.($index + 1).'.png', 'width' => 1080, 'height' => 1350,
            ]);
        }

        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->assertSeeText('Shows a price the product page does not show (899)')
            ->assertViewHas('bannerPriceIssues', fn (array $issues) => count($issues) === 1)
            ->assertDontSeeHtml(route('campaign-banners.download', [$pack, $creatives[0]]))
            ->assertSeeHtml(route('campaign-banners.download', [$pack, $creatives[1]]));
        $this->actingAs($owner)->get(route('campaign-banners.download', [$pack, $creatives[0]]))->assertNotFound();
        $this->actingAs($owner)->get(route('campaign-banners.download', [$pack, $creatives[1]]))->assertOk();

        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $checks = fn (string $headline) => $library->checks($pack->contentItems()->where('section', 'like', 'banner.%')->get()
            ->first(fn ($item) => $item->latestVersion->body['headline'] === $headline)->latestVersion);
        $this->assertContains('States a price the product page does not show (899)', collect($checks('INR 899 + Free Shipping'))->where('ok', false)->where('blocking', true)->pluck('label')->all());
        $flagged = $pack->contentItems()->get()->first(fn ($item) => $item->latestVersion?->body['headline'] === 'INR 899 + Free Shipping');
        $edited = $flagged->versions()->create(['version' => 2, 'status' => 'draft', 'body' => ['headline' => 'Free Shipping'] + $flagged->latestVersion->body]);
        $this->assertContains('States a price the product page does not show (899)', collect($library->checks($edited))->where('ok', false)->pluck('label')->all(), 'The PNG still shows the price, so editing the text does not clear it');
        $this->actingAs($owner)->get(route('products.hub-creative', [$product, $creatives[0]]))->assertNotFound();
        $this->actingAs($owner)->get(route('products.hub-creative', [$product, $creatives[1]]))->assertOk();
        $this->assertEmpty(collect($checks('A stand for one more chapter'))->where('ok', false)->where('blocking', true)->all(), 'An unedited banner, size line included, is not an edit');
    }

    public function test_only_currency_amounts_on_the_page_count_as_prices(): void
    {
        $validator = app(CampaignPackSafetyValidator::class);
        $supported = $validator->supportedPrices(new SourceSnapshot(['extracted_content' => self::PAGE, 'extracted_truth' => ['price' => '699.00', 'currency' => 'INR']]));

        $this->assertEqualsCanonicalizing(['INR 750', 'INR 699', 'INR 499'], $supported);
        $this->assertSame(['48'], $validator->unsupportedPrices('Just INR 48 today', $supported), 'A dispatch time is not a price');
        $this->assertSame(['699'], $validator->unsupportedPrices('Only $699', $supported), 'The currency must match too');
        $this->assertSame([], $validator->unsupportedPrices('Only Rs. 699.00, was ₹750.', $supported));
    }

    public function test_another_products_price_further_down_the_page_does_not_count(): void
    {
        $validator = app(CampaignPackSafetyValidator::class);
        $page = self::PAGE.' '.str_repeat('Reviews and care notes. ', 30).'You may also like: Cloud Modular Organiser ₹899 Crab Pen Holder ₹750';
        $truth = ['price' => '699.00', 'currency' => 'INR'];

        $supported = $validator->supportedPrices(new SourceSnapshot(['extracted_content' => $page, 'extracted_truth' => $truth]));
        $this->assertEqualsCanonicalizing(['INR 750', 'INR 699', 'INR 499'], $supported);
        $this->assertSame(['899'], $validator->unsupportedPrices('Now just INR 899', $supported));

        $unanchored = $validator->supportedPrices(new SourceSnapshot(['extracted_content' => 'Organiser ₹899', 'extracted_truth' => []]));
        $this->assertSame(['INR 899'], $unanchored, 'With no product price to anchor on, every page amount still counts');
    }

    public function test_other_iso_currencies_and_bare_price_fields_are_checked(): void
    {
        $validator = app(CampaignPackSafetyValidator::class);
        $yen = $validator->supportedPrices(new SourceSnapshot(['extracted_content' => 'Stand', 'extracted_truth' => ['price' => '699', 'currency' => 'JPY']]));

        $this->assertSame(['JPY 699'], $yen);
        $this->assertSame(['699'], $validator->unsupportedPrices('Only USD 699', $yen));
        $this->assertSame(['899'], $validator->unsupportedPrices('Only 899 USD', $yen));
        $this->assertSame([], $validator->unsupportedPrices('Only 699 JPY', $yen));

        $source = new SourceSnapshot(['extracted_content' => self::PAGE, 'extracted_truth' => ['price' => '699.00', 'currency' => 'INR']]);
        $issues = collect($validator->issues(['product_truth' => ['price' => '899'], 'marketing_hub' => ['product_details' => ['price' => '699.00']]], [], [], $source))->whereNotNull('path');
        $this->assertSame(['product_truth.price'], $issues->pluck('path')->values()->all());
    }

    public function test_the_source_currency_settles_dollar_signs_and_unlisted_codes(): void
    {
        $validator = app(CampaignPackSafetyValidator::class);
        $aud = $validator->supportedPrices(new SourceSnapshot(['extracted_content' => 'Now $699', 'extracted_truth' => ['price' => '699', 'currency' => 'AUD']]));
        $this->assertSame(['AUD 699'], $aud);
        $this->assertSame(['699'], $validator->unsupportedPrices('USD 699', $aud));
        $this->assertSame([], $validator->unsupportedPrices('Just $699', $aud));

        $pln = $validator->supportedPrices(new SourceSnapshot(['extracted_content' => 'Stand', 'extracted_truth' => ['price' => '699', 'currency' => 'PLN']]));
        $this->assertSame(['899'], $validator->unsupportedPrices('Only PLN 899', $pln));
    }

    public function test_a_price_only_seeded_from_an_old_page_is_not_supported_once_the_page_drops_it(): void
    {
        $workspace = Workspace::create(['name' => 'Banner Agency']);
        $product = $workspace->brands()->create(['name' => 'Plush'])->products()->create(['name' => 'Kindle Stand']);
        $old = $product->sourceSnapshots()->create(['url' => 'https://example.com/stand', 'content_hash' => 'a', 'status' => 'ready', 'extracted_content' => 'Stand', 'extracted_truth' => ['price' => '899', 'currency' => 'INR']]);
        app(ProductKnowledge::class)->syncFromSource($product, $old);
        $new = $product->sourceSnapshots()->create(['url' => 'https://example.com/stand', 'content_hash' => 'b', 'status' => 'ready', 'extracted_content' => 'Stand', 'extracted_truth' => []]);
        app(ProductKnowledge::class)->syncFromSource($product, $new);
        $this->assertSame('ok', $product->facts()->where('key', 'price')->value('status'));

        $this->assertSame([], app(CampaignPackSafetyValidator::class)->supportedPrices($new));
    }

    public function test_decimal_comma_prices_keep_their_value(): void
    {
        $validator = app(CampaignPackSafetyValidator::class);
        $supported = $validator->supportedPrices(new SourceSnapshot(['extracted_content' => 'Nur €1,99 oder €1.299,00', 'extracted_truth' => []]));

        $this->assertEqualsCanonicalizing(['EUR 1.99', 'EUR 1299'], $supported);
        $this->assertSame(['199'], $validator->unsupportedPrices('Now €199', $supported));
        $this->assertSame([], $validator->unsupportedPrices('Now EUR 1,299', $supported));
    }

    public function test_the_fallback_headline_is_also_checked_for_price(): void
    {
        $workspace = Workspace::create(['name' => 'Banner Agency']);
        $product = $workspace->brands()->create(['name' => 'Plush'])->products()->create(['name' => 'Kindle Stand']);
        $source = $product->sourceSnapshots()->create(['url' => 'https://example.com/stand', 'content_hash' => 'h', 'status' => 'ready', 'extracted_content' => self::PAGE, 'extracted_truth' => ['price' => '699.00', 'currency' => 'INR']]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Pack', 'status' => 'approved', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $version = $pack->versions()->create(['version' => 1, 'content' => [
            'product_truth' => ['name' => 'Kindle Stand'],
            'direction' => ['title' => 'Now INR 899'],
            'ranked_angles' => [['title' => 'Read hands-free']],
        ]]);

        $this->assertSame('Read hands-free', app(BannerCopySelector::class)->select($version, 1)['headline']);
    }

    public function test_a_long_brand_name_is_cut_short_before_the_cta(): void
    {
        $background = imagecreatetruecolor(1080, 1350);
        imagefill($background, 0, 0, imagecolorallocate($background, 0, 0, 0));
        ob_start();
        imagepng($background);
        $bytes = ob_get_clean();
        $brand = new Brand(['name' => str_repeat('Wonderfully Long Brand Name ', 4), 'primary_color' => '#0000FF']);
        $creative = new BannerCreative(['headline' => 'A stand', 'supporting_text' => null, 'cta' => 'Shop now', 'layout' => 'bottom_panel']);

        $image = imagecreatefromstring(app(BannerComposer::class)->compose($bytes, $brand, $creative));

        // The CTA ends at x = 80 + 230; nothing white may sit between it and the reserved 360px.
        for ($x = 320; $x < 1000 - 360; $x++) {
            for ($y = 1250; $y < 1300; $y++) {
                $this->assertNotSame(255, (imagecolorat($image, $x, $y) >> 16) & 255, "White wordmark pixel at {$x},{$y}");
            }
        }
    }

    public function test_banner_copy_skips_lines_with_an_unsupported_price(): void
    {
        $workspace = Workspace::create(['name' => 'Banner Agency']);
        $product = $workspace->brands()->create(['name' => 'Plush'])->products()->create(['name' => 'Kindle Stand', 'price' => 'INR 899.00']);
        $source = $product->sourceSnapshots()->create(['url' => 'https://example.com/stand', 'content_hash' => 'h', 'status' => 'ready', 'extracted_content' => self::PAGE, 'extracted_truth' => ['price' => '699.00', 'currency' => 'INR']]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Pack', 'status' => 'approved', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $version = $pack->versions()->create(['version' => 1, 'content' => [
            'product_truth' => ['name' => 'Kindle Stand'],
            'creative_routes' => [['platform_assets' => ['meta_ads' => ['headlines' => ['INR 899 + Free Shipping', 'A stand for one more chapter'], 'description_lines' => ['Yours for ₹899', 'Dispatches within 48 hours']]]]],
        ]]);

        $selected = app(BannerCopySelector::class)->select($version, 1);

        $this->assertSame('A stand for one more chapter', $selected['headline']);
        $this->assertSame('Dispatches within 48 hours', $selected['supporting_text']);
    }

    public function test_the_readability_gradient_has_no_scan_lines_and_the_bottom_wordmark_sits_on_the_panel(): void
    {
        $background = imagecreatetruecolor(1080, 1350);
        imagefill($background, 0, 0, imagecolorallocate($background, 200, 160, 120));
        ob_start();
        imagepng($background);
        $bytes = ob_get_clean();
        $brand = new Brand(['name' => 'Plush', 'primary_color' => '#F4B942']);

        foreach (['split_left' => fn ($image, $i) => imagecolorat($image, $i, 900), 'bottom_panel' => fn ($image, $i) => imagecolorat($image, 1060, 720 + $i)] as $layout => $pixel) {
            $creative = new BannerCreative(['headline' => 'A stand', 'supporting_text' => null, 'cta' => 'Shop now', 'layout' => $layout]);
            $image = imagecreatefromstring(app(BannerComposer::class)->compose($bytes, $brand, $creative));
            $red = array_map(fn ($i) => (($pixel($image, $i) >> 16) & 255), range(0, 400));
            $steps = array_map(fn ($i) => $red[$i] - $red[$i - 1], range(1, 400));
            $this->assertLessThanOrEqual(2, max($steps) - min($steps), "{$layout} gradient should be smooth");

            if ($layout === 'bottom_panel') {
                $top = null;
                for ($y = 0; $y < 1350 && $top === null; $y++) {
                    for ($x = 600; $x < 1000; $x++) {
                        if (((imagecolorat($image, $x, $y) >> 16) & 255) === 255) {
                            $top = $y;
                            break;
                        }
                    }
                }
                $this->assertGreaterThan(1200, $top, 'The wordmark sits on the CTA row, not over the photo');
            }
        }
    }
}

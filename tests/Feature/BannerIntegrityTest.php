<?php

namespace Tests\Feature;

use App\Models\BannerCreative;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\SourceSnapshot;
use App\Models\Workspace;
use App\Services\BannerComposer;
use App\Services\BannerCopySelector;
use App\Services\CampaignPackSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

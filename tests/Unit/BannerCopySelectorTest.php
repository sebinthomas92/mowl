<?php

namespace Tests\Unit;

use App\Models\CampaignPackVersion;
use App\Services\BannerCopySelector;
use PHPUnit\Framework\TestCase;

class BannerCopySelectorTest extends TestCase
{
    public function test_it_uses_new_campaign_route_meta_copy_verbatim_without_inventing_claims(): void
    {
        $version = new CampaignPackVersion;
        $version->content = [
            'overview' => ['campaign_goal' => 'Approved goal'],
            'product_truth' => ['name' => 'Exact Product', 'supported_benefits' => ['Verified benefit']],
            'positioning' => ['audience_priorities' => [['name' => 'Approved audience']]],
            'ranked_angles' => [['title' => 'Approved angle']],
            'creative_routes' => [[
                'target_buyer' => 'Approved buyer',
                'core_promise' => 'Approved promise',
                'hooks' => ['Approved route hook'],
                'platform_assets' => ['meta_ads' => [
                    'title' => 'Approved title',
                    'headlines' => ['Approved headline'],
                    'description_lines' => ['Approved description'],
                ]],
            ]],
        ];

        $selected = (new BannerCopySelector)->select($version, 1);

        $this->assertSame('Approved headline', $selected['headline']);
        $this->assertSame('Approved description', $selected['supporting_text']);
        $this->assertSame('Shop now', $selected['cta']);
        $this->assertStringContainsString('You are an expert ad creative prompt engineer', $selected['prompt']);
        $this->assertStringContainsString('ONE specific product: Exact Product', $selected['prompt']);
        $this->assertStringContainsString('PRODUCT DNA is the source of truth', $selected['prompt']);
        $this->assertStringContainsString('Use the first supplied product image as the exact product reference', $selected['prompt']);
        $this->assertStringContainsString('Verified benefit', $selected['prompt']);
        $this->assertStringContainsString('Do not include any text', $selected['prompt']);
    }
}

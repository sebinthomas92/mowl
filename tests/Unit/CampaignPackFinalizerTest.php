<?php

namespace Tests\Unit;

use App\Models\SourceSnapshot;
use App\Services\CampaignPackFinalizer;
use App\Services\CampaignPackSafetyValidator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CampaignPackFinalizerTest extends TestCase
{
    public function test_it_dates_the_hub_today_and_keeps_ctas_retail(): void
    {
        Carbon::setTestNow('2026-10-15 11:00:00');

        $content = app(CampaignPackFinalizer::class)->finalize([
            'marketing_hub' => [
                'overview' => ['summary' => 'Launch', 'updated_at' => 'July 2026'],
                'channels' => ['meta_ads' => ['ctas' => ['Shop Now', 'Learn More', 'Book Now']]],
            ],
            'creative_routes' => [['platform_assets' => ['meta_ads' => ['cta' => 'Sign up.'], 'instagram_reels' => ['cta' => 'Order Now']]]],
        ]);

        $this->assertSame('2026-10-15', $content['marketing_hub']['overview']['updated_at']);
        $this->assertSame(['Shop Now', 'Learn More'], $content['marketing_hub']['channels']['meta_ads']['ctas']);
        $this->assertSame('Shop Now', $content['creative_routes'][0]['platform_assets']['meta_ads']['cta']);
        $this->assertSame('Order Now', $content['creative_routes'][0]['platform_assets']['instagram_reels']['cta']);
    }

    public function test_restated_claims_pass_but_off_page_quotes_and_new_numbers_block_approval(): void
    {
        $source = new SourceSnapshot([
            'url' => 'https://example.com/stand',
            'extracted_content' => 'Prepared in small batches. 7-day returns. Free shipping on orders over ₹499.',
            'extracted_truth' => ['name' => 'Kindle Stand – Book-Shaped Holder'],
        ]);
        $evidence = [
            ['id' => 'e1', 'claim' => 'The stand is prepared in small batches.', 'source' => $source->url, 'excerpt' => 'Prepared in small batches', 'status' => 'directly_supported'],
            ['id' => 'e2', 'claim' => 'The brand offers a 7-day return policy.', 'source' => $source->url, 'excerpt' => '7-day returns', 'status' => 'directly_supported'],
            ['id' => 'e3', 'claim' => 'Free shipping over ₹999.', 'source' => $source->url, 'excerpt' => 'Free shipping on orders over ₹499', 'status' => 'supported_paraphrased'],
            ['id' => 'e4', 'claim' => 'Ships in 24 hours.', 'source' => $source->url, 'excerpt' => 'Ships within a day', 'status' => 'supported_paraphrased'],
        ];

        $messages = array_column(app(CampaignPackSafetyValidator::class)->issues([], $evidence, [], $source), 'message');

        $this->assertNotContains('The stand is prepared in small batches. is not stated directly in the supplied source.', $messages);
        $this->assertEmpty(array_filter($messages, fn ($message) => str_starts_with($message, 'The brand offers')));
        $this->assertContains('Free shipping over ₹999. uses a number the source does not state (999).', $messages);
        $this->assertContains('Ships in 24 hours. quotes text that is not on the product page.', $messages);
    }
}

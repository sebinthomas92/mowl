<?php

namespace App\Services;

use App\Support\VoiceoverTiming;

/**
 * Deterministic clean-up of generated pack content before it is versioned.
 *
 * Models copy dates from the page ("Month of manufacture: July 2026") into fields that should
 * say when the pack was made, and pick service CTAs ("Book Now") for products. Every pack is for
 * an ecommerce product page, so CTAs come from the retail set. Voiceover word counts and paces
 * are recounted from the lines, since models miscount them.
 */
class CampaignPackFinalizer
{
    /** CTAs for services, bookings, apps or lead forms; replaced for a product. */
    private const NON_RETAIL_CTAS = [
        'book now', 'book a demo', 'sign up', 'subscribe', 'apply now', 'get quote', 'request quote', 'get a quote',
        'download', 'install now', 'use app', 'play game', 'contact us', 'call now', 'get directions', 'listen now',
        'watch more', 'donate now', 'register now', 'get showtimes', 'request time', 'see menu', 'start free trial',
    ];

    public function finalize(array $content): array
    {
        if (isset($content['marketing_hub']['overview']) && is_array($content['marketing_hub']['overview'])) {
            $content['marketing_hub']['overview']['updated_at'] = now()->toDateString();
        }

        if (is_array($content['marketing_hub']['channels']['meta_ads']['ctas'] ?? null)) {
            $content['marketing_hub']['channels']['meta_ads']['ctas'] = array_values(array_unique(array_map(
                fn ($cta) => $this->retail((string) $cta),
                $content['marketing_hub']['channels']['meta_ads']['ctas'],
            )));
        }

        $content = VoiceoverTiming::refreshContent($content);
        foreach ($content['creative_routes'] ?? [] as $routeIndex => $route) {
            foreach (is_array($route['platform_assets'] ?? null) ? $route['platform_assets'] : [] as $platform => $asset) {
                if (is_array($asset) && isset($asset['cta'])) {
                    $content['creative_routes'][$routeIndex]['platform_assets'][$platform]['cta'] = $this->retail((string) $asset['cta']);
                }
            }
        }

        return $content;
    }

    private function retail(string $cta): string
    {
        return in_array(mb_strtolower(trim($cta, " \t\n.!")), self::NON_RETAIL_CTAS, true) ? 'Shop Now' : $cta;
    }
}

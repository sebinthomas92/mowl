<?php

namespace App\Services;

use App\Models\CampaignPackVersion;
use App\Models\Product;

class ProductHubContentAdapter
{
    public function adapt(Product $product, CampaignPackVersion $version): array
    {
        $pack = $version->campaignPack;
        $normalized = app(CampaignPackContentNormalizer::class)->normalize(
            $version->content ?? [],
            $product,
            $pack->sourceSnapshot,
        );
        $hub = $normalized['marketing_hub'] ?? [];
        $route = $normalized['creative_routes'][0] ?? [];
        $meta = data_get($route, 'platform_assets.meta_ads', []);
        $positioning = $normalized['positioning'] ?? [];
        $angles = collect($normalized['ranked_angles'] ?? []);
        $sourceUrl = $pack->sourceSnapshot?->url ?? '';

        $defaults = [
            'overview' => [
                'summary' => data_get($normalized, 'overview.summary', $product->summary ?: 'Approved product marketing guidance.'),
                'updated_at' => optional($version->reviewed_at ?? $version->created_at)->toDateString(),
            ],
            'product_details' => [
                'name' => data_get($normalized, 'product_truth.name', $product->name),
                'price' => data_get($normalized, 'product_truth.price', $product->price ?: 'Price not supplied'),
                'summary' => $product->summary ?: data_get($normalized, 'overview.summary', ''),
                'source_url' => $sourceUrl,
                'facts' => collect(data_get($normalized, 'product_truth.verified_facts', []))->pluck('statement')->filter()->values()->all(),
            ],
            'key_messaging' => [
                'value_proposition' => data_get($positioning, 'value_proposition', data_get($route, 'core_promise', '')),
                'audiences' => collect(data_get($positioning, 'audience_priorities', []))->pluck('name')->filter()->values()->all(),
                'proof_points' => data_get($normalized, 'product_truth.supported_benefits', []),
                'tone' => data_get($route, 'tone', 'Clear and practical'),
            ],
            'channels' => [
                'meta_ads' => [
                    'primary_texts' => array_values(array_filter([$meta['primary_copy'] ?? null, $meta['short_caption'] ?? null])),
                    'headlines' => $meta['headlines'] ?? array_values(array_filter([$meta['title'] ?? null])),
                    'descriptions' => $meta['description_lines'] ?? [],
                    'ctas' => array_values(array_filter([$meta['cta'] ?? null])),
                ],
                'google_ads' => $this->legacyGoogleAds($product, $sourceUrl, $angles, $meta),
                'email_sms' => [
                    'subject_lines' => $angles->pluck('title')->take(3)->values()->all(),
                    'preview_texts' => array_values(array_filter([data_get($route, 'core_promise')])),
                    'email_bodies' => array_values(array_filter([$meta['primary_copy'] ?? null])),
                    'sms_messages' => array_values(array_filter([$meta['short_caption'] ?? null])),
                ],
                'organic_social' => [
                    'captions' => array_values(array_filter([$meta['short_caption'] ?? null, $meta['primary_copy'] ?? null])),
                    'hooks' => $route['hooks'] ?? $angles->pluck('title')->take(3)->values()->all(),
                    'hashtags' => $meta['hashtags'] ?? [],
                ],
            ],
        ];

        return array_replace_recursive($defaults, $hub);
    }

    private function legacyGoogleAds(Product $product, string $url, $angles, array $meta): array
    {
        $headlines = collect($meta['headlines'] ?? [])->merge($angles->pluck('title'))->filter()->unique()->take(15)->values()->all();
        if ($headlines === []) {
            $headlines = [$product->name];
        }
        $descriptions = collect($meta['description_lines'] ?? [])->filter()->take(4)->values()->all();
        $base = [
            'headlines' => $headlines,
            'long_headlines' => array_values(array_filter([$meta['title'] ?? null])),
            'descriptions' => $descriptions,
            'final_url' => $url,
            'sitelinks' => [],
            'promotion' => '',
        ];

        return ['search' => $base, 'performance_max' => $base, 'display' => $base];
    }
}

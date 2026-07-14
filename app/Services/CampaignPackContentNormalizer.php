<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SourceSnapshot;

class CampaignPackContentNormalizer
{
    public function normalize(array $content, Product $product, SourceSnapshot $source): array
    {
        if (isset($content['ranked_angles'], $content['creative_routes'])) {
            return $content;
        }

        $hooks = array_values($content['hooks'] ?? [$content['direction']['title'] ?? 'A practical product story']);
        $angles = collect($hooks)->take(5)->values()->map(fn (string $hook, int $index) => [
            'rank' => $index + 1,
            'title' => $hook,
            'core_idea' => $content['direction']['summary'] ?? $hook,
            'target_audience' => $content['audiences'][$index % max(1, count($content['audiences'] ?? []))] ?? 'Intent-led shoppers',
            'buyer_moment' => 'When comparing practical product options',
            'main_benefit' => $content['benefits'][$index % max(1, count($content['benefits'] ?? []))] ?? 'A clear product benefit',
            'relevance' => 'Built from the legacy campaign direction.',
            'ranking_reason' => 'Retained from the existing campaign version.',
            'supporting_claim_ids' => [],
            'status' => $index < 3 ? 'recommended' : 'secondary',
        ])->all();

        $script = collect($content['script'] ?? [])->values()->map(function (array $line, int $index): array {
            $start = $index * 4;
            $end = $start + 4;
            $text = (string) ($line['line'] ?? '');
            $words = str_word_count($text);

            return [
                'start_seconds' => $start,
                'end_seconds' => $end,
                'line' => $text,
                'word_count' => $words,
                'pace_wpm' => (int) round(($words / 4) * 60),
                'delivery_notes' => 'Natural delivery',
                'shot_id' => 'shot-'.($index + 1),
            ];
        })->all();
        $duration = max(20, count($script) * 4);
        $captions = collect($content['captions'] ?? [])->values()->map(fn (string $caption, int $index) => [
            'start_seconds' => $index * ($duration / max(1, count($content['captions'] ?? []))),
            'end_seconds' => ($index + 1) * ($duration / max(1, count($content['captions'] ?? []))),
            'text' => $caption,
            'shot_id' => 'shot-'.($index + 1),
        ])->all();
        $shots = collect($content['shot_log'] ?? [])->values()->map(fn (string $shot, int $index) => [
            'id' => 'shot-'.($index + 1),
            'start_seconds' => $index * ($duration / max(1, count($content['shot_log'] ?? []))),
            'end_seconds' => ($index + 1) * ($duration / max(1, count($content['shot_log'] ?? []))),
            'purpose' => $index === 0 ? 'hook' : ($index === count($content['shot_log'] ?? []) - 1 ? 'cta' : 'product proof'),
            'scene' => $shot,
            'action' => $shot,
            'camera_framing' => 'Use framing appropriate to the action.',
            'product_visibility' => 'Keep the product clearly identifiable.',
            'voiceover_line' => $script[$index]['line'] ?? '',
            'on_screen_caption' => $captions[$index]['text'] ?? '',
            'product_fact_or_benefit' => '',
            'props_or_requirements' => 'Product and a clean setting.',
            'lighting_or_movement' => 'Natural light and stable movement.',
            'priority' => 'essential',
        ])->all();

        $platform = [
            'primary_copy' => $content['meta']['primary_text'] ?? '',
            'short_caption' => $content['captions'][0] ?? '',
            'title' => $content['meta']['headlines'][0] ?? $product->name,
            'headlines' => $content['meta']['headlines'] ?? [],
            'description_lines' => $content['meta']['descriptions'] ?? [],
            'hashtags' => [],
            'cta' => 'Learn more',
            'frames' => [],
        ];
        $route = [
            'id' => 'legacy-route',
            'name' => $content['direction']['title'] ?? 'Legacy campaign route',
            'angle_rank' => 1,
            'marketing_angle' => $content['direction']['summary'] ?? '',
            'target_buyer' => $content['audiences'][0] ?? 'Intent-led shoppers',
            'buyer_moment' => 'When comparing product options',
            'core_promise' => $content['benefits'][0] ?? 'A clear product benefit',
            'tone' => 'Clear and practical',
            'total_duration_seconds' => $duration,
            'hooks' => $hooks,
            'voiceover' => $script,
            'captions' => $captions,
            'shot_plan' => $shots,
            'platform_assets' => array_fill_keys(['instagram_reels', 'youtube_shorts', 'whatsapp_status', 'meta_ads'], $platform),
            'offer_treatment' => 'Product-led value framing; no discount assumed.',
            'claim_validation_status' => 'blocked',
        ];

        return [
            'overview' => [
                'summary' => $content['direction']['summary'] ?? ($product->summary ?: 'Legacy campaign pack'),
                'campaign_goal' => 'Preserve and review the existing campaign version.',
            ],
            'product_truth' => [
                'name' => $content['product_truth']['name'] ?? $product->name,
                'price' => $content['product_truth']['price'] ?? ($product->price ?: 'Price not supplied'),
                'availability' => $source->extracted_truth['availability'] ?? 'Not confirmed',
                'verified_facts' => collect($content['product_truth']['verified_facts'] ?? [])->map(fn (string $fact) => ['statement' => $fact, 'claim_id' => ''])->all(),
                'supported_benefits' => $content['benefits'] ?? [],
                'offers_and_trust_signals' => [],
                'brand_context' => [$product->brand->name],
                'missing_information' => ['This legacy version predates the structured Product Truth format.'],
                'prohibited_claims' => [],
                'sources' => [$source->url],
            ],
            'positioning' => [
                'value_proposition' => $content['direction']['summary'] ?? '',
                'brand_position' => 'Legacy campaign positioning',
                'audience_priorities' => collect($content['audiences'] ?? [])->map(fn (string $audience) => ['name' => $audience, 'need' => '', 'buyer_moment' => '', 'why_relevant' => 'Retained from the existing version.'])->all(),
            ],
            'ranked_angles' => $angles,
            'creative_routes' => [$route],
            'offers' => [],
            'qa' => ['claim_status' => 'blocked', 'timing_status' => 'blocked', 'caption_status' => 'blocked', 'approval_status' => 'blocked', 'summary' => 'Regenerate this legacy pack to use the strengthened QA contract.'],
        ];
    }
}

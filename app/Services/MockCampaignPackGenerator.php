<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Models\Product;
use App\Models\SourceSnapshot;

class MockCampaignPackGenerator implements CampaignPackGenerator
{
    public function generate(Product $product, SourceSnapshot $source, array $page = []): GenerationResult
    {
        $name = $product->name;
        $fact = trim((string) (data_get($page, 'product_truth.description') ?: data_get($page, 'description') ?: $product->summary ?: $name));
        $fact = mb_substr($fact, 0, 280);
        $claimId = 'claim-1';

        $angles = [
            $this->angle(1, 'The everyday upgrade', 'Make the product feel like a useful improvement to a familiar routine.', 'Routine-led shoppers', 'While comparing practical options', 'A more considered everyday choice', $claimId, 'recommended'),
            $this->angle(2, 'See the useful details', 'Lead with the product details a buyer can verify on the page.', 'Careful product researchers', 'Before choosing between similar products', 'Clarity before purchase', $claimId, 'recommended'),
            $this->angle(3, 'Fits the real moment', 'Show the product in the specific moment it becomes relevant.', 'Intent-led shoppers', 'When the need becomes immediate', 'Easy-to-picture relevance', $claimId, 'recommended'),
            $this->angle(4, 'A clearer choice', 'Reduce choice friction with a simple, grounded product story.', 'Comparison shoppers', 'At the shortlist stage', 'A confident next step', $claimId, 'secondary'),
            $this->angle(5, 'Product-first proof', 'Let visible product details carry the story.', 'Detail-oriented buyers', 'When checking the final details', 'Evidence-led confidence', $claimId, 'secondary'),
        ];

        $content = [
            'overview' => [
                'summary' => "A source-constrained marketing workspace for {$name}, built before production starts.",
                'campaign_goal' => 'Give the team three coherent campaign routes they can record and publish.',
            ],
            'product_truth' => [
                'name' => $name,
                'price' => $product->price ?: 'Price not supplied',
                'availability' => data_get($page, 'product_truth.availability') ?: 'Not confirmed',
                'verified_facts' => [['statement' => $fact, 'claim_id' => $claimId]],
                'supported_benefits' => ['A product story grounded in the supplied page'],
                'offers_and_trust_signals' => [],
                'brand_context' => [$product->brand?->name ?: 'Brand context not supplied'],
                'missing_information' => array_values(array_filter([
                    $product->price ? null : 'Price was not supplied.',
                    data_get($page, 'product_truth.availability') ? null : 'Availability was not confirmed.',
                    'No discount or scarcity claim was confirmed.',
                ])),
                'prohibited_claims' => ['Do not add materials, origin, manufacturing, review, discount, or scarcity claims not stated by the source.'],
                'sources' => [$source->url],
            ],
            'positioning' => [
                'value_proposition' => "Make {$name} easy to understand through a useful, source-grounded product story.",
                'brand_position' => 'Clear, considered, and practical rather than promotional for its own sake.',
                'audience_priorities' => [
                    ['name' => 'Intent-led shoppers', 'need' => 'A clear reason to consider the product', 'buyer_moment' => 'Comparing practical options', 'why_relevant' => 'They are close enough to purchase that product clarity matters.'],
                    ['name' => 'Careful researchers', 'need' => 'Visible, verifiable details', 'buyer_moment' => 'Checking whether the product fits', 'why_relevant' => 'They respond to proof rather than broad claims.'],
                ],
            ],
            'ranked_angles' => $angles,
            'creative_routes' => [
                $this->route('route-1', 'Everyday upgrade', 1, $name, 'Routine-led shoppers', 'Setting up a familiar part of the day', 'Warm and practical'),
                $this->route('route-2', 'Useful details', 2, $name, 'Careful product researchers', 'Comparing the final options', 'Clear and evidence-led'),
                $this->route('route-3', 'Real buyer moment', 3, $name, 'Intent-led shoppers', 'When the product need becomes immediate', 'Direct and relatable'),
            ],
            'offers' => [[
                'wording' => "Explore {$name} and see whether it fits your routine.",
                'supporting_claim_ids' => [$claimId],
                'audience_or_situation' => 'For buyers who want product clarity without discount pressure.',
                'brand_fit' => 'It keeps the product and verified details at the centre of the message.',
                'limitations' => 'No discount, scarcity, shipping, or availability promise is implied.',
            ]],
            'qa' => [
                'claim_status' => 'passed',
                'timing_status' => 'passed',
                'caption_status' => 'passed',
                'approval_status' => 'ready',
                'summary' => 'The mock route uses one directly supported fact and timing that fits naturally.',
            ],
        ];

        if ($section = $page['regeneration_section'] ?? null) {
            $content = $this->applyMockVariation($content, $section);
        }

        return new GenerationResult(
            content: $content,
            evidence: [[
                'id' => $claimId,
                'claim' => $fact,
                'source' => $source->url,
                'excerpt' => $fact,
                'status' => 'directly_supported',
            ]],
            complianceFlags: [],
            provider: 'mock',
            model: null,
        );
    }

    private function angle(int $rank, string $title, string $idea, string $audience, string $moment, string $benefit, string $claimId, string $status): array
    {
        return [
            'rank' => $rank,
            'title' => $title,
            'core_idea' => $idea,
            'target_audience' => $audience,
            'buyer_moment' => $moment,
            'main_benefit' => $benefit,
            'relevance' => 'It gives the product a concrete role without inventing a factual claim.',
            'ranking_reason' => $rank <= 3 ? 'Strong fit for a short, producible campaign route.' : 'Useful as a secondary testing direction.',
            'supporting_claim_ids' => [$claimId],
            'status' => $status,
        ];
    }

    private function route(string $id, string $name, int $rank, string $productName, string $buyer, string $moment, string $tone): array
    {
        $voiceoverLines = [
            'Start with the everyday moment that needs a clearer answer.',
            "Introduce {$productName} and keep the product easy to see.",
            'Show the useful details while the product stays in context.',
            'Close with a simple invitation to learn more.',
        ];
        $captions = ['A familiar moment', "Meet {$productName}", 'See the useful details', 'Explore the product'];
        $purposes = ['hook', 'product proof', 'benefit', 'cta'];
        $scenes = ['A clean everyday setting', 'A clear product reveal', 'Product in a realistic context', 'Product hero and brand close'];
        $voiceover = [];
        $shotPlan = [];
        $timedCaptions = [];

        foreach ($voiceoverLines as $index => $line) {
            $start = $index * 5;
            $end = $start + 5;
            $words = $this->wordCount($line);
            $shotId = "{$id}-shot-".($index + 1);
            $voiceover[] = [
                'start_seconds' => $start,
                'end_seconds' => $end,
                'line' => $line,
                'word_count' => $words,
                'pace_wpm' => (int) round(($words / 5) * 60),
                'delivery_notes' => $index === 0 ? 'Open conversationally.' : 'Keep the delivery natural and unhurried.',
                'shot_id' => $shotId,
            ];
            $timedCaptions[] = ['start_seconds' => $start, 'end_seconds' => $end, 'text' => $captions[$index], 'shot_id' => $shotId];
            $shotPlan[] = [
                'id' => $shotId,
                'start_seconds' => $start,
                'end_seconds' => $end,
                'purpose' => $purposes[$index],
                'scene' => $scenes[$index],
                'action' => $index === 0 ? 'Begin with the buyer situation, then bring the product into view.' : 'Keep the product action simple and easy to understand.',
                'camera_framing' => $index === 1 ? 'Medium product reveal, then one close detail.' : 'Stable medium or close framing.',
                'product_visibility' => $index === 0 ? 'Product can enter after the first beat.' : 'Product should remain clearly visible.',
                'voiceover_line' => $line,
                'on_screen_caption' => $captions[$index],
                'product_fact_or_benefit' => $index === 2 ? 'Show only details visible on or supported by the product page.' : 'No additional factual claim.',
                'props_or_requirements' => 'Product and a clean, relevant setting.',
                'lighting_or_movement' => 'Soft natural light with restrained camera movement.',
                'priority' => 'essential',
            ];
        }

        $asset = [
            'primary_copy' => "A clear look at {$productName}, built around a real buyer moment.",
            'short_caption' => "See where {$productName} fits.",
            'title' => "Meet {$productName}",
            'headlines' => ["Meet {$productName}", 'See the useful details'],
            'description_lines' => ['Explore the product page for verified details.'],
            'hashtags' => [],
            'cta' => 'Learn more',
            'frames' => ['Hook frame', 'Product proof frame', 'CTA frame'],
        ];

        return [
            'id' => $id,
            'name' => $name,
            'angle_rank' => $rank,
            'marketing_angle' => $name,
            'target_buyer' => $buyer,
            'buyer_moment' => $moment,
            'core_promise' => 'A clear, practical product story grounded in the supplied source.',
            'tone' => $tone,
            'total_duration_seconds' => 20,
            'hooks' => ['Start with the buyer moment.', 'Make the product easy to picture.', 'Let the useful details lead.'],
            'voiceover' => $voiceover,
            'captions' => $timedCaptions,
            'shot_plan' => $shotPlan,
            'platform_assets' => array_fill_keys(['instagram_reels', 'youtube_shorts', 'whatsapp_status', 'meta_ads'], $asset),
            'offer_treatment' => 'Use product-led value framing. Do not imply a discount or limited availability.',
            'claim_validation_status' => 'passed',
        ];
    }

    private function applyMockVariation(array $content, string $section): array
    {
        match ($section) {
            'positioning' => $content['positioning']['brand_position'] = 'A fresh, source-grounded positioning variation.',
            'ranked_angles' => $content['ranked_angles'] = array_values(array_reverse($content['ranked_angles'])),
            'creative_routes' => $content['creative_routes'] = array_map(function (array $route): array {
                $route['name'] .= ' — variation';

                return $route;
            }, $content['creative_routes']),
            'offers' => $content['offers'][0]['wording'] = 'See the verified product details and decide whether it fits.',
            default => null,
        };

        return $content;
    }

    private function wordCount(string $value): int
    {
        preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}’'-]*/u", $value, $matches);

        return count($matches[0]);
    }
}

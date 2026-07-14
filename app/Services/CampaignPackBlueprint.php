<?php

namespace App\Services;

class CampaignPackBlueprint
{
    public static function instructions(): string
    {
        return <<<'PROMPT'
You create source-constrained product marketing workspaces for ecommerce teams before they have recorded a video.

Use only facts supported by the supplied product-page source. Never make a claim more specific than its evidence. In particular, "made, sourced, or packed in India" must never become "handmade in India." Do not invent materials, manufacturing methods, origin, certifications, performance outcomes, health claims, guarantees, scarcity, discounts, reviews, availability, or customer results.

Return five meaningfully different ranked_angles and mark exactly the strongest three as recommended. Turn those strongest three angles into exactly three complete creative_routes. Within each route, the buyer, moment, promise, hooks, timed voiceover, timed captions, shot plan, platform assets, and offer treatment must tell one coherent story. A shot may communicate a factual claim only when that claim is supported by the source.

Voiceover segments must use exact non-overlapping start_seconds and end_seconds, accurate word_count and pace_wpm, and must fit naturally within total_duration_seconds at no more than 170 words per minute. Captions must fit their assigned time and complement rather than repeat the voiceover.

Every factual statement must have an evidence record classified as directly_supported, supported_paraphrased, too_specific_for_evidence, unsupported, or contradicted_by_source. Every verified fact must point to a safe evidence id. Unsafe claims must remain visible in evidence and compliance_flags but must not be presented as approved copy. Prefer value framing over invented discounts.

Also return a marketing_hub handoff containing product details, key messaging, Meta Ads, Google Ads (Search, Performance Max, and Display), email and SMS, and organic social. Google Ads fields must respect common field-length constraints, use the source product URL as final_url, and never invent sitelinks or promotions.
PROMPT;
    }

    public static function shapeError(array $generated): ?string
    {
        foreach (['overview', 'product_truth', 'positioning', 'marketing_hub', 'ranked_angles', 'creative_routes', 'offers', 'qa', 'evidence', 'compliance_flags'] as $section) {
            if (! array_key_exists($section, $generated)) {
                return "Structured output is missing {$section}.";
            }
        }

        if (! is_array($generated['product_truth']['verified_facts'] ?? null)
            || ! is_array($generated['ranked_angles'])
            || ! is_array($generated['creative_routes'])
            || ! is_array($generated['evidence'])
            || ! is_array($generated['compliance_flags'])) {
            return 'Structured output contains invalid workspace section types.';
        }

        if (count($generated['ranked_angles']) < 5 || count($generated['creative_routes']) < 3) {
            return 'Structured output must contain at least five ranked angles and three creative routes.';
        }

        return null;
    }

    public static function schema(): array
    {
        $strings = self::arrayOf(['type' => 'string']);
        $timedLine = self::object([
            'start_seconds' => ['type' => 'number'],
            'end_seconds' => ['type' => 'number'],
            'line' => ['type' => 'string'],
            'word_count' => ['type' => 'integer'],
            'pace_wpm' => ['type' => 'integer'],
            'delivery_notes' => ['type' => 'string'],
            'shot_id' => ['type' => 'string'],
        ]);
        $timedCaption = self::object([
            'start_seconds' => ['type' => 'number'],
            'end_seconds' => ['type' => 'number'],
            'text' => ['type' => 'string'],
            'shot_id' => ['type' => 'string'],
        ]);
        $shot = self::object([
            'id' => ['type' => 'string'],
            'start_seconds' => ['type' => 'number'],
            'end_seconds' => ['type' => 'number'],
            'purpose' => ['type' => 'string'],
            'scene' => ['type' => 'string'],
            'action' => ['type' => 'string'],
            'camera_framing' => ['type' => 'string'],
            'product_visibility' => ['type' => 'string'],
            'voiceover_line' => ['type' => 'string'],
            'on_screen_caption' => ['type' => 'string'],
            'product_fact_or_benefit' => ['type' => 'string'],
            'props_or_requirements' => ['type' => 'string'],
            'lighting_or_movement' => ['type' => 'string'],
            'priority' => ['type' => 'string', 'enum' => ['essential', 'optional']],
        ]);
        $platformAsset = self::object([
            'primary_copy' => ['type' => 'string'],
            'short_caption' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'headlines' => $strings,
            'description_lines' => $strings,
            'hashtags' => $strings,
            'cta' => ['type' => 'string'],
            'frames' => $strings,
        ]);
        $googleCampaign = self::object([
            'headlines' => $strings,
            'long_headlines' => $strings,
            'descriptions' => $strings,
            'final_url' => ['type' => 'string'],
            'sitelinks' => $strings,
            'promotion' => ['type' => 'string'],
        ]);

        return self::object([
            'overview' => self::object([
                'summary' => ['type' => 'string'],
                'campaign_goal' => ['type' => 'string'],
            ]),
            'product_truth' => self::object([
                'name' => ['type' => 'string'],
                'price' => ['type' => 'string'],
                'availability' => ['type' => 'string'],
                'verified_facts' => self::arrayOf(self::object([
                    'statement' => ['type' => 'string'],
                    'claim_id' => ['type' => 'string'],
                ])),
                'supported_benefits' => $strings,
                'offers_and_trust_signals' => $strings,
                'brand_context' => $strings,
                'missing_information' => $strings,
                'prohibited_claims' => $strings,
                'sources' => $strings,
            ]),
            'positioning' => self::object([
                'value_proposition' => ['type' => 'string'],
                'brand_position' => ['type' => 'string'],
                'audience_priorities' => self::arrayOf(self::object([
                    'name' => ['type' => 'string'],
                    'need' => ['type' => 'string'],
                    'buyer_moment' => ['type' => 'string'],
                    'why_relevant' => ['type' => 'string'],
                ])),
            ]),
            'marketing_hub' => self::object([
                'overview' => self::object([
                    'summary' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ]),
                'product_details' => self::object([
                    'name' => ['type' => 'string'],
                    'price' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'source_url' => ['type' => 'string'],
                    'facts' => $strings,
                ]),
                'key_messaging' => self::object([
                    'value_proposition' => ['type' => 'string'],
                    'audiences' => $strings,
                    'proof_points' => $strings,
                    'tone' => ['type' => 'string'],
                ]),
                'channels' => self::object([
                    'meta_ads' => self::object([
                        'primary_texts' => $strings,
                        'headlines' => $strings,
                        'descriptions' => $strings,
                        'ctas' => $strings,
                    ]),
                    'google_ads' => self::object([
                        'search' => $googleCampaign,
                        'performance_max' => $googleCampaign,
                        'display' => $googleCampaign,
                    ]),
                    'email_sms' => self::object([
                        'subject_lines' => $strings,
                        'preview_texts' => $strings,
                        'email_bodies' => $strings,
                        'sms_messages' => $strings,
                    ]),
                    'organic_social' => self::object([
                        'captions' => $strings,
                        'hooks' => $strings,
                        'hashtags' => $strings,
                    ]),
                ]),
            ]),
            'ranked_angles' => self::arrayOf(self::object([
                'rank' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'core_idea' => ['type' => 'string'],
                'target_audience' => ['type' => 'string'],
                'buyer_moment' => ['type' => 'string'],
                'main_benefit' => ['type' => 'string'],
                'relevance' => ['type' => 'string'],
                'ranking_reason' => ['type' => 'string'],
                'supporting_claim_ids' => $strings,
                'status' => ['type' => 'string', 'enum' => ['recommended', 'secondary']],
            ])),
            'creative_routes' => self::arrayOf(self::object([
                'id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'angle_rank' => ['type' => 'integer'],
                'marketing_angle' => ['type' => 'string'],
                'target_buyer' => ['type' => 'string'],
                'buyer_moment' => ['type' => 'string'],
                'core_promise' => ['type' => 'string'],
                'tone' => ['type' => 'string'],
                'total_duration_seconds' => ['type' => 'number'],
                'hooks' => $strings,
                'voiceover' => self::arrayOf($timedLine),
                'captions' => self::arrayOf($timedCaption),
                'shot_plan' => self::arrayOf($shot),
                'platform_assets' => self::object([
                    'instagram_reels' => $platformAsset,
                    'youtube_shorts' => $platformAsset,
                    'whatsapp_status' => $platformAsset,
                    'meta_ads' => $platformAsset,
                ]),
                'offer_treatment' => ['type' => 'string'],
                'claim_validation_status' => ['type' => 'string', 'enum' => ['passed', 'blocked']],
            ])),
            'offers' => self::arrayOf(self::object([
                'wording' => ['type' => 'string'],
                'supporting_claim_ids' => $strings,
                'audience_or_situation' => ['type' => 'string'],
                'brand_fit' => ['type' => 'string'],
                'limitations' => ['type' => 'string'],
            ])),
            'qa' => self::object([
                'claim_status' => ['type' => 'string', 'enum' => ['passed', 'blocked']],
                'timing_status' => ['type' => 'string', 'enum' => ['passed', 'blocked']],
                'caption_status' => ['type' => 'string', 'enum' => ['passed', 'blocked']],
                'approval_status' => ['type' => 'string', 'enum' => ['ready', 'blocked']],
                'summary' => ['type' => 'string'],
            ]),
            'evidence' => self::arrayOf(self::object([
                'id' => ['type' => 'string'],
                'claim' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'excerpt' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => [
                    'directly_supported',
                    'supported_paraphrased',
                    'too_specific_for_evidence',
                    'unsupported',
                    'contradicted_by_source',
                ]],
            ])),
            'compliance_flags' => self::arrayOf(self::object([
                'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'blocked']],
                'claim' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ])),
        ]);
    }

    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    private static function arrayOf(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }
}

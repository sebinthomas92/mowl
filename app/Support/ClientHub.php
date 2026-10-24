<?php

namespace App\Support;

use App\Models\CampaignPackVersion;
use App\Models\Product;
use App\Services\CampaignPackSafetyValidator;
use App\Services\ProductHubContentAdapter;
use Illuminate\Support\Collection;

/**
 * What the client hub shows: the latest approved launch pack version of one product, laid out for handoff.
 * The same data feeds the Client hub tab, the shared client link and the client portal, so they never drift.
 */
class ClientHub
{
    /** Channel => label, icon and fields (field => [label, character limit, whether the limit is a hard platform limit]). */
    public const CHANNELS = [
        'meta_ads' => ['label' => 'Meta Ads', 'icon' => 'brand-meta', 'fields' => [
            'primary_texts' => ['Primary text', 125, false],
            'headlines' => ['Headlines', 40, false],
            'descriptions' => ['Descriptions', 30, false],
            'ctas' => ['Call to action', null, false],
        ]],
        'google_ads' => ['label' => 'Google Ads', 'icon' => 'brand-google', 'fields' => [
            'headlines' => ['Headlines', 30, true],
            'long_headlines' => ['Long headlines', 90, true],
            'descriptions' => ['Descriptions', 90, true],
        ]],
        'email_sms' => ['label' => 'Email & SMS', 'icon' => 'mail', 'fields' => [
            'subject_lines' => ['Subject lines', 60, false],
            'preview_texts' => ['Preview text', 90, false],
            'email_bodies' => ['Email body', null, false],
            'sms_messages' => ['SMS', 160, false],
        ]],
        'organic_social' => ['label' => 'Organic social', 'icon' => 'social', 'fields' => [
            'hooks' => ['Hooks', null, false],
            'captions' => ['Captions', null, false],
            'hashtags' => ['Hashtags', null, false],
        ]],
    ];

    public const GOOGLE_TYPES = ['search' => 'Search', 'performance_max' => 'Performance Max', 'display' => 'Display'];

    public static function latestApprovedVersion(Product $product): ?CampaignPackVersion
    {
        return CampaignPackVersion::query()
            ->whereHas('campaignPack', fn ($query) => $query->where('product_id', $product->id))
            ->where('review_status', 'approved')
            ->with(['campaignPack.sourceSnapshot', 'bannerCreatives'])
            ->orderByDesc('reviewed_at')->orderByDesc('id')->first();
    }

    /**
     * Everything the hub body renders. $links builds URLs, so the same body works signed in and on a shared link.
     *
     * @param  array{media: callable, creative: callable, export: callable}  $links
     */
    public static function build(Product $product, ?CampaignPackVersion $version, array $links): array
    {
        $product->loadMissing(['brand', 'resourceLinks', 'mediaAssets']);
        $content = $version ? app(ProductHubContentAdapter::class)->adapt($product, $version) : null;
        $cover = $product->coverImage();

        return [
            'version' => $version,
            'content' => $content,
            'imageUrl' => $cover ? ($links['media'])($cover) : null,
            'images' => $version ? self::images($version)->map(fn ($creative) => [
                'id' => $creative->id,
                'url' => ($links['creative'])($creative),
                'headline' => $creative->headline,
                'layout' => $creative->layout,
            ])->values()->all() : [],
            'exportUrl' => $links['export'],
            'channels' => $content ? self::channels($content) : [],
            'links' => $product->resourceLinks->sortBy('id')->values(),
        ];
    }

    /** Approved banners that are safe to hand over: finished, with a file, and showing no price the product page does not. */
    public static function images(CampaignPackVersion $version): Collection
    {
        $validator = app(CampaignPackSafetyValidator::class);

        return $version->bannerCreatives
            ->where('status', 'completed')
            ->filter(fn ($creative) => filled($creative->output_path) && $validator->bannerPrices($creative) === [])
            ->sortBy('sequence')->values();
    }

    /** Channel copy as lists of non-empty strings, dropping channels with nothing approved. */
    private static function channels(array $content): array
    {
        $channels = [];
        foreach (self::CHANNELS as $key => $channel) {
            if ($key === 'google_ads') {
                $types = [];
                foreach (self::GOOGLE_TYPES as $type => $label) {
                    $source = data_get($content, "channels.google_ads.{$type}", []);
                    $fields = self::fields($channel['fields'], $source);
                    if ($fields) {
                        $types[$type] = ['label' => $label, 'fields' => $fields, 'final_url' => data_get($source, 'final_url'), 'promotion' => data_get($source, 'promotion')];
                    }
                }
                if ($types) {
                    $channels[$key] = ['label' => $channel['label'], 'icon' => $channel['icon'], 'types' => $types];
                }

                continue;
            }
            $fields = self::fields($channel['fields'], data_get($content, "channels.{$key}", []));
            if ($fields) {
                $channels[$key] = ['label' => $channel['label'], 'icon' => $channel['icon'], 'fields' => $fields];
            }
        }

        return $channels;
    }

    private static function fields(array $definitions, mixed $source): array
    {
        $fields = [];
        foreach ($definitions as $field => [$label, $limit, $hard]) {
            $values = collect((array) data_get($source, $field, []))
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn ($value) => trim($value))->values()->all();
            if ($values) {
                $fields[$field] = ['label' => $label, 'limit' => $limit, 'hard' => $hard, 'values' => $values];
            }
        }

        return $fields;
    }
}

<?php

namespace App\Services;

use App\Models\CampaignPackVersion;

class BannerCopySelector
{
    private const DIRECTIONS = [
        ['key' => 'product_hero', 'label' => 'Product hero · strongest verified benefit', 'layout' => 'hero_top'],
        ['key' => 'problem_solution', 'label' => 'Problem / solution · approved positioning', 'layout' => 'split_left'],
        ['key' => 'lifestyle_context', 'label' => 'Lifestyle · approved audience context', 'layout' => 'bottom_panel'],
    ];

    public function select(CampaignPackVersion $version, int $sequence): array
    {
        $content = $version->content;
        $direction = self::DIRECTIONS[($sequence - 1) % count(self::DIRECTIONS)];
        $routes = collect(data_get($content, 'creative_routes', []));
        $headlines = $this->strings([
            ...data_get($content, 'meta.headlines', []),
            ...$routes->flatMap(fn (array $route) => data_get($route, 'platform_assets.meta_ads.headlines', []))->all(),
            ...$routes->pluck('platform_assets.meta_ads.title')->all(),
        ]);
        $hooks = $this->strings([
            ...data_get($content, 'hooks', []),
            ...$routes->flatMap(fn (array $route) => $route['hooks'] ?? [])->all(),
        ]);
        $descriptions = $this->strings([
            ...data_get($content, 'meta.descriptions', []),
            ...$routes->flatMap(fn (array $route) => data_get($route, 'platform_assets.meta_ads.description_lines', []))->all(),
        ]);
        $benefits = $this->strings([
            ...data_get($content, 'benefits', []),
            ...data_get($content, 'product_truth.supported_benefits', []),
            ...$routes->pluck('core_promise')->all(),
        ]);
        $audiences = $this->strings([
            ...data_get($content, 'audiences', []),
            ...$routes->pluck('target_buyer')->all(),
            ...collect(data_get($content, 'positioning.audience_priorities', []))->pluck('name')->all(),
        ]);
        $fallback = trim((string) (data_get($content, 'direction.title') ?: data_get($content, 'ranked_angles.0.title') ?: data_get($content, 'overview.campaign_goal')));
        $headlinePool = array_values(array_unique([...$headlines, ...$hooks, ...($fallback !== '' ? [$fallback] : [])]));
        $headline = $headlinePool[($sequence - 1) % max(1, count($headlinePool))]
            ?? (string) data_get($content, 'product_truth.name', 'Product');
        $supportPool = array_values(array_unique([...$descriptions, ...$benefits]));
        if ($direction['key'] === 'lifestyle_context') {
            $supportPool = array_values(array_unique([...$audiences, ...$supportPool]));
        }

        return [
            ...$direction,
            'headline' => $headline,
            'supporting_text' => $supportPool[($sequence - 1) % max(1, count($supportPool))] ?? null,
            'cta' => 'Shop now',
            'prompt' => $this->prompt($version, $content, $direction, $sequence),
        ];
    }

    private function prompt(CampaignPackVersion $version, array $content, array $direction, int $sequence): string
    {
        $product = (string) data_get($content, 'product_truth.name', 'the supplied product');
        $brand = (string) data_get($content, 'product_truth.brand', 'the approved brand');
        if ($version->exists) {
            $brand = (string) ($version->campaignPack?->product?->brand?->name ?: $brand);
        }
        $benefits = $this->strings([
            ...data_get($content, 'benefits', []),
            ...data_get($content, 'product_truth.supported_benefits', []),
            ...collect(data_get($content, 'creative_routes', []))->pluck('core_promise')->all(),
        ]);
        $facts = collect(data_get($content, 'product_truth.verified_facts', []))
            ->map(fn (mixed $fact): mixed => is_array($fact) ? ($fact['statement'] ?? null) : $fact)
            ->filter(fn (mixed $fact): bool => is_string($fact) && trim($fact) !== '')
            ->map(fn (string $fact): string => trim($fact))
            ->values()
            ->all();
        $audiences = $this->strings([
            ...data_get($content, 'audiences', []),
            ...collect(data_get($content, 'creative_routes', []))->pluck('target_buyer')->all(),
        ]);
        $benefit = $benefits[($sequence - 1) % max(1, count($benefits))] ?? '';
        $audience = $audiences[0] ?? '';
        $productDna = implode('; ', array_slice(array_values(array_unique([...$facts, ...$benefits])), 0, 6)) ?: $product;
        $negativeSpace = match ($direction['layout']) {
            'split_left' => 'Keep the left 46% visually quiet for an overlay.',
            'bottom_panel' => 'Keep the bottom 38% visually quiet for an overlay.',
            default => 'Keep the upper-left 46% visually quiet for an overlay.',
        };

        return trim("You are an expert ad creative prompt engineer. Create one premium vertical Meta feed visual composition for ONE specific product: {$product}. The approved PRODUCT DNA is the source of truth for what to depict: {$productDna}. Brand context: {$brand}; use it only for palette and aesthetic consistency. CRITICAL: Every visual must depict the specific product {$product}; ignore and do not show other products or SKUs from the brand catalog. Direction: {$direction['label']}. Approved benefit context: {$benefit}. Approved audience context: {$audience}. Use the first supplied product image as the exact product reference. Follow its visual cues so the result matches the real product. Preserve its recognizable form, materials, proportions, color, and packaging silhouette; do not invent components or features. {$negativeSpace} Generate the visual composition only. Do not include any text, letters, numbers, logos, labels, captions, UI, signatures, borders, or watermarks. Do not add unverified product features or claims. Photorealistic commercial lighting, clean art direction, 4:5 composition.");
    }

    private function strings(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->values()
            ->all();
    }
}

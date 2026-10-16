<?php

namespace App\Services;

use App\Models\CampaignPackVersion;

class BannerCopySelector
{
    /** Every scene-only prompt carries this, so a stored prompt says which way its banner is rendered. */
    public const SCENE_MARKER = 'will be placed into this photo afterwards';

    /** @var (\Closure(string): bool)|null */
    private ?\Closure $usable = null;

    private const DIRECTIONS = [
        ['key' => 'product_hero', 'label' => 'Product hero · strongest verified benefit', 'layout' => 'hero_top'],
        ['key' => 'problem_solution', 'label' => 'Problem / solution · approved positioning', 'layout' => 'split_left'],
        ['key' => 'lifestyle_context', 'label' => 'Lifestyle · approved audience context', 'layout' => 'bottom_panel'],
    ];

    public function select(CampaignPackVersion $version, int $sequence): array
    {
        $content = $version->content;
        // Banner copy is reused from the approved pack, and a price on a banner is the claim people
        // act on, so a line whose price the product page does not show is never used.
        $validator = app(CampaignPackSafetyValidator::class);
        $prices = $version->exists ? $validator->supportedPrices($version->campaignPack?->sourceSnapshot) : null;
        $this->usable = fn (string $text): bool => $validator->unsupportedPrices($text, $prices) === [];
        $direction = self::DIRECTIONS[($sequence - 1) % count(self::DIRECTIONS)];
        $routes = collect(data_get($content, 'creative_routes', []));
        // Ad headlines and short hooks only. Listing titles ("Kindle Stand | Cozy Reading") and the
        // product's own name read as SEO titles on an image, not as a headline.
        $product = mb_strtolower((string) data_get($content, 'product_truth.name', ''));
        $headlineLike = fn (string $text): bool => $text !== '' && ! str_contains($text, '|')
            && mb_strlen($text) <= 48
            && ! ($product !== '' && str_contains($this->letters($product), $this->letters(mb_strtolower($text))));
        // Approved copy is never shortened: a bracketed aside may be a qualifier ("(orders over ₹499)"),
        // so a headline or hook with one is skipped rather than cut.
        $unbracketed = fn (string $text): bool => ! preg_match('/[()\[\]]/u', $text);
        $headlines = array_values(array_filter(array_filter($this->strings([
            ...data_get($content, 'meta.headlines', []),
            ...$routes->flatMap(fn (array $route) => data_get($route, 'platform_assets.meta_ads.headlines', []))->all(),
        ]), $unbracketed), $headlineLike));
        $hooks = array_values(array_filter(array_filter($this->strings([
            ...data_get($content, 'hooks', []),
            ...$routes->flatMap(fn (array $route) => $route['hooks'] ?? [])->all(),
        ]), $unbracketed), $headlineLike));
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
        $fallback = $this->strings([data_get($content, 'direction.title'), data_get($content, 'ranked_angles.0.title'), data_get($content, 'overview.campaign_goal')]);
        // Angle and direction titles are internal strategy labels ("The Gift of Reading (Diwali Focus)"),
        // not approved ad copy, so they are only a last resort and their bracketed label is dropped.
        $labels = array_values(array_filter(array_map($this->withoutLabels(...), $fallback), fn (string $text): bool => $text !== ''));
        $headlinePool = array_values(array_unique([...$headlines, ...$hooks]))
            ?: array_slice(array_values(array_filter($labels, $headlineLike)), 0, 1)
            ?: array_slice($labels, 0, 1);
        $headline = $headlinePool[($sequence - 1) % max(1, count($headlinePool))]
            ?? (string) data_get($content, 'product_truth.name', 'Product');
        $supportPool = array_values(array_unique([...$descriptions, ...$benefits]));
        if ($direction['key'] === 'lifestyle_context') {
            $supportPool = array_values(array_unique([...$audiences, ...$supportPool]));
        }
        // A banner is a static image that outlives price and shipping changes, so lines with a
        // currency amount are used only when nothing else is approved.
        $withoutAmounts = array_values(array_filter($supportPool, fn (string $text): bool => $validator->unsupportedPrices($text, []) === []));
        $supportPool = $withoutAmounts ?: $supportPool;

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
        if (config('campaigns.banners.composite_product')) {
            return $this->scenePrompt($product, $brand, $direction, $benefit, $audience);
        }
        $negativeSpace = match ($direction['layout']) {
            'split_left' => 'Place the product in the right half and let the left half continue the same scene as soft, out-of-focus depth for an overlay.',
            'bottom_panel' => 'Keep the bottom 38% visually quiet for an overlay.',
            default => 'Keep the upper-left 46% visually quiet for an overlay.',
        }.' The quiet area must be a natural, softly lit continuation of the same scene: no flat color block, no split-screen and no hard vertical or horizontal boundary. Keep the bottom-right corner simple and free of the product for a small brand mark.';

        return trim("You are an expert ad creative prompt engineer. Create one premium vertical Meta feed visual composition for ONE specific product: {$product}. The approved PRODUCT DNA is the source of truth for what to depict: {$productDna}. Brand context: {$brand}; use it only for palette and aesthetic consistency. CRITICAL: Every visual must depict the specific product {$product}; ignore and do not show other products or SKUs from the brand catalog. Direction: {$direction['label']}. Approved benefit context: {$benefit}. Approved audience context: {$audience}. Use the first supplied product image as the exact product reference. Follow its visual cues so the result matches the real product. Preserve its recognizable form, materials, proportions, color, and packaging silhouette; do not invent components or features. Show the product as the physical object it is, in its own material and surface finish: if it is shaped like another everyday object, it must still read as the product and never as that object itself. {$negativeSpace} Generate the visual composition only. Do not include any text, letters, numbers, logos, labels, captions, UI, signatures, borders, or watermarks. Show no third-party intellectual property: no real book covers, titles or publisher marks, no film, game or app imagery, and no other brands' logos or trademarks. The product itself must still match its reference exactly; any other screen, book, package or poster in the scene must be blank, plain or abstract. Any device screen, including one held by or shown with the product, must be switched off or show a plain blank page with no text or image, even if the reference image shows content on it. No readable text anywhere in the scene: no book spines, magazine names, labels or packaging text. Do not add unverified product features or claims. Photorealistic commercial lighting, clean art direction, 4:5 composition.");
    }

    /**
     * The product is composited from its store photo afterwards, so the image model draws only the
     * empty set it will stand in, with a clear resting spot where BannerComposer places it.
     */
    private function scenePrompt(string $product, string $brand, array $direction, string $benefit, string $audience): string
    {
        [$spot, $quiet] = match ($direction['layout']) {
            'split_left' => ['on a surface in the right half of the frame, a little below the middle', 'Let the left half continue the same scene as soft, out-of-focus depth for an overlay.'],
            'bottom_panel' => ['on a surface in the centre of the frame, with the surface\'s resting point about 60% of the way down', 'Keep the bottom 38% visually quiet for an overlay.'],
            default => ['on a surface in the lower half of the frame, slightly right of centre', 'Keep the upper-left 46% visually quiet for an overlay.'],
        };

        return trim("Create one premium, photorealistic vertical 4:5 product-photography scene for an ad for {$product}. Brand context: {$brand}; use it only for palette and mood. Direction: {$direction['label']}. Approved benefit context: {$benefit}. Approved audience context: {$audience}. "
            .'The product itself '.self::SCENE_MARKER." from its real studio photo, so do NOT show {$product} or anything resembling it anywhere. "
            ."Leave a clear, empty resting spot {$spot}, sized for an object about 40% of the image width, with soft, even, slightly frontal studio light and a camera at a slight high angle, so a placed product looks natural there. "
            ."{$quiet} The quiet area must be a natural, softly lit continuation of the same scene: no flat colour block, no split-screen and no hard vertical or horizontal boundary. Keep the bottom-right corner simple for a small brand mark. "
            .'Keep the set minimal: a surface, a backdrop and at most one or two small, plain, out-of-focus props away from the resting spot. '
            .'Do not include any text, letters, numbers, logos, labels, captions, UI, signatures, borders or watermarks. No books, magazines, screens, packaging or posters. No readable text anywhere. Clean commercial art direction, 4:5 composition.');
    }

    /** Drops a bracketed working label such as "(Diwali Focus)" from an internal angle title. */
    private function withoutLabels(string $text): string
    {
        return trim((string) preg_replace(['/\s*[(\[][^)\]]*[)\]]/u', '/\s{2,}/u'], ['', ' '], $text));
    }

    /** Lowercase letters and digits only, so dashes, ampersands and spacing don't hide a match. */
    private function letters(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
    }

    private function strings(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '' && (! $this->usable || ($this->usable)($value)))
            ->map(fn (string $value) => trim($value))
            ->values()
            ->all();
    }
}

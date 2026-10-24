<?php

namespace App\Services\Pages\Document;

use App\Models\BannerCreative;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\ProductKnowledge;
use Illuminate\Support\Collection;

/**
 * What a page may say about a product, gathered once: confirmed Knowledge, approved launch pack copy,
 * the product page as it was read, product photos and the brand. Templates fill sections from it,
 * the AI writes from it and the claim checks compare page text with it.
 */
final class ProductBrief
{
    /**
     * @param  array<string, string>  $facts  confirmed Knowledge, by fact key
     * @param  array<string, array>  $approved  approved launch pack copy, by section
     * @param  list<array>  $images  product image references, product page photos first
     */
    public function __construct(
        public readonly Product $product,
        public readonly array $facts,
        public readonly ?string $price,
        public readonly string $buyUrl,
        public readonly array $images,
        public readonly array $approved,
        public readonly string $sourceText,
        public readonly array $truth,
        public readonly array $team,
        public readonly array $brand,
        public readonly ?SourceSnapshot $source,
    ) {}

    public static function for(Product $product): self
    {
        $product->loadMissing('brand');
        $source = $product->sourceSnapshots()->where('status', 'ready')->latest('id')->first();
        $page = [
            'product_truth' => (array) ($source?->extracted_truth ?? []),
            'description' => $source?->extracted_truth['description'] ?? null,
            'content' => (string) ($source?->extracted_content ?? ''),
        ];
        $subject = $product;
        $facts = [];
        if (ProductKnowledge::available()) {
            $knowledge = app(ProductKnowledge::class);
            [$subject, $page] = $knowledge->prepare($product, $page);
            $facts = $product->facts()->where('status', 'ok')->whereNotNull('confirmed_value')->pluck('confirmed_value', 'key')
                ->map(fn ($value) => trim((string) $value))->filter()->all();
        }
        $truth = (array) ($page['product_truth'] ?? []);
        $price = $facts['price'] ?? (filled($subject->price) ? (string) $subject->price : null);
        if (! $price && ProductKnowledge::available()) {
            $price = app(ProductKnowledge::class)->sourceValues($truth)['price'] ?? null;
        }

        return new self(
            product: $product,
            facts: $facts,
            price: $price ? trim($price) : null,
            buyUrl: (string) ($source?->canonical_url ?: $source?->url ?: ''),
            images: self::images($product),
            approved: self::approved($product),
            sourceText: mb_substr(trim((string) ($page['content'] ?? '')), 0, 40_000),
            truth: $truth,
            team: (array) ($page['team_knowledge'] ?? []),
            brand: array_filter([
                'name' => $product->brand?->name,
                'voice' => $page['brand_profile']['voice'] ?? null,
                'palette' => $product->brand?->colors() ?: null,
            ]),
            source: $source,
        );
    }

    /** The product's name as the page should show it. */
    public function name(): string
    {
        return (string) ($this->facts['name'] ?? $this->product->name);
    }

    public function description(): string
    {
        return trim((string) ($this->facts['description'] ?? $this->truth['description'] ?? $this->product->summary ?? ''));
    }

    /** @return list<string> one line per entry of a multi-line fact */
    public function lines(string $fact): array
    {
        return array_values(array_filter(array_map(fn ($line) => trim((string) preg_replace('/^[\s\-•*·–]+/u', '', $line)), preg_split('/\R/u', (string) ($this->facts[$fact] ?? '')) ?: [])));
    }

    public function valueProposition(): string
    {
        return trim((string) ($this->approved['positioning']['value_proposition'] ?? $this->approved['marketing_hub']['key_messaging']['value_proposition'] ?? ''));
    }

    /** @return list<array{title: string, text: string}> approved angles, best first */
    public function angles(): array
    {
        return collect($this->approved['ranked_angles']['angles'] ?? [])
            ->filter(fn ($angle) => is_array($angle) && filled($angle['title'] ?? null))
            ->sortBy(fn (array $angle) => (int) ($angle['rank'] ?? 99))
            ->map(fn (array $angle) => ['title' => trim((string) $angle['title']), 'text' => trim((string) ($angle['main_benefit'] ?? $angle['core_idea'] ?? ''))])
            ->values()->all();
    }

    /** @return list<string> */
    public function proofPoints(): array
    {
        return array_values(array_filter(array_map(fn ($point) => is_string($point) ? trim($point) : '', $this->approved['marketing_hub']['key_messaging']['proof_points'] ?? [])));
    }

    /** All text the page may rely on, for the claim checks. */
    public function supportText(): string
    {
        return implode("\n", array_filter([
            $this->product->name, $this->product->summary, $this->product->price, $this->price, $this->brand['name'] ?? null,
            $this->sourceText, json_encode($this->truth, JSON_UNESCAPED_UNICODE), implode("\n", $this->facts), implode("\n", array_map('strval', $this->team)),
            json_encode($this->approved, JSON_UNESCAPED_UNICODE),
        ]));
    }

    /**
     * Product photos (product page ones first, then uploads) and finished banners, newest last.
     *
     * @return list<array{kind: string, id: int, key: string, alt: string, label: string, width: ?int, height: ?int}>
     */
    public static function images(Product $product): array
    {
        $media = $product->mediaAssets()->where('type', 'image')->where('mime_type', 'like', 'image/%')->where('status', '!=', 'failed')->orderBy('id')->get()
            ->sortBy(fn (MediaAsset $asset) => ($asset->metadata['origin'] ?? '') === 'product_page' ? 0 : 1)
            ->map(fn (MediaAsset $asset) => [
                'kind' => 'media', 'id' => $asset->id, 'key' => "media-{$asset->id}", 'alt' => $product->name,
                'label' => ($asset->metadata['origin'] ?? '') === 'product_page' ? 'Product page photo' : 'Uploaded image',
                'width' => isset($asset->metadata['width']) ? (int) $asset->metadata['width'] : null,
                'height' => isset($asset->metadata['height']) ? (int) $asset->metadata['height'] : null,
            ]);
        $banners = BannerCreative::query()
            ->whereHas('campaignPack', fn ($query) => $query->where('product_id', $product->id))
            ->where('status', 'completed')->whereNotNull('output_path')->orderBy('id')->get()
            ->map(fn (BannerCreative $banner) => [
                'kind' => 'banner', 'id' => $banner->id, 'key' => "banner-{$banner->id}", 'alt' => (string) ($banner->headline ?: $product->name),
                'label' => 'Banner'.($banner->headline ? ': '.$banner->headline : ''), 'width' => $banner->width ? (int) $banner->width : null, 'height' => $banner->height ? (int) $banner->height : null,
            ]);

        return $media->values()->concat($banners)->values()->all();
    }

    /** @return list<array> product photos only, as section image references */
    public function photos(int $limit = 6): array
    {
        return array_slice(array_values(array_map(
            fn (array $image) => ['kind' => $image['kind'], 'id' => $image['id'], 'key' => $image['key'], 'alt' => $this->name()],
            array_filter($this->images, fn (array $image) => $image['kind'] === 'media'),
        )), 0, $limit);
    }

    /** @return array<string, array> latest approved body of each launch pack copy section */
    private static function approved(Product $product): array
    {
        $items = $product->contentItems()->where('status', 'approved')->where('type', 'copy')
            ->whereIn('section', ['positioning', 'ranked_angles', 'offers', 'marketing_hub'])
            ->orderByDesc('updated_at')->orderByDesc('id')->get();

        return $items->unique('section')->mapWithKeys(function (ContentItem $item): array {
            $version = $item->versions()->where('status', 'approved')->orderByDesc('version')->first();

            return $version ? [$item->section => (array) $version->body] : [];
        })->all();
    }

    /** @return Collection<int, array> */
    public function bannerImages(): Collection
    {
        return collect($this->images)->where('kind', 'banner')->values();
    }
}

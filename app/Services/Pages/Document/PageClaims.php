<?php

namespace App\Services\Pages\Document;

use App\Services\CampaignPackSafetyValidator;
use App\Services\ContentLibrary;

/**
 * What a page says that its sources do not back up. Pages go to a real store, so before one is
 * published:
 *  - a price that differs from the product's price (amount or currency) blocks publishing, and so
 *    do buttons with nowhere to go and images the page may not use (a banner not approved yet);
 *  - any other claim that the product page, confirmed Knowledge and approved launch copy do not
 *    contain (claim words, numbers, amounts, a former price) needs a workspace owner to confirm it;
 *  - proof sections (reviews, numbers, logos, a guarantee, a comparison) always need that
 *    confirmation, because only the store knows they are real.
 * A confirmation is tied to the exact wording: editing the text asks again.
 */
final class PageClaims
{
    /** Store promises, social proof and authority words, on top of the launch pack's claim words. */
    public const PAGE_TERMS = [
        'refund', 'refunds', 'returns', 'return policy', 'free returns', 'easy returns', 'exchange', 'free delivery',
        'cash on delivery', 'cod', 'ships in', 'ships within', 'dispatched in', 'same-day', 'same day', 'next-day',
        'next day', 'express delivery', 'bestseller', 'best seller', 'best-selling', 'bestselling', 'top rated',
        'top-rated', 'trusted by', 'loved by', 'customers', 'satisfaction', 'risk-free', 'risk free',
        'no questions asked', 'in stock', 'limited stock', 'limited edition', 'exclusive', 'official', 'authentic',
        'genuine', 'clinically', 'scientifically', 'study', 'studies', 'expert', 'experts', 'safe for',
        'chemical-free', 'toxin-free', 'dermatologically', 'paediatrician', 'pediatrician', 'ayurvedic', 'herbal',
        'gluten-free', 'sugar-free', 'food-grade', 'dishwasher-safe', 'microwave-safe', 'unbreakable', 'scratch-proof',
        'shockproof', 'fda', 'iso', 'bis', 'ce certified',
    ];

    /** Sections that are proof in themselves, with what the owner confirms. */
    public const PROOF = [
        'reviews' => 'Confirm these are real reviews from your customers, shown as written, and that you may publish them.',
        'stats' => 'Confirm you can back up these numbers and where they come from.',
        'logos' => 'Confirm you may show these logos: press, partners or certifications you really have.',
        'guarantee' => 'Confirm your store offers this promise exactly as written.',
        'comparison' => 'Confirm every row is true, for your product and for the alternative.',
    ];

    /** Amounts with a currency, as in the launch pack checks: "₹699", "Rs. 1,499", "INR 899", "500 rupees". */
    private const AMOUNT = '/(?:[₹$€£¥]|(?<![\p{L}\p{N}])(?:rs\.?|inr|usd|eur|gbp|aud|cad|sgd|aed))\s?\d(?:[\d.,]*\d)?|\d(?:[\d.,]*\d)?\s?(?:rupees|inr|usd|eur|gbp)(?![\p{L}])/iu';

    public function __construct(private CampaignPackSafetyValidator $prices) {}

    /**
     * Everything to fix or confirm before publishing, in page order.
     *
     * @return list<array{key: ?string, kind: string, section: ?string, path: ?string, where: string, message: string, text: string, confirmed: ?array}>
     */
    public function check(array $document, ProductBrief $brief): array
    {
        $issues = [];
        $visible = array_values(array_filter($document['sections'] ?? [], fn (array $section) => ! ($section['hidden'] ?? false)));
        if ($visible === []) {
            return [$this->issue(null, 'blocker', null, null, 'Page', 'The page has no visible sections. Add one, or show a hidden one.', '', $document)];
        }

        $support = $this->normalize($brief->supportText());
        $supportNumbers = $this->numbers($support);
        $supported = $this->prices->supportedPrices($brief->source) ?? [];
        if ($own = $this->amount((string) $brief->price)) {
            $supported[] = '* '.$own;
        }
        $terms = array_values(array_unique([...ContentLibrary::CLAIM_TERMS, ...self::PAGE_TERMS]));
        $usable = array_flip(array_column($brief->images, 'key'));
        $missingLink = [];

        foreach ($visible as $section) {
            $type = $section['type'];
            $label = Sections::get($type)['label'] ?? $type;
            $settings = $section['settings'] ?? [];

            if ($type === 'product_hero') {
                if (trim((string) ($settings['title'] ?? '')) === '') {
                    $issues[] = $this->issue(null, 'blocker', $section['id'], 'title', $label, 'The product hero needs a title.', '', $document);
                }
                array_push($issues, ...$this->priceIssues($section, $brief, $document));
            }
            if (trim((string) ($settings['button_label'] ?? '')) !== '' && Document::url($settings['button_url'] ?? '') === '' && Document::url($document['buy_url'] ?? '') === '') {
                $missingLink[] = $label;
            }
            array_push($issues, ...$this->imageIssues($section, $label, $usable, $brief, $document));

            if (isset(self::PROOF[$type])) {
                if ($proof = $this->proofContent($section)) {
                    $issues[] = $this->issue(sha1('proof|'.$type.'|'.$proof), 'confirm', $section['id'], null, $label, self::PROOF[$type], mb_strimwidth($this->firstText($section), 0, 160, '…'), $document);
                }

                continue;
            }

            $counts = $this->itemCounts($section);
            Document::walk(Sections::get($type)['fields'] ?? [], $settings, function (array $field, mixed $value, string $path) use (&$issues, $section, $type, $label, $support, $supportNumbers, $supported, $terms, $counts, $document): void {
                if (! in_array($field['type'], ['text', 'textarea'], true) || ! is_string($value) || trim($value) === '') {
                    return;
                }
                if ($type === 'product_hero' && in_array($path, ['price', 'compare_price'], true)) {
                    // The amount has its own check (priceIssues); anything written beside it is checked here.
                    $value = $this->withoutFirstAmount($value);
                    if (trim($value, " \t\n\r\0\x0B,.;:·-–—/|()") === '') {
                        return;
                    }
                }
                $text = $this->normalize($value);
                $found = [];
                foreach ($terms as $term) {
                    if ($this->contains($text, $term) && ! $this->contains($support, $term)) {
                        $found[] = '“'.$term.'”';
                    }
                }
                foreach ($this->prices->unsupportedPrices($value, $supported) as $amount) {
                    $found[] = $amount;
                }
                $withoutAmounts = (string) preg_replace(self::AMOUNT, ' ', $text);
                foreach (array_diff($this->numbers($withoutAmounts), $supportNumbers) as $number) {
                    // "4 reasons to choose…" over four reasons counts the page, it claims nothing.
                    if (! str_contains($path, '.') && in_array($number, $counts, true)) {
                        continue;
                    }
                    $found[] = $number;
                }
                if ($found) {
                    $found = array_values(array_unique($found));
                    $where = $label.' · '.$this->fieldLabel($type, $path);
                    $issues[] = $this->issue(sha1('text|'.$type.'|'.$text), 'confirm', $section['id'], $path, $where,
                        'Not on the product page, in confirmed Knowledge or in approved launch copy: '.implode(', ', $found).'. Confirm it is true, or change the text.', mb_strimwidth(trim($value), 0, 160, '…'), $document);
                }
            });
        }

        if ($missingLink) {
            array_unshift($issues, $this->issue(null, 'blocker', null, null, 'Page settings', 'Buttons on this page have nowhere to go ('.implode(', ', array_unique($missingLink)).'). Add the buy link in Page settings.', '', $document));
        }

        return $issues;
    }

    /** Messages for what stops publishing: every blocker and every claim not confirmed yet. */
    public static function blocking(array $issues): array
    {
        return array_values(array_map(
            fn (array $issue) => $issue['kind'] === 'blocker' ? $issue['message'] : "{$issue['where']} needs an owner's confirmation before publishing.",
            array_filter($issues, fn (array $issue) => $issue['kind'] === 'blocker' || ! $issue['confirmed']),
        ));
    }

    /**
     * The hero's price must be the product's own price, in its currency, and nothing else: a former
     * price belongs in the compare-at field, where the product page has to show it as one.
     *
     * @return list<array>
     */
    private function priceIssues(array $section, ProductBrief $brief, array $document): array
    {
        $settings = $section['settings'] ?? [];
        $price = trim((string) ($settings['price'] ?? ''));
        $compare = trim((string) ($settings['compare_price'] ?? ''));
        $own = $this->ownPrice($brief);
        $issues = [];
        if ($price !== '') {
            if ($own === null) {
                $issues[] = $this->issue(sha1('price|'.$this->normalize($price)), 'confirm', $section['id'], 'price', 'Product hero · Price',
                    'No product page or confirmed Knowledge gives a price. Confirm the store charges exactly this.', $price, $document);
            } elseif ($this->prices->unsupportedPrices($price, [$own], true) !== [] || $this->amount($price) !== explode(' ', $own, 2)[1]) {
                $issues[] = $this->issue(null, 'blocker', $section['id'], 'price', 'Product hero · Price',
                    "The page shows {$price}, but the product's price is {$brief->price}. Show only the price your store charges (a former price goes in Compare-at price), or correct it in Knowledge.", $price, $document);
            }
        }
        if ($price !== '' && $compare !== '' && app(DocumentRenderer::class)->saving($price, $compare) !== null) {
            $currency = $own === null ? '*' : explode(' ', $own, 2)[0];
            if ($this->prices->unsupportedPrices($compare, [$currency.' '.$this->amount($compare)], true) !== []) {
                $issues[] = $this->issue(null, 'blocker', $section['id'], 'compare_price', 'Product hero · Compare-at price',
                    "Compare-at price shows {$compare}. Show one former price, in the same currency as the price. The page works out the saving.", $compare, $document);
            } elseif (! $this->wasPrice($compare, $brief)) {
                $issues[] = $this->issue(sha1('compare|'.$this->normalize($compare).'|'.$this->normalize($price)), 'confirm', $section['id'], 'compare_price', 'Product hero · Compare-at price',
                    "The product page does not show {$compare} as a former price. Confirm the product really sold at it: a former price that was never charged misleads buyers.", $compare, $document);
            }
        }

        return $issues;
    }

    /**
     * The product's price as "CUR amount" ("INR 699"): in the currency it names, else the product
     * page's currency, else the currency the product page writes that amount in, else "*" for unknown.
     * Null when there is no price.
     */
    private function ownPrice(ProductBrief $brief): ?string
    {
        $own = $this->prices->labelPrice((string) $brief->price, $this->prices->pageCurrency($brief->source));
        if ($own === null) {
            return null;
        }
        [$currency, $amount] = explode(' ', $own, 2);
        if ($currency === '*') {
            $written = collect($this->prices->supportedPrices($brief->source) ?? [])
                ->map(fn (string $price) => explode(' ', $price, 2))
                ->first(fn (array $price) => $price[1] === $amount && $price[0] !== '*');
            $currency = $written[0] ?? '*';
        }

        return $currency.' '.$amount;
    }

    /** The text with its first amount ("₹1,999", or the first number) taken out. */
    private function withoutFirstAmount(string $text): string
    {
        $count = 0;
        $out = (string) preg_replace(self::AMOUNT, ' ', $text, 1, $count);

        return $count > 0 ? $out : (string) preg_replace('/\d(?:[\d.,]*\d)?/u', ' ', $text, 1);
    }

    /**
     * Images in the section that the page may not use: a banner not approved yet, one showing a price
     * the product page does not, or an image that is not this product's.
     *
     * @param  array<string, int>  $usable  keys of the images the page may use
     * @return list<array>
     */
    private function imageIssues(array $section, string $label, array $usable, ProductBrief $brief, array $document): array
    {
        $issues = [];
        Document::walk(Sections::get($section['type'])['fields'] ?? [], $section['settings'] ?? [], function (array $field, mixed $value, string $path) use (&$issues, $section, $label, $usable, $brief, $document): void {
            $refs = match ($field['type']) {
                'image' => is_array($value) ? [$value] : [],
                'images' => array_values(array_filter((array) $value, 'is_array')),
                default => [],
            };
            foreach ($refs as $ref) {
                $key = (string) ($ref['key'] ?? '');
                if ($key === '' || isset($usable[$key])) {
                    continue;
                }
                $message = $brief->withheld[$key] ?? 'An image here is not one of this product\'s images any more. Choose another image.';
                $issues[$key] = $this->issue(null, 'blocker', $section['id'], $path, $label, $message, '', $document);
            }
        });

        return array_values($issues);
    }

    /** The product page shows the amount as the product's old price ("was", MRP, struck out). */
    private function wasPrice(string $compare, ProductBrief $brief): bool
    {
        $amount = $this->amount($compare);
        if (! $amount || ! $brief->source) {
            return false;
        }
        foreach ($this->prices->pricesBesideProductPrice($brief->source) as $price => $roles) {
            if (in_array('old', $roles, true) && explode(' ', $price, 2)[1] === $amount) {
                return true;
            }
        }

        return false;
    }

    private function issue(?string $key, string $kind, ?string $section, ?string $path, string $where, string $message, string $text, array $document): array
    {
        return [
            'key' => $key, 'kind' => $kind, 'section' => $section, 'path' => $path, 'where' => $where, 'message' => $message, 'text' => $text,
            'confirmed' => $key ? ($document['confirmations'][$key] ?? null) : null,
        ];
    }

    /** A proof section's wording and images, or '' when it shows nothing. */
    private function proofContent(array $section): string
    {
        $parts = [];
        Document::walk(Sections::get($section['type'])['fields'] ?? [], $section['settings'] ?? [], function (array $field, mixed $value, string $path) use (&$parts): void {
            if (in_array($field['type'], ['text', 'textarea'], true) && is_string($value) && trim($value) !== '') {
                $parts[] = $path.'='.$this->normalize($value);
            } elseif (in_array($field['type'], ['toggle', 'rating'], true) && str_contains($path, '.')) {
                $parts[] = $path.'='.json_encode($value);
            } elseif ($field['type'] === 'image' && is_array($value)) {
                $parts[] = $path.'='.$value['key'];
            } elseif ($field['type'] === 'images' && $value) {
                $parts[] = $path.'='.implode(',', array_column((array) $value, 'key'));
            }
        });
        // Reviews, numbers, logos and comparisons publish nothing until they have items, so a heading
        // alone needs no confirmation. A guarantee's heading is the promise itself.
        $content = $section['type'] === 'guarantee' ? $parts : array_filter($parts, fn (string $part) => ! preg_match('/^(heading|subheading)=/', $part));

        return $content ? implode("\n", $parts) : '';
    }

    /** The first item's text (a review, a number), else the first text the section shows (a guarantee's heading). */
    private function firstText(array $section): string
    {
        $first = ['item' => '', 'any' => ''];
        Document::walk(Sections::get($section['type'])['fields'] ?? [], $section['settings'] ?? [], function (array $field, mixed $value, string $path) use (&$first): void {
            if (! in_array($field['type'], ['text', 'textarea'], true) || ! is_string($value) || trim($value) === '') {
                return;
            }
            if ($first['item'] === '' && str_contains($path, '.')) {
                $first['item'] = trim($value);
            }
            if ($first['any'] === '') {
                $first['any'] = trim($value);
            }
        });

        return $first['item'] !== '' ? $first['item'] : $first['any'];
    }

    /** @return list<string> how many items each list of the section shows */
    private function itemCounts(array $section): array
    {
        $counts = [];
        foreach (Sections::get($section['type'])['fields'] ?? [] as $field) {
            if ($field['type'] === 'list') {
                $counts[] = (string) count((array) ($section['settings'][$field['key']] ?? []));
            }
        }

        return $counts;
    }

    private function fieldLabel(string $type, string $path): string
    {
        $parts = explode('.', $path);
        if (count($parts) === 3) {
            $list = Sections::field($type, $parts[0]);
            $child = Sections::field($type, $parts[0].'.'.$parts[2]);

            return ($list['item_label'] ?? 'Item').' '.((int) $parts[1] + 1).' '.mb_strtolower($child['label'] ?? $parts[2]);
        }

        return Sections::field($type, $path)['label'] ?? $path;
    }

    /** Lower case with typography made plain, so “Free” and "free" match. */
    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtr($text, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', "\u{00A0}" => ' ', "\u{2009}" => ' ']);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private function contains(string $text, string $term): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $text);
    }

    /** @return list<string> numbers in a text, normalised: "1,499.00" and "1499" are the same */
    private function numbers(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $matches);

        return array_values(array_unique(array_map(fn (string $match) => $this->number($match), $matches[0])));
    }

    private function number(string $value): string
    {
        $number = preg_replace('/[^\d.]/', '', str_replace(',', '', $value));

        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }

    /** The amount in a price ("₹1,499.00" is "1499"), the way the launch pack checks write it. */
    private function amount(string $price): ?string
    {
        return preg_match('/\d(?:[\d.,]*\d)?/u', $price, $match) ? CampaignPackSafetyValidator::number($match[0]) : null;
    }
}

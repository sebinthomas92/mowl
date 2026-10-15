<?php

namespace App\Services;

use App\Models\BannerCreative;
use App\Models\CampaignPackVersion;
use App\Models\SourceSnapshot;
use Illuminate\Validation\ValidationException;

class CampaignPackSafetyValidator
{
    /** ISO codes recognised next to an amount in copy ("899 USD", "AED 99"). */
    private const DOLLARS = ['USD', 'AUD', 'CAD', 'SGD', 'NZD', 'HKD', 'MXN'];

    /** How far (in bytes of page text) an amount may sit from the product's own price and still be about it. */
    private const PRICE_BLOCK_BYTES = 400;

    private const CURRENCY_CODES = ['INR', 'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'SGD', 'AED', 'NZD', 'CHF', 'CNY', 'HKD', 'SAR', 'MYR', 'ZAR', 'SEK', 'NOK', 'DKK', 'BRL', 'MXN', 'IDR', 'THB', 'PHP', 'KRW', 'QAR', 'NPR', 'LKR', 'BDT', 'PKR'];

    private const UNSAFE_STATUSES = [
        'too_specific_for_evidence',
        'unsupported',
        'contradicted_by_source',
    ];

    private const SPECIFICITY_TERMS = [
        'handmade', 'handcrafted', 'artisan-made', 'made in', 'manufactured in', 'sourced in', 'packed in',
        'organic', 'natural', 'vegan', 'cruelty-free', 'waterproof', 'sustainable', 'recyclable',
        'plastic-free', 'solid wood', 'genuine leather', 'clinically proven', 'guaranteed',
    ];

    public function assertApprovable(CampaignPackVersion $version, SourceSnapshot $source): void
    {
        $issues = $this->issues(
            $version->content ?? [],
            $version->evidence ?? [],
            $version->compliance_flags ?? [],
            $source,
        );

        if ($issues !== []) {
            throw ValidationException::withMessages([
                'reviewNote' => 'Approval blocked: '.$issues[0]['message'],
            ]);
        }
    }

    public function issues(array $content, array $evidence, array $flags, ?SourceSnapshot $source = null): array
    {
        $issues = [];
        $safeEvidence = collect($evidence)->filter(fn (array $reference) => in_array($reference['status'] ?? '', ['directly_supported', 'supported_paraphrased', 'source-linked'], true));
        $sourceText = $this->normalize(implode("\n", array_filter([
            $source?->extracted_content,
            CampaignPackEvidence::json($source?->extracted_truth ?? []),
        ])));

        if ($source && (! $source->approved_at || $source->status !== 'ready')) {
            $issues[] = $this->issue('source', 'Product Truth must be owner-approved before this version can be approved.');
        }

        if (count($content['ranked_angles'] ?? []) !== 5) {
            $issues[] = $this->issue('structure', 'A complete workspace must contain five ranked marketing angles.');
        }
        if (count($content['creative_routes'] ?? []) !== 3) {
            $issues[] = $this->issue('structure', 'A complete workspace must contain exactly three creative routes.');
        }
        if ($safeEvidence->isEmpty()) {
            $issues[] = $this->issue('claim', 'No claim has a safe source-linked evidence record.');
        }

        if (isset($content['marketing_hub'])) {
            foreach (['meta_ads', 'google_ads', 'email_sms', 'organic_social'] as $channel) {
                if (! is_array(data_get($content, "marketing_hub.channels.{$channel}"))) {
                    $issues[] = $this->issue('structure', "The marketing hub is missing the {$channel} deliverable.");
                }
            }
            foreach (['search', 'performance_max', 'display'] as $type) {
                $finalUrl = data_get($content, "marketing_hub.channels.google_ads.{$type}.final_url");
                if (! is_string($finalUrl) || ! filter_var($finalUrl, FILTER_VALIDATE_URL)) {
                    $issues[] = $this->issue('structure', "The {$type} Google Ads handoff is missing a valid final URL.");
                }
            }
        }

        foreach ($evidence as $reference) {
            $status = $reference['status'] ?? '';
            $claim = trim((string) ($reference['claim'] ?? 'Unnamed claim'));
            $excerpt = $this->normalize((string) ($reference['excerpt'] ?? ''));
            $normalizedClaim = $this->normalize($claim);

            if (in_array($status, self::UNSAFE_STATUSES, true)) {
                $issues[] = $this->issue('claim', "{$claim} is classified as ".str_replace('_', ' ', $status).'.');

                continue;
            }

            if (! in_array($status, ['directly_supported', 'supported_paraphrased', 'source-linked'], true)) {
                $issues[] = $this->issue('claim', "{$claim} does not have a recognized safe evidence status.");

                continue;
            }

            if ($excerpt === '' || trim((string) ($reference['source'] ?? '')) === '') {
                $issues[] = $this->issue('claim', "{$claim} is missing its supporting source or excerpt.");

                continue;
            }

            // Claims are restated as sentences, so the wording need not match. What must hold: the quoted
            // excerpt is on the page (Knowledge excerpts were checked at generation), and every number in
            // the claim appears in that excerpt or on the page.
            if ($source && ($reference['source'] ?? null) !== 'team_confirmed' && ! str_contains($sourceText, $excerpt)) {
                $issues[] = $this->issue('claim', "{$claim} quotes text that is not on the product page.");
            } elseif ($missing = array_diff($this->numbers($normalizedClaim), $this->numbers($excerpt.' '.$sourceText))) {
                $issues[] = $this->issue('claim', "{$claim} uses a number the source does not state (".implode(', ', $missing).').');
            }

            foreach (self::SPECIFICITY_TERMS as $term) {
                if (str_contains($normalizedClaim, $term) && ! str_contains($excerpt, $term) && ! str_contains($sourceText, $term)) {
                    $issues[] = $this->issue('claim', "{$claim} is more specific than its evidence.");
                    break;
                }
            }
        }

        foreach ($flags as $flag) {
            if (($flag['severity'] ?? null) === 'blocked') {
                $issues[] = $this->issue('claim', (string) ($flag['reason'] ?? 'A blocking compliance issue remains.'));
            }
        }

        foreach ($this->claimBearingStrings($content) as $path => $text) {
            $normalized = $this->normalize($text);
            foreach (self::SPECIFICITY_TERMS as $term) {
                if (! str_contains($normalized, $term)) {
                    continue;
                }

                $supported = str_contains($sourceText, $term) || $safeEvidence->contains(function (array $reference) use ($term): bool {
                    return str_contains($this->normalize(implode(' ', [
                        $reference['claim'] ?? '',
                        $reference['excerpt'] ?? '',
                    ])), $term);
                });
                if (! $supported) {
                    $issues[] = $this->issue('claim', "{$text} is more specific than the approved evidence.", ['path' => $path, 'text' => $text, 'term' => $term]);
                }
                break;
            }
        }

        foreach ($this->stringsWithPaths($content) as $path => $text) {
            $normalized = $this->normalize($text);
            foreach (['limited time', 'only one left', 'only 1 left', 'selling fast', 'today only', 'ends tonight', 'while stocks last'] as $scarcity) {
                if (str_contains($normalized, $scarcity) && ! str_contains($sourceText, $scarcity)) {
                    $issues[] = $this->issue('claim', "Unsupported scarcity language detected: {$text}", ['path' => $path, 'text' => $text, 'term' => $scarcity]);
                    break 2;
                }
            }
        }

        $supportedPrices = $this->supportedPrices($source);
        foreach ($this->stringsWithPaths($content) as $path => $text) {
            if ($prices = $this->unsupportedPrices($text, $supportedPrices, (bool) preg_match('/(^|\.)price$/', $path))) {
                $issues[] = $this->issue('claim', "{$text} states a price the product page does not show (".implode(', ', $prices).').', ['path' => $path, 'text' => $text]);
            }
        }

        foreach ($content['creative_routes'] ?? [] as $routeIndex => $route) {
            $routeName = $route['name'] ?? 'Route '.($routeIndex + 1);
            $requiredPlatforms = ['instagram_reels', 'youtube_shorts', 'whatsapp_status', 'meta_ads'];
            if (array_diff($requiredPlatforms, array_keys($route['platform_assets'] ?? [])) !== []) {
                $issues[] = $this->issue('structure', "{$routeName} is missing one or more required platform asset packages.");
            }
            $duration = (float) ($route['total_duration_seconds'] ?? 0);
            if ($duration <= 0) {
                $issues[] = $this->issue('timing', "{$routeName} has no valid total duration.");

                continue;
            }

            $previousEnd = 0.0;
            foreach ($route['voiceover'] ?? [] as $segmentIndex => $segment) {
                $start = (float) ($segment['start_seconds'] ?? -1);
                $end = (float) ($segment['end_seconds'] ?? -1);
                $line = trim((string) ($segment['line'] ?? ''));
                $words = $this->wordCount($line);
                $declaredWords = (int) ($segment['word_count'] ?? -1);
                $segmentDuration = $end - $start;
                $pace = $segmentDuration > 0 ? (int) round(($words / $segmentDuration) * 60) : PHP_INT_MAX;

                if ($start < $previousEnd || $start < 0 || $start >= $duration || $end <= $start || $end > $duration) {
                    $issues[] = $this->issue('timing', "{$routeName} voiceover segment ".($segmentIndex + 1).' has an impossible time range.');
                }
                if ($declaredWords !== $words) {
                    $issues[] = $this->issue('timing', "{$routeName} voiceover segment ".($segmentIndex + 1).' has an inaccurate word count.');
                }
                if ($pace > 170) {
                    $issues[] = $this->issue('timing', "{$routeName} voiceover segment ".($segmentIndex + 1)." requires {$pace} words per minute.");
                }
                if (abs(((int) ($segment['pace_wpm'] ?? -1)) - $pace) > 2) {
                    $issues[] = $this->issue('timing', "{$routeName} voiceover segment ".($segmentIndex + 1).' has an inaccurate speaking pace.');
                }
                $previousEnd = max($previousEnd, $end);
            }

            $previousEnd = 0.0;
            foreach ($route['captions'] ?? [] as $captionIndex => $caption) {
                $start = (float) ($caption['start_seconds'] ?? -1);
                $end = (float) ($caption['end_seconds'] ?? -1);
                $captionDuration = $end - $start;
                $text = trim((string) ($caption['text'] ?? ''));
                $charactersPerSecond = $captionDuration > 0 ? mb_strlen($text) / $captionDuration : INF;

                if ($start < $previousEnd || $start < 0 || $start >= $duration || $end <= $start || $end > $duration) {
                    $issues[] = $this->issue('caption', "{$routeName} caption ".($captionIndex + 1).' has an impossible time range.');
                }
                if ($charactersPerSecond > 20) {
                    $issues[] = $this->issue('caption', "{$routeName} caption ".($captionIndex + 1).' is too long to read in its assigned time.');
                }
                $previousEnd = max($previousEnd, $end);
            }
        }

        return collect($issues)->unique(fn (array $issue) => $issue['type'].'|'.$issue['message'].'|'.($issue['path'] ?? ''))->values()->all();
    }

    /**
     * Amounts a price may use: the page's structured price, a price the team confirmed in Knowledge,
     * and currency amounts written on the product page. When the page text shows the product's own
     * price, only amounts near it count (a struck-through price, a free-shipping threshold), so
     * another product's price in a "you may also like" row does not. Other numbers on the page
     * (dispatch times, sizes, review counts) are not prices. Null source means nothing to check against.
     *
     * @return list<string>|null
     */
    public function supportedPrices(?SourceSnapshot $source): ?array
    {
        if (! $source) {
            return null;
        }
        $truth = $source->extracted_truth ?? [];
        $code = strtoupper(trim((string) data_get($truth, 'currency', '')));
        $currency = $this->currency($code) ?? (preg_match('/^[A-Z]{3}$/', $code) ? $code : '*');
        $explicit = [(string) data_get($truth, 'price', '')];
        if (ProductKnowledge::available() && $source->product_id) {
            // A price the team confirmed, or one they saved that no page supplied. A value only ever
            // seeded from an earlier page is not kept alive once the page stops showing it.
            $explicit[] = (string) $source->product?->facts()->where('key', 'price')->where('status', 'ok')
                ->where(fn ($query) => $query->whereNotNull('confirmed_by_user_id')->orWhereNull('source_value'))
                ->value('confirmed_value');
        }
        $known = $currency === '*' ? [] : [$currency];
        $own = [];
        foreach (array_filter($explicit) as $price) {
            $labelled = $this->currencyAmounts($price, $known);
            if ($labelled === [] && preg_match('/\\d(?:[\\d.,]*\\d)?/u', $price, $number)) {
                $labelled = [$currency.' '.$this->amount($number[0])];
            }
            $own = [...$own, ...$labelled];
        }
        $page = (string) $source->extracted_content;
        $onPage = $this->currencyAmountsAt($page, $known);
        $anchors = array_filter($onPage, fn (array $found) => $this->matchesAny($found[0], $own));
        $nearby = $anchors === [] ? $onPage : array_filter($onPage, function (array $found) use ($anchors): bool {
            foreach ($anchors as $anchor) {
                if (abs($found[1] - $anchor[1]) <= self::PRICE_BLOCK_BYTES) {
                    return true;
                }
            }

            return false;
        });

        return array_values(array_unique([...array_column($nearby, 0), ...$own]));
    }

    /**
     * Unsupported prices in the text rendered into a banner's image, field by field.
     *
     * @return list<string>
     */
    public function bannerPrices(BannerCreative $creative): array
    {
        $supported = $this->supportedPrices($creative->campaignPack?->sourceSnapshot);

        return array_values(array_unique(array_merge(
            $this->unsupportedPrices((string) $creative->headline, $supported),
            $this->unsupportedPrices((string) $creative->supporting_text, $supported),
        )));
    }

    /**
     * Currency amounts in the text that the supported prices do not include. The currency counts:
     * "INR 699" is not supported by a page showing "$699". In a price field a bare "899" is a price too.
     *
     * @param  list<string>|null  $supported  from supportedPrices()
     * @return list<string>
     */
    public function unsupportedPrices(string $text, ?array $supported, bool $priceField = false): array
    {
        if ($supported === null) {
            return [];
        }
        $known = array_values(array_filter(array_map(fn (string $price) => explode(' ', $price)[0], $supported), fn (string $code) => $code !== '*'));
        if ($priceField && $this->currencyAmounts($text, $known) === []) {
            preg_match_all('/\\d(?:[\\d.,]*\\d)?/u', $text, $numbers);
            $amounts = array_map(fn (string $price) => explode(' ', $price)[1], $supported);

            return array_values(array_unique(array_filter(
                array_map(fn (string $number) => $this->amount($number), $numbers[0]),
                fn (string $amount) => ! in_array($amount, $amounts, true),
            )));
        }

        return array_values(array_unique(array_map(
            fn (string $price) => explode(' ', $price)[1],
            array_filter($this->currencyAmounts($text, $known), function (string $price) use ($supported) {
                return ! in_array($price, $supported, true) && ! in_array('* '.explode(' ', $price)[1], $supported, true);
            }),
        )));
    }

    /**
     * Amounts written with a currency, as "INR 699": "₹699", "INR 899", "Rs. 1,499", "500 rupees".
     * Symbols may follow a digit ("₹750₹699" is a struck-out price beside the sale price); words may not.
     *
     * The source's own currencies are always recognised, and settle what "$" or "¥" means.
     *
     * @param  list<string>  $known  currency codes the source uses
     * @return list<string>
     */
    private function currencyAmounts(string $text, array $known = []): array
    {
        return array_values(array_unique(array_column($this->currencyAmountsAt($text, $known), 0)));
    }

    /**
     * Currency amounts with the byte offset each starts at.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function currencyAmountsAt(string $text, array $known = []): array
    {
        $number = '(\\d(?:[\\d.,]*\\d)?)';
        $codes = implode('|', array_unique([...self::CURRENCY_CODES, ...array_filter($known, fn (string $code) => preg_match('/^[A-Z]{3}$/', $code))]));
        preg_match_all('/([₹$€£¥]|(?<![\\p{L}\\p{N}])(?:rs\\.?|'.$codes.'))\\s?'.$number.'/iu', $text, $before, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        preg_match_all('/'.$number.'\\s?(rupees|'.$codes.')(?![\\p{L}])/iu', $text, $after, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return [
            ...array_map(fn (array $match) => [$this->currency($match[1][0], $known).' '.$this->amount($match[2][0]), $match[0][1]], $before),
            ...array_map(fn (array $match) => [$this->currency($match[2][0], $known).' '.$this->amount($match[1][0]), $match[0][1]], $after),
        ];
    }

    /** A "CUR amount" pair matches when the amounts agree and the currencies do, or either is unknown ("*"). */
    private function matchesAny(string $price, array $candidates): bool
    {
        [$currency, $amount] = explode(' ', $price, 2);
        foreach ($candidates as $candidate) {
            [$otherCurrency, $otherAmount] = explode(' ', $candidate, 2);
            if ($amount === $otherAmount && ($currency === $otherCurrency || $currency === '*' || $otherCurrency === '*')) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $known  currency codes the source uses, to read "$" as AUD on an Australian page */
    private function currency(string $label, array $known = []): ?string
    {
        $label = rtrim(mb_strtolower(trim($label)), '.');

        return match ($label) {
            '₹', 'rs', 'rupees' => 'INR',
            '$' => collect($known)->first(fn (string $code) => in_array($code, self::DOLLARS, true)) ?? 'USD',
            '€' => 'EUR',
            '£' => 'GBP',
            '¥' => collect($known)->first(fn (string $code) => in_array($code, ['JPY', 'CNY'], true)) ?? 'JPY',
            default => preg_match('/^[a-z]{3}$/', $label) ? strtoupper($label) : null,
        };
    }

    /** "1,499.00", "1.499,00" and "1499" are the same amount; "1,99" (a decimal comma) is 1.99. */
    private function amount(string $number): string
    {
        $number = preg_match('/,\\d{1,2}$/', $number)
            ? str_replace(',', '.', str_replace('.', '', $number))
            : str_replace(',', '', $number);

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** @param  array{path?: string, text?: string, term?: string}  $location  where in the content the wording sits */
    private function issue(string $type, string $message, array $location = []): array
    {
        return ['type' => $type, 'message' => $message] + $location;
    }

    /**
     * The text with an unsupported term taken out, for a one-click fix, or null when removing the
     * term would not leave a sensible line. Location phrases ("made in …") are left to a person.
     */
    public static function withoutTerm(string $text, string $term): ?string
    {
        if (str_ends_with($term, ' in')) {
            return null;
        }
        $pattern = implode('\\s*[-\\x{2010}-\\x{2013}]\\s*', array_map(fn ($part) => preg_quote($part, '/'), explode('-', $term)));
        // "Not guaranteed" without its term reads as a different claim, so a negated term is left to a person.
        if (preg_match('/\\b(not|no|never|without|non)\\s+'.$pattern.'/iu', $text)) {
            return null;
        }
        $result = (string) preg_replace('/(?<![\\p{L}\\p{N}])'.$pattern.'(?![\\p{L}\\p{N}])/iu', '', $text);
        if ($result === $text) {
            return null;
        }
        $result = (string) preg_replace('/\\s{2,}/u', ' ', $result);
        $result = (string) preg_replace('/\\s+([,.;:!?)])/u', '$1', $result);
        $result = (string) preg_replace('/\\b(and|or)\\s*(,|and\\b|or\\b)/iu', '$1', $result);
        $result = (string) preg_replace('/([,;:])\\s*([.!?]|$)/u', '$2', $result);
        $result = trim((string) preg_replace('/^[\\s,;:&-]+|[\\s,;:&-]+$/u', '', $result));
        $result = (string) preg_replace('/^(and|or)\\s+|\\s+(and|or)$/iu', '', $result);
        if (preg_match_all('/\\p{L}{2,}/u', $result) < 2) {
            return null;
        }
        if (preg_match('/^\\p{Lu}/u', $text)) {
            $result = mb_strtoupper(mb_substr($result, 0, 1)).mb_substr($result, 1);
        }

        return $result;
    }

    private function normalize(string $value): string
    {
        return CampaignPackEvidence::normalize($value);
    }

    /** @return list<string> plain numbers ("1,499.00" and "1499" are the same number) */
    private function numbers(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $matches);

        return array_values(array_unique(array_map(function (string $number): string {
            $number = str_replace(',', '', $number);

            return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
        }, $matches[0])));
    }

    private function wordCount(string $value): int
    {
        preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}’'-]*/u", $value, $matches);

        return count($matches[0]);
    }

    /** @return array<string, string> dot path => text, for every string in the value */
    private function stringsWithPaths(mixed $value, string $path = ''): array
    {
        if (is_string($value)) {
            return [$path => $value];
        }
        if (! is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $key => $entry) {
            $strings += $this->stringsWithPaths($entry, $path === '' ? (string) $key : "{$path}.{$key}");
        }

        return $strings;
    }

    /** @return array<string, string> dot path => text, for the content that makes product claims */
    private function claimBearingStrings(array $content): array
    {
        $paths = ['product_truth.supported_benefits', 'product_truth.offers_and_trust_signals', 'marketing_hub'];
        foreach (array_keys(data_get($content, 'product_truth.verified_facts', []) ?: []) as $index) {
            $paths[] = "product_truth.verified_facts.{$index}.statement";
        }
        foreach (array_keys(is_array($content['offers'] ?? null) ? $content['offers'] : []) as $index) {
            $paths[] = "offers.{$index}.wording";
        }
        foreach ($content['creative_routes'] ?? [] as $routeIndex => $route) {
            $prefix = "creative_routes.{$routeIndex}";
            array_push($paths, "{$prefix}.marketing_angle", "{$prefix}.core_promise", "{$prefix}.hooks", "{$prefix}.platform_assets");
            foreach (['voiceover' => 'line', 'captions' => 'text', 'shot_plan' => 'product_fact_or_benefit'] as $list => $key) {
                foreach (array_keys(is_array($route[$list] ?? null) ? $route[$list] : []) as $index) {
                    $paths[] = "{$prefix}.{$list}.{$index}.{$key}";
                }
            }
        }

        $strings = [];
        foreach ($paths as $path) {
            $strings += $this->stringsWithPaths(data_get($content, $path), $path);
        }

        return $strings;
    }
}

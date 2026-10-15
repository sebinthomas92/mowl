<?php

namespace App\Services;

use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Checks a generator's evidence records against the product page and confirmed Knowledge.
 *
 * A safe record must quote an excerpt that appears in its source. Matching ignores case, spacing
 * and typography (curly quotes, dashes, non-breaking spaces), because models retype those. A safe
 * record whose excerpt still cannot be found is dropped, and so is any verified fact that points
 * at it: the claim is never shown as verified, and the rest of the pack is kept. The pack is
 * rejected only when no safe record survives, or when an unsafe claim has no compliance flag.
 */
class CampaignPackEvidence
{
    public const SAFE_STATUSES = ['directly_supported', 'supported_paraphrased'];

    public const UNSAFE_STATUSES = ['too_specific_for_evidence', 'unsupported', 'contradicted_by_source'];

    /**
     * @return array{0: array, 1: array}|string [generated content, kept evidence], or an error code:
     *                                          empty_claim, unflagged_claim, no_traceable_evidence
     */
    public function check(array $generated, array $evidence, array $flags, SourceSnapshot $source, array $page): array|string
    {
        $allowedSources = array_map(fn ($url) => self::url((string) $url), array_filter([$source->url, $page['url'] ?? null, $page['canonical_url'] ?? null]));
        $sourceText = self::normalize(implode("\n", [
            $page['description'] ?? '',
            $page['content'] ?? '',
            self::json($page['product_truth'] ?? []),
        ]));
        $teamText = self::normalize(implode("\n", array_map(fn ($value) => is_array($value) ? self::json($value) : (string) $value, $page['team_knowledge'] ?? [])));
        $flaggedClaims = collect($flags)->pluck('claim')->map(fn ($claim) => self::normalize((string) $claim));
        $kept = [];
        $linkedClaims = [];
        $dropped = [];

        foreach ($evidence as $reference) {
            $claim = self::normalize((string) ($reference['claim'] ?? ''));
            $status = $reference['status'] ?? null;
            if ($claim === '') {
                return 'empty_claim';
            }

            if (in_array($status, self::UNSAFE_STATUSES, true)) {
                if (! $flaggedClaims->contains($claim)) {
                    return 'unflagged_claim';
                }
                $kept[] = $reference;

                continue;
            }

            $excerpt = self::normalize((string) ($reference['excerpt'] ?? ''));
            $traceable = in_array($status, self::SAFE_STATUSES, true) && $excerpt !== '' && (
                // Facts the agency team confirmed on the product's Knowledge tab carry their own provenance.
                (($reference['source'] ?? null) === 'team_confirmed' && $teamText !== '' && str_contains($teamText, $excerpt))
                || (in_array(self::url((string) ($reference['source'] ?? '')), $allowedSources, true) && str_contains($sourceText, $excerpt))
            );

            if (! $traceable) {
                $dropped[] = ['claim' => $reference['claim'] ?? '', 'status' => $status, 'source' => $reference['source'] ?? null, 'excerpt' => mb_substr((string) ($reference['excerpt'] ?? ''), 0, 160)];

                continue;
            }

            $kept[] = $reference;
            $linkedClaims[(string) ($reference['id'] ?? '')] = $claim;
        }

        if ($dropped !== []) {
            Log::warning('Dropped evidence that is not traceable to the product page or Knowledge.', ['records' => $dropped]);
        }

        if ($linkedClaims === []) {
            return 'no_traceable_evidence';
        }

        if (is_array($generated['product_truth']['verified_facts'] ?? null)) {
            $generated['product_truth']['verified_facts'] = array_values(array_filter(
                $generated['product_truth']['verified_facts'],
                function ($fact) use ($linkedClaims): bool {
                    $claimId = (string) ($fact['claim_id'] ?? '');

                    return $claimId !== '' && ($linkedClaims[$claimId] ?? null) === self::normalize((string) ($fact['statement'] ?? ''));
                },
            ));
        }

        return [$generated, $kept];
    }

    /** Case, spacing and typography-insensitive text for substring checks. */
    public static function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }
        $value = strtr($value, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{2032}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
            "\u{2026}" => '...',
        ]);
        // Space around dashes varies ("Stand – Holder" vs "Stand-Holder"), so it is dropped.
        $value = (string) preg_replace('/\s*-\s*/u', '-', $value);

        return mb_strtolower(trim((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $value)));
    }

    /** JSON as the model sees it: readable Unicode and slashes, so quotes from it can match. */
    public static function json(mixed $value, int $flags = 0): string
    {
        return (string) json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** A source URL compared without scheme, "www.", trailing slash, query or fragment. */
    private static function url(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! isset($parts['host'])) {
            return mb_strtolower(trim($url));
        }

        return preg_replace('/^www\./', '', mb_strtolower($parts['host'])).rtrim($parts['path'] ?? '', '/');
    }
}

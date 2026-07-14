<?php

namespace App\Services;

use App\Models\CampaignPackVersion;
use App\Models\SourceSnapshot;
use Illuminate\Validation\ValidationException;

class CampaignPackSafetyValidator
{
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
            json_encode($source?->extracted_truth ?? []),
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

            if ($status === 'directly_supported' && ! str_contains($excerpt, $normalizedClaim) && ! str_contains($sourceText, $normalizedClaim)) {
                $issues[] = $this->issue('claim', "{$claim} is not stated directly in the supplied source.");
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

        foreach ($this->claimBearingStrings($content) as $text) {
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
                    $issues[] = $this->issue('claim', "{$text} is more specific than the approved evidence.");
                }
                break;
            }
        }

        foreach ($this->allStrings($content) as $text) {
            $normalized = $this->normalize($text);
            foreach (['limited time', 'only one left', 'only 1 left', 'selling fast', 'today only', 'ends tonight', 'while stocks last'] as $scarcity) {
                if (str_contains($normalized, $scarcity) && ! str_contains($sourceText, $scarcity)) {
                    $issues[] = $this->issue('claim', "Unsupported scarcity language detected: {$text}");
                    break 2;
                }
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

        return collect($issues)->unique(fn (array $issue) => $issue['type'].'|'.$issue['message'])->values()->all();
    }

    private function issue(string $type, string $message): array
    {
        return ['type' => $type, 'message' => $message];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function wordCount(string $value): int
    {
        preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}’'-]*/u", $value, $matches);

        return count($matches[0]);
    }

    private function allStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)->flatMap(fn ($item) => $this->allStrings($item))->all();
    }

    private function claimBearingStrings(array $content): array
    {
        $values = [
            collect(data_get($content, 'product_truth.verified_facts', []))->pluck('statement')->all(),
            data_get($content, 'product_truth.supported_benefits', []),
            data_get($content, 'product_truth.offers_and_trust_signals', []),
            collect(data_get($content, 'offers', []))->pluck('wording')->all(),
        ];

        foreach ($content['creative_routes'] ?? [] as $route) {
            $values[] = $route['marketing_angle'] ?? '';
            $values[] = $route['core_promise'] ?? '';
            $values[] = $route['hooks'] ?? [];
            $values[] = collect($route['voiceover'] ?? [])->pluck('line')->all();
            $values[] = collect($route['captions'] ?? [])->pluck('text')->all();
            $values[] = collect($route['shot_plan'] ?? [])->pluck('product_fact_or_benefit')->all();
            $values[] = $route['platform_assets'] ?? [];
        }

        return $this->allStrings($values);
    }
}

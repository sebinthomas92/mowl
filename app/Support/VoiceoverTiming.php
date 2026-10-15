<?php

namespace App\Support;

/**
 * Word count and speaking pace of a voiceover segment, counted from its line and time range.
 *
 * Models report word_count and pace_wpm with each segment and often miscount by one, and an
 * edited line keeps the numbers of the old one, so these are always derived, never trusted.
 */
class VoiceoverTiming
{
    /** Words as a narrator says them: "e-reader", "don't" and "2026" are one word each. */
    public static function words(string $line): int
    {
        preg_match_all("/[\\p{L}\\p{N}][\\p{L}\\p{N}’'-]*/u", $line, $matches);

        return count($matches[0]);
    }

    /** Words per minute over the segment's time range; null when the range is empty or invalid. */
    public static function pace(array $segment): ?int
    {
        $seconds = (float) ($segment['end_seconds'] ?? 0) - (float) ($segment['start_seconds'] ?? 0);

        return $seconds > 0 ? (int) round((self::words((string) ($segment['line'] ?? '')) / $seconds) * 60) : null;
    }

    /** The segment with word_count and pace_wpm recounted from its line and times. */
    public static function refresh(array $segment): array
    {
        $segment['word_count'] = self::words((string) ($segment['line'] ?? ''));
        $pace = self::pace($segment);
        if ($pace !== null) {
            $segment['pace_wpm'] = $pace;
        }

        return $segment;
    }
}

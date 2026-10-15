<?php

namespace App\Support;

/**
 * The time a generation run may still spend on provider calls.
 *
 * In request-processing mode a run lives inside one Vercel function invocation, which is killed at
 * its maxDuration without running any PHP cleanup. A killed run leaves its job "processing" with no
 * error until the stale check picks it up. Provider retries and timeouts stay inside this budget so
 * the run records a retryable failure instead of being killed.
 */
class GenerationDeadline
{
    /** Below this many seconds a new provider attempt is not started. */
    public const MIN_ATTEMPT_SECONDS = 20;

    private ?float $endsAt = null;

    public function start(int $seconds): void
    {
        $this->endsAt = microtime(true) + $seconds;
    }

    public function clear(): void
    {
        $this->endsAt = null;
    }

    /** Seconds left, or null when the run has no deadline (queue workers). */
    public function remaining(): ?float
    {
        return $this->endsAt === null ? null : max(0.0, $this->endsAt - microtime(true));
    }

    public function allowsAnotherAttempt(): bool
    {
        $remaining = $this->remaining();

        return $remaining === null || $remaining >= self::MIN_ATTEMPT_SECONDS;
    }

    /** The configured provider timeout, shortened to what the deadline leaves. */
    public function timeout(int $configured): int
    {
        $remaining = $this->remaining();

        return $remaining === null ? $configured : max(1, min($configured, (int) floor($remaining) - 5));
    }
}

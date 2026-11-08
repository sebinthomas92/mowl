<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A banner attempt failed after it had already paid for generation, for example because every
 * background showed third-party text. Carries that spend so it is still recorded on the banner, and
 * the last rejected background so the rejection can be checked by eye.
 */
final class BannerAttemptFailed extends RuntimeException
{
    /**
     * @param  array{0: int, 1: int, 2: int}  $tokens  image-model input, output text and output image tokens already spent
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly float $spentCost, public readonly ?string $rejectedImage = null, public readonly array $tokens = [0, 0, 0])
    {
        parent::__construct($message);
    }
}

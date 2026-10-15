<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A banner attempt failed after it had already paid for generation, for example because every
 * background showed third-party text. Carries that spend so it is still recorded on the banner.
 */
final class BannerAttemptFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly float $spentCost)
    {
        parent::__construct($message);
    }
}

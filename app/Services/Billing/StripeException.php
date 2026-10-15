<?php

namespace App\Services\Billing;

use RuntimeException;

class StripeException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $stripeCode = '')
    {
        parent::__construct($message, $status);
    }
}

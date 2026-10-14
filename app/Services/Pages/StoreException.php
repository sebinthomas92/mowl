<?php

namespace App\Services\Pages;

use RuntimeException;

/**
 * A store problem, worded for the person publishing. $auth means the connection needs new credentials;
 * $unsure means the request may have reached the store before the answer was lost.
 */
class StoreException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $auth = false, public readonly bool $unsure = false)
    {
        parent::__construct($message);
    }
}

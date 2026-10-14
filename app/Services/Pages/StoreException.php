<?php

namespace App\Services\Pages;

use RuntimeException;

/** A store problem, worded for the person publishing. $auth means the connection needs new credentials. */
class StoreException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $auth = false)
    {
        parent::__construct($message);
    }
}

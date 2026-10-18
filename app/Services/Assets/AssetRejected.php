<?php

namespace App\Services\Assets;

use RuntimeException;

/** An upload we refuse; the message is written for the person who uploaded it. */
class AssetRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}

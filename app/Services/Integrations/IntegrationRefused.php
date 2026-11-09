<?php

namespace App\Services\Integrations;

use RuntimeException;

/** A call to another platform that could not be completed. The message is a plain sentence a person can act on. */
class IntegrationRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}

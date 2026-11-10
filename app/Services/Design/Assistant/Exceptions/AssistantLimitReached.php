<?php

namespace App\Services\Design\Assistant\Exceptions;

use RuntimeException;

/** A daily cap was reached. The message says which cap, in plain words, and what still works. */
final class AssistantLimitReached extends RuntimeException
{
    public function __construct(string $message, public readonly string $scope, public readonly int $limit)
    {
        parent::__construct($message);
    }
}

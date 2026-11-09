<?php

namespace App\Services\Design;

use RuntimeException;

/**
 * A review action the rules do not allow. `httpStatus`: 403 separation of duties, 409 wrong state, 422 bad input.
 */
final class ReviewRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}

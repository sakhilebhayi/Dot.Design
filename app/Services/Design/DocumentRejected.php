<?php

namespace App\Services\Design;

use RuntimeException;

/**
 * A document the server will not store. `errors` is field-level ("elements.3.text" => messages) for a 422; a document
 * over the size ceiling is a 413 with a single message.
 */
final class DocumentRejected extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}

<?php

namespace App\Services\Design\Assistant\Exceptions;

use RuntimeException;

/**
 * The call to the model, or what came back, was unusable. `attempted` says whether a request actually left the server
 * (only those count toward the daily caps). `reason` is a short code for the audit row, never a body or a secret.
 */
final class AssistantFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly bool $attempted = true,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }

    public static function busy(bool $attempted = true): self
    {
        return new self('The assistant is busy right now. Wait a minute and try again. Nothing was changed.', 'busy', $attempted);
    }

    public static function refused(): self
    {
        return new self('The assistant could not help with that request. Try describing it differently. Nothing was changed.', 'declined', true, false);
    }

    public static function unusable(string $reason): self
    {
        return new self('The assistant gave an answer we could not use. Try again, or ask in different words. Nothing was changed.', $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self('The assistant could not complete that request. Nothing was changed.', $reason, true, false);
    }
}

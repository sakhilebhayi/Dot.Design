<?php

namespace App\Services\Design\Assistant\Exceptions;

use RuntimeException;

/** The assistant is switched off (no key). Nothing was sent anywhere. */
final class AssistantUnavailable extends RuntimeException
{
    public const MESSAGE = 'The assistant is not switched on for this workspace. Automatic fixes, templates and sizes still work.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}

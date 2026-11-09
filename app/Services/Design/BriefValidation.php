<?php

namespace App\Services\Design;

/** The outcome of validating a brief: a clean brief, plain-language errors keyed by field, and what was ignored. */
final class BriefValidation
{
    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, string>  $errors
     * @param  list<string>  $ignored
     */
    public function __construct(public readonly array $brief, public readonly array $errors, public readonly array $ignored = []) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** Laravel-style error bag: field => [message]. */
    public function errorBag(): array
    {
        return array_map(fn (string $message) => [$message], $this->errors);
    }
}

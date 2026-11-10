<?php

namespace App\Services\Design;

/**
 * Mutable state of one sanitising pass: the errors found, the corrections made and the ids already used.
 */
final class SanitiseRun
{
    public const MAX_ERRORS = 50;

    /** @var array<string, list<string>> */
    public array $errors = [];

    /** @var array<string, int> */
    public array $corrections = [];

    /** @var array<string, true> */
    public array $seenIds = [];

    /** @var list<array{path: string, id: int}> */
    public array $assetRefs = [];

    public int $nodes = 0;

    private int $errorCount = 0;

    public function __construct(public readonly bool $strict) {}

    public function fix(string $code): void
    {
        $this->corrections[$code] = ($this->corrections[$code] ?? 0) + 1;
    }

    public function error(string $path, string $message): void
    {
        if ($this->errorCount >= self::MAX_ERRORS) {
            return;
        }

        $this->errorCount++;
        $this->errors[$path][] = $message;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}

<?php

namespace App\Services;

/** The outcome of looking up an image element's picture for an export. */
readonly class ExportImage
{
    public const PLACEHOLDER = 'placeholder';

    public const UNAVAILABLE = 'unavailable';

    public const TOO_LARGE = 'too_large';

    private function __construct(
        public ?string $bytes,
        public ?string $mime,
        public ?string $problem,
    ) {}

    public static function found(string $bytes, string $mime): self
    {
        return new self($bytes, $mime, null);
    }

    public static function missing(string $problem): self
    {
        return new self(null, null, $problem);
    }

    /** The text drawn in place of a picture that could not be used. */
    public function label(): string
    {
        return match ($this->problem) {
            self::PLACEHOLDER => 'Placeholder image',
            self::TOO_LARGE => 'Image too large to embed',
            default => 'Image unavailable',
        };
    }
}

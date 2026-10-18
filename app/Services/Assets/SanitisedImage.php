<?php

namespace App\Services\Assets;

/** The bytes we will actually store, plus what we learned about them. */
readonly class SanitisedImage
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public bool $reencoded,
    ) {}
}

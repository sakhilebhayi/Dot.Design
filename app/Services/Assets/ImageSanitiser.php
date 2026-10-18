<?php

namespace App\Services\Assets;

/**
 * Turns untrusted upload bytes into bytes we are willing to store and serve.
 *
 * The order matters: the type is taken from the content (never the file name
 * or client MIME), the dimensions are read from the header and checked
 * BEFORE anything is decoded (so a few hundred bytes cannot make us allocate
 * gigabytes), and only then is the image decoded and re-encoded with GD.
 * Re-encoding is what strips EXIF/GPS and anything appended after the image
 * data. EXIF orientation is applied to the pixels first, otherwise stripping
 * it would turn phone photos sideways.
 */
class ImageSanitiser
{
    private const TYPES = [
        'image/jpeg' => ['ext' => 'jpg', 'imagetype' => IMAGETYPE_JPEG],
        'image/png' => ['ext' => 'png', 'imagetype' => IMAGETYPE_PNG],
        'image/webp' => ['ext' => 'webp', 'imagetype' => IMAGETYPE_WEBP],
    ];

    public const TYPE_MESSAGE = 'Only JPG, PNG or WebP images can be uploaded. SVG and other formats are not accepted.';

    public function canReencode(): bool
    {
        return (bool) config('design.assets.reencode')
            && function_exists('imagecreatefromstring')
            && function_exists('imagewebp');
    }

    public function sanitise(string $bytes): SanitisedImage
    {
        $mime = $this->detectMime($bytes);
        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[2] !== self::TYPES[$mime]['imagetype']) {
            throw new AssetRejected('That file could not be read as an image.');
        }

        [$width, $height] = $info;
        $this->checkDimensions($width, $height);

        if (! $this->canReencode()) {
            return new SanitisedImage($bytes, $mime, self::TYPES[$mime]['ext'], $width, $height, false);
        }

        $orientation = $mime === 'image/jpeg' ? $this->exifOrientation($bytes) : 1;
        $this->checkMemory($width, $height, $orientation !== 1);

        $image = $this->decode($bytes);
        $image = $this->applyOrientation($image, $orientation);

        $out = $this->encode($image, $mime);
        $outWidth = imagesx($image);
        $outHeight = imagesy($image);
        unset($image);

        return new SanitisedImage($out, $mime, self::TYPES[$mime]['ext'], $outWidth, $outHeight, true);
    }

    private function detectMime(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        if (! isset(self::TYPES[$mime])) {
            throw new AssetRejected(self::TYPE_MESSAGE, 'type');
        }

        return $mime;
    }

    private function checkDimensions(int $width, int $height): void
    {
        $min = (int) config('design.assets.min_dimension');
        $max = (int) config('design.assets.max_dimension');
        $maxMegapixels = (int) config('design.assets.max_megapixels');

        if ($width < $min || $height < $min) {
            throw new AssetRejected("The image is {$width}x{$height}px; both sides must be at least {$min}px.", 'dimensions');
        }

        if ($width > $max || $height > $max) {
            throw new AssetRejected("The image is {$width}x{$height}px; both sides must be at most {$max}px.", 'dimensions');
        }

        if ($width * $height > $maxMegapixels * 1_000_000) {
            $actual = round($width * $height / 1_000_000, 1);

            throw new AssetRejected("The image is {$actual} megapixels; the limit is {$maxMegapixels} megapixels.", 'dimensions');
        }
    }

    /**
     * GD holds a decoded image at four bytes per pixel. Refuse cleanly when
     * that would not fit in PHP's memory_limit, instead of dying with a fatal
     * "allowed memory size exhausted" that no code can catch. A rotated copy
     * (EXIF orientation) needs the pixels twice.
     */
    private function checkMemory(int $width, int $height, bool $needsRotation): void
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null) {
            return;
        }

        $needed = $width * $height * 4 * ($needsRotation ? 2 : 1) + 8 * 1024 * 1024;
        $available = $limit - memory_get_usage(true);

        if ($needed > $available) {
            $mb = (int) ceil($needed / 1048576);

            throw new AssetRejected("This image is too large to process on this server (it needs about {$mb} MB of memory). Try a smaller version.", 'memory');
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function decode(string $bytes): \GdImage
    {
        set_error_handler(static fn () => true);

        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }

        if ($image === false) {
            throw new AssetRejected('That file could not be decoded as an image. It may be damaged or truncated.', 'decode');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    private function exifOrientation(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        $exif = @exif_read_data($stream, 'IFD0');
        fclose($stream);

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        // EXIF orientations 2/4 are mirrors, 5/7 a mirror plus a quarter turn,
        // 3/6/8 plain rotations. imagerotate() turns counter-clockwise.
        match ($orientation) {
            2, 5, 7 => imageflip($image, IMG_FLIP_HORIZONTAL),
            4 => imageflip($image, IMG_FLIP_VERTICAL),
            default => null,
        };

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }

    private function encode(\GdImage $image, string $mime): string
    {
        ob_start();

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, (int) config('design.assets.jpeg_quality', 90)),
            'image/webp' => $this->encodeWebp($image),
            default => $this->encodePng($image),
        };

        $out = (string) ob_get_clean();

        if (! $ok || $out === '') {
            throw new AssetRejected('That image could not be processed.', 'encode');
        }

        return $out;
    }

    private function encodePng(\GdImage $image): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, null, 6);
    }

    private function encodeWebp(\GdImage $image): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagewebp($image, null, (int) config('design.assets.webp_quality', 90));
    }
}

<?php

namespace App\Services\Assets;

use App\Support\MemoryGuard;

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

    /**
     * How many times the decoded size (width x height x 4 bytes) a decode-and-re-encode of each type holds at its peak,
     * measured as peak resident memory on a 24 megapixel photo (R-45 perf, docs/R45_PERF.md): JPEG 2x, PNG 3x (libpng
     * keeps a second copy of every row while writing), WebP 4.7x (libwebp's own work buffers), rounded up. The input
     * bytes come on top. A rotated copy (EXIF orientation) adds one more decoded size.
     */
    private const PEAK_FACTOR = ['image/jpeg' => 2, 'image/png' => 3, 'image/webp' => 5];

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
        $this->checkMemory($mime, $width, $height, $orientation !== 1, strlen($bytes));

        $image = $this->decode($bytes);
        $image = $this->applyOrientation($image, $orientation);

        $out = $this->encode($image, $mime);
        $outWidth = imagesx($image);
        $outHeight = imagesy($image);
        [$preview, $previewWidth, $previewHeight] = $this->previewCopy($image);
        unset($image);

        return new SanitisedImage($out, $mime, self::TYPES[$mime]['ext'], $outWidth, $outHeight, true, $preview, $previewWidth, $previewHeight);
    }

    /**
     * The editor copy of an already stored (already sanitised, already upright) picture: for pictures uploaded before copies
     * existed. Same memory guard as an upload. Returns null when the picture is small enough not to need one.
     *
     * @return array{bytes: string, width: int, height: int}|null
     *
     * @throws AssetRejected when it cannot be decoded here (too large for this server's memory, or damaged)
     */
    public function previewOf(string $storedBytes): ?array
    {
        $mime = $this->detectMime($storedBytes);
        $info = @getimagesizefromstring($storedBytes);

        if ($info === false) {
            throw new AssetRejected('That file could not be read as an image.');
        }

        $edge = (int) config('design.assets.preview_edge', 1600);

        if (max($info[0], $info[1]) <= $edge * 1.15) {
            return null;
        }

        $this->checkMemory($mime, $info[0], $info[1], false, strlen($storedBytes));
        $image = $this->decode($storedBytes);
        [$bytes, $width, $height] = $this->previewCopy($image);
        unset($image);

        return $bytes === null ? null : ['bytes' => $bytes, 'width' => $width, 'height' => $height];
    }

    /**
     * A smaller WebP copy for the editor canvas, made from the pixels already in memory (no second decode). The original stays
     * the file exports use. Only worth making when the picture is larger than the edge in config('design.assets.preview_edge')
     * (default 1600 px: the page is rarely wider than that on screen). A failure here never fails the upload: the editor then
     * simply loads the original.
     *
     * @return array{0: ?string, 1: int, 2: int}
     */
    private function previewCopy(\GdImage $image): array
    {
        $edge = (int) config('design.assets.preview_edge', 1600);
        $width = imagesx($image);
        $height = imagesy($image);

        if ($edge < 200 || max($width, $height) <= $edge * 1.15) {
            return [null, 0, 0];
        }

        try {
            $newWidth = $width >= $height ? $edge : (int) max(1, round($width * $edge / $height));
            $newHeight = $width >= $height ? (int) max(1, round($height * $edge / $width)) : $edge;

            // IMG_BICUBIC returns false on some GD builds (it does here), and the filters that work differ in cost: IMG_TRIANGLE
            // (73 ms for 24 MP) is the first choice, the fixed bilinear one (13 ms, harsher) the fallback.
            $scaled = imagescale($image, $newWidth, $newHeight, IMG_TRIANGLE) ?: imagescale($image, $newWidth, $newHeight, IMG_BILINEAR_FIXED);

            if ($scaled === false) {
                return [null, 0, 0];
            }

            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            ob_start();
            $ok = imagewebp($scaled, null, (int) config('design.assets.preview_quality', 82));
            $bytes = (string) ob_get_clean();
            [$w, $h] = [imagesx($scaled), imagesy($scaled)];
            unset($scaled);

            // A copy that is not clearly smaller than the picture is not worth serving.
            return $ok && $bytes !== '' ? [$bytes, $w, $h] : [null, 0, 0];
        } catch (\Throwable) {
            return [null, 0, 0];
        }
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
     * Refuse cleanly when the decoded image would not fit in PHP's
     * memory_limit (see MemoryGuard), using the measured peak of each type
     * (PEAK_FACTOR) rather than the decoded size alone.
     */
    /** Bytes a decode-and-re-encode of this image holds at its peak (see PEAK_FACTOR). */
    public static function peakBytes(string $mime, int $width, int $height, bool $rotated = false, int $inputBytes = 0): int
    {
        return MemoryGuard::imageBytes($width, $height) * (self::PEAK_FACTOR[$mime] + ($rotated ? 1 : 0)) + $inputBytes;
    }

    private function checkMemory(string $mime, int $width, int $height, bool $needsRotation, int $inputBytes): void
    {
        $bytes = self::peakBytes($mime, $width, $height, $needsRotation, $inputBytes);
        $mb = MemoryGuard::shortfallMegabytes($bytes);

        if ($mb !== null) {
            throw new AssetRejected("This image is too large to process on this server (it needs about {$mb} MB of memory). Try a smaller version.", 'memory');
        }
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

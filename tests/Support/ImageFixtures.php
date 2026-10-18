<?php

namespace Tests\Support;

/**
 * Real, decodable image bytes for the upload tests -- built with GD so the
 * suite never depends on binary fixtures checked into the repo.
 */
trait ImageFixtures
{
    protected function imageBytes(string $format, int $width = 64, int $height = 48): string
    {
        $img = imagecreatetruecolor($width, $height);
        $left = imagecolorallocate($img, 220, 40, 40);
        $right = imagecolorallocate($img, 40, 60, 220);
        imagefilledrectangle($img, 0, 0, intdiv($width, 2) - 1, $height - 1, $left);
        imagefilledrectangle($img, intdiv($width, 2), 0, $width - 1, $height - 1, $right);

        ob_start();
        match ($format) {
            'png' => imagepng($img),
            'jpg', 'jpeg' => imagejpeg($img, null, 92),
            'webp' => imagewebp($img, null, 90),
            'gif' => imagegif($img),
            default => throw new \InvalidArgumentException($format),
        };

        return (string) ob_get_clean();
    }

    /** A PNG whose pixel data differs per $seed, so two calls never hash alike. */
    protected function distinctPng(int $seed, int $width = 40, int $height = 40): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, $seed % 256, intdiv($seed, 256) % 256, 90));
        imagesetpixel($img, 1, 1, imagecolorallocate($img, 255, $seed % 256, 0));

        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /**
     * Only the PNG signature and IHDR chunk: enough for getimagesize() to
     * report the dimensions, nowhere near enough to decode. Lets the tests
     * assert that oversized images are refused before any decoding happens.
     */
    protected function headerOnlyPng(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    /**
     * A landscape JPEG carrying an EXIF block with GPS latitude and an
     * orientation of 6 (rotate 90 degrees clockwise to display upright).
     */
    protected function jpegWithGpsExif(int $width = 40, int $height = 20): string
    {
        $jpeg = $this->imageBytes('jpg', $width, $height);

        $gps = pack('v', 1)                                  // one GPS entry
            .pack('vvVa4', 0x0001, 2, 2, "N\0\0\0")          // GPSLatitudeRef = "N"
            .pack('V', 0);
        $ifd0 = pack('v', 2)
            .pack('vvVv', 0x0112, 3, 1, 6).pack('v', 0)      // Orientation = 6
            .pack('vvVV', 0x8825, 4, 1, 8 + 2 + 24 + 4)      // pointer to GPS IFD
            .pack('V', 0);
        $tiff = 'II'.pack('vV', 42, 8).$ifd0.$gps;
        $payload = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }
}

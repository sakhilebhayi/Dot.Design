<?php

namespace Tests\Unit;

use App\Services\Assets\AssetRejected;
use App\Services\Assets\ImageSanitiser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-45 perf: the upload guard must model the real peak memory of a decode and re-encode (JPEG 2x, PNG 3x, WebP 5x the
 * decoded size, measured on a 24 megapixel photo), not just the decoded pixels, or a large PNG or WebP dies on PHP's
 * uncatchable memory fatal instead of being refused with a message.
 */
class ImageMemoryModelTest extends TestCase
{
    /** @return array<string, array{string, int, int, int}> */
    public static function peaks(): array
    {
        return [
            '24 MP JPEG' => ['image/jpeg', 6000, 4000, 192_000_000],
            '24 MP PNG' => ['image/png', 6000, 4000, 288_000_000],
            '24 MP WebP' => ['image/webp', 6000, 4000, 480_000_000],
            '40 MP JPEG' => ['image/jpeg', 8000, 5000, 320_000_000],
        ];
    }

    #[DataProvider('peaks')]
    public function test_peak_bytes_are_the_measured_multiple_of_the_decoded_size(string $mime, int $w, int $h, int $expected): void
    {
        $this->assertSame($expected, ImageSanitiser::peakBytes($mime, $w, $h));
    }

    public function test_a_rotated_copy_and_the_input_bytes_come_on_top(): void
    {
        $this->assertSame(6000 * 4000 * 4 * 3 + 7_000_000, ImageSanitiser::peakBytes('image/jpeg', 6000, 4000, true, 7_000_000));
    }

    public function test_a_large_png_is_refused_with_a_message_when_a_jpeg_of_the_same_size_still_fits(): void
    {
        $limit = ini_get('memory_limit');
        // Room for about 250 MB: enough for a 24 MP JPEG (192 MB + 8), not for the same pixels as a PNG (288 MB).
        $headroom = (int) ceil(memory_get_usage(true) / 1048576) + 250;
        ini_set('memory_limit', $headroom.'M');

        try {
            $sanitiser = new ImageSanitiser;
            $png = $this->headerOnlyPng(6000, 4000);
            $this->expectRefusal(fn () => $sanitiser->sanitise($png), 'memory');

            $jpeg = $this->headerOnlyJpeg(6000, 4000);
            // The JPEG passes the memory guard; it is then refused because it has no picture data, a different reason.
            $this->expectRefusal(fn () => $sanitiser->sanitise($jpeg), 'decode');
        } finally {
            ini_set('memory_limit', (string) $limit);
        }
    }

    private function expectRefusal(callable $call, string $reason): void
    {
        try {
            $call();
            $this->fail("expected a refusal for {$reason}");
        } catch (AssetRejected $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    /** A PNG signature and IHDR only: enough for the header checks, never decoded. */
    private function headerOnlyPng(int $w, int $h): string
    {
        $ihdr = pack('NN', $w, $h)."\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }

    /** A JPEG with a start-of-frame header and no scan data. */
    private function headerOnlyJpeg(int $w, int $h): string
    {
        return "\xFF\xD8\xFF\xC0\x00\x11\x08".pack('nn', $h, $w)."\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9";
    }
}

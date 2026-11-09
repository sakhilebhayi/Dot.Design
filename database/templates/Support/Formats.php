<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * Canvas sizes (px at 72 dpi) and safe margins (px; print values are mm x 72 / 25.4) for the 42 format
 * keys of the business catalogue (config/design_formats.php on feat/r45-business). A template's
 * width and height must equal its format's px size; text and icons must stay inside the safe margins.
 * Mirrored here so the library can be built and tested without that branch; keep in sync at merge.
 */
final class Formats
{
    /** key => [width, height, [safe top, right, bottom, left]] */
    public const TABLE = [
        'instagram-post-portrait' => [1080, 1350, [40, 40, 40, 40]],
        'instagram-post-square' => [1080, 1080, [40, 40, 40, 40]],
        'instagram-story' => [1080, 1920, [250, 60, 340, 60]],
        'facebook-post' => [1200, 630, [40, 40, 40, 40]],
        'facebook-cover' => [1640, 924, [120, 250, 120, 250]],
        'linkedin-post' => [1200, 627, [40, 40, 40, 40]],
        'linkedin-banner' => [1584, 396, [40, 40, 40, 320]],
        'x-post' => [1600, 900, [50, 50, 50, 50]],
        'tiktok-cover' => [1080, 1920, [130, 140, 480, 60]],
        'youtube-thumbnail' => [1280, 720, [40, 40, 40, 40]],
        'whatsapp-status' => [1080, 1920, [200, 60, 260, 60]],
        'pinterest-pin' => [1000, 1500, [50, 50, 50, 50]],
        'a3-portrait' => [842, 1191, [22.7, 22.7, 22.7, 22.7]],
        'a3-landscape' => [1191, 842, [22.7, 22.7, 22.7, 22.7]],
        'a4-portrait' => [595, 842, [17, 17, 17, 17]],
        'a4-landscape' => [842, 595, [17, 17, 17, 17]],
        'a5-portrait' => [420, 595, [14.2, 14.2, 14.2, 14.2]],
        'a5-landscape' => [595, 420, [14.2, 14.2, 14.2, 14.2]],
        'a6-portrait' => [298, 420, [11.3, 11.3, 11.3, 11.3]],
        'a6-landscape' => [420, 298, [11.3, 11.3, 11.3, 11.3]],
        'dl-portrait' => [281, 595, [14.2, 14.2, 14.2, 14.2]],
        'dl-landscape' => [595, 281, [14.2, 14.2, 14.2, 14.2]],
        'business-card-za' => [255, 156, [11.3, 11.3, 11.3, 11.3]],
        'business-card-85x55' => [241, 156, [11.3, 11.3, 11.3, 11.3]],
        'letterhead-a4' => [595, 842, [28.3, 42.5, 42.5, 42.5]],
        'poster-a2' => [1191, 1684, [34, 34, 34, 34]],
        'poster-a1' => [1684, 2384, [42.5, 42.5, 42.5, 42.5]],
        'roll-up-banner' => [2409, 5669, [85, 56.7, 425.2, 56.7]],
        'certificate-a4-landscape' => [842, 595, [28.3, 28.3, 28.3, 28.3]],
        'menu-a4' => [595, 842, [22.7, 22.7, 22.7, 22.7]],
        'menu-a5' => [420, 595, [17, 17, 17, 17]],
        'label-100x70' => [283, 198, [11.3, 11.3, 11.3, 11.3]],
        'voucher-dl' => [595, 281, [14.2, 14.2, 14.2, 14.2]],
        'email-signature' => [600, 200, [12, 12, 12, 12]],
        'presentation' => [1920, 1080, [60, 90, 60, 90]],
        'presentation-cover' => [1920, 1080, [60, 90, 60, 90]],
        'web-banner-leaderboard' => [728, 90, [8, 12, 8, 12]],
        'web-banner-medium-rectangle' => [300, 250, [10, 10, 10, 10]],
        'web-banner-skyscraper' => [160, 600, [10, 10, 10, 10]],
        'email-header' => [600, 200, [12, 20, 12, 20]],
        'report-a4' => [595, 842, [42.5, 42.5, 42.5, 42.5]],
        'newsletter-a4' => [595, 842, [34, 34, 34, 34]],
        'ebook-6x9' => [432, 648, [36.9, 36.9, 36.9, 36.9]],
    ];

    public static function size(string $key): array
    {
        $f = self::TABLE[$key] ?? throw new \InvalidArgumentException("Unknown format: {$key}");

        return [$f[0], $f[1]];
    }

    /** @return array{0:float,1:float,2:float,3:float} top, right, bottom, left in px */
    public static function safe(string $key): array
    {
        return self::TABLE[$key][2] ?? throw new \InvalidArgumentException("Unknown format: {$key}");
    }
}

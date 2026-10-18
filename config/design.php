<?php

/*
|--------------------------------------------------------------------------
| Dot.Design editor limits
|--------------------------------------------------------------------------
|
| Every number the upload pipeline, the canvas save endpoint and the export
| pipeline enforce lives here so an operator can tune it without a code
| change. See README.md ("Uploads") for the reasoning behind the defaults.
|
*/

return [

    'assets' => [
        // Private disk (config/filesystems.php). Never point this at "public":
        // files are only ever served through the policy-checked route.
        'disk' => env('DESIGN_ASSET_DISK', 'design_assets'),

        // Request size cap in kilobytes (8 MB).
        'max_kilobytes' => 8192,

        // Decoded-dimension caps, checked from the header before decoding.
        'min_dimension' => 16,
        'max_dimension' => 8000,
        'max_megapixels' => 40,

        // Total stored bytes per user (post re-encode), default 200 MB.
        'quota_bytes' => (int) env('DESIGN_ASSET_QUOTA_MB', 200) * 1024 * 1024,

        // Upload requests per user per minute.
        'uploads_per_minute' => (int) env('DESIGN_ASSET_UPLOADS_PER_MINUTE', 20),

        // Re-encode through GD to strip EXIF/GPS and anything appended to the
        // file. Falls back to storing the original bytes (flagged in the
        // asset's meta) if GD is missing or this is switched off.
        'reencode' => (bool) env('DESIGN_ASSET_REENCODE', true),
        'jpeg_quality' => 90,
        'webp_quality' => 90,
    ],

    'canvas' => [
        'max_elements' => 300,
        // Serialized size of one canvas's elements array.
        'max_json_bytes' => 512 * 1024,
    ],

    'export' => [
        // Images larger than this are not embedded in SVG exports.
        'max_embed_bytes' => 6 * 1024 * 1024,
        // Total bytes of images embedded in a single export.
        'max_embed_total_bytes' => 20 * 1024 * 1024,
        // Absolute path to a .ttf/.otf used to draw text in PNG exports.
        // Without one, PNG export refuses canvases that contain text.
        'png_font' => env('DESIGN_EXPORT_FONT'),
    ],

];

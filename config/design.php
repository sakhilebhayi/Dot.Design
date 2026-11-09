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
        // PNG export refuses canvases larger than this (per side / in total).
        'png_max_dimension' => 4096,
        'png_max_megapixels' => 16,
    ],

    /*
    |--------------------------------------------------------------------------
    | R-45 studio backend
    |--------------------------------------------------------------------------
    |
    | Limits for the structured-document API (docs/R45_BACKEND.md). The document
    | limits themselves (elements per page, depth, text length, JSON bytes) live
    | in resources/design/document-schema.json, the single source of truth shared
    | with the browser engine; only operational knobs are here.
    */
    'studio' => [
        // Throttles, per user per minute (shares/comments/thumbnails/exports) -- see StudioBackendServiceProvider.
        'saves_per_minute' => (int) env('DESIGN_SAVES_PER_MINUTE', 120),
        'shares_per_minute' => (int) env('DESIGN_SHARES_PER_MINUTE', 20),
        'comments_per_minute' => (int) env('DESIGN_COMMENTS_PER_MINUTE', 30),
        'thumbnails_per_minute' => (int) env('DESIGN_THUMBNAILS_PER_MINUTE', 30),
        'exports_per_minute' => (int) env('DESIGN_EXPORTS_PER_MINUTE', 60),
        // Public share-link comments per minute per link and visitor address (the address is hashed by the limiter, never stored by us).
        'public_comments_per_minute' => (int) env('DESIGN_PUBLIC_COMMENTS_PER_MINUTE', 6),
        'public_views_per_minute' => (int) env('DESIGN_PUBLIC_VIEWS_PER_MINUTE', 120),
        // Link probing: this many unusable-link answers per visitor address per ten minutes, then a 429 (R-45 security S-6).
        'public_misses_per_ten_minutes' => (int) env('DESIGN_PUBLIC_MISSES_PER_TEN_MINUTES', 30),
        // Guest comments one link accepts per day in total, whoever sends them.
        'public_comments_per_link_per_day' => (int) env('DESIGN_PUBLIC_COMMENTS_PER_LINK_PER_DAY', 200),

        'versions' => [
            // An automatic snapshot is taken at most this often while a project is being edited.
            'auto_interval_minutes' => 10,
            // Newest automatic snapshots kept per project; manual, review and approved versions are never pruned.
            'auto_retention' => 50,
            'max_label_length' => 80,
        ],

        'thumbnail' => [
            'max_bytes' => 300 * 1024,
            'max_dimension' => 1600,
        ],

        'shares' => [
            'max_active_per_project' => 20,
            'max_expiry_days' => 365,
            'asset_url_minutes' => 10,
        ],

        'comments' => [
            'max_length' => 2000,
            'max_mentions' => 20,
            'max_per_listing' => 500,
        ],

        // How long a client-supplied Idempotency-Key is remembered for page creation.
        'idempotency_hours' => 24,

        // Request body ceiling (bytes) for the page-save endpoint: the document limit plus envelope headroom.
        'max_request_bytes' => 1_400_000,
    ],

];

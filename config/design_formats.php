<?php

/*
|--------------------------------------------------------------------------
| Dot.Design format catalog (R-45)
|--------------------------------------------------------------------------
|
| The single list of sizes a business can design for. Read through
| App\Services\Design\Formats; never read this array directly from a view or
| controller. Template authors, the brief recommender and the resize tool all
| key off the `key` of an entry.
|
| Every entry:
|   label     plain-language name shown to people
|   use       one short line saying what it is for
|   group     social | print | digital | documents
|   width, height, unit   the real-world size (px for screens, mm or in for paper)
|   kind      digital | print
|   dpi_print 300 for print formats (the engine multiplies the canvas by this on export)
|   bleed_mm  print only: extra area beyond the trim that must be filled with background
|   safe      the margin inside which text and logos stay safe. Print: millimetres.
|             Digital: pixels (platform interface overlays, e.g. story captions)
|   family    aspect family: formats in one family have close aspect ratios so a design
|             resizes between them with little re-layout (used by the resize tool)
|   platform  instagram|facebook|linkedin|x|tiktok|youtube|whatsapp|pinterest|email|web|print|office
|   orientation portrait | landscape | square
|   type      the existing design_projects.type value this maps to
|   popular   true = shown first in Simple mode
|   notes     plain-language guidance, shown next to the format
|
| Canvas size: the editor works in px at 72 dpi. Screen formats are already px.
| Print formats convert with px = mm / 25.4 * 72 (A4 = 595 x 842). Export to print
| multiplies by dpi_print / 72 (A4 at 300 dpi = 2480 x 3508).
|
| Sources (checked 2026-11): social sizes follow the platforms' own advertiser/creator
| help pages and widely used recommended sizes; platforms change their guidance, so
| these are recommended sizes, not hard limits. Paper sizes follow ISO 216 (A series)
| and ISO 269 (DL = 110 x 220 envelope, 99 x 210 leaflet). 90 x 55 mm is the standard
| South African business card, 85 x 55 mm the common alternative. Web banners are the
| IAB / Google Ads standard units. E-book 6 x 9 in is a common trade paperback size.
*/

return [

    'groups' => [
        'social' => ['label' => 'Social media', 'help' => 'Posts, stories, covers and thumbnails.'],
        'print' => ['label' => 'Print', 'help' => 'Flyers, posters, cards and anything you will print on paper.'],
        'digital' => ['label' => 'Digital business', 'help' => 'Email, presentations and website banners.'],
        'documents' => ['label' => 'Documents', 'help' => 'Reports, newsletters and books.'],
    ],

    'print_dpi' => 300,
    'canvas_dpi' => 72,

    'formats' => [

        // ---- Social ---------------------------------------------------------------------------------------------
        'instagram-post-portrait' => [
            'label' => 'Instagram post (portrait)', 'use' => 'Feed post that takes the most room on screen',
            'group' => 'social', 'width' => 1080, 'height' => 1350, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40],
            'family' => 'portrait-4-5', 'platform' => 'instagram', 'orientation' => 'portrait', 'type' => 'social', 'popular' => true,
            'notes' => 'The 4:5 portrait shape fills the most of the Instagram feed. Keep key text away from the edges, the feed crops the grid preview to a square.',
        ],
        'instagram-post-square' => [
            'label' => 'Instagram post (square)', 'use' => 'Classic square feed post',
            'group' => 'social', 'width' => 1080, 'height' => 1080, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40],
            'family' => 'square', 'platform' => 'instagram', 'orientation' => 'square', 'type' => 'social', 'popular' => true,
            'notes' => 'Works on Instagram, Facebook and LinkedIn. A safe choice if you are posting the same picture in several places.',
        ],
        'instagram-story' => [
            'label' => 'Instagram story or reel', 'use' => 'Full-screen vertical story, reel cover or short video',
            'group' => 'social', 'width' => 1080, 'height' => 1920, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 250, 'right' => 60, 'bottom' => 340, 'left' => 60],
            'family' => 'story-9-16', 'platform' => 'instagram', 'orientation' => 'portrait', 'type' => 'social', 'popular' => true,
            'notes' => 'Full-screen 9:16. Instagram draws your name at the top and a reply bar at the bottom, so keep words and logos inside the safe area.',
        ],
        'facebook-post' => [
            'label' => 'Facebook post', 'use' => 'Link-style or image post in the Facebook feed',
            'group' => 'social', 'width' => 1200, 'height' => 630, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40],
            'family' => 'landscape-1-91', 'platform' => 'facebook', 'orientation' => 'landscape', 'type' => 'social', 'popular' => true,
            'notes' => 'The 1.91:1 landscape shape shows in full on desktop and mobile feeds and in link previews.',
        ],
        'facebook-cover' => [
            'label' => 'Facebook cover', 'use' => 'Banner at the top of your Facebook page',
            'group' => 'social', 'width' => 1640, 'height' => 924, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 120, 'right' => 250, 'bottom' => 120, 'left' => 250],
            'family' => 'landscape-16-9', 'platform' => 'facebook', 'orientation' => 'landscape', 'type' => 'banner', 'popular' => false,
            'notes' => 'Phones show the middle of the cover and crop the sides. Keep your name and logo in the middle.',
        ],
        'linkedin-post' => [
            'label' => 'LinkedIn post', 'use' => 'Image post in the LinkedIn feed',
            'group' => 'social', 'width' => 1200, 'height' => 627, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40],
            'family' => 'landscape-1-91', 'platform' => 'linkedin', 'orientation' => 'landscape', 'type' => 'social', 'popular' => true,
            'notes' => 'The standard shared-image size on LinkedIn. Keep it clean and readable on a phone.',
        ],
        'linkedin-banner' => [
            'label' => 'LinkedIn banner', 'use' => 'Wide banner on a LinkedIn personal or company page',
            'group' => 'social', 'width' => 1584, 'height' => 396, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 320],
            'family' => 'banner-4-1', 'platform' => 'linkedin', 'orientation' => 'landscape', 'type' => 'banner', 'popular' => false,
            'notes' => 'Very wide and short. On a personal profile your photo covers the left part, so keep key words to the right of it.',
        ],
        'x-post' => [
            'label' => 'X (Twitter) post', 'use' => 'Image attached to a post on X',
            'group' => 'social', 'width' => 1600, 'height' => 900, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 50, 'right' => 50, 'bottom' => 50, 'left' => 50],
            'family' => 'landscape-16-9', 'platform' => 'x', 'orientation' => 'landscape', 'type' => 'social', 'popular' => false,
            'notes' => 'A 16:9 landscape image shows in full in the timeline.',
        ],
        'tiktok-cover' => [
            'label' => 'TikTok cover', 'use' => 'Cover picture for a TikTok video',
            'group' => 'social', 'width' => 1080, 'height' => 1920, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 130, 'right' => 140, 'bottom' => 480, 'left' => 60],
            'family' => 'story-9-16', 'platform' => 'tiktok', 'orientation' => 'portrait', 'type' => 'social', 'popular' => false,
            'notes' => 'Full-screen vertical. TikTok puts buttons on the right and the caption at the bottom, so keep key words inside the safe area.',
        ],
        'youtube-thumbnail' => [
            'label' => 'YouTube thumbnail', 'use' => 'Picture shown for a YouTube video',
            'group' => 'social', 'width' => 1280, 'height' => 720, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40],
            'family' => 'landscape-16-9', 'platform' => 'youtube', 'orientation' => 'landscape', 'type' => 'social', 'popular' => false,
            'notes' => 'Keep the bottom-right corner clear, YouTube draws the video length there. Use big, short words.',
        ],
        'whatsapp-status' => [
            'label' => 'WhatsApp status', 'use' => 'Full-screen picture for a WhatsApp status',
            'group' => 'social', 'width' => 1080, 'height' => 1920, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 200, 'right' => 60, 'bottom' => 260, 'left' => 60],
            'family' => 'story-9-16', 'platform' => 'whatsapp', 'orientation' => 'portrait', 'type' => 'social', 'popular' => true,
            'notes' => 'Full-screen vertical. Popular for specials and price lists sent to customers.',
        ],
        'pinterest-pin' => [
            'label' => 'Pinterest pin', 'use' => 'Tall pin for Pinterest',
            'group' => 'social', 'width' => 1000, 'height' => 1500, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 50, 'right' => 50, 'bottom' => 50, 'left' => 50],
            'family' => 'portrait-2-3', 'platform' => 'pinterest', 'orientation' => 'portrait', 'type' => 'social', 'popular' => false,
            'notes' => 'The 2:3 tall shape gets the most room in Pinterest feeds.',
        ],

        // ---- Print ----------------------------------------------------------------------------------------------
        'a3-portrait' => [
            'label' => 'A3 portrait', 'use' => 'Large posters and notices',
            'group' => 'print', 'width' => 297, 'height' => 420, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'poster', 'popular' => false,
            'notes' => 'Twice the size of A4. Good for posters in shop windows and notice boards.',
        ],
        'a3-landscape' => [
            'label' => 'A3 landscape', 'use' => 'Large wide posters',
            'group' => 'print', 'width' => 420, 'height' => 297, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8],
            'family' => 'a-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'poster', 'popular' => false,
            'notes' => 'Twice the size of A4, held sideways.',
        ],
        'a4-portrait' => [
            'label' => 'A4 portrait', 'use' => 'Flyers, notices and one-page documents',
            'group' => 'print', 'width' => 210, 'height' => 297, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 6, 'right' => 6, 'bottom' => 6, 'left' => 6],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => true,
            'notes' => 'The standard office paper size. Every printer can print it.',
        ],
        'a4-landscape' => [
            'label' => 'A4 landscape', 'use' => 'Wide flyers, price lists and certificates',
            'group' => 'print', 'width' => 297, 'height' => 210, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 6, 'right' => 6, 'bottom' => 6, 'left' => 6],
            'family' => 'a-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'A4 held sideways.',
        ],
        'a5-portrait' => [
            'label' => 'A5 portrait', 'use' => 'Handout flyers and small menus',
            'group' => 'print', 'width' => 148, 'height' => 210, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 5, 'right' => 5, 'bottom' => 5, 'left' => 5],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => true,
            'notes' => 'Half of A4. The usual handout flyer size, cheap to print in bulk.',
        ],
        'a5-landscape' => [
            'label' => 'A5 landscape', 'use' => 'Wide handouts and invitations',
            'group' => 'print', 'width' => 210, 'height' => 148, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 5, 'right' => 5, 'bottom' => 5, 'left' => 5],
            'family' => 'a-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'Half of A4, held sideways.',
        ],
        'a6-portrait' => [
            'label' => 'A6 portrait', 'use' => 'Postcards, small flyers and thank-you cards',
            'group' => 'print', 'width' => 105, 'height' => 148, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 4, 'right' => 4, 'bottom' => 4, 'left' => 4],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => false,
            'notes' => 'Postcard size. Keep text short and large.',
        ],
        'a6-landscape' => [
            'label' => 'A6 landscape', 'use' => 'Postcards and small cards',
            'group' => 'print', 'width' => 148, 'height' => 105, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 4, 'right' => 4, 'bottom' => 4, 'left' => 4],
            'family' => 'a-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'Postcard size, held sideways.',
        ],
        'dl-portrait' => [
            'label' => 'DL leaflet (portrait)', 'use' => 'Slim leaflets that fit a standard envelope',
            'group' => 'print', 'width' => 99, 'height' => 210, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 5, 'right' => 5, 'bottom' => 5, 'left' => 5],
            'family' => 'dl-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => false,
            'notes' => 'One third of A4. Fits a DL envelope when folded.',
        ],
        'dl-landscape' => [
            'label' => 'DL (landscape)', 'use' => 'Slim cards, tickets and vouchers',
            'group' => 'print', 'width' => 210, 'height' => 99, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 5, 'right' => 5, 'bottom' => 5, 'left' => 5],
            'family' => 'dl-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'One third of A4, held sideways.',
        ],
        'business-card-za' => [
            'label' => 'Business card (South Africa)', 'use' => 'Standard 90 x 55 mm card',
            'group' => 'print', 'width' => 90, 'height' => 55, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 4, 'right' => 4, 'bottom' => 4, 'left' => 4],
            'family' => 'card-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => true,
            'notes' => 'The usual card size at South African printers. Keep names, numbers and logos away from the edge so nothing is cut off.',
        ],
        'business-card-85x55' => [
            'label' => 'Business card (85 x 55 mm)', 'use' => 'Slightly smaller card, common in Europe and online printers',
            'group' => 'print', 'width' => 85, 'height' => 55, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 4, 'right' => 4, 'bottom' => 4, 'left' => 4],
            'family' => 'card-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'Check with your printer which card size they use before you order.',
        ],
        'letterhead-a4' => [
            'label' => 'Letterhead (A4)', 'use' => 'Company letters, quotes and invoices',
            'group' => 'print', 'width' => 210, 'height' => 297, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 10, 'right' => 15, 'bottom' => 15, 'left' => 15],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => true,
            'notes' => 'Leave the middle clear for the letter. Office printers cannot print to the edge, so keep the logo and details inside the margin.',
        ],
        'poster-a2' => [
            'label' => 'Poster A2', 'use' => 'Big posters for walls and windows',
            'group' => 'print', 'width' => 420, 'height' => 594, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 5,
            'safe' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'poster', 'popular' => false,
            'notes' => 'Large format. Printed at a print shop, not on an office printer. Big pictures need to be high quality.',
        ],
        'poster-a1' => [
            'label' => 'Poster A1', 'use' => 'Very large posters and event boards',
            'group' => 'print', 'width' => 594, 'height' => 841, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 5,
            'safe' => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'poster', 'popular' => false,
            'notes' => 'Large format, usually printed at a lower resolution because people read it from further away. Ask your printer.',
        ],
        'roll-up-banner' => [
            'label' => 'Roll-up banner', 'use' => 'Stand-up banner for events and shops (850 x 2000 mm)',
            'group' => 'print', 'width' => 850, 'height' => 2000, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 5,
            'safe' => ['top' => 30, 'right' => 20, 'bottom' => 150, 'left' => 20],
            'family' => 'roll-up', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'banner', 'popular' => false,
            'notes' => 'The bottom 150 mm disappears into the stand, so keep key words above it. Printers often ask for lower resolution on this size.',
        ],
        'certificate-a4-landscape' => [
            'label' => 'Certificate (A4 landscape)', 'use' => 'Awards, certificates of completion',
            'group' => 'print', 'width' => 297, 'height' => 210, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
            'family' => 'a-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'A4 held sideways with a wide margin so a frame does not cover anything.',
        ],
        'menu-a4' => [
            'label' => 'Menu (A4)', 'use' => 'Full-size restaurant and cafe menus',
            'group' => 'print', 'width' => 210, 'height' => 297, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => true,
            'notes' => 'Plenty of room for a full menu. Check prices before you print.',
        ],
        'menu-a5' => [
            'label' => 'Menu (A5)', 'use' => 'Table menus and short price lists',
            'group' => 'print', 'width' => 148, 'height' => 210, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 6, 'right' => 6, 'bottom' => 6, 'left' => 6],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => false,
            'notes' => 'Half of A4, good for a short menu that sits on a table.',
        ],
        'label-100x70' => [
            'label' => 'Product label (100 x 70 mm)', 'use' => 'Stickers, product and parcel labels',
            'group' => 'print', 'width' => 100, 'height' => 70, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 2,
            'safe' => ['top' => 4, 'right' => 4, 'bottom' => 4, 'left' => 4],
            'family' => 'label', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'A common label sheet size. Ask your printer for the label stock they use.',
        ],
        'voucher-dl' => [
            'label' => 'Voucher (DL)', 'use' => 'Gift vouchers and discount slips',
            'group' => 'print', 'width' => 210, 'height' => 99, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 5, 'right' => 5, 'bottom' => 5, 'left' => 5],
            'family' => 'dl-landscape', 'platform' => 'print', 'orientation' => 'landscape', 'type' => 'graphic', 'popular' => false,
            'notes' => 'A long thin voucher that fits a standard envelope.',
        ],

        // ---- Digital business -----------------------------------------------------------------------------------
        'email-signature' => [
            'label' => 'Email signature', 'use' => 'Banner or block at the bottom of your emails',
            'group' => 'digital', 'width' => 600, 'height' => 200, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12],
            'family' => 'email-3-1', 'platform' => 'email', 'orientation' => 'landscape', 'type' => 'banner', 'popular' => true,
            'notes' => 'Keep it small in file size and 600 px wide, so it fits all email programs. Phone and email need to be readable at this size.',
        ],
        'presentation' => [
            'label' => 'Presentation (16:9)', 'use' => 'Slides for meetings and pitches',
            'group' => 'digital', 'width' => 1920, 'height' => 1080, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 60, 'right' => 90, 'bottom' => 60, 'left' => 90],
            'family' => 'landscape-16-9', 'platform' => 'office', 'orientation' => 'landscape', 'type' => 'presentation', 'popular' => true,
            'notes' => 'The standard widescreen slide shape. Leave a margin so projectors do not cut off the edges.',
        ],
        'presentation-cover' => [
            'label' => 'Presentation cover', 'use' => 'Title slide for a deck',
            'group' => 'digital', 'width' => 1920, 'height' => 1080, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 60, 'right' => 90, 'bottom' => 60, 'left' => 90],
            'family' => 'landscape-16-9', 'platform' => 'office', 'orientation' => 'landscape', 'type' => 'presentation', 'popular' => false,
            'notes' => 'The first slide of a presentation: your title, your business name and the date.',
        ],
        'web-banner-leaderboard' => [
            'label' => 'Web banner (728 x 90)', 'use' => 'Wide banner across the top of a web page',
            'group' => 'digital', 'width' => 728, 'height' => 90, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 8, 'right' => 12, 'bottom' => 8, 'left' => 12],
            'family' => 'leaderboard', 'platform' => 'web', 'orientation' => 'landscape', 'type' => 'banner', 'popular' => false,
            'notes' => 'Standard "leaderboard" ad size. Very short, so use one line and one button.',
        ],
        'web-banner-medium-rectangle' => [
            'label' => 'Web banner (300 x 250)', 'use' => 'Box ad inside articles and sidebars',
            'group' => 'digital', 'width' => 300, 'height' => 250, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
            'family' => 'rectangle', 'platform' => 'web', 'orientation' => 'landscape', 'type' => 'banner', 'popular' => false,
            'notes' => 'The most common web ad size, the "medium rectangle".',
        ],
        'web-banner-skyscraper' => [
            'label' => 'Web banner (160 x 600)', 'use' => 'Tall banner along the side of a web page',
            'group' => 'digital', 'width' => 160, 'height' => 600, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
            'family' => 'skyscraper', 'platform' => 'web', 'orientation' => 'portrait', 'type' => 'banner', 'popular' => false,
            'notes' => 'The "wide skyscraper" ad size. Narrow, so keep words short.',
        ],
        'email-header' => [
            'label' => 'Email newsletter header', 'use' => 'Banner at the top of an email newsletter',
            'group' => 'digital', 'width' => 600, 'height' => 200, 'unit' => 'px', 'kind' => 'digital',
            'safe' => ['top' => 12, 'right' => 20, 'bottom' => 12, 'left' => 20],
            'family' => 'email-3-1', 'platform' => 'email', 'orientation' => 'landscape', 'type' => 'banner', 'popular' => false,
            'notes' => '600 px wide fits all email programs. Email apps may hide pictures until the reader allows them, so add your name as text too.',
        ],

        // ---- Documents ------------------------------------------------------------------------------------------
        'report-a4' => [
            'label' => 'Report (A4)', 'use' => 'Business reports, proposals and company profiles',
            'group' => 'documents', 'width' => 210, 'height' => 297, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => true,
            'notes' => 'A4 pages with generous margins, for printing or sending as a PDF.',
        ],
        'newsletter-a4' => [
            'label' => 'Newsletter (A4)', 'use' => 'Printed or PDF newsletters',
            'group' => 'documents', 'width' => 210, 'height' => 297, 'unit' => 'mm', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12],
            'family' => 'a-portrait', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => false,
            'notes' => 'A4 pages for columns of news, with room for pictures.',
        ],
        'ebook-6x9' => [
            'label' => 'E-book or booklet (6 x 9 in)', 'use' => 'Guides, e-books and printed booklets',
            'group' => 'documents', 'width' => 6, 'height' => 9, 'unit' => 'in', 'kind' => 'print', 'dpi_print' => 300, 'bleed_mm' => 3,
            'safe' => ['top' => 13, 'right' => 13, 'bottom' => 13, 'left' => 13],
            'family' => 'portrait-2-3', 'platform' => 'print', 'orientation' => 'portrait', 'type' => 'graphic', 'popular' => false,
            'notes' => 'A common trade-book size. Keep text well inside the margin so it is not lost in the binding.',
        ],
    ],
];

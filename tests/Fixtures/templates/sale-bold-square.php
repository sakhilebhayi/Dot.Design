<?php

// Test fixture only. The real library lives in database/templates (library agent).
return [
    'slug' => 'fixture-sale-bold-square',
    'name' => 'Fixture: bold sale post',
    'description' => 'Test template for sales on Instagram.',
    'category' => 'social',
    'kind' => 'instagram-post',
    'format_key' => 'instagram-post-square',
    'industries' => ['general'],
    'purposes' => ['sale'],
    'styles' => ['bold'],
    'objectives' => ['sales'],
    'popularity' => 80,
    'pages' => [[
        'background' => ['type' => 'solid', 'color' => '#111111'],
        'elements' => [
            ['id' => 'bg', 'type' => 'shape', 'kind' => 'rect', 'name' => 'Backdrop', 'x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1080, 'fill' => '#ff3b30', 'tokens' => ['fill' => 'brand.colors.primary']],
            ['id' => 'headline', 'type' => 'text', 'name' => 'Headline', 'role' => 'headline', 'bind' => 'headline', 'x' => 80, 'y' => 120, 'width' => 920, 'height' => 240, 'text' => 'BIG SALE', 'font' => 'anton', 'fontSize' => 160, 'color' => '#ffffff', 'tokens' => ['color' => 'brand.colors.neutral', 'font' => 'brand.fonts.heading']],
            ['id' => 'offer', 'type' => 'text', 'name' => 'Offer', 'bind' => 'offer', 'x' => 80, 'y' => 400, 'width' => 920, 'height' => 120, 'text' => '50% off everything', 'fontSize' => 64, 'color' => '#ffffff', 'stroke' => ['color' => '#000000', 'width' => 2], 'tokens' => ['stroke_color' => 'brand.colors.accent']],
            ['id' => 'group', 'type' => 'group', 'name' => 'Footer', 'x' => 80, 'y' => 900, 'width' => 920, 'height' => 100, 'children' => [
                ['id' => 'name', 'type' => 'text', 'bind' => 'business_name', 'x' => 0, 'y' => 0, 'width' => 500, 'height' => 50, 'text' => 'Your Business', 'fontSize' => 36, 'color' => '#ffffff'],
                ['id' => 'phone', 'type' => 'text', 'bind' => 'phone', 'x' => 520, 'y' => 0, 'width' => 400, 'height' => 50, 'text' => '000 000 0000', 'fontSize' => 36, 'color' => '#ffffff', 'align' => 'right'],
            ]],
            ['id' => 'logo', 'type' => 'image', 'name' => 'Logo', 'role' => 'logo', 'bind' => 'logo', 'x' => 900, 'y' => 40, 'width' => 120, 'height' => 120],
            ['id' => 'photo', 'type' => 'image', 'name' => 'Photo', 'bind' => 'photo_1', 'x' => 560, 'y' => 540, 'width' => 440, 'height' => 320, 'fit' => 'cover'],
            ['id' => 'note', 'type' => 'text', 'name' => 'Fine print', 'x' => 80, 'y' => 1040, 'width' => 920, 'height' => 30, 'text' => 'Terms apply', 'fontSize' => 20, 'color' => '#aaaaaa'],
        ],
    ]],
];

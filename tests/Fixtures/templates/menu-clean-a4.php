<?php

// Test fixture only.
return [
    'slug' => 'fixture-menu-clean-a4',
    'name' => 'Fixture: clean menu',
    'category' => 'commercial',
    'kind' => 'menu',
    'format_key' => 'menu-a4',
    'industries' => ['food'],
    'purposes' => ['menu'],
    'styles' => ['clean', 'elegant'],
    'popularity' => 60,
    'pages' => [
        ['elements' => [
            ['id' => 'title', 'type' => 'text', 'bind' => 'business_name', 'x' => 40, 'y' => 40, 'width' => 515, 'height' => 60, 'text' => 'Cafe Name', 'font' => 'playfair-display', 'fontSize' => 40, 'color' => '#222222', 'align' => 'center', 'tokens' => ['font' => 'brand.fonts.heading']],
            ['id' => 'line', 'type' => 'shape', 'kind' => 'line', 'x' => 40, 'y' => 110, 'width' => 515, 'height' => 2, 'fill' => '#222222'],
            ['id' => 'tag', 'type' => 'text', 'bind' => 'tagline', 'x' => 40, 'y' => 120, 'width' => 515, 'height' => 30, 'text' => 'Fresh every day', 'fontSize' => 16, 'color' => '#555555', 'align' => 'center'],
        ]],
        ['elements' => [
            ['id' => 'price', 'type' => 'text', 'bind' => 'price', 'x' => 400, 'y' => 200, 'width' => 150, 'height' => 30, 'text' => 'R00', 'fontSize' => 18, 'color' => '#222222', 'align' => 'right'],
        ]],
    ],
];

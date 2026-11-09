<?php

// Test fixture only.
return [
    'slug' => 'fixture-event-dark-story',
    'name' => 'Fixture: dark event story',
    'category' => 'social',
    'kind' => 'instagram-story',
    'format_key' => 'instagram-story',
    'industries' => ['events', 'general'],
    'purposes' => ['event', 'social'],
    'styles' => ['dark'],
    'popularity' => 40,
    'pages' => [[
        'background' => ['type' => 'gradient', 'color' => '#000000', 'gradient' => ['kind' => 'linear', 'angle' => 180, 'stops' => [['at' => 0, 'color' => '#0b0b2e'], ['at' => 1, 'color' => '#000000']]]],
        'elements' => [
            ['id' => 'head', 'type' => 'text', 'bind' => 'headline', 'x' => 80, 'y' => 600, 'width' => 920, 'height' => 300, 'text' => 'THE EVENT', 'fontSize' => 140, 'color' => '#ffffff', 'tokens' => ['color' => 'brand.colors.accent']],
            ['id' => 'when', 'type' => 'text', 'bind' => 'date', 'x' => 80, 'y' => 940, 'width' => 920, 'height' => 80, 'text' => '1 January', 'fontSize' => 56, 'color' => '#ffffff'],
            ['id' => 'where', 'type' => 'text', 'bind' => 'venue', 'x' => 80, 'y' => 1040, 'width' => 920, 'height' => 80, 'text' => 'The Venue', 'fontSize' => 44, 'color' => '#cccccc'],
            ['id' => 'ic', 'type' => 'icon', 'icon' => 'star', 'x' => 80, 'y' => 400, 'width' => 120, 'height' => 120, 'color' => '#ffd60a', 'tokens' => ['color' => 'brand.colors.accent']],
        ],
    ]],
];

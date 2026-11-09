<?php

/*
|--------------------------------------------------------------------------
| Dot.Design user-facing fonts (R-45)
|--------------------------------------------------------------------------
|
| The single list of fonts the editor offers. PHP owns it: the editor page
| renders it into the boot JSON and the engine (resources/js/studio/engine/
| fonts.js) loads the self-hosted woff2 files lazily, only for the families a
| page uses. Files live in public/fonts/design/<key>/ and were fetched by
| scripts/fonts/fetch-design-fonts.mjs (licences: public/fonts/design/
| LICENSES.md; all SIL OFL 1.1 except Permanent Marker, Apache 2.0).
|
| files: "400" => regular weight 400, "700i" => italic weight 700. Paths are
| relative to public/ (use asset() to build URLs). fallback is the CSS stack
| used while a font loads or if it fails.
|
*/

return [

    'default' => 'inter',

    'fonts' => [
        [
            'key' => 'inter',
            'label' => 'Inter',
            'category' => 'sans',
            'weights' => [300, 400, 500, 600, 700],
            'italic' => true,
            'italic_weights' => [400, 700],
            'files' => [
                '300' => 'fonts/design/inter/inter-300.woff2',
                '400' => 'fonts/design/inter/inter-400.woff2',
                '500' => 'fonts/design/inter/inter-500.woff2',
                '600' => 'fonts/design/inter/inter-600.woff2',
                '700' => 'fonts/design/inter/inter-700.woff2',
                '400i' => 'fonts/design/inter/inter-400-italic.woff2',
                '700i' => 'fonts/design/inter/inter-700-italic.woff2',
            ],
            'fallback' => '"Helvetica Neue", Arial, sans-serif',
        ],
        [
            'key' => 'dm-sans',
            'label' => 'DM Sans',
            'category' => 'sans',
            'weights' => [300, 400, 500, 600, 700],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '300' => 'fonts/design/dm-sans/dm-sans-300.woff2',
                '400' => 'fonts/design/dm-sans/dm-sans-400.woff2',
                '500' => 'fonts/design/dm-sans/dm-sans-500.woff2',
                '600' => 'fonts/design/dm-sans/dm-sans-600.woff2',
                '700' => 'fonts/design/dm-sans/dm-sans-700.woff2',
            ],
            'fallback' => '"Helvetica Neue", Arial, sans-serif',
        ],
        [
            'key' => 'poppins',
            'label' => 'Poppins',
            'category' => 'sans',
            'weights' => [300, 400, 500, 600, 700],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '300' => 'fonts/design/poppins/poppins-300.woff2',
                '400' => 'fonts/design/poppins/poppins-400.woff2',
                '500' => 'fonts/design/poppins/poppins-500.woff2',
                '600' => 'fonts/design/poppins/poppins-600.woff2',
                '700' => 'fonts/design/poppins/poppins-700.woff2',
            ],
            'fallback' => '"Helvetica Neue", Arial, sans-serif',
        ],
        [
            'key' => 'montserrat',
            'label' => 'Montserrat',
            'category' => 'sans',
            'weights' => [300, 400, 500, 600, 700],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '300' => 'fonts/design/montserrat/montserrat-300.woff2',
                '400' => 'fonts/design/montserrat/montserrat-400.woff2',
                '500' => 'fonts/design/montserrat/montserrat-500.woff2',
                '600' => 'fonts/design/montserrat/montserrat-600.woff2',
                '700' => 'fonts/design/montserrat/montserrat-700.woff2',
            ],
            'fallback' => '"Helvetica Neue", Arial, sans-serif',
        ],
        [
            'key' => 'oswald',
            'label' => 'Oswald',
            'category' => 'display',
            'weights' => [400, 700],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/oswald/oswald-400.woff2',
                '700' => 'fonts/design/oswald/oswald-700.woff2',
            ],
            'fallback' => '"Arial Narrow", Impact, sans-serif',
        ],
        [
            'key' => 'bebas-neue',
            'label' => 'Bebas Neue',
            'category' => 'display',
            'weights' => [400],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/bebas-neue/bebas-neue-400.woff2',
            ],
            'fallback' => 'Impact, "Arial Narrow", sans-serif',
        ],
        [
            'key' => 'anton',
            'label' => 'Anton',
            'category' => 'display',
            'weights' => [400],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/anton/anton-400.woff2',
            ],
            'fallback' => 'Impact, "Arial Narrow", sans-serif',
        ],
        [
            'key' => 'archivo-black',
            'label' => 'Archivo Black',
            'category' => 'display',
            'weights' => [400],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/archivo-black/archivo-black-400.woff2',
            ],
            'fallback' => '"Arial Black", Impact, sans-serif',
        ],
        [
            'key' => 'playfair-display',
            'label' => 'Playfair Display',
            'category' => 'serif',
            'weights' => [400, 700],
            'italic' => true,
            'italic_weights' => [400, 700],
            'files' => [
                '400' => 'fonts/design/playfair-display/playfair-display-400.woff2',
                '700' => 'fonts/design/playfair-display/playfair-display-700.woff2',
                '400i' => 'fonts/design/playfair-display/playfair-display-400-italic.woff2',
                '700i' => 'fonts/design/playfair-display/playfair-display-700-italic.woff2',
            ],
            'fallback' => 'Georgia, "Times New Roman", serif',
        ],
        [
            'key' => 'lora',
            'label' => 'Lora',
            'category' => 'serif',
            'weights' => [400, 700],
            'italic' => true,
            'italic_weights' => [400, 700],
            'files' => [
                '400' => 'fonts/design/lora/lora-400.woff2',
                '700' => 'fonts/design/lora/lora-700.woff2',
                '400i' => 'fonts/design/lora/lora-400-italic.woff2',
                '700i' => 'fonts/design/lora/lora-700-italic.woff2',
            ],
            'fallback' => 'Georgia, "Times New Roman", serif',
        ],
        [
            'key' => 'merriweather',
            'label' => 'Merriweather',
            'category' => 'serif',
            'weights' => [400, 700],
            'italic' => true,
            'italic_weights' => [400, 700],
            'files' => [
                '400' => 'fonts/design/merriweather/merriweather-400.woff2',
                '700' => 'fonts/design/merriweather/merriweather-700.woff2',
                '400i' => 'fonts/design/merriweather/merriweather-400-italic.woff2',
                '700i' => 'fonts/design/merriweather/merriweather-700-italic.woff2',
            ],
            'fallback' => 'Georgia, "Times New Roman", serif',
        ],
        [
            'key' => 'cormorant-garamond',
            'label' => 'Cormorant Garamond',
            'category' => 'serif',
            'weights' => [400, 700],
            'italic' => true,
            'italic_weights' => [400, 700],
            'files' => [
                '400' => 'fonts/design/cormorant-garamond/cormorant-garamond-400.woff2',
                '700' => 'fonts/design/cormorant-garamond/cormorant-garamond-700.woff2',
                '400i' => 'fonts/design/cormorant-garamond/cormorant-garamond-400-italic.woff2',
                '700i' => 'fonts/design/cormorant-garamond/cormorant-garamond-700-italic.woff2',
            ],
            'fallback' => 'Garamond, Georgia, serif',
        ],
        [
            'key' => 'fraunces',
            'label' => 'Fraunces',
            'category' => 'serif',
            'weights' => [400, 700],
            'italic' => true,
            'italic_weights' => [400, 700],
            'files' => [
                '400' => 'fonts/design/fraunces/fraunces-400.woff2',
                '700' => 'fonts/design/fraunces/fraunces-700.woff2',
                '400i' => 'fonts/design/fraunces/fraunces-400-italic.woff2',
                '700i' => 'fonts/design/fraunces/fraunces-700-italic.woff2',
            ],
            'fallback' => 'Georgia, "Times New Roman", serif',
        ],
        [
            'key' => 'space-grotesk',
            'label' => 'Space Grotesk',
            'category' => 'sans',
            'weights' => [400, 700],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/space-grotesk/space-grotesk-400.woff2',
                '700' => 'fonts/design/space-grotesk/space-grotesk-700.woff2',
            ],
            'fallback' => '"Helvetica Neue", Arial, sans-serif',
        ],
        [
            'key' => 'caveat',
            'label' => 'Caveat',
            'category' => 'script',
            'weights' => [400, 700],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/caveat/caveat-400.woff2',
                '700' => 'fonts/design/caveat/caveat-700.woff2',
            ],
            'fallback' => '"Comic Sans MS", cursive',
        ],
        [
            'key' => 'pacifico',
            'label' => 'Pacifico',
            'category' => 'script',
            'weights' => [400],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/pacifico/pacifico-400.woff2',
            ],
            'fallback' => '"Brush Script MT", cursive',
        ],
        [
            'key' => 'permanent-marker',
            'label' => 'Permanent Marker',
            'category' => 'script',
            'weights' => [400],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/permanent-marker/permanent-marker-400.woff2',
            ],
            'fallback' => '"Marker Felt", "Comic Sans MS", cursive',
        ],
        [
            'key' => 'jetbrains-mono',
            'label' => 'JetBrains Mono',
            'category' => 'mono',
            'weights' => [400, 700],
            'italic' => false,
            'italic_weights' => [],
            'files' => [
                '400' => 'fonts/design/jetbrains-mono/jetbrains-mono-400.woff2',
                '700' => 'fonts/design/jetbrains-mono/jetbrains-mono-700.woff2',
            ],
            'fallback' => 'ui-monospace, Menlo, Consolas, monospace',
        ],
    ],

];

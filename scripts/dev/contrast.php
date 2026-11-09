<?php

use Database\Templates\Support\Colour;

// Dev tool: contrast of foreground:background pairs.
// Usage: php scripts/dev/contrast.php '#d23a1d:#ffffff' '#111111:#ff5a1f'
require __DIR__.'/../../database/templates/Support/Colour.php';
array_shift($argv);
foreach ($argv as $pair) {
    [$fg, $bg] = explode(':', $pair);
    printf("%s on %s = %.2f\n", $fg, $bg, Colour::contrast($fg, $bg));
}

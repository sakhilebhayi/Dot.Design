<?php

declare(strict_types=1);

// Loads the template builder support classes without relying on composer autoload.
foreach (['Colour', 'FontMetrics', 'Palette', 'TypePair', 'Formats', 'Vocab', 'Sample', 'Page', 'Stack', 'Template', 'Craft'] as $c) {
    $f = __DIR__.'/'.$c.'.php';
    if (is_file($f)) {
        require_once $f;
    }
}

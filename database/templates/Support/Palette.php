<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/** The five colours a template may use. Defined once; elements reference names. */
final class Palette
{
    public readonly array $colours;

    /** palette colour name => brand kit colour role (primary|secondary|accent|neutral|extra). */
    public readonly array $tokens;

    /**
     * @param  ?array<string,string>  $tokens  which palette colours follow the brand kit. Default: light pages
     *                                         map accent to primary and ink to neutral; dark pages map the page
     *                                         colour to primary and accent to accent.
     */
    public function __construct(string $bg, string $ink, string $accent, string $surface, string $extra, public readonly string $label = '', ?array $tokens = null)
    {
        $this->colours = [
            'bg' => Colour::normalise($bg),
            'ink' => Colour::normalise($ink),
            'accent' => Colour::normalise($accent),
            'surface' => Colour::normalise($surface),
            'extra' => Colour::normalise($extra),
        ];
        if ($tokens === null) {
            $dark = Colour::luminance(Colour::parse($this->colours['bg'])) < 0.2;
            $tokens = $dark ? ['bg' => 'primary', 'accent' => 'accent'] : ['accent' => 'primary', 'ink' => 'neutral'];
        }
        $this->tokens = $tokens;
    }

    /** The brand token_ref for a palette colour name, or null when that colour is not brand driven. */
    public function tokenFor(?string $name): ?string
    {
        if ($name === null || $name === '' || $name[0] === '#' || ! isset($this->tokens[$name])) {
            return null;
        }

        return 'brand.colors.'.$this->tokens[$name];
    }

    /** Resolve a palette name or a literal hex. */
    public function get(string $nameOrHex): string
    {
        if ($nameOrHex !== '' && $nameOrHex[0] === '#') {
            return Colour::normalise($nameOrHex);
        }

        return $this->colours[$nameOrHex] ?? throw new \InvalidArgumentException("Unknown palette colour: {$nameOrHex}");
    }
}

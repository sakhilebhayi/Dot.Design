<?php

namespace App\Services\Design;

use RuntimeException;

/**
 * Reads resources/design/document-schema.json, the single source of truth shared with the browser engine
 * (docs/R45_ARCHITECTURE.md section 2). Nothing about a limit, an enum or a value pattern is written twice.
 */
final class DocumentSchema
{
    /** The user-facing font keys, used only when config/design_fonts.php is absent or unreadable. */
    public const FALLBACK_FONT_KEYS = [
        'inter', 'dm-sans', 'poppins', 'montserrat', 'oswald', 'bebas-neue', 'anton', 'archivo-black', 'playfair-display',
        'lora', 'merriweather', 'cormorant-garamond', 'fraunces', 'space-grotesk', 'caveat', 'pacifico',
        'permanent-marker', 'jetbrains-mono',
    ];

    public const DEFAULT_FONT = 'inter';

    private static ?self $shared = null;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(?string $path = null)
    {
        $path ??= resource_path('design/document-schema.json');
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! isset($data['limits'], $data['enums'], $data['value_types'])) {
            throw new RuntimeException('The document schema could not be loaded.');
        }

        $this->data = $data;
    }

    public static function shared(): self
    {
        return self::$shared ??= new self;
    }

    public static function flush(): void
    {
        self::$shared = null;
    }

    public function version(): int
    {
        return (int) ($this->data['version'] ?? 2);
    }

    public function limit(string $name): int|float
    {
        return $this->data['limits'][$name];
    }

    /** @return array<string, int|float> */
    public function limits(): array
    {
        return $this->data['limits'];
    }

    /** @return list<string> */
    public function enum(string $name): array
    {
        return $this->data['enums'][$name];
    }

    public function inEnum(string $name, mixed $value): bool
    {
        return is_string($value) && in_array($value, $this->data['enums'][$name], true);
    }

    /**
     * The schema entry of one element property: group is common|text|shape|image|icon|group.
     *
     * @return array<string, mixed>
     */
    public function spec(string $group, string $prop): array
    {
        $spec = $this->data['element'][$group][$prop] ?? [];

        return is_array($spec) ? $spec : [];
    }

    /** The schema entry of one page property. @return array<string, mixed> */
    public function pageSpec(string $prop): array
    {
        $spec = $this->data['page'][$prop] ?? [];

        return is_array($spec) ? $spec : [];
    }

    /** Whether $value (a string) matches one of the named value patterns (hex, uuid_or_id, font_key, token_ref, bind_key, icon_key). */
    public function matches(string $valueType, mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 256) {
            return false;
        }

        return preg_match('~'.$this->data['value_types'][$valueType].'~D', $value) === 1;
    }

    /**
     * Font keys a document may use. config/design_fonts.php (owned by the engine agent) is the authority once it
     * exists; it may be a plain list of keys, a key => metadata map, or a list/map under a `fonts` key.
     *
     * @return list<string>
     */
    public function fontKeys(): array
    {
        $config = config('design_fonts');
        $fonts = is_array($config) ? ($config['fonts'] ?? $config) : [];
        $keys = [];

        foreach ($fonts as $key => $font) {
            if (is_string($key) && ! is_int($key)) {
                $keys[] = $key;
            } elseif (is_string($font)) {
                $keys[] = $font;
            } elseif (is_array($font) && is_string($font['key'] ?? null)) {
                $keys[] = $font['key'];
            }
        }

        $keys = array_values(array_unique(array_filter($keys, fn ($k) => $this->matches('font_key', $k))));

        return $keys === [] ? self::fallbackFontKeys() : $keys;
    }

    /** @return list<string> */
    public static function fallbackFontKeys(): array
    {
        return self::FALLBACK_FONT_KEYS;
    }
}

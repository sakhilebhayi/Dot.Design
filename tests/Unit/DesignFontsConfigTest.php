<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * config/design_fonts.php is the one list of editor fonts. Every file it names
 * must exist on disk (the editor loads them lazily and a missing file would
 * silently fall back to a system font), and the shared 18 keys must all be there.
 */
class DesignFontsConfigTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'inter', 'dm-sans', 'poppins', 'montserrat', 'oswald', 'bebas-neue', 'anton', 'archivo-black',
        'playfair-display', 'lora', 'merriweather', 'cormorant-garamond', 'fraunces', 'space-grotesk',
        'caveat', 'pacifico', 'permanent-marker', 'jetbrains-mono',
    ];

    /** @return list<array<string, mixed>> */
    private function fonts(): array
    {
        return config('design_fonts.fonts');
    }

    public function test_all_shared_font_keys_are_present(): void
    {
        $keys = array_column($this->fonts(), 'key');

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $keys, "Font key {$key} is missing from config/design_fonts.php");
        }

        $this->assertSame(array_values(array_unique($keys)), $keys, 'Font keys must be unique.');
        $this->assertContains(config('design_fonts.default'), $keys);
    }

    public function test_every_listed_font_file_exists_and_is_woff2(): void
    {
        foreach ($this->fonts() as $font) {
            $this->assertNotEmpty($font['files'], "{$font['key']} lists no files");

            foreach ($font['files'] as $variant => $path) {
                $full = public_path($path);
                $this->assertFileExists($full, "{$font['key']} {$variant}: {$path}");
                $this->assertSame('wOF2', file_get_contents($full, false, null, 0, 4), "{$path} is not a woff2 file");
            }
        }
    }

    public function test_weights_and_italics_match_the_files(): void
    {
        foreach ($this->fonts() as $font) {
            $this->assertContains($font['category'], ['sans', 'display', 'serif', 'script', 'mono']);
            $this->assertNotEmpty($font['fallback']);

            foreach ($font['weights'] as $weight) {
                $this->assertArrayHasKey((string) $weight, $font['files'], "{$font['key']} has no file for weight {$weight}");
            }

            $this->assertSame(! empty($font['italic_weights']), $font['italic'], "{$font['key']}: italic flag and italic_weights disagree");

            foreach ($font['italic_weights'] as $weight) {
                $this->assertArrayHasKey($weight.'i', $font['files'], "{$font['key']} has no italic file for weight {$weight}");
            }

            $this->assertCount(count($font['weights']) + count($font['italic_weights']), $font['files'], "{$font['key']} lists files that no weight declares");
        }
    }

    public function test_font_payload_stays_under_the_size_budget_and_is_licensed(): void
    {
        $bytes = 0;

        foreach ($this->fonts() as $font) {
            foreach ($font['files'] as $path) {
                $bytes += filesize(public_path($path));
            }
        }

        $this->assertLessThan(2.5 * 1024 * 1024, $bytes, 'Design fonts exceed the 2.5 MB budget.');
        $this->assertFileExists(public_path('fonts/design/LICENSES.md'));

        $licences = file_get_contents(public_path('fonts/design/LICENSES.md'));
        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertStringContainsString("| {$key} |", $licences, "{$key} has no licence entry");
        }
    }
}

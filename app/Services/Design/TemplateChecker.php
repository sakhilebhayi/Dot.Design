<?php

namespace App\Services\Design;

/**
 * Strict validation of a template definition and its pages against the document schema.
 *
 * Unlike a save-time sanitiser (which clamps and strips) this REFUSES: a template is authored
 * content, so a wrong enum, an unknown key or an out-of-range number is a bug to fix, not
 * something to quietly repair. Returns a list of human-readable problems; empty means valid.
 *
 * SEAM: at merge the backend's DocumentSanitiser can be run on top of this in
 * extraSanitiserErrors() (left as a hook, not guessed at here).
 */
class TemplateChecker
{
    private const COMMON_KEYS = ['id', 'type', 'name', 'role', 'bind', 'x', 'y', 'width', 'height', 'rotation', 'opacity', 'locked', 'hidden', 'flip_x', 'flip_y', 'blend', 'shadow', 'animation', 'tokens'];

    private const TYPE_KEYS = [
        'text' => ['text', 'font', 'fontSize', 'fontWeight', 'italic', 'underline', 'strike', 'align', 'valign', 'lineHeight', 'letterSpacing', 'textCase', 'color', 'autoFit', 'stroke'],
        'shape' => ['kind', 'fill', 'stroke', 'radius', 'sides', 'points', 'inner', 'd'],
        'image' => ['asset_id', 'fit', 'focal', 'zoom', 'mask', 'radius', 'border', 'filters'],
        'icon' => ['icon', 'color', 'strokeWidth'],
        'group' => ['children'],
    ];

    private const PAGE_KEYS = ['id', 'name', 'width', 'height', 'background', 'duration_ms', 'transition', 'guides', 'elements'];

    /** Which element props a token may drive, per element type. */
    public const TOKEN_PROPS = ['color', 'fill', 'stroke_color', 'shadow_color', 'font'];

    /** @var list<string> */
    private array $errors = [];

    private array $ids = [];

    private int $elementCount = 0;

    public function __construct(private readonly SchemaReader $schema, private readonly Formats $formats) {}

    /**
     * @param  array<string, mixed>  $definition  the template as authored (slug, name, category, kind, ..., pages)
     * @return list<string>
     */
    public function checkDefinition(array $definition): array
    {
        $this->errors = [];
        $copy = (array) config('design_copy');
        $flow = (array) config('design_brief_flow');

        $slug = $definition['slug'] ?? null;
        if (! is_string($slug) || ! preg_match('/^[a-z0-9][a-z0-9-]{2,99}$/', $slug)) {
            $this->fail('slug', 'must be 3 to 100 lowercase letters, digits or dashes');
        }

        $this->text($definition, 'name', 'name', 1, 120, true);
        $this->text($definition, 'description', 'description', 0, 600, false);

        if (! in_array($definition['category'] ?? null, ['marketing', 'social', 'identity', 'commercial', 'document'], true)) {
            $this->fail('category', 'must be one of marketing, social, identity, commercial, document');
        }

        if (! is_string($definition['kind'] ?? null) || ! preg_match('/^[a-z0-9-]{1,40}$/', $definition['kind'])) {
            $this->fail('kind', 'must be a lowercase key such as instagram-post');
        }

        $this->subset($definition, 'styles', array_keys((array) ($flow['styles'] ?? [])));
        $this->subset($definition, 'purposes', array_keys((array) ($copy['purposes'] ?? [])));
        $this->subset($definition, 'objectives', array_keys((array) ($copy['objectives'] ?? [])));
        $this->subset($definition, 'industries', array_merge(['general'], array_keys((array) ($flow['industries'] ?? []))));

        $platforms = array_unique(array_map(fn (array $format) => $format['platform'], $this->formats->all()));
        $this->subset($definition, 'platforms', $platforms);

        $format = null;
        if (isset($definition['format_key'])) {
            $format = is_string($definition['format_key']) ? $this->formats->find($definition['format_key']) : null;
            if ($format === null) {
                $this->fail('format_key', 'is not a format in config/design_formats.php');
            }
        }

        if (isset($definition['status']) && ! in_array($definition['status'], ['draft', 'published', 'retired'], true)) {
            $this->fail('status', 'must be draft, published or retired');
        }

        if (isset($definition['popularity']) && (! is_int($definition['popularity']) || $definition['popularity'] < 0 || $definition['popularity'] > 100)) {
            $this->fail('popularity', 'must be a whole number from 0 to 100');
        }

        $pages = $definition['pages'] ?? null;
        if (! is_array($pages) || $pages === [] || ! array_is_list($pages)) {
            $this->fail('pages', 'must be a non-empty list of pages');
        } else {
            $width = $definition['width'] ?? $format['px']['width'] ?? null;
            $height = $definition['height'] ?? $format['px']['height'] ?? null;
            $this->runPages($pages, $width, $height);

            if ($format !== null && isset($definition['width'], $definition['height'])
                && ($definition['width'] !== $format['px']['width'] || $definition['height'] !== $format['px']['height'])) {
                $this->fail('width', "must equal the format's canvas size {$format['px']['width']} x {$format['px']['height']}");
            }
        }

        if (isset($definition['slots'])) {
            $this->checkSlots($definition['slots'], is_array($pages) ? $pages : []);
        }

        return $this->errors;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<string>
     */
    public function checkPages(array $pages, int|float|null $defaultWidth = null, int|float|null $defaultHeight = null): array
    {
        $this->errors = [];
        $this->runPages($pages, $defaultWidth, $defaultHeight);

        return $this->errors;
    }

    private function runPages(array $pages, int|float|null $defaultWidth, int|float|null $defaultHeight): void
    {
        if (count($pages) > $this->schema->limit('max_pages')) {
            $this->fail('pages', 'has more pages than the limit of '.$this->schema->limit('max_pages'));
        }

        if (strlen((string) json_encode($pages)) > $this->schema->limit('max_json_bytes')) {
            $this->fail('pages', 'is larger than the allowed document size');
        }

        foreach ($pages as $index => $page) {
            $this->ids = [];
            $this->elementCount = 0;
            $this->checkPage($page, "pages.$index", $defaultWidth, $defaultHeight);
        }
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function checkPage(mixed $page, string $path, int|float|null $dw, int|float|null $dh): void
    {
        if (! is_array($page)) {
            $this->fail($path, 'must be an object');

            return;
        }

        $this->unknownKeys($page, self::PAGE_KEYS, $path);
        $this->number($page, 'width', "$path.width", 16, 20000, false);
        $this->number($page, 'height', "$path.height", 16, 20000, false);

        if (! isset($page['width']) && $dw === null) {
            $this->fail("$path.width", 'is missing and the template has no default size');
        }

        $this->text($page, 'name', "$path.name", 0, (int) $this->schema->limit('max_name_length'), false);

        if (isset($page['duration_ms']) && (! is_int($page['duration_ms']) || $page['duration_ms'] < 500 || $page['duration_ms'] > 60000)) {
            $this->fail("$path.duration_ms", 'must be a whole number from 500 to 60000');
        }

        if (isset($page['transition']) && ! in_array($page['transition'], $this->schema->enum('page_transition'), true)) {
            $this->fail("$path.transition", 'is not an allowed transition');
        }

        if (isset($page['guides'])) {
            if (! is_array($page['guides']) || count($page['guides']) > 40) {
                $this->fail("$path.guides", 'must be a list of at most 40 guides');
            } else {
                foreach ($page['guides'] as $i => $guide) {
                    if (! is_array($guide) || ! in_array($guide['axis'] ?? null, ['x', 'y'], true) || ! is_numeric($guide['pos'] ?? null)) {
                        $this->fail("$path.guides.$i", 'needs axis x or y and a numeric pos');
                    }
                }
            }
        }

        if (isset($page['background'])) {
            $this->checkBackground($page['background'], "$path.background");
        }

        $elements = $page['elements'] ?? null;
        if (! is_array($elements) || ($elements !== [] && ! array_is_list($elements))) {
            $this->fail("$path.elements", 'must be a list of elements');

            return;
        }

        foreach ($elements as $i => $element) {
            $this->checkElement($element, "$path.elements.$i", 1);
        }

        if ($this->elementCount > $this->schema->limit('max_elements_per_page')) {
            $this->fail("$path.elements", 'has more elements than the limit of '.$this->schema->limit('max_elements_per_page'));
        }
    }

    private function checkBackground(mixed $bg, string $path): void
    {
        if (! is_array($bg)) {
            $this->fail($path, 'must be an object');

            return;
        }

        $this->unknownKeys($bg, ['type', 'color', 'gradient', 'asset_id', 'fit'], $path);

        if (isset($bg['type']) && ! in_array($bg['type'], $this->schema->enum('background_type'), true)) {
            $this->fail("$path.type", 'is not a background type');
        }
        if (isset($bg['color'])) {
            $this->hex($bg['color'], "$path.color");
        }
        if (isset($bg['gradient'])) {
            $this->paint($bg['gradient'], "$path.gradient");
        }
        if (isset($bg['asset_id'])) {
            $this->fail("$path.asset_id", 'must be empty in a template (templates carry no pictures of their own)');
        }
        if (isset($bg['fit']) && ! in_array($bg['fit'], $this->schema->enum('image_fit'), true)) {
            $this->fail("$path.fit", 'is not an image fit');
        }
    }

    private function checkElement(mixed $el, string $path, int $depth): void
    {
        if (! is_array($el)) {
            $this->fail($path, 'must be an object');

            return;
        }

        $this->elementCount++;
        $type = $el['type'] ?? null;

        if (! in_array($type, $this->schema->enum('type'), true)) {
            $this->fail("$path.type", 'must be text, shape, image, icon or group');

            return;
        }

        $this->unknownKeys($el, array_merge(self::COMMON_KEYS, self::TYPE_KEYS[$type]), $path);

        $id = $el['id'] ?? null;
        if (! is_string($id) || ! preg_match($this->schema->pattern('uuid_or_id'), $id)) {
            $this->fail("$path.id", 'must be 1 to 64 letters, digits, dashes or underscores');
        } elseif (isset($this->ids[$id])) {
            $this->fail("$path.id", "[$id] is used twice on the page");
        } else {
            $this->ids[$id] = true;
        }

        $this->text($el, 'name', "$path.name", 0, (int) $this->schema->limit('max_name_length'), false);

        if (isset($el['role']) && ! in_array($el['role'], $this->schema->enum('role'), true)) {
            $this->fail("$path.role", 'is not a role');
        }

        foreach (['x' => [-40000, 40000, true], 'y' => [-40000, 40000, true], 'width' => [1, 20000, true], 'height' => [1, 20000, true], 'rotation' => [-360, 360, false], 'opacity' => [0, 1, false]] as $key => [$min, $max, $required]) {
            $this->number($el, $key, "$path.$key", $min, $max, $required);
        }

        foreach (['locked', 'hidden', 'flip_x', 'flip_y'] as $flag) {
            if (isset($el[$flag]) && ! is_bool($el[$flag])) {
                $this->fail("$path.$flag", 'must be true or false');
            }
        }

        if (isset($el['blend']) && ! in_array($el['blend'], $this->schema->enum('blend'), true)) {
            $this->fail("$path.blend", 'is not a blend mode');
        }

        if (isset($el['shadow'])) {
            $this->checkShadow($el['shadow'], "$path.shadow");
        }

        if (isset($el['animation'])) {
            $this->checkAnimation($el['animation'], "$path.animation");
        }

        $this->checkBind($el, $type, $path);
        $this->checkTokens($el, $type, $path);

        match ($type) {
            'text' => $this->checkText($el, $path),
            'shape' => $this->checkShape($el, $path),
            'image' => $this->checkImage($el, $path),
            'icon' => $this->checkIcon($el, $path),
            'group' => $this->checkGroup($el, $path, $depth),
        };
    }

    private function checkBind(array $el, string $type, string $path): void
    {
        if (! array_key_exists('bind', $el) || $el['bind'] === null) {
            return;
        }

        $bind = $el['bind'];
        $slots = (array) config('design_copy.slots');

        if (! is_string($bind) || ! preg_match($this->schema->pattern('bind_key'), $bind)) {
            $this->fail("$path.bind", 'must be a slot key such as business_name');

            return;
        }

        if (! isset($slots[$bind])) {
            $this->fail("$path.bind", "[$bind] is not a known slot (see config/design_copy.php)");

            return;
        }

        $slotType = $slots[$bind]['type'];

        if (($slotType === 'text' && $type !== 'text') || ($slotType === 'image' && $type !== 'image')) {
            $this->fail("$path.bind", "[$bind] is a $slotType slot and cannot be bound to a $type element");
        }
    }

    private function checkTokens(array $el, string $type, string $path): void
    {
        if (! isset($el['tokens'])) {
            return;
        }

        if (! is_array($el['tokens'])) {
            $this->fail("$path.tokens", 'must be an object');

            return;
        }

        foreach ($el['tokens'] as $prop => $ref) {
            if (! in_array($prop, self::TOKEN_PROPS, true)) {
                $this->fail("$path.tokens.$prop", 'is not a token-driven property (color, fill, stroke_color, shadow_color, font)');
            } elseif (! is_string($ref) || ! preg_match($this->schema->pattern('token_ref'), $ref)) {
                $this->fail("$path.tokens.$prop", 'must look like brand.colors.primary or brand.fonts.heading');
            } elseif ($prop === 'font' ? ! str_starts_with($ref, 'brand.fonts.') : ! str_starts_with($ref, 'brand.colors.')) {
                $this->fail("$path.tokens.$prop", 'points at the wrong kind of brand token');
            } elseif (! $this->tokenPropApplies($prop, $type, $el)) {
                $this->fail("$path.tokens.$prop", "does not apply to a $type element");
            }
        }
    }

    private function tokenPropApplies(string $prop, string $type, array $el): bool
    {
        return match ($prop) {
            'color' => in_array($type, ['text', 'icon'], true),
            'fill' => $type === 'shape',
            'font' => $type === 'text',
            'stroke_color' => in_array($type, ['text', 'shape'], true),
            'shadow_color' => true,
            default => false,
        };
    }

    private function checkText(array $el, string $path): void
    {
        if (! isset($el['text']) || ! is_string($el['text'])) {
            $this->fail("$path.text", 'is required and must be text');
        } elseif (mb_strlen($el['text']) > $this->schema->limit('max_text_length')) {
            $this->fail("$path.text", 'is too long');
        }

        if (isset($el['font']) && (! is_string($el['font']) || ! preg_match($this->schema->pattern('font_key'), $el['font']))) {
            $this->fail("$path.font", 'must be a font key');
        }

        $this->number($el, 'fontSize', "$path.fontSize", 4, 2000, false);
        $this->number($el, 'lineHeight', "$path.lineHeight", 0.6, 3, false);
        $this->number($el, 'letterSpacing', "$path.letterSpacing", -0.2, 1, false);

        if (isset($el['fontWeight']) && (! is_int($el['fontWeight']) || $el['fontWeight'] < 100 || $el['fontWeight'] > 900)) {
            $this->fail("$path.fontWeight", 'must be a whole number from 100 to 900');
        }

        foreach (['italic', 'underline', 'strike'] as $flag) {
            if (isset($el[$flag]) && ! is_bool($el[$flag])) {
                $this->fail("$path.$flag", 'must be true or false');
            }
        }

        foreach (['align' => 'text_align', 'valign' => 'text_valign', 'textCase' => 'text_case', 'autoFit' => 'auto_fit'] as $key => $enum) {
            if (isset($el[$key]) && ! in_array($el[$key], $this->schema->enum($enum), true)) {
                $this->fail("$path.$key", 'is not an allowed value');
            }
        }

        if (isset($el['color'])) {
            $this->hex($el['color'], "$path.color");
        }

        if (isset($el['stroke'])) {
            $this->checkStroke($el['stroke'], "$path.stroke", 40);
        }
    }

    private function checkShape(array $el, string $path): void
    {
        if (! in_array($el['kind'] ?? null, $this->schema->enum('shape_kind'), true)) {
            $this->fail("$path.kind", 'is required and must be a shape kind');
        }

        if (array_key_exists('fill', $el) && $el['fill'] !== null) {
            $this->paint($el['fill'], "$path.fill");
        }

        if (isset($el['stroke'])) {
            $this->checkStroke($el['stroke'], "$path.stroke", 100, true);
        }

        $this->number($el, 'radius', "$path.radius", 0, 5000, false);
        $this->number($el, 'inner', "$path.inner", 0.1, 0.95, false);

        foreach (['sides' => [3, 12], 'points' => [3, 16]] as $key => [$min, $max]) {
            if (isset($el[$key]) && (! is_int($el[$key]) || $el[$key] < $min || $el[$key] > $max)) {
                $this->fail("$path.$key", "must be a whole number from $min to $max");
            }
        }

        if (($el['kind'] ?? null) === 'path') {
            $d = $el['d'] ?? null;
            if (! is_string($d) || $d === '' || strlen($d) > 20000 || ! preg_match('/^[MmLlHhVvCcSsQqTtZz0-9eE+\-.,\s]+$/', $d)) {
                $this->fail("$path.d", 'must be SVG path data using only M L H V C S Q T Z commands');
            }
        } elseif (isset($el['d'])) {
            $this->fail("$path.d", 'is only for path shapes');
        }
    }

    private function checkImage(array $el, string $path): void
    {
        if (isset($el['asset_id'])) {
            $this->fail("$path.asset_id", 'must be empty in a template: a template holds an empty picture slot, never someone\'s upload');
        }

        foreach (['fit' => 'image_fit', 'mask' => 'image_mask'] as $key => $enum) {
            if (isset($el[$key]) && ! in_array($el[$key], $this->schema->enum($enum), true)) {
                $this->fail("$path.$key", 'is not an allowed value');
            }
        }

        $this->number($el, 'zoom', "$path.zoom", 1, 8, false);
        $this->number($el, 'radius', "$path.radius", 0, 5000, false);

        if (isset($el['focal'])) {
            if (! is_array($el['focal']) || ! isset($el['focal']['x'], $el['focal']['y'])
                || ! is_numeric($el['focal']['x']) || ! is_numeric($el['focal']['y'])
                || $el['focal']['x'] < 0 || $el['focal']['x'] > 1 || $el['focal']['y'] < 0 || $el['focal']['y'] > 1) {
                $this->fail("$path.focal", 'must be {x, y} between 0 and 1');
            }
        }

        if (isset($el['border'])) {
            $this->checkStroke($el['border'], "$path.border", 100);
        }

        if (isset($el['filters'])) {
            $ranges = ['brightness' => [0, 2], 'contrast' => [0, 2], 'saturate' => [0, 2], 'blur' => [0, 40], 'grayscale' => [0, 1], 'sepia' => [0, 1], 'hue' => [-180, 180]];
            if (! is_array($el['filters'])) {
                $this->fail("$path.filters", 'must be an object');
            } else {
                foreach ($el['filters'] as $name => $value) {
                    if (! isset($ranges[$name]) || ! is_numeric($value) || $value < $ranges[$name][0] || $value > $ranges[$name][1]) {
                        $this->fail("$path.filters.$name", 'is not a filter or is out of range');
                    }
                }
            }
        }
    }

    private function checkIcon(array $el, string $path): void
    {
        if (! isset($el['icon']) || ! is_string($el['icon']) || ! preg_match($this->schema->pattern('icon_key'), $el['icon'])) {
            $this->fail("$path.icon", 'is required and must be an icon key');
        }
        if (isset($el['color'])) {
            $this->hex($el['color'], "$path.color");
        }
        $this->number($el, 'strokeWidth', "$path.strokeWidth", 0.5, 6, false);
    }

    private function checkGroup(array $el, string $path, int $depth): void
    {
        $children = $el['children'] ?? null;

        if (! is_array($children) || $children === [] || ! array_is_list($children)) {
            $this->fail("$path.children", 'is required and must be a non-empty list');

            return;
        }

        if ($depth >= $this->schema->limit('max_group_depth')) {
            $this->fail("$path.children", 'nests groups deeper than the limit of '.$this->schema->limit('max_group_depth'));

            return;
        }

        foreach ($children as $i => $child) {
            $this->checkElement($child, "$path.children.$i", $depth + 1);
        }
    }

    private function checkShadow(mixed $shadow, string $path): void
    {
        if (! is_array($shadow)) {
            $this->fail($path, 'must be an object');

            return;
        }

        $this->unknownKeys($shadow, ['x', 'y', 'blur', 'color', 'opacity'], $path);
        $this->number($shadow, 'x', "$path.x", -200, 200, false);
        $this->number($shadow, 'y', "$path.y", -200, 200, false);
        $this->number($shadow, 'blur', "$path.blur", 0, 200, false);
        $this->number($shadow, 'opacity', "$path.opacity", 0, 1, false);
        if (isset($shadow['color'])) {
            $this->hex($shadow['color'], "$path.color");
        }
    }

    private function checkAnimation(mixed $animation, string $path): void
    {
        if (! is_array($animation)) {
            $this->fail($path, 'must be an object');

            return;
        }

        $this->unknownKeys($animation, ['in', 'out', 'loop'], $path);

        foreach (['in' => 'anim_preset_in', 'out' => 'anim_preset_out', 'loop' => 'anim_preset_loop'] as $phase => $enum) {
            if (! isset($animation[$phase])) {
                continue;
            }

            $a = $animation[$phase];
            if (! is_array($a) || ! in_array($a['preset'] ?? null, $this->schema->enum($enum), true)) {
                $this->fail("$path.$phase.preset", 'is not an allowed preset');
            }
            if (isset($a['easing']) && ! in_array($a['easing'], $this->schema->enum('anim_easing'), true)) {
                $this->fail("$path.$phase.easing", 'is not an allowed easing');
            }
            $this->number((array) $a, 'delay_ms', "$path.$phase.delay_ms", 0, 60000, false);
            $this->number((array) $a, 'duration_ms', "$path.$phase.duration_ms", 100, 10000, false);
        }
    }

    private function checkStroke(mixed $stroke, string $path, int $maxWidth, bool $shape = false): void
    {
        if (! is_array($stroke)) {
            $this->fail($path, 'must be an object');

            return;
        }

        $this->unknownKeys($stroke, $shape ? ['color', 'width', 'dash', 'cap'] : ['color', 'width'], $path);
        $this->hex($stroke['color'] ?? null, "$path.color");
        $this->number($stroke, 'width', "$path.width", 0, $maxWidth, true);

        if (isset($stroke['dash']) && ! in_array($stroke['dash'], $this->schema->enum('dash'), true)) {
            $this->fail("$path.dash", 'is not a dash style');
        }
        if (isset($stroke['cap']) && ! in_array($stroke['cap'], ['butt', 'round'], true)) {
            $this->fail("$path.cap", 'must be butt or round');
        }
    }

    private function paint(mixed $paint, string $path): void
    {
        if (is_string($paint)) {
            $this->hex($paint, $path);

            return;
        }

        if (! is_array($paint)) {
            $this->fail($path, 'must be a colour or a gradient');

            return;
        }

        $this->unknownKeys($paint, ['kind', 'angle', 'stops'], $path);

        if (! in_array($paint['kind'] ?? null, $this->schema->enum('gradient_kind'), true)) {
            $this->fail("$path.kind", 'must be linear or radial');
        }
        $this->number($paint, 'angle', "$path.angle", -360, 360, false);

        $stops = $paint['stops'] ?? null;
        if (! is_array($stops) || count($stops) < 2 || count($stops) > 6) {
            $this->fail("$path.stops", 'needs 2 to 6 colour stops');

            return;
        }

        foreach ($stops as $i => $stop) {
            if (! is_array($stop) || ! is_numeric($stop['at'] ?? null) || $stop['at'] < 0 || $stop['at'] > 1) {
                $this->fail("$path.stops.$i.at", 'must be a number from 0 to 1');
            }
            $this->hex(is_array($stop) ? ($stop['color'] ?? null) : null, "$path.stops.$i.color");
        }
    }

    private function checkSlots(mixed $slots, array $pages): void
    {
        if (! is_array($slots) || ! array_is_list($slots)) {
            $this->fail('slots', 'must be a list');

            return;
        }

        $bound = $this->boundKeys($pages);

        foreach ($slots as $i => $slot) {
            $key = is_array($slot) ? ($slot['key'] ?? null) : null;
            if (! is_string($key) || ! isset(config('design_copy.slots')[$key])) {
                $this->fail("slots.$i.key", 'is not a known slot');
            } elseif (! in_array($key, $bound, true)) {
                $this->fail("slots.$i.key", "[$key] is declared but no element is bound to it");
            }
        }
    }

    /** @return list<string> every bind key used in the pages (including inside groups) */
    public function boundKeys(array $pages): array
    {
        $keys = [];
        $walk = function (array $elements) use (&$walk, &$keys): void {
            foreach ($elements as $el) {
                if (! is_array($el)) {
                    continue;
                }
                if (! empty($el['bind']) && is_string($el['bind'])) {
                    $keys[] = $el['bind'];
                }
                if (isset($el['children']) && is_array($el['children'])) {
                    $walk($el['children']);
                }
            }
        };

        foreach ($pages as $page) {
            if (is_array($page) && is_array($page['elements'] ?? null)) {
                $walk($page['elements']);
            }
        }

        return array_values(array_unique($keys));
    }

    private function hex(mixed $value, string $path): void
    {
        if (! is_string($value) || ! preg_match($this->schema->pattern('hex'), $value)) {
            $this->fail($path, 'must be a hex colour such as #1a2b3c');
        }
    }

    private function number(array $source, string $key, string $path, int|float $min, int|float $max, bool $required): void
    {
        if (! array_key_exists($key, $source) || $source[$key] === null) {
            if ($required) {
                $this->fail($path, 'is required');
            }

            return;
        }

        $value = $source[$key];
        if ((! is_int($value) && ! is_float($value)) || is_nan((float) $value) || $value < $min || $value > $max) {
            $this->fail($path, "must be a number from $min to $max");
        }
    }

    private function text(array $source, string $key, string $path, int $min, int $max, bool $required): void
    {
        $value = $source[$key] ?? null;

        if ($value === null) {
            if ($required) {
                $this->fail($path, 'is required');
            }

            return;
        }

        if (! is_string($value) || mb_strlen($value) < $min || mb_strlen($value) > $max) {
            $this->fail($path, "must be text of $min to $max characters");
        }
    }

    /** @param list<string> $allowed */
    private function subset(array $source, string $key, array $allowed): void
    {
        if (! isset($source[$key])) {
            return;
        }

        if (! is_array($source[$key]) || array_diff($source[$key], $allowed) !== []) {
            $bad = is_array($source[$key]) ? implode(', ', array_diff($source[$key], $allowed)) : 'not a list';
            $this->fail($key, "has values that are not allowed ($bad); allowed: ".implode(', ', $allowed));
        }
    }

    /** @param list<string> $allowed */
    private function unknownKeys(array $source, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($source), $allowed);

        if ($unknown !== []) {
            $this->fail($path, 'has keys the document schema does not know: '.implode(', ', array_map('strval', $unknown)));
        }
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[] = "$path $message";
    }
}

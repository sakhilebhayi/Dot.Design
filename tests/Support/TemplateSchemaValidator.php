<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Strict validator for Dot.Design document-schema v2 as used by the template and element library.
 * It reads enums and limits from resources/design/document-schema.json (single source of truth)
 * and implements the per-property rules by hand. It is intentionally stricter than a sanitiser:
 * unknown keys, wrong types and out-of-range numbers are errors, not silently clamped.
 * The real App\Services\Design\DocumentSanitiser arrives from the backend branch; swap it in at merge.
 */
final class TemplateSchemaValidator
{
    public const BIND_KEYS = [
        'business_name', 'tagline', 'headline', 'subheadline', 'offer', 'price', 'date', 'time', 'venue', 'address', 'phone',
        'whatsapp', 'email', 'website', 'social_handle', 'logo', 'photo_1', 'photo_2', 'photo_3', 'cta', 'description',
    ];

    private array $schema;

    /** @var string[] */
    private array $errors = [];

    public function __construct(?string $schemaPath = null)
    {
        $this->schema = json_decode((string) file_get_contents($schemaPath ?? dirname(__DIR__, 2).'/resources/design/document-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return string[] */
    public function validateElements(array $elements, string $ctx = 'elements', ?float $boxW = null, ?float $boxH = null): array
    {
        $this->errors = [];
        $this->elements($elements, $ctx, 1);

        return $this->errors;
    }

    /** @return string[] */
    public function validatePage(array $page, string $ctx = 'page'): array
    {
        $this->errors = [];
        $this->page($page, $ctx);

        return $this->errors;
    }

    /** @return string[] */
    public function validateTemplate(array $t): array
    {
        $this->errors = [];
        $ctx = $t['slug'] ?? 'template';
        $allowed = ['slug', 'name', 'description', 'category', 'kind', 'industries', 'purposes', 'objectives', 'styles', 'platforms', 'format_key', 'width', 'height', 'pages', 'slots', 'popularity', 'status'];
        $this->noExtra($t, $allowed, $ctx);
        foreach ($allowed as $k) {
            if (! array_key_exists($k, $t)) {
                $this->err("{$ctx}: missing {$k}");
            }
        }
        if (! is_string($t['slug'] ?? null) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $t['slug'])) {
            $this->err("{$ctx}: bad slug");
        }
        foreach (['name', 'description', 'kind', 'format_key'] as $k) {
            if (! is_string($t[$k] ?? null) || trim($t[$k]) === '') {
                $this->err("{$ctx}: {$k} must be a non-empty string");
            }
        }
        if (! in_array($t['category'] ?? null, ['marketing', 'social', 'identity', 'commercial', 'document'], true)) {
            $this->err("{$ctx}: bad category");
        }
        foreach (['industries', 'purposes', 'objectives', 'styles', 'platforms'] as $k) {
            if (! is_array($t[$k] ?? null) || $t[$k] === [] || array_filter($t[$k], fn ($v) => ! is_string($v) || $v === '')) {
                $this->err("{$ctx}: {$k} must be a non-empty list of strings");
            }
        }
        foreach ($t['styles'] ?? [] as $s) {
            if (! in_array($s, ['bold', 'clean', 'elegant', 'playful', 'corporate', 'dark', 'earthy'], true)) {
                $this->err("{$ctx}: unknown style {$s}");
            }
        }
        if (! is_int($t['popularity'] ?? null) || $t['popularity'] < 0 || $t['popularity'] > 100) {
            $this->err("{$ctx}: popularity 0..100");
        }
        if (! is_array($t['pages'] ?? null) || $t['pages'] === [] || count($t['pages']) > $this->schema['limits']['max_pages']) {
            $this->err("{$ctx}: pages 1..".$this->schema['limits']['max_pages']);
        } else {
            foreach ($t['pages'] as $i => $p) {
                $this->page($p, "{$ctx}.pages[{$i}]");
            }
        }
        $size = strlen(json_encode($t));
        if ($size > $this->schema['limits']['max_json_bytes']) {
            $this->err("{$ctx}: document too large ({$size} bytes)");
        }
        // slots
        if (! is_array($t['slots'] ?? null)) {
            $this->err("{$ctx}: slots must be a list");
        } else {
            $seen = [];
            foreach ($t['slots'] as $s) {
                $this->noExtra($s, ['key', 'label', 'type', 'help', 'default'], "{$ctx}.slot");
                if (! in_array($s['key'] ?? null, self::BIND_KEYS, true)) {
                    $this->err("{$ctx}: slot key outside the vocabulary: ".json_encode($s['key'] ?? null));
                }
                if (isset($seen[$s['key'] ?? ''])) {
                    $this->err("{$ctx}: duplicate slot ".($s['key'] ?? ''));
                }
                $seen[$s['key'] ?? ''] = true;
                if (! is_string($s['label'] ?? null) || $s['label'] === '' || ! in_array($s['type'] ?? null, ['text', 'image'], true)) {
                    $this->err("{$ctx}: slot {$s['key']} needs label and type");
                }
            }
            $binds = [];
            foreach ($t['pages'] ?? [] as $p) {
                $this->collectBinds($p['elements'] ?? [], $binds);
            }
            foreach (array_keys($binds) as $b) {
                if (! isset($seen[$b])) {
                    $this->err("{$ctx}: bind {$b} has no slot");
                }
            }
            foreach (array_keys($seen) as $b) {
                if (! isset($binds[$b])) {
                    $this->err("{$ctx}: slot {$b} has no bound element");
                }
            }
        }

        return $this->errors;
    }

    private function collectBinds(array $els, array &$binds): void
    {
        foreach ($els as $e) {
            if (isset($e['bind'])) {
                $binds[$e['bind']] = true;
            }
            if (isset($e['children'])) {
                $this->collectBinds($e['children'], $binds);
            }
        }
    }

    private function err(string $m): void
    {
        $this->errors[] = $m;
    }

    private function noExtra(array $a, array $allowed, string $ctx): void
    {
        foreach (array_keys($a) as $k) {
            if (! in_array($k, $allowed, true)) {
                $this->err("{$ctx}: unknown key '{$k}'");
            }
        }
    }

    private function num(mixed $v, float $min, float $max, string $ctx): void
    {
        if (! (is_int($v) || is_float($v)) || is_nan((float) $v) || $v < $min || $v > $max) {
            $this->err("{$ctx}: number ".json_encode($v)." outside [{$min}, {$max}]");
        }
    }

    private function intIn(mixed $v, int $min, int $max, string $ctx): void
    {
        if (! is_int($v) || $v < $min || $v > $max) {
            $this->err("{$ctx}: integer ".json_encode($v)." outside [{$min}, {$max}]");
        }
    }

    private function enum(mixed $v, string $name, string $ctx): void
    {
        if (! in_array($v, $this->schema['enums'][$name], true)) {
            $this->err("{$ctx}: ".json_encode($v)." not in enum {$name}");
        }
    }

    private function hex(mixed $v, string $ctx): void
    {
        if (! is_string($v) || ! preg_match('/'.$this->schema['value_types']['hex'].'/', $v)) {
            $this->err("{$ctx}: not a hex colour: ".json_encode($v));
        }
    }

    private function bool(mixed $v, string $ctx): void
    {
        if (! is_bool($v)) {
            $this->err("{$ctx}: must be boolean");
        }
    }

    private function gradient(mixed $g, string $ctx): void
    {
        if (! is_array($g)) {
            $this->err("{$ctx}: gradient must be an object");

            return;
        }
        $this->noExtra($g, ['kind', 'angle', 'stops'], $ctx);
        $this->enum($g['kind'] ?? null, 'gradient_kind', "{$ctx}.kind");
        if (isset($g['angle'])) {
            $this->num($g['angle'], -360, 360, "{$ctx}.angle");
        }
        if (! is_array($g['stops'] ?? null) || count($g['stops']) < 2 || count($g['stops']) > 6) {
            $this->err("{$ctx}: gradient needs 2..6 stops");

            return;
        }
        foreach ($g['stops'] as $s) {
            $this->noExtra($s, ['at', 'color'], "{$ctx}.stop");
            $this->num($s['at'] ?? null, 0, 1, "{$ctx}.stop.at");
            $this->hex($s['color'] ?? null, "{$ctx}.stop.color");
        }
    }

    private function page(array $p, string $ctx): void
    {
        $L = $this->schema['limits'];
        $this->noExtra($p, ['name', 'width', 'height', 'background', 'duration_ms', 'transition', 'guides', 'elements'], $ctx);
        if (isset($p['name']) && (! is_string($p['name']) || mb_strlen($p['name']) > $L['max_name_length'])) {
            $this->err("{$ctx}: bad name");
        }
        $this->num($p['width'] ?? null, 16, 20000, "{$ctx}.width");
        $this->num($p['height'] ?? null, 16, 20000, "{$ctx}.height");
        $bg = $p['background'] ?? null;
        if (! is_array($bg)) {
            $this->err("{$ctx}: background required");
        } else {
            $this->noExtra($bg, ['type', 'color', 'gradient', 'asset_id', 'fit'], "{$ctx}.background");
            $this->enum($bg['type'] ?? null, 'background_type', "{$ctx}.background.type");
            $this->hex($bg['color'] ?? null, "{$ctx}.background.color");
            if (($bg['type'] ?? '') === 'gradient') {
                $this->gradient($bg['gradient'] ?? null, "{$ctx}.background.gradient");
            }
            if (($bg['type'] ?? '') === 'image') {
                $this->err("{$ctx}: library pages may not use image backgrounds");
            }
        }
        if (isset($p['duration_ms'])) {
            $this->intIn($p['duration_ms'], 500, $L['max_page_duration_ms'], "{$ctx}.duration_ms");
        }
        if (isset($p['transition'])) {
            $this->enum($p['transition'], 'page_transition', "{$ctx}.transition");
        }
        if (! is_array($p['elements'] ?? null)) {
            $this->err("{$ctx}: elements required");

            return;
        }
        if (count($p['elements']) > $L['max_elements_per_page']) {
            $this->err("{$ctx}: too many elements");
        }
        $this->elements($p['elements'], $ctx, 1);
    }

    private function elements(array $els, string $ctx, int $depth): void
    {
        if ($depth > $this->schema['limits']['max_group_depth']) {
            $this->err("{$ctx}: group depth exceeded");

            return;
        }
        $ids = [];
        foreach ($els as $i => $e) {
            $c = "{$ctx}[{$i}]";
            if (! is_array($e)) {
                $this->err("{$c}: not an object");

                continue;
            }
            $this->element($e, $c, $depth);
            if (isset($e['id'])) {
                if (isset($ids[$e['id']])) {
                    $this->err("{$c}: duplicate id {$e['id']}");
                }
                $ids[$e['id']] = true;
            }
        }
    }

    private const COMMON = ['id', 'type', 'name', 'role', 'bind', 'x', 'y', 'width', 'height', 'rotation', 'opacity', 'locked', 'hidden', 'flip_x', 'flip_y', 'blend', 'shadow', 'animation', 'tokens'];

    private const TYPE_KEYS = [
        'text' => ['text', 'font', 'fontSize', 'fontWeight', 'italic', 'underline', 'strike', 'align', 'valign', 'lineHeight', 'letterSpacing', 'textCase', 'color', 'autoFit', 'stroke'],
        'shape' => ['kind', 'fill', 'stroke', 'radius', 'sides', 'points', 'inner', 'd'],
        'image' => ['asset_id', 'fit', 'focal', 'zoom', 'mask', 'radius', 'border', 'filters'],
        'icon' => ['icon', 'color', 'strokeWidth'],
        'group' => ['children'],
    ];

    private function element(array $e, string $ctx, int $depth): void
    {
        $L = $this->schema['limits'];
        $type = $e['type'] ?? null;
        $this->enum($type, 'type', "{$ctx}.type");
        if (! isset(self::TYPE_KEYS[$type])) {
            return;
        }
        $this->noExtra($e, array_merge(self::COMMON, self::TYPE_KEYS[$type]), $ctx);
        if (! is_string($e['id'] ?? null) || ! preg_match('/'.$this->schema['value_types']['uuid_or_id'].'/', $e['id'])) {
            $this->err("{$ctx}: bad id");
        }
        if (isset($e['name']) && (! is_string($e['name']) || mb_strlen($e['name']) > $L['max_name_length'])) {
            $this->err("{$ctx}: bad name");
        }
        if (isset($e['role'])) {
            $this->enum($e['role'], 'role', "{$ctx}.role");
        }
        if (isset($e['bind']) && ! (is_string($e['bind']) && preg_match('/'.$this->schema['value_types']['bind_key'].'/', $e['bind']) && in_array($e['bind'], self::BIND_KEYS, true))) {
            $this->err("{$ctx}: bad bind");
        }
        $this->num($e['x'] ?? null, -40000, 40000, "{$ctx}.x");
        $this->num($e['y'] ?? null, -40000, 40000, "{$ctx}.y");
        $this->num($e['width'] ?? null, $L['min_size'], $L['max_size'], "{$ctx}.width");
        $this->num($e['height'] ?? null, $L['min_size'], $L['max_size'], "{$ctx}.height");
        if (isset($e['rotation'])) {
            $this->num($e['rotation'], -360, 360, "{$ctx}.rotation");
        }
        if (isset($e['opacity'])) {
            $this->num($e['opacity'], 0, 1, "{$ctx}.opacity");
        }
        foreach (['locked', 'hidden', 'flip_x', 'flip_y'] as $b) {
            if (isset($e[$b])) {
                $this->bool($e[$b], "{$ctx}.{$b}");
            }
        }
        if (isset($e['blend'])) {
            $this->enum($e['blend'], 'blend', "{$ctx}.blend");
        }
        if (isset($e['shadow'])) {
            $s = $e['shadow'];
            $this->noExtra($s, ['x', 'y', 'blur', 'color', 'opacity'], "{$ctx}.shadow");
            $this->num($s['x'] ?? null, -200, 200, "{$ctx}.shadow.x");
            $this->num($s['y'] ?? null, -200, 200, "{$ctx}.shadow.y");
            $this->num($s['blur'] ?? null, 0, 200, "{$ctx}.shadow.blur");
            $this->hex($s['color'] ?? null, "{$ctx}.shadow.color");
            $this->num($s['opacity'] ?? null, 0, 1, "{$ctx}.shadow.opacity");
        }
        if (isset($e['animation'])) {
            $this->animation($e['animation'], "{$ctx}.animation");
        }
        if (isset($e['tokens'])) {
            $this->tokens($e, $ctx);
        }
        match ($type) {
            'text' => $this->text($e, $ctx),
            'shape' => $this->shape($e, $ctx),
            'image' => $this->image($e, $ctx),
            'icon' => $this->icon($e, $ctx),
            'group' => $this->group($e, $ctx, $depth),
        };
    }

    /** Brand tokens: only on props the element really has; colours never on gradients. */
    private function tokens(array $e, string $ctx): void
    {
        if (! is_array($e['tokens']) || $e['tokens'] === []) {
            $this->err("{$ctx}: tokens must be a non-empty object");

            return;
        }
        foreach ($e['tokens'] as $prop => $ref) {
            if (! is_string($ref) || ! preg_match('/'.$this->schema['value_types']['token_ref'].'/', $ref)) {
                $this->err("{$ctx}: bad token ref ".json_encode($ref));

                continue;
            }
            $isFont = str_starts_with($ref, 'brand.fonts.');
            $ok = match ($prop) {
                'color' => in_array($e['type'], ['text', 'icon'], true) && ! $isFont,
                'fill' => $e['type'] === 'shape' && is_string($e['fill'] ?? null) && ! $isFont,
                'stroke_color' => $e['type'] === 'shape' && isset($e['stroke']) && ! $isFont,
                'shadow_color' => isset($e['shadow']) && ! $isFont,
                'font' => $e['type'] === 'text' && $isFont,
                default => false,
            };
            if (! $ok) {
                $this->err("{$ctx}: token {$prop} => {$ref} is not valid on this element");
            }
        }
    }

    private function animation(array $a, string $ctx): void
    {
        $this->noExtra($a, ['in', 'out', 'loop'], $ctx);
        foreach (['in' => 'anim_preset_in', 'out' => 'anim_preset_out'] as $k => $enum) {
            if (isset($a[$k])) {
                $x = $a[$k];
                $this->noExtra($x, ['preset', 'delay_ms', 'duration_ms', 'easing'], "{$ctx}.{$k}");
                $this->enum($x['preset'] ?? null, $enum, "{$ctx}.{$k}.preset");
                if (isset($x['delay_ms'])) {
                    $this->intIn($x['delay_ms'], 0, 60000, "{$ctx}.{$k}.delay_ms");
                }
                if (isset($x['duration_ms'])) {
                    $this->intIn($x['duration_ms'], 100, 10000, "{$ctx}.{$k}.duration_ms");
                }
                if (isset($x['easing'])) {
                    $this->enum($x['easing'], 'anim_easing', "{$ctx}.{$k}.easing");
                }
            }
        }
        if (isset($a['loop'])) {
            $this->noExtra($a['loop'], ['preset', 'duration_ms'], "{$ctx}.loop");
            $this->enum($a['loop']['preset'] ?? null, 'anim_preset_loop', "{$ctx}.loop.preset");
            if (isset($a['loop']['duration_ms'])) {
                $this->intIn($a['loop']['duration_ms'], 300, 10000, "{$ctx}.loop.duration_ms");
            }
        }
    }

    private function text(array $e, string $ctx): void
    {
        if (! is_string($e['text'] ?? null) || mb_strlen($e['text']) > $this->schema['limits']['max_text_length']) {
            $this->err("{$ctx}: bad text");
        }
        if (isset($e['font']) && ! (is_string($e['font']) && preg_match('/'.$this->schema['value_types']['font_key'].'/', $e['font']))) {
            $this->err("{$ctx}: bad font key");
        }
        if (isset($e['fontSize'])) {
            $this->num($e['fontSize'], 4, 2000, "{$ctx}.fontSize");
        }
        if (isset($e['fontWeight'])) {
            $this->intIn($e['fontWeight'], 100, 900, "{$ctx}.fontWeight");
        }
        foreach (['italic', 'underline', 'strike'] as $b) {
            if (isset($e[$b])) {
                $this->bool($e[$b], "{$ctx}.{$b}");
            }
        }
        foreach (['align' => 'text_align', 'valign' => 'text_valign', 'textCase' => 'text_case', 'autoFit' => 'auto_fit'] as $k => $enum) {
            if (isset($e[$k])) {
                $this->enum($e[$k], $enum, "{$ctx}.{$k}");
            }
        }
        if (isset($e['lineHeight'])) {
            $this->num($e['lineHeight'], 0.6, 3, "{$ctx}.lineHeight");
        }
        if (isset($e['letterSpacing'])) {
            $this->num($e['letterSpacing'], -0.2, 1, "{$ctx}.letterSpacing");
        }
        if (isset($e['color'])) {
            $this->hex($e['color'], "{$ctx}.color");
        }
        if (isset($e['stroke'])) {
            $this->noExtra($e['stroke'], ['color', 'width'], "{$ctx}.stroke");
            $this->hex($e['stroke']['color'] ?? null, "{$ctx}.stroke.color");
            $this->num($e['stroke']['width'] ?? null, 0, 40, "{$ctx}.stroke.width");
        }
    }

    private function shape(array $e, string $ctx): void
    {
        $this->enum($e['kind'] ?? null, 'shape_kind', "{$ctx}.kind");
        if (array_key_exists('fill', $e) && $e['fill'] !== null) {
            if (is_array($e['fill'])) {
                $this->gradient($e['fill'], "{$ctx}.fill");
            } else {
                $this->hex($e['fill'], "{$ctx}.fill");
            }
        }
        if (isset($e['stroke'])) {
            $s = $e['stroke'];
            $this->noExtra($s, ['color', 'width', 'dash', 'cap'], "{$ctx}.stroke");
            $this->hex($s['color'] ?? null, "{$ctx}.stroke.color");
            $this->num($s['width'] ?? null, 0, 100, "{$ctx}.stroke.width");
            if (isset($s['dash'])) {
                $this->enum($s['dash'], 'dash', "{$ctx}.stroke.dash");
            }
            if (isset($s['cap']) && ! in_array($s['cap'], ['butt', 'round'], true)) {
                $this->err("{$ctx}: bad cap");
            }
        }
        if (isset($e['radius'])) {
            $this->num($e['radius'], 0, 5000, "{$ctx}.radius");
        }
        if (isset($e['sides'])) {
            $this->intIn($e['sides'], 3, 12, "{$ctx}.sides");
        }
        if (isset($e['points'])) {
            $this->intIn($e['points'], 3, 16, "{$ctx}.points");
        }
        if (isset($e['inner'])) {
            $this->num($e['inner'], 0.1, 0.95, "{$ctx}.inner");
        }
        if (($e['kind'] ?? '') === 'path') {
            if (! is_string($e['d'] ?? null) || strlen($e['d']) > 20000) {
                $this->err("{$ctx}: path needs d");
            } else {
                foreach (self::pathProblems($e['d'], (float) $e['width'], (float) $e['height'], 1.0) as $m) {
                    $this->err("{$ctx}: path {$m}");
                }
            }
        } elseif (isset($e['d'])) {
            $this->err("{$ctx}: d only on path shapes");
        }
    }

    private function image(array $e, string $ctx): void
    {
        if (array_key_exists('asset_id', $e) && $e['asset_id'] !== null && ! is_int($e['asset_id'])) {
            $this->err("{$ctx}: asset_id");
        }
        if (isset($e['fit'])) {
            $this->enum($e['fit'], 'image_fit', "{$ctx}.fit");
        }
        if (isset($e['mask'])) {
            $this->enum($e['mask'], 'image_mask', "{$ctx}.mask");
        }
        if (isset($e['radius'])) {
            $this->num($e['radius'], 0, 5000, "{$ctx}.radius");
        }
        if (isset($e['zoom'])) {
            $this->num($e['zoom'], 1, 8, "{$ctx}.zoom");
        }
        if (isset($e['border'])) {
            $this->noExtra($e['border'], ['color', 'width'], "{$ctx}.border");
            $this->hex($e['border']['color'] ?? null, "{$ctx}.border.color");
            $this->num($e['border']['width'] ?? null, 0, 100, "{$ctx}.border.width");
        }
        if (isset($e['focal'])) {
            $this->num($e['focal']['x'] ?? null, 0, 1, "{$ctx}.focal.x");
            $this->num($e['focal']['y'] ?? null, 0, 1, "{$ctx}.focal.y");
        }
        if (isset($e['filters'])) {
            $this->noExtra($e['filters'], ['brightness', 'contrast', 'saturate', 'blur', 'grayscale', 'sepia', 'hue'], "{$ctx}.filters");
        }
    }

    private function icon(array $e, string $ctx): void
    {
        if (! is_string($e['icon'] ?? null) || ! preg_match('/'.$this->schema['value_types']['icon_key'].'/', $e['icon'])) {
            $this->err("{$ctx}: bad icon key");
        }
        if (isset($e['color'])) {
            $this->hex($e['color'], "{$ctx}.color");
        }
        if (isset($e['strokeWidth'])) {
            $this->num($e['strokeWidth'], 0.5, 6, "{$ctx}.strokeWidth");
        }
    }

    private function group(array $e, string $ctx, int $depth): void
    {
        if (! is_array($e['children'] ?? null) || $e['children'] === []) {
            $this->err("{$ctx}: group needs children");

            return;
        }
        $this->elements($e['children'], "{$ctx}.children", $depth + 1);
    }

    /**
     * Path grammar: commands M L H V C S Q T Z only (absolute or relative), correct argument counts,
     * a leading M, and (if $w/$h given) every point inside the box plus tolerance.
     *
     * @return string[]
     */
    public static function pathProblems(string $d, float $w, float $h, float $tol = 0.0): array
    {
        $out = [];
        if (! preg_match('/^[MLHVCSQTZmlhvcsqtz0-9eE.,\s+\-]*$/', $d)) {
            return ['contains characters outside the allowed command set'];
        }
        preg_match_all('/([MLHVCSQTZmlhvcsqtz])|(-?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/', $d, $m, PREG_SET_ORDER);
        $argc = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'Z' => 0];
        $cmd = null;
        $args = [];
        $cx = $cy = $sx = $sy = 0.0;
        $started = false;
        $flush = function () use (&$cmd, &$args, &$cx, &$cy, &$sx, &$sy, &$out, &$started, $argc, $w, $h, $tol) {
            if ($cmd === null) {
                return;
            }
            $U = strtoupper($cmd);
            $rel = $cmd !== $U;
            $n = $argc[$U];
            if ($n === 0) {
                if ($args !== []) {
                    $out[] = 'Z takes no arguments';
                }
                $cx = $sx;
                $cy = $sy;

                return;
            }
            if (count($args) === 0 || count($args) % $n !== 0) {
                $out[] = "{$cmd} has ".count($args)." arguments (needs a multiple of {$n})";

                return;
            }
            foreach (array_chunk($args, $n) as $k => $a) {
                $pts = [];
                if ($U === 'H') {
                    $cx = $rel ? $cx + $a[0] : $a[0];
                    $pts[] = [$cx, $cy];
                } elseif ($U === 'V') {
                    $cy = $rel ? $cy + $a[0] : $a[0];
                    $pts[] = [$cx, $cy];
                } else {
                    for ($i = 0; $i < $n; $i += 2) {
                        $px = $rel ? $cx + $a[$i] : $a[$i];
                        $py = $rel ? $cy + $a[$i + 1] : $a[$i + 1];
                        $pts[] = [$px, $py];
                    }
                    [$cx, $cy] = end($pts);
                    if ($U === 'M' && $k === 0) {
                        $sx = $cx;
                        $sy = $cy;
                    }
                }
                foreach ($pts as [$px, $py]) {
                    if ($px < -$tol || $py < -$tol || $px > $w + $tol || $py > $h + $tol) {
                        $out[] = sprintf('point (%.2f, %.2f) outside the %gx%g box', $px, $py, $w, $h);
                    }
                }
            }
            $started = true;
        };
        foreach ($m as $tok) {
            if ($tok[1] !== '') {
                $flush();
                $cmd = $tok[1];
                $args = [];
                if (! $started && strtoupper($cmd) !== 'M' && $out === [] && ! isset($firstChecked)) {
                    $out[] = 'must start with M';
                    $firstChecked = true;
                }
                $started = $started || strtoupper($cmd) === 'M';
            } else {
                if ($cmd === null) {
                    $out[] = 'number before any command';
                    break;
                }
                $args[] = (float) $tok[2];
            }
        }
        $flush();

        return array_values(array_unique($out));
    }
}

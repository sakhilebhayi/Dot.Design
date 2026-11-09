<?php

namespace App\Services\Design;

use Illuminate\Support\Str;

/**
 * Validates, normalises and migrates a page document. The client is never trusted: every number is clamped to the
 * schema's range, every string is cleaned, every enum and colour is checked, unknown keys are dropped, the limits in
 * document-schema.json are enforced, and an image may only point at an asset the saving user is allowed to place.
 *
 * Three outcomes for a bad value:
 *  - a correction (counted, never reported with the value): wrong type, out of range, unknown enum/font/colour, a
 *    duplicate or malformed element id, an unknown key.
 *  - an error (the save is refused with field-level 422 messages): an unknown element type, text over the limit,
 *    unusable path data, an unusable image reference.
 *  - a rejection (nothing is stored): too many elements, groups nested too deep, a document over the byte ceiling (413).
 *
 * Reads use DocumentContext::lenient(): the same normalisation, but nothing throws and an unusable element is dropped
 * and counted instead, so opening an old project can never fail.
 */
final class DocumentSanitiser
{
    private const PATH_ARITY = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'Z' => 0];

    private const TOKEN_KEYS = ['color', 'fill', 'stroke_color', 'shadow_color'];

    private DocumentSchema $schema;

    /** @var list<string> */
    private array $fontKeys;

    public function __construct(?DocumentSchema $schema = null)
    {
        $this->schema = $schema ?? DocumentSchema::shared();
        $this->fontKeys = $this->schema->fontKeys();
    }

    /**
     * Sanitise a page payload: any of name, width, height, background, duration_ms, transition, guides and elements.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws DocumentRejected
     */
    public function sanitisePage(array $input, ?DocumentContext $context = null): SanitisedPage
    {
        $context ??= new DocumentContext;
        $run = new SanitiseRun($context->strict);
        $fields = [];
        $elements = null;
        $bytes = 0;

        if (array_key_exists('elements', $input)) {
            $elements = $this->elementList($input['elements'], $run);
            $bytes = strlen((string) json_encode($elements, JSON_UNESCAPED_UNICODE));

            if ($context->strict && $bytes > (int) $this->schema->limit('max_json_bytes')) {
                throw new DocumentRejected(
                    'This page has too much content to save ('.round($this->schema->limit('max_json_bytes') / 1024).' KB limit). Remove some elements or smaller pictures and try again.',
                    ['elements' => ['This page has too much content to save.']],
                    413,
                );
            }
        }

        $this->pageFields($input, $fields, $run);
        $this->checkAssets($run, $context);

        if ($run->failed()) {
            throw new DocumentRejected($this->summary($run), $run->errors);
        }

        return new SanitisedPage($fields, $elements, $run->corrections, $run->nodes, $bytes);
    }

    /**
     * Bring any element list up to schema v2 without validating it: v1 rectangles become shapes and v1 text gains a
     * font. Idempotent. This is what a read does before handing a page to the editor; nothing is written back.
     *
     * @param  array<int, mixed>  $elements
     * @return list<array<string, mixed>>
     */
    public function migrateElements(array $elements): array
    {
        $out = [];

        foreach ($elements as $element) {
            if (is_array($element)) {
                $out[] = $this->migrateElement($element);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public function migrateElement(array $element): array
    {
        $type = $element['type'] ?? null;

        if ($type === 'rectangle') {
            $element['type'] = 'shape';
            $element['kind'] = 'rect';
            $element['fill'] ??= '#cccccc';
        } elseif ($type === 'text') {
            $element['font'] ??= DocumentSchema::DEFAULT_FONT;
        } elseif ($type === 'group' && is_array($element['children'] ?? null)) {
            $element['children'] = $this->migrateElements($element['children']);
        }

        return $element;
    }

    /**
     * Normalise elements read back from storage (lenient): the clean v2 list plus how many had to be corrected or dropped.
     *
     * @param  array<int, mixed>|null  $elements
     * @return array{elements: list<array<string, mixed>>, corrections: int}
     */
    public function readElements(?array $elements): array
    {
        $run = new SanitiseRun(false);
        $clean = $this->elementList(array_values($elements ?? []), $run);

        return ['elements' => $clean, 'corrections' => array_sum($run->corrections)];
    }

    // ---------------------------------------------------------------- page

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $fields
     */
    private function pageFields(array $input, array &$fields, SanitiseRun $run): void
    {
        if (array_key_exists('name', $input)) {
            $fields['name'] = $this->label($input['name'], 'page_name', $run);
        }

        foreach (['width', 'height'] as $side) {
            if (array_key_exists($side, $input)) {
                $fields[$side] = $input[$side] === null ? null : $this->number($input, $side, $this->schema->pageSpec($side), $run, true);
            }
        }

        if (array_key_exists('duration_ms', $input)) {
            $fields['duration_ms'] = $this->number($input, 'duration_ms', $this->schema->pageSpec('duration_ms'), $run, true);
        }

        if (array_key_exists('transition', $input)) {
            $fields['transition'] = $this->enum($input['transition'], 'page_transition', 'none', $run);
        }

        if (array_key_exists('guides', $input)) {
            $fields['guides'] = $this->guides($input['guides'], $run);
        }

        if (array_key_exists('background', $input)) {
            $fields['background'] = $this->background($input['background'], $run);
        }
    }

    /** @return list<array{axis: string, pos: int|float}> */
    private function guides(mixed $guides, SanitiseRun $run): array
    {
        if (! is_array($guides) || ! array_is_list($guides)) {
            $run->fix('type');

            return [];
        }

        $max = (int) ($this->schema->pageSpec('guides')['max'] ?? 40);
        $coord = (float) $this->schema->limit('max_coord');
        $out = [];

        foreach ($guides as $guide) {
            if (count($out) >= $max) {
                $run->fix('limit');
                break;
            }

            if (! is_array($guide) || ! in_array($guide['axis'] ?? null, ['x', 'y'], true)) {
                $run->fix('type');

                continue;
            }

            $out[] = ['axis' => $guide['axis'], 'pos' => $this->number($guide, 'pos', ['min' => -$coord, 'max' => $coord, 'default' => 0], $run)];
        }

        return $out;
    }

    /** @return array{type: string, color: string, gradient: array<string, mixed>|null, asset_id: int|null, fit: string} */
    private function background(mixed $background, SanitiseRun $run): array
    {
        $out = ['type' => 'solid', 'color' => '#ffffff', 'gradient' => null, 'asset_id' => null, 'fit' => 'cover'];

        if (! is_array($background)) {
            if ($background !== null) {
                $run->fix('type');
            }

            return $out;
        }

        $type = $this->enum($background['type'] ?? 'solid', 'background_type', 'solid', $run);
        $out['color'] = $this->hex($background['color'] ?? null, '#ffffff', $run);
        $out['fit'] = $this->enum($background['fit'] ?? 'cover', 'image_fit', 'cover', $run);

        if ($type === 'gradient') {
            $gradient = $this->gradient($background['gradient'] ?? null, $run);
            $type = $gradient === null ? 'solid' : 'gradient';
            $out['gradient'] = $gradient;
        }

        if ($type === 'image') {
            $assetId = $this->assetId($background['asset_id'] ?? null);

            if ($assetId === null) {
                $run->fix('background_image_dropped');
                $type = 'solid';
            } else {
                $out['asset_id'] = $assetId;
                $run->assetRefs[] = ['path' => 'background.asset_id', 'id' => $assetId];
            }
        }

        $out['type'] = $type;

        return $out;
    }

    // ------------------------------------------------------------ elements

    /**
     * @return list<array<string, mixed>>
     */
    private function elementList(mixed $elements, SanitiseRun $run): array
    {
        if (! is_array($elements) || ! array_is_list($elements)) {
            if ($run->strict) {
                throw new DocumentRejected('The page elements must be a list.', ['elements' => ['The page elements must be a list.']]);
            }

            return [];
        }

        $max = (int) $this->schema->limit('max_elements_per_page');

        if ($run->strict && count($elements) > $max) {
            throw new DocumentRejected("A page can hold at most {$max} elements.", ['elements' => ["A page can hold at most {$max} elements."]]);
        }

        $out = [];

        foreach ($elements as $i => $element) {
            $clean = $this->element($element, "elements.{$i}", 1, $run);

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function element(mixed $element, string $path, int $depth, SanitiseRun $run): ?array
    {
        $max = (int) $this->schema->limit('max_elements_per_page');

        if (++$run->nodes > $max && $run->strict) {
            throw new DocumentRejected("A page can hold at most {$max} elements.", ['elements' => ["A page can hold at most {$max} elements."]]);
        }

        if (! is_array($element) || array_is_list($element) && $element !== []) {
            return $this->unusable($run, $path, 'This element is not valid.');
        }

        $element = $this->migrateElement($element);
        $type = $element['type'] ?? null;

        if (! $this->schema->inEnum('type', $type)) {
            return $this->unusable($run, "{$path}.type", 'This element has an unknown type.');
        }

        if ($type === 'group' && $depth > (int) $this->schema->limit('max_group_depth')) {
            if (! $run->strict) {
                // A read never throws: a stored group nested too deep is dropped and counted (R-45 security S-4).
                return $this->unusable($run, $path, 'Groups are nested too deeply.');
            }

            throw new DocumentRejected('Groups can be nested at most '.$this->schema->limit('max_group_depth').' levels deep.', [$path => ['Groups are nested too deeply.']]);
        }

        $out = $this->common($element, $run);

        $specific = match ($type) {
            'text' => $this->text($element, $path, $run),
            'shape' => $this->shape($element, $path, $run),
            'image' => $this->image($element, $path, $run),
            'icon' => $this->icon($element, $path, $run),
            default => $this->group($element, $path, $depth, $run),
        };

        if ($specific === null) {
            return null;
        }

        // Keys this element type does not carry (v1 leftovers such as `fill` on text, or anything invented) are dropped.
        if (array_diff(array_keys($element), array_keys($out), array_keys($specific)) !== []) {
            $run->fix('unknown_keys');
        }

        return $out + $specific;
    }

    private function unusable(SanitiseRun $run, string $path, string $message): null
    {
        if ($run->strict) {
            $run->error($path, $message);
        } else {
            $run->fix('dropped_invalid');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function common(array $e, SanitiseRun $run): array
    {
        $out = [];
        $id = $e['id'] ?? null;

        if (! $this->schema->matches('uuid_or_id', $id) || isset($run->seenIds[$id])) {
            $run->fix('id_regenerated');
            $id = (string) Str::uuid();
        }

        $run->seenIds[$id] = true;
        $out['id'] = $id;
        $out['type'] = $e['type'];
        $out['name'] = $this->label($e['name'] ?? null, 'name', $run);
        $out['role'] = $this->enum($e['role'] ?? 'other', 'role', 'other', $run);

        $bind = $e['bind'] ?? null;
        $out['bind'] = $bind === null ? null : ($this->schema->matches('bind_key', $bind) ? $bind : $this->dropped($run, 'bind'));

        foreach (['x', 'y', 'width', 'height'] as $key) {
            $out[$key] = $this->number($e, $key, $this->schema->spec('common', $key) + ['default' => in_array($key, ['width', 'height'], true) ? 100 : 0], $run, false, true);
        }

        $out['rotation'] = $this->number($e, 'rotation', $this->schema->spec('common', 'rotation'), $run);
        $out['opacity'] = $this->number($e, 'opacity', $this->schema->spec('common', 'opacity'), $run);

        foreach (['locked', 'hidden', 'flip_x', 'flip_y'] as $flag) {
            $out[$flag] = $this->bool($e[$flag] ?? false, $run);
        }

        $out['blend'] = $this->enum($e['blend'] ?? 'normal', 'blend', 'normal', $run);
        $out['shadow'] = $this->shadow($e['shadow'] ?? null, $run);
        $out['animation'] = $this->animation($e['animation'] ?? null, $run);
        $out['tokens'] = $this->tokens($e['tokens'] ?? null, $run);

        return $out;
    }

    private function dropped(SanitiseRun $run, string $code): null
    {
        $run->fix($code);

        return null;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>|null
     */
    private function text(array $e, string $path, SanitiseRun $run): ?array
    {
        $raw = $e['text'] ?? '';

        if (is_float($raw) && ! is_finite($raw)) {
            // INF and NAN have no text form (PHP 8.5 warns when they are coerced); they are not text.
            $raw = '';
            $run->fix('type');
        } elseif (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
            $run->fix('type');
        } elseif (! is_string($raw)) {
            $raw = '';
            $run->fix('type');
        }

        $text = $this->cleanText($raw, true, $run);
        $limit = (int) $this->schema->limit('max_text_length');

        if (mb_strlen($text) > $limit) {
            return $this->unusable($run, "{$path}.text", "Text can be at most {$limit} characters.");
        }

        $font = $e['font'] ?? DocumentSchema::DEFAULT_FONT;

        if (! is_string($font) || ! in_array($font, $this->fontKeys, true)) {
            $run->fix('font');
            $font = DocumentSchema::DEFAULT_FONT;
        }

        $s = fn (string $prop) => $this->schema->spec('text', $prop);

        $stroke = $this->object($e['stroke'] ?? null, $run);

        return [
            'text' => $text,
            'font' => $font,
            'fontSize' => $this->number($e, 'fontSize', $s('fontSize'), $run),
            'fontWeight' => $this->number($e, 'fontWeight', $s('fontWeight'), $run, true),
            'italic' => $this->bool($e['italic'] ?? false, $run),
            'underline' => $this->bool($e['underline'] ?? false, $run),
            'strike' => $this->bool($e['strike'] ?? false, $run),
            'align' => $this->enum($e['align'] ?? 'left', 'text_align', 'left', $run),
            'valign' => $this->enum($e['valign'] ?? 'top', 'text_valign', 'top', $run),
            'lineHeight' => $this->number($e, 'lineHeight', $s('lineHeight'), $run),
            'letterSpacing' => $this->number($e, 'letterSpacing', $s('letterSpacing'), $run),
            'textCase' => $this->enum($e['textCase'] ?? 'none', 'text_case', 'none', $run),
            'color' => $this->hex($e['color'] ?? null, '#18181b', $run),
            'autoFit' => $this->enum($e['autoFit'] ?? 'none', 'auto_fit', 'none', $run),
            'stroke' => $stroke === null ? null : [
                'color' => $this->hex($stroke['color'] ?? null, '#000000', $run),
                'width' => $this->number($stroke, 'width', $s('stroke')['props']['width'] ?? ['min' => 0, 'max' => 40, 'default' => 1], $run),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>|null
     */
    private function shape(array $e, string $path, SanitiseRun $run): ?array
    {
        $kind = $e['kind'] ?? null;

        if (! $this->schema->inEnum('shape_kind', $kind)) {
            return $this->unusable($run, "{$path}.kind", 'This shape has an unknown kind.');
        }

        $s = fn (string $prop) => $this->schema->spec('shape', $prop);
        $out = ['kind' => $kind];

        $out['fill'] = array_key_exists('fill', $e) ? $this->paint($e['fill'], '#e4e4e7', $run) : '#e4e4e7';

        $stroke = $this->object($e['stroke'] ?? null, $run);
        $out['stroke'] = $stroke === null ? null : [
            'color' => $this->hex($stroke['color'] ?? null, '#000000', $run),
            'width' => $this->number($stroke, 'width', $s('stroke')['props']['width'] ?? ['min' => 0, 'max' => 100, 'default' => 1], $run),
            'dash' => $this->enum($stroke['dash'] ?? 'solid', 'dash', 'solid', $run),
            'cap' => in_array($stroke['cap'] ?? null, ['butt', 'round'], true) ? $stroke['cap'] : 'butt',
        ];

        if ($kind === 'rect') {
            $out['radius'] = $this->number($e, 'radius', $s('radius'), $run);
        } elseif ($kind === 'polygon') {
            $out['sides'] = $this->number($e, 'sides', $s('sides'), $run, true);
        } elseif ($kind === 'star') {
            $out['points'] = $this->number($e, 'points', $s('points'), $run, true);
            $out['inner'] = $this->number($e, 'inner', $s('inner'), $run);
        } elseif ($kind === 'path') {
            $d = $this->pathData($e['d'] ?? null, (int) ($s('d')['max'] ?? 20000));

            if ($d === null) {
                return $this->unusable($run, "{$path}.d", 'This path is not valid. Only the commands M L H V C S Q T Z are allowed.');
            }

            $out['d'] = $d;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>|null
     */
    private function image(array $e, string $path, SanitiseRun $run): ?array
    {
        $s = fn (string $prop) => $this->schema->spec('image', $prop);
        $assetId = null;

        if (array_key_exists('asset_id', $e) && $e['asset_id'] !== null) {
            $assetId = $this->assetId($e['asset_id']);

            if ($assetId === null) {
                return $this->unusable($run, "{$path}.asset_id", 'This picture reference is not valid.');
            }

            $run->assetRefs[] = ['path' => "{$path}.asset_id", 'id' => $assetId];
        }

        $focal = $this->object($e['focal'] ?? null, $run) ?? [];
        $border = $this->object($e['border'] ?? null, $run);
        $filters = $this->object($e['filters'] ?? null, $run);
        $fp = $s('filters')['props'] ?? [];
        $defaults = ['brightness' => 1, 'contrast' => 1, 'saturate' => 1, 'blur' => 0, 'grayscale' => 0, 'sepia' => 0, 'hue' => 0];

        $alt = $this->altText($e['alt'] ?? null, $run);

        return ($alt === null ? [] : ['alt' => $alt]) + [
            'asset_id' => $assetId,
            'fit' => $this->enum($e['fit'] ?? 'cover', 'image_fit', 'cover', $run),
            'focal' => [
                'x' => $this->number($focal, 'x', ['min' => 0, 'max' => 1, 'default' => 0.5], $run),
                'y' => $this->number($focal, 'y', ['min' => 0, 'max' => 1, 'default' => 0.5], $run),
            ],
            'zoom' => $this->number($e, 'zoom', $s('zoom'), $run),
            'mask' => $this->enum($e['mask'] ?? 'none', 'image_mask', 'none', $run),
            'radius' => $this->number($e, 'radius', $s('radius'), $run),
            'border' => $border === null ? null : [
                'color' => $this->hex($border['color'] ?? null, '#000000', $run),
                'width' => $this->number($border, 'width', ($s('border')['props']['width'] ?? []) + ['min' => 0, 'max' => 100, 'default' => 1], $run),
            ],
            'filters' => $filters === null ? null : array_map(
                fn (string $key) => $this->number($filters, $key, ($fp[$key] ?? ['min' => 0, 'max' => 2]) + ['default' => $defaults[$key]], $run),
                array_combine(array_keys($defaults), array_keys($defaults)),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>|null
     */
    private function icon(array $e, string $path, SanitiseRun $run): ?array
    {
        $icon = $e['icon'] ?? null;

        if (! $this->schema->matches('icon_key', $icon)) {
            return $this->unusable($run, "{$path}.icon", 'This icon is not valid.');
        }

        return [
            'icon' => $icon,
            'color' => $this->hex($e['color'] ?? null, '#18181b', $run),
            'strokeWidth' => $this->number($e, 'strokeWidth', $this->schema->spec('icon', 'strokeWidth'), $run),
        ];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>|null
     */
    private function group(array $e, string $path, int $depth, SanitiseRun $run): ?array
    {
        $children = $e['children'] ?? null;

        if (! is_array($children) || ! array_is_list($children)) {
            return $this->unusable($run, "{$path}.children", 'A group needs a list of children.');
        }

        $out = [];

        foreach ($children as $i => $child) {
            $clean = $this->element($child, "{$path}.children.{$i}", $depth + 1, $run);

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        if ($out === []) {
            // An empty group has nothing to draw and nothing to select: dropped rather than stored.
            $run->fix('empty_group_dropped');

            return null;
        }

        return ['children' => $out];
    }

    // ------------------------------------------------------------- paints

    private function paint(mixed $value, string $default, SanitiseRun $run): string|array|null
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $this->hex($value, $default, $run);
        }

        $gradient = $this->gradient($value, $run);

        if ($gradient === null) {
            $run->fix('paint');

            return $default;
        }

        return $gradient;
    }

    /**
     * @return array{kind: string, angle: int|float, stops: list<array{at: int|float, color: string}>}|null
     */
    private function gradient(mixed $value, SanitiseRun $run): ?array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            return null;
        }

        $stops = $value['stops'] ?? null;

        if (! is_array($stops) || ! array_is_list($stops) || count($stops) < 2) {
            return null;
        }

        $clean = [];

        foreach (array_slice($stops, 0, 6) as $stop) {
            if (! is_array($stop) || ! is_string($stop['color'] ?? null) || ! $this->schema->matches('hex', $stop['color'])) {
                return null;
            }

            $clean[] = [
                'at' => $this->number($stop, 'at', ['min' => 0, 'max' => 1, 'default' => 0], $run),
                'color' => strtolower($stop['color']),
            ];
        }

        if (count($stops) > 6) {
            $run->fix('limit');
        }

        usort($clean, fn ($a, $b) => $a['at'] <=> $b['at']);

        return [
            'kind' => $this->enum($value['kind'] ?? 'linear', 'gradient_kind', 'linear', $run),
            'angle' => $this->number($value, 'angle', ['min' => -360, 'max' => 360, 'default' => 90], $run),
            'stops' => $clean,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shadow(mixed $value, SanitiseRun $run): ?array
    {
        $shadow = $this->object($value, $run);

        if ($shadow === null) {
            return null;
        }

        $p = $this->schema->spec('common', 'shadow')['props'] ?? [];

        return [
            'x' => $this->number($shadow, 'x', ($p['x'] ?? []) + ['min' => -200, 'max' => 200, 'default' => 0], $run),
            'y' => $this->number($shadow, 'y', ($p['y'] ?? []) + ['min' => -200, 'max' => 200, 'default' => 4], $run),
            'blur' => $this->number($shadow, 'blur', ($p['blur'] ?? []) + ['min' => 0, 'max' => 200, 'default' => 8], $run),
            'color' => $this->hex($shadow['color'] ?? null, '#000000', $run),
            'opacity' => $this->number($shadow, 'opacity', ($p['opacity'] ?? []) + ['min' => 0, 'max' => 1, 'default' => 0.3], $run),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function animation(mixed $value, SanitiseRun $run): ?array
    {
        $animation = $this->object($value, $run);

        if ($animation === null) {
            return null;
        }

        $p = $this->schema->spec('common', 'animation')['props'] ?? [];
        $out = [];

        foreach (['in' => 'anim_preset_in', 'out' => 'anim_preset_out', 'loop' => 'anim_preset_loop'] as $phase => $presets) {
            $part = $this->object($animation[$phase] ?? null, $run);

            if ($part === null) {
                continue;
            }

            $spec = $p[$phase] ?? [];
            $clean = ['preset' => $this->enum($part['preset'] ?? 'none', $presets, 'none', $run)];

            if ($phase !== 'loop') {
                $clean['delay_ms'] = $this->number($part, 'delay_ms', ($spec['delay_ms'] ?? []) + ['min' => 0, 'max' => 60000, 'default' => 0], $run, true);
                $clean['easing'] = $this->enum($part['easing'] ?? 'ease-out', 'anim_easing', 'ease-out', $run);
            }

            $clean['duration_ms'] = $this->number($part, 'duration_ms', ($spec['duration_ms'] ?? []) + ['min' => 100, 'max' => 10000, 'default' => $phase === 'loop' ? 1500 : 600], $run, true);
            $out[$phase] = $clean;
        }

        return $out === [] ? null : $out;
    }

    /**
     * @return array<string, string>|null
     */
    private function tokens(mixed $value, SanitiseRun $run): ?array
    {
        $tokens = $this->object($value, $run);

        if ($tokens === null) {
            return null;
        }

        $out = [];

        foreach ($tokens as $key => $ref) {
            if (in_array($key, self::TOKEN_KEYS, true) && $this->schema->matches('token_ref', $ref)) {
                $out[$key] = $ref;
            } else {
                $run->fix('token');
            }
        }

        return $out === [] ? null : $out;
    }

    // ------------------------------------------------------------ helpers

    /**
     * An object-shaped value (string keys), or null for null/absent. A wrong type is also null and counted.
     *
     * @return array<string, mixed>|null
     */
    private function object(mixed $value, SanitiseRun $run): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            $run->fix('type');

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $spec  min, max, default
     */
    private function number(array $source, string $key, array $spec, SanitiseRun $run, bool $int = false, bool $required = false): int|float
    {
        $min = $spec['min'] ?? -PHP_FLOAT_MAX;
        $max = $spec['max'] ?? PHP_FLOAT_MAX;
        $default = $spec['default'] ?? (is_numeric($min) ? max(0, $min) : 0);
        $default = is_numeric($default) ? $default + 0 : max(0, $min);

        if (! array_key_exists($key, $source) || $source[$key] === null) {
            if ($required) {
                $run->fix('missing');
            }

            return $this->tidy($default, $int);
        }

        $value = $source[$key];

        if (is_string($value) && is_numeric($value)) {
            $value += 0;
            $run->fix('type');
        }

        if (is_bool($value) || ! (is_int($value) || is_float($value)) || (is_float($value) && ! is_finite($value))) {
            $run->fix('type');

            return $this->tidy($default, $int);
        }

        $clamped = max($min, min($max, $value));

        if ($clamped != $value) {
            $run->fix('clamped');
        }

        return $this->tidy($clamped, $int);
    }

    private function tidy(int|float $value, bool $int): int|float
    {
        if ($int) {
            return (int) round($value);
        }

        $rounded = round((float) $value, 3);

        return abs($rounded) < 1e15 && $rounded == floor($rounded) ? (int) $rounded : $rounded;
    }

    private function bool(mixed $value, SanitiseRun $run): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        $run->fix('type');

        return false;
    }

    private function enum(mixed $value, string $enum, string $default, SanitiseRun $run): string
    {
        if ($this->schema->inEnum($enum, $value)) {
            return $value;
        }

        if ($value !== null) {
            $run->fix('enum');
        }

        return $default;
    }

    private function hex(mixed $value, string $default, SanitiseRun $run): string
    {
        if ($this->schema->matches('hex', $value)) {
            return strtolower($value);
        }

        if ($value !== null) {
            $run->fix('colour');
        }

        return $default;
    }

    private function assetId(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value) && strlen($value) <= 18) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function label(mixed $value, string $code, SanitiseRun $run): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $run->fix('type');

            return null;
        }

        $clean = trim($this->cleanText($value, false, $run));
        $max = (int) $this->schema->limit('max_name_length');

        if (mb_strlen($clean) > $max) {
            $run->fix($code.'_truncated');
            $clean = mb_substr($clean, 0, $max);
        }

        return $clean === '' ? null : $clean;
    }

    /** Alternative text for a picture: one clean line, at most 300 characters; empty means none (the key is then left out). */
    private function altText(mixed $value, SanitiseRun $run): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $run->fix('type');

            return null;
        }

        $clean = trim($this->cleanText($value, false, $run));

        if (mb_strlen($clean) > 300) {
            $run->fix('alt_truncated');
            $clean = mb_substr($clean, 0, 300);
        }

        return $clean === '' ? null : $clean;
    }

    /** Valid UTF-8 only, no control characters (tab and, for multi-line text, newline survive), CRLF folded to LF. */
    private function cleanText(string $text, bool $multiline, SanitiseRun $run): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $run->fix('encoding');
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $clean = preg_replace($pattern, '', $text);

        if ($clean === null) {
            $run->fix('encoding');

            return '';
        }

        if ($clean !== $text) {
            $run->fix('control_chars');
        }

        return $clean;
    }

    /**
     * SVG path data restricted to M L H V C S Q T Z with the right number of arguments. Returned re-serialised from
     * its tokens, so nothing but commands and numbers can survive. Null means unusable.
     */
    private function pathData(mixed $d, int $maxLength): ?string
    {
        if (! is_string($d) || $d === '' || strlen($d) > $maxLength || ! preg_match('/^\s*[Mm]/', $d)) {
            return null;
        }

        $token = '/([MmLlHhVvCcSsQqTtZz])|([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)|[\s,]+|(.)/su';

        if (preg_match_all($token, $d, $matches, PREG_SET_ORDER) === false) {
            return null;
        }

        $out = [];
        $command = null;
        $numbers = 0;

        $closeSegment = function () use (&$command, &$numbers): bool {
            if ($command === null) {
                return true;
            }

            $arity = self::PATH_ARITY[strtoupper($command)];

            return $arity === 0 ? $numbers === 0 : ($numbers > 0 && $numbers % $arity === 0);
        };

        foreach ($matches as $match) {
            if (isset($match[3]) && $match[3] !== '') {
                return null; // anything that is not a command, a number or a separator
            }

            if (isset($match[1]) && $match[1] !== '') {
                if (! $closeSegment()) {
                    return null;
                }

                $command = $match[1];
                $numbers = 0;
                $out[] = $command;

                continue;
            }

            if (isset($match[2]) && $match[2] !== '') {
                $number = (float) $match[2];

                if ($command === null || ! is_finite($number) || abs($number) > 1_000_000 || self::PATH_ARITY[strtoupper($command)] === 0) {
                    return null;
                }

                $numbers++;
                $out[] = $this->tidy($number, false);
            }
        }

        if (! $closeSegment() || $out === []) {
            return null;
        }

        $result = implode(' ', $out);

        return strlen($result) > $maxLength ? null : $result;
    }

    /** Resolve every referenced asset in one query and refuse the ones this user may not place. */
    private function checkAssets(SanitiseRun $run, DocumentContext $context): void
    {
        if (! $context->strict || $context->allowedAssets === null || $run->assetRefs === []) {
            return;
        }

        $ids = array_values(array_unique(array_column($run->assetRefs, 'id')));
        $allowed = array_flip(($context->allowedAssets)($ids));

        foreach ($run->assetRefs as $ref) {
            if (! isset($allowed[$ref['id']])) {
                $run->error($ref['path'], 'That picture is not available to you.');
            }
        }
    }

    private function summary(SanitiseRun $run): string
    {
        $first = array_values($run->errors)[0][0] ?? 'This page could not be saved.';

        return $first;
    }
}

<?php

namespace App\Services\Design\Assistant;

use App\Models\BrandKit;
use App\Services\Design\Contrast;
use App\Services\Design\DocumentSchema;

/**
 * The server's gate for every patch, whether a model proposed it or the browser's automatic fixes did. Nothing the
 * client or the model says is trusted: ids must belong to the page, properties come from a short list and are
 * clamped, fonts come from the font list (brand fonts preferred), colours are snapped to the brand kit when one
 * exists and must stay readable, existing words are never changed, and nothing is ever deleted.
 *
 * Canonical operations:
 *   {op:'set', id, props:{color|fill|fontSize|fontWeight|font|lineHeight|letterSpacing|align|x|y|width|height|opacity|rotation|shadow}}
 *   {op:'add', element:{type:'text'|'shape', ...}, behind_id?}
 *   {op:'reorder', id, to:'forward'|'back'|'front'|'back-most'}
 */
class OpValidator
{
    public const SET_PROPS = ['color', 'fill', 'fontSize', 'fontWeight', 'font', 'lineHeight', 'letterSpacing', 'align', 'x', 'y', 'width', 'height', 'opacity', 'rotation', 'shadow'];

    public const REORDER_TO = ['forward', 'back', 'front', 'back-most'];

    public const MAX_OPS = 40;

    public const MAX_ADDS = 3;

    private const TEXT_ONLY = ['fontSize', 'fontWeight', 'font', 'lineHeight', 'letterSpacing', 'align'];

    private const BOX = ['x', 'y', 'width', 'height'];

    private const LABELS = [
        'color' => 'text colour', 'fill' => 'fill colour', 'fontSize' => 'text size', 'fontWeight' => 'boldness', 'font' => 'font',
        'lineHeight' => 'line spacing', 'letterSpacing' => 'letter spacing', 'align' => 'alignment', 'x' => 'position across',
        'y' => 'position down', 'width' => 'width', 'height' => 'height', 'opacity' => 'opacity', 'rotation' => 'rotation', 'shadow' => 'shadow',
    ];

    /** @var list<string> */
    private array $fonts;

    public function __construct(?DocumentSchema $schema = null)
    {
        $this->fonts = ($schema ?? DocumentSchema::shared())->fontKeys();
    }

    /**
     * @param  array{width: int|float, height: int|float, background?: array<string, mixed>|null, elements: list<array<string, mixed>>}  $page
     * @param  list<mixed>  $rawOps
     * @param  list<string>  $selection  ids the change is limited to (empty = the whole page)
     * @param  string  $source  text a new word on the page may take its facts from (instruction and existing page text)
     */
    public function validate(array $page, array $rawOps, ?BrandKit $kit = null, array $selection = [], string $source = ''): OpResult
    {
        $index = $this->index($page['elements']);
        $allowed = $selection === [] ? null : $this->selectionScope($index, $selection);
        $width = (float) $page['width'];
        $height = (float) $page['height'];

        $ops = [];
        $notes = [];
        $dropped = 0;
        $adds = 0;

        foreach (array_slice($rawOps, 0, self::MAX_OPS * 2) as $raw) {
            if (count($ops) >= self::MAX_OPS) {
                $dropped++;

                continue;
            }

            $kind = is_array($raw) ? ($raw['op'] ?? null) : null;

            if ($kind === 'set') {
                $op = $this->set($raw, $index, $allowed, $width, $height, $kit, $notes);
            } elseif ($kind === 'add') {
                $op = $adds < self::MAX_ADDS && count($page['elements']) + $adds < (int) DocumentSchema::shared()->limit('max_elements_per_page')
                    ? $this->add($raw, $index, $width, $height, $kit, $source, $notes)
                    : null;
                if ($op !== null) {
                    $adds++;
                }
            } elseif ($kind === 'reorder') {
                $op = $this->reorder($raw, $index, $allowed);
            } else {
                $op = null; // delete and anything unknown is not an operation
            }

            if ($op === null) {
                $dropped++;
            } else {
                $ops[] = $op;
            }
        }

        $ops = $this->dropNoops($this->enforceContrast($page, $index, $ops, $kit, $notes, $dropped), $index, $dropped);

        return new OpResult($ops, $this->describe($ops, $index), array_values(array_unique($notes)), $dropped);
    }

    // ------------------------------------------------------------------ set

    /** @return array<string, mixed>|null */
    private function set(array $raw, array $index, ?array $allowed, float $pageW, float $pageH, ?BrandKit $kit, array &$notes): ?array
    {
        $id = $raw['id'] ?? null;
        $entry = is_string($id) ? ($index[$id] ?? null) : null;

        if ($entry === null || ($allowed !== null && ! isset($allowed[$id]))) {
            return null;
        }

        $element = $entry['el'];
        if (! empty($element['locked'])) {
            $notes[] = 'A locked element was left alone.';

            return null;
        }

        $type = $element['type'];
        $in = is_array($raw['props'] ?? null) ? $raw['props'] : [];
        $props = [];

        foreach ($in as $prop => $value) {
            if (! is_string($prop) || ! in_array($prop, self::SET_PROPS, true)) {
                continue;
            }
            if (in_array($prop, self::TEXT_ONLY, true) && $type !== 'text') {
                continue;
            }
            if ($prop === 'color' && ! in_array($type, ['text', 'icon'], true)) {
                continue;
            }
            if ($prop === 'fill' && $type !== 'shape') {
                continue;
            }
            if (in_array($prop, self::BOX, true) && $entry['parent'] !== null) {
                continue; // children use group-relative coordinates
            }

            $clean = $this->value($prop, $value, $element, $kit, $notes);
            if ($clean !== null || ($prop === 'shadow' && array_key_exists('shadow', $in) && ($value === null || $value === 'none'))) {
                $props[$prop] = $clean;
            }
        }

        $props = $this->box($props, $element, $pageW, $pageH, $notes);

        // Drop anything that would not change the element.
        foreach ($props as $prop => $value) {
            if (($element[$prop] ?? null) === $value || (is_numeric($value) && is_numeric($element[$prop] ?? null) && abs((float) $element[$prop] - (float) $value) < 0.001)) {
                unset($props[$prop]);
            }
        }

        return $props === [] ? null : ['op' => 'set', 'id' => $id, 'props' => $props];
    }

    private function value(string $prop, mixed $value, array $element, ?BrandKit $kit, array &$notes): mixed
    {
        switch ($prop) {
            case 'color':
            case 'fill':
                $hex = $this->hex($value);

                return $hex === null ? null : $this->brandColour($hex, $kit, $notes);

            case 'fontSize':
                if (! is_numeric($value)) {
                    return null;
                }
                $current = (float) ($element['fontSize'] ?? 32);

                return round(min(max((float) $value, max(8, $current * 0.5)), min(2000, $current * 3)), 1);

            case 'fontWeight':
                return is_numeric($value) ? (int) (round(min(max((float) $value, 100), 900) / 100) * 100) : null;

            case 'font':
                if (! is_string($value) || ! in_array($value, $this->fonts, true)) {
                    return null;
                }

                return $this->brandFont($value, $element, $kit, $notes);

            case 'lineHeight':
                return is_numeric($value) ? round(min(max((float) $value, 0.6), 3), 2) : null;

            case 'letterSpacing':
                return is_numeric($value) ? round(min(max((float) $value, -0.2), 1), 3) : null;

            case 'align':
                return in_array($value, ['left', 'center', 'right', 'justify'], true) ? $value : null;

            case 'x':
            case 'y':
            case 'width':
            case 'height':
                return is_numeric($value) ? round((float) $value, 1) : null;

            case 'opacity':
                return is_numeric($value) ? round(min(max((float) $value, 0), 1), 2) : null;

            case 'rotation':
                return is_numeric($value) ? round(min(max((float) $value, -360), 360), 1) : null;

            case 'shadow':
                if ($value === null || $value === 'none') {
                    return null;
                }
                if (! is_array($value)) {
                    return null;
                }
                $color = $this->hex($value['color'] ?? '#000000');

                return $color === null ? null : [
                    'x' => round(min(max((float) ($value['x'] ?? 0), -200), 200), 1),
                    'y' => round(min(max((float) ($value['y'] ?? 0), -200), 200), 1),
                    'blur' => round(min(max((float) ($value['blur'] ?? 0), 0), 200), 1),
                    'color' => $color,
                    'opacity' => round(min(max((float) ($value['opacity'] ?? 0.25), 0), 1), 2),
                ];
        }

        return null;
    }

    /** Keep the element inside the page, and leave elements that bleed off the page where they are. */
    private function box(array $props, array $element, float $pageW, float $pageH, array &$notes): array
    {
        $touched = array_intersect(self::BOX, array_keys($props));
        if ($touched === []) {
            return $props;
        }

        $cur = ['x' => (float) $element['x'], 'y' => (float) $element['y'], 'width' => (float) $element['width'], 'height' => (float) $element['height']];
        $inside = $cur['x'] >= -2 && $cur['y'] >= -2 && $pageW + 2 >= $cur['x'] + $cur['width'] && $pageH + 2 >= $cur['y'] + $cur['height'];

        if (! $inside) {
            foreach (self::BOX as $key) {
                unset($props[$key]);
            }
            $notes[] = 'An element that runs past the edge of the page was not moved.';

            return $props;
        }

        $next = array_merge($cur, array_map('floatval', array_intersect_key($props, array_flip(self::BOX))));
        $next['width'] = min(max($next['width'], 8), $pageW);
        $next['height'] = min(max($next['height'], 8), $pageH);
        $next['x'] = min(max($next['x'], 0), $pageW - $next['width']);
        $next['y'] = min(max($next['y'], 0), $pageH - $next['height']);

        foreach (self::BOX as $key) {
            if (array_key_exists($key, $props)) {
                $props[$key] = round($next[$key], 1);
            }
        }

        // A move that pushed a sibling of the pair out of range is kept consistent: x and width travel together.
        foreach (['x' => 'width', 'y' => 'height'] as $pos => $size) {
            if (array_key_exists($size, $props) && ! array_key_exists($pos, $props) && abs($next[$pos] - $cur[$pos]) > 0.05) {
                $props[$pos] = round($next[$pos], 1);
            }
        }

        return $props;
    }

    // ------------------------------------------------------------------ add

    /** @return array<string, mixed>|null */
    private function add(array $raw, array $index, float $pageW, float $pageH, ?BrandKit $kit, string $source, array &$notes): ?array
    {
        $in = is_array($raw['element'] ?? null) ? $raw['element'] : null;
        if ($in === null) {
            return null;
        }

        $type = $in['type'] ?? null;
        $box = $this->newBox($in, $pageW, $pageH);
        if ($box === null) {
            return null;
        }

        $behind = isset($raw['behind_id']) && is_string($raw['behind_id']) && isset($index[$raw['behind_id']]) && $index[$raw['behind_id']]['parent'] === null ? $raw['behind_id'] : null;

        if ($type === 'shape') {
            $kind = in_array($in['kind'] ?? 'rect', ['rect', 'ellipse'], true) ? ($in['kind'] ?? 'rect') : null;
            $fill = $this->hex($in['fill'] ?? null);
            if ($kind === null || $fill === null) {
                return null;
            }

            return ['op' => 'add', 'element' => ['type' => 'shape', 'kind' => $kind, 'name' => 'Backing shape', 'fill' => $this->brandColour($fill, $kit, $notes), 'opacity' => round(min(max((float) ($in['opacity'] ?? 1), 0), 1), 2)] + $box, 'behind_id' => $behind];
        }

        if ($type === 'text') {
            $text = TextGuard::clean($in['text'] ?? null, 200);
            $color = $this->hex($in['color'] ?? null);
            $font = is_string($in['font'] ?? null) && in_array($in['font'], $this->fonts, true) ? $in['font'] : null;
            if ($text === null || $color === null || $font === null || ! is_numeric($in['fontSize'] ?? null)) {
                return null;
            }
            if (! TextGuard::supported($text, $source)) {
                $notes[] = 'A new line of text with a number or contact detail you did not give was left out.';

                return null;
            }

            $element = [
                'type' => 'text', 'name' => 'Added text', 'text' => $text,
                'font' => $this->brandFont($font, ['role' => 'body'], $kit, $notes),
                'fontSize' => round(min(max((float) $in['fontSize'], 8), 400), 1),
                'fontWeight' => (int) (round(min(max((float) ($in['fontWeight'] ?? 400), 100), 900) / 100) * 100),
                'color' => $this->brandColour($color, $kit, $notes),
                'align' => in_array($in['align'] ?? 'left', ['left', 'center', 'right'], true) ? ($in['align'] ?? 'left') : 'left',
                'autoFit' => 'grow',
            ] + $box;

            return ['op' => 'add', 'element' => $element, 'behind_id' => $behind];
        }

        return null;
    }

    /** @return array{x: float, y: float, width: float, height: float}|null */
    private function newBox(array $in, float $pageW, float $pageH): ?array
    {
        foreach (self::BOX as $key) {
            if (! is_numeric($in[$key] ?? null)) {
                return null;
            }
        }

        $w = min(max((float) $in['width'], 20), $pageW);
        $h = min(max((float) $in['height'], 8), $pageH);

        return [
            'x' => round(min(max((float) $in['x'], 0), $pageW - $w), 1),
            'y' => round(min(max((float) $in['y'], 0), $pageH - $h), 1),
            'width' => round($w, 1),
            'height' => round($h, 1),
        ];
    }

    // -------------------------------------------------------------- reorder

    /** @return array<string, mixed>|null */
    private function reorder(array $raw, array $index, ?array $allowed): ?array
    {
        $id = $raw['id'] ?? null;
        $entry = is_string($id) ? ($index[$id] ?? null) : null;

        if ($entry === null || $entry['parent'] !== null || ($allowed !== null && ! isset($allowed[$id])) || ! empty($entry['el']['locked'])) {
            return null;
        }

        return in_array($raw['to'] ?? null, self::REORDER_TO, true) ? ['op' => 'reorder', 'id' => $id, 'to' => $raw['to']] : null;
    }

    // --------------------------------------------------------------- colour

    private function hex(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            return null;
        }

        $hex = strtolower($value);

        return strlen($hex) === 4 ? '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3] : $hex;
    }

    /** With a brand kit, a colour is snapped to the nearest brand colour (or black or white). */
    private function brandColour(string $hex, ?BrandKit $kit, array &$notes): string
    {
        $palette = $this->palette($kit);
        if ($palette === []) {
            return $hex;
        }

        $best = $hex;
        $bestDistance = INF;
        foreach ($palette as $candidate) {
            $d = $this->distance($hex, $candidate);
            if ($d < $bestDistance) {
                $best = $candidate;
                $bestDistance = $d;
            }
        }

        if ($best !== $hex) {
            $notes[] = 'Colours were matched to your brand colours.';
        }

        return $best;
    }

    /** @return list<string> */
    private function palette(?BrandKit $kit): array
    {
        if ($kit === null) {
            return [];
        }

        $colours = [];
        foreach ((array) $kit->colors as $colour) {
            $hex = is_array($colour) ? $this->hex($colour['hex'] ?? null) : null;
            if ($hex !== null) {
                $colours[] = $hex;
            }
        }

        return $colours === [] ? [] : array_values(array_unique(array_merge($colours, ['#ffffff', '#111111'])));
    }

    private function distance(string $a, string $b): float
    {
        [$ar, $ag, $ab] = Contrast::rgb($a);
        [$br, $bg, $bb] = Contrast::rgb($b);

        return sqrt(($ar - $br) ** 2 + ($ag - $bg) ** 2 + ($ab - $bb) ** 2);
    }

    private function brandFont(string $font, array $element, ?BrandKit $kit, array &$notes): string
    {
        $brand = array_values(array_filter(array_map(fn ($slot) => $kit?->fontKey($slot), ['heading', 'body', 'accent']), fn ($f) => is_string($f) && in_array($f, $this->fonts, true)));
        if ($brand === [] || in_array($font, $brand, true)) {
            return $font;
        }

        $display = in_array($element['role'] ?? null, ['headline', 'subhead', 'price', 'badge'], true);
        $slot = $display ? 'heading' : 'body';
        $replacement = $kit?->fontKey($slot);
        $notes[] = 'Fonts were matched to your brand fonts.';

        return is_string($replacement) && in_array($replacement, $this->fonts, true) ? $replacement : $brand[0];
    }

    /**
     * A repaired colour can land back on the colour the element already has; that is no change at all.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, mixed>>
     */
    private function dropNoops(array $ops, array $index, int &$dropped): array
    {
        $out = [];

        foreach ($ops as $op) {
            if ($op['op'] === 'set') {
                $element = $index[$op['id']]['el'];
                foreach ($op['props'] as $prop => $value) {
                    if (($element[$prop] ?? null) === $value) {
                        unset($op['props'][$prop]);
                    }
                }
                if ($op['props'] === []) {
                    $dropped++;

                    continue;
                }
            }
            $out[] = $op;
        }

        return $out;
    }

    // ------------------------------------------------------------- contrast

    /**
     * After every operation is individually valid, check that no text ends up unreadable (under 3:1 against what is
     * behind it). A text colour or fill change that causes it is dropped.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, mixed>>
     */
    private function enforceContrast(array $page, array $index, array $ops, ?BrandKit $kit, array &$notes, int &$dropped): array
    {
        $colourOps = array_keys(array_filter($ops, fn ($op) => $op['op'] === 'set' && (isset($op['props']['color']) || isset($op['props']['fill']))));
        if ($colourOps === []) {
            return $ops;
        }

        $background = $this->backgroundColour($page['background'] ?? null);

        foreach ($colourOps as $i) {
            $trial = $ops;
            $state = $this->project($page['elements'], $trial);
            $problems = $this->unreadable($state, $background, $ops[$i]);

            if ($problems === []) {
                continue;
            }

            // A text colour can be repaired by choosing the best readable ink; anything else is dropped.
            $op = $ops[$i];
            if (isset($op['props']['color'])) {
                $fix = $this->readableInk($state, $op['id'], $background, $this->palette($kit));
                if ($fix !== null) {
                    $ops[$i]['props']['color'] = $fix;
                    $notes[] = 'A text colour was adjusted so it stays easy to read.';

                    continue;
                }
            }

            unset($ops[$i]['props']['color'], $ops[$i]['props']['fill']);
            $notes[] = 'A colour change was left out because it would make text hard to read.';
            if ($ops[$i]['props'] === []) {
                unset($ops[$i]);
                $dropped++;
            }
        }

        return array_values($ops);
    }

    /**
     * Text elements (touched by $op, or sitting over the shape it recolours) whose contrast against their backdrop is under 3:1.
     *
     * @param  list<array<string, mixed>>  $elements  top-level elements after the operations
     * @return list<string>
     */
    private function unreadable(array $elements, string $background, array $op): array
    {
        $out = [];

        foreach ($elements as $position => $element) {
            if (($element['type'] ?? null) !== 'text' || empty($element['color'])) {
                continue;
            }

            $relevant = isset($op['props']['color']) ? $element['id'] === $op['id'] : $this->overlaps($elements[$this->positionOf($elements, $op['id'])] ?? [], $element);
            if (! $relevant) {
                continue;
            }

            $behind = $this->backdrop($elements, $position, $background);
            if ($behind !== null && Contrast::ratio((string) $element['color'], $behind) < 3.0) {
                $out[] = $element['id'];
            }
        }

        return $out;
    }

    private function readableInk(array $elements, string $id, string $background, array $palette): ?string
    {
        $position = $this->positionOf($elements, $id);
        if ($position === null) {
            return null;
        }

        $behind = $this->backdrop($elements, $position, $background);
        if ($behind === null) {
            return null;
        }

        $best = null;
        $bestRatio = 0.0;
        foreach (array_merge($palette, ['#111111', '#ffffff']) as $candidate) {
            $ratio = Contrast::ratio($candidate, $behind);
            if ($ratio > $bestRatio) {
                $best = $candidate;
                $bestRatio = $ratio;
            }
        }

        return $bestRatio >= 4.5 ? $best : null;
    }

    /** Colour behind the element at $position: the nearest lower solid shape that contains its centre, else the page. Null when a picture or gradient is behind. */
    private function backdrop(array $elements, int $position, string $background): ?string
    {
        $text = $elements[$position];
        $cx = $text['x'] + $text['width'] / 2;
        $cy = $text['y'] + $text['height'] / 2;

        for ($i = $position - 1; $i >= 0; $i--) {
            $el = $elements[$i];
            if (! empty($el['hidden']) || ($el['opacity'] ?? 1) < 0.6) {
                continue;
            }
            $contains = $cx >= $el['x'] && $cx <= $el['x'] + $el['width'] && $cy >= $el['y'] && $cy <= $el['y'] + $el['height'];
            if (! $contains) {
                continue;
            }
            if (($el['type'] ?? null) === 'image') {
                return null;
            }
            if (($el['type'] ?? null) === 'shape' && in_array($el['kind'] ?? 'rect', ['rect', 'ellipse'], true)) {
                $fill = $el['fill'] ?? null;
                if (is_string($fill) && preg_match('/^#[0-9a-f]{6}/i', $fill)) {
                    return substr($fill, 0, 7);
                }

                return null;
            }
        }

        return $background;
    }

    private function overlaps(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        return $a['x'] < $b['x'] + $b['width'] && $b['x'] < $a['x'] + $a['width'] && $a['y'] < $b['y'] + $b['height'] && $b['y'] < $a['y'] + $a['height'];
    }

    private function positionOf(array $elements, string $id): ?int
    {
        foreach ($elements as $i => $el) {
            if (($el['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Top-level elements after applying the set operations (adds and reorders are ignored for the check).
     *
     * @param  list<array<string, mixed>>  $elements
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, mixed>>
     */
    private function project(array $elements, array $ops): array
    {
        $patch = [];
        foreach ($ops as $op) {
            if ($op['op'] === 'set') {
                $patch[$op['id']] = ($patch[$op['id']] ?? []) + $op['props'];
            }
        }

        return array_map(fn (array $el) => isset($patch[$el['id'] ?? '']) ? array_merge($el, $patch[$el['id']]) : $el, $elements);
    }

    private function backgroundColour(?array $background): string
    {
        $colour = is_array($background) ? ($background['color'] ?? null) : null;
        if (is_string($colour) && preg_match('/^#[0-9a-f]{6}/i', $colour)) {
            return substr($colour, 0, 7);
        }

        return '#ffffff';
    }

    // ---------------------------------------------------------------- index

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return array<string, array{el: array<string, mixed>, parent: ?string}>
     */
    private function index(array $elements, ?string $parent = null): array
    {
        $out = [];
        foreach ($elements as $el) {
            if (! is_array($el) || ! isset($el['id'])) {
                continue;
            }
            $out[(string) $el['id']] = ['el' => $el, 'parent' => $parent];
            if (($el['type'] ?? null) === 'group') {
                $out += $this->index((array) ($el['children'] ?? []), (string) $el['id']);
            }
        }

        return $out;
    }

    /**
     * The ids a selection covers: the selected elements and everything inside selected groups.
     *
     * @return array<string, true>
     */
    private function selectionScope(array $index, array $selection): array
    {
        $scope = [];
        foreach ($selection as $id) {
            if (is_string($id) && isset($index[$id])) {
                $scope[$id] = true;
            }
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($index as $id => $entry) {
                if (! isset($scope[$id]) && $entry['parent'] !== null && isset($scope[$entry['parent']])) {
                    $scope[$id] = true;
                    $changed = true;
                }
            }
        }

        return $scope;
    }

    // ------------------------------------------------------------- describe

    /**
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, mixed>>
     */
    private function describe(array $ops, array $index): array
    {
        $changes = [];

        foreach ($ops as $op) {
            if ($op['op'] === 'set') {
                $element = $index[$op['id']]['el'];
                foreach ($op['props'] as $prop => $to) {
                    $from = $element[$prop] ?? null;
                    $changes[] = [
                        'element_id' => $op['id'],
                        'label' => $this->label($element),
                        'prop' => $prop,
                        'from' => $from,
                        'to' => $to,
                        'text' => $this->sentence($this->label($element), $prop, $from, $to),
                    ];
                }
            } elseif ($op['op'] === 'add') {
                $isText = $op['element']['type'] === 'text';
                $changes[] = [
                    'element_id' => null,
                    'label' => $isText ? 'New text' : 'New shape',
                    'prop' => 'add',
                    'from' => null,
                    'to' => $isText ? $op['element']['text'] : $op['element']['kind'],
                    'text' => $isText ? 'Adds a new line of text: "'.$op['element']['text'].'"' : 'Adds a '.($op['element']['kind'] === 'ellipse' ? 'round' : 'rectangular').' shape behind the content',
                ];
            } else {
                $label = $this->label($index[$op['id']]['el']);
                $changes[] = [
                    'element_id' => $op['id'],
                    'label' => $label,
                    'prop' => 'order',
                    'from' => null,
                    'to' => $op['to'],
                    'text' => $label.': moves '.match ($op['to']) {
                        'forward' => 'one step forward',
                        'back' => 'one step back',
                        'front' => 'to the front',
                        default => 'to the back',
                    },
                ];
            }
        }

        return $changes;
    }

    private function label(array $element): string
    {
        if (! empty($element['name'])) {
            return (string) $element['name'];
        }

        $roles = ['headline' => 'Headline', 'subhead' => 'Supporting line', 'body' => 'Body text', 'cta' => 'Button', 'logo' => 'Logo', 'photo' => 'Picture', 'price' => 'Price', 'badge' => 'Badge', 'contact' => 'Contact details'];
        if (isset($roles[$element['role'] ?? ''])) {
            return $roles[$element['role']];
        }

        if (($element['type'] ?? null) === 'text' && ! empty($element['text'])) {
            return 'Text "'.mb_substr((string) $element['text'], 0, 24).(mb_strlen((string) $element['text']) > 24 ? '...' : '').'"';
        }

        return match ($element['type'] ?? '') {
            'shape' => 'Shape', 'image' => 'Picture', 'icon' => 'Icon', 'group' => 'Group', default => 'Element',
        };
    }

    private function sentence(string $label, string $prop, mixed $from, mixed $to): string
    {
        $name = self::LABELS[$prop] ?? $prop;

        if ($prop === 'shadow') {
            return $label.': '.($to === null ? 'removes the shadow' : 'adds a soft shadow');
        }

        $fmt = fn ($v) => $v === null ? 'none' : (is_float($v) || is_int($v) ? (string) (round((float) $v, 2) + 0) : (string) (is_array($v) ? '' : str_replace('-', ' ', (string) $v)));

        return $from === null ? "$label: sets $name to ".$fmt($to) : "$label: $name from ".$fmt($from).' to '.$fmt($to);
    }
}

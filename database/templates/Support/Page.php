<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * Fluent page composer. Coordinates are px on the page canvas; $u is 1% of the short side so
 * one design logic scales across formats. Emits exact document-schema v2 element arrays.
 */
final class Page
{
    public float $w;

    public float $h;

    /** Safe margin. */
    public float $m;

    /** Short side. */
    public float $s;

    /** 1% of the short side. */
    public float $u;

    /** @var array<int,array<string,mixed>> */
    private array $els = [];

    private array $background = ['type' => 'solid', 'color' => '#ffffff'];

    private int $duration = 5000;

    private ?string $transition = null;

    private ?array $bgRect = null;

    private array $ctx;

    private const ROLE_NAMES = [
        'headline' => 'Headline', 'subhead' => 'Subheading', 'body' => 'Body text', 'cta' => 'Button label', 'price' => 'Price',
        'badge' => 'Badge text', 'contact' => 'Contact line', 'logo' => 'Logo', 'photo' => 'Picture', 'decor' => 'Decoration',
        'background' => 'Background', 'other' => 'Text',
    ];

    private function __construct(float $w, float $h, public readonly Palette $pal, public readonly TypePair $type, ?float $margin, ?array $ctx = null)
    {
        $this->w = $w;
        $this->h = $h;
        $this->s = min($w, $h);
        $this->u = $this->s / 100;
        $this->m = $margin ?? round($this->s * 0.07, 2);
        $this->ctx = $ctx ?? ['n' => 0, 'prefix' => 'e'];
    }

    public static function make(float $w, float $h, Palette $pal, TypePair $type, ?float $margin = null, string $idPrefix = 'e'): self
    {
        return new self($w, $h, $pal, $type, $margin, ['n' => 0, 'prefix' => $idPrefix]);
    }

    // ---- geometry helpers ------------------------------------------------------------------

    public function right(): float
    {
        return round($this->w - $this->m, 2);
    }

    public function bottom(): float
    {
        return round($this->h - $this->m, 2);
    }

    /** Content width between the margins. */
    public function cw(): float
    {
        return round($this->w - 2 * $this->m, 2);
    }

    /** Column $i (0-based) of $n inside the margins with a gutter. @return array{0:float,1:float} [x, width] */
    public function col(int $i, int $n, float $gutter = 0): array
    {
        $cw = ($this->cw() - $gutter * ($n - 1)) / $n;

        return [round($this->m + $i * ($cw + $gutter), 2), round($cw, 2)];
    }

    // ---- page properties -------------------------------------------------------------------

    public function bg(string $colour): self
    {
        $this->background = ['type' => 'solid', 'color' => $this->pal->get($colour)];
        $this->bgRect = null;
        // A brand-driven page colour also gets a full-page backdrop shape, because only elements can carry brand tokens.
        if ($this->pal->tokenFor($colour) !== null) {
            $this->bgRect = [
                'id' => $this->id(), 'type' => 'shape', 'name' => 'Background colour', 'role' => 'background',
                'x' => 0, 'y' => 0, 'width' => $this->w, 'height' => $this->h, 'kind' => 'rect', 'fill' => $this->pal->get($colour),
                'tokens' => ['fill' => $this->pal->tokenFor($colour)],
            ];
        }

        return $this;
    }

    /** @param array<int,array{0:float,1:string}> $stops */
    public function bgGradient(string $kind, float $angle, array $stops): self
    {
        $this->background = [
            'type' => 'gradient', 'color' => $this->pal->get($stops[0][1]),
            'gradient' => $this->gradient($kind, $angle, $stops),
        ];

        return $this;
    }

    public function duration(int $ms): self
    {
        $this->duration = $ms;

        return $this;
    }

    public function transition(string $t): self
    {
        $this->transition = $t;

        return $this;
    }

    /** @return array{kind:string,angle:float,stops:array} */
    public function gradient(string $kind, float $angle, array $stops): array
    {
        return [
            'kind' => $kind, 'angle' => $angle,
            'stops' => array_map(fn ($s) => ['at' => (float) $s[0], 'color' => $this->pal->get($s[1])], $stops),
        ];
    }

    // ---- elements --------------------------------------------------------------------------

    private function id(): string
    {
        return $this->ctx['prefix'].str_pad((string) ++$this->ctx['n'], 2, '0', STR_PAD_LEFT);
    }

    /** Shared state for children. */
    private function ctxRef(): array
    {
        return $this->ctx;
    }

    private function common(string $type, string $name, string $role, float $x, float $y, float $w, float $h, array $o): array
    {
        $e = [
            'id' => $this->id(), 'type' => $type, 'name' => $name, 'role' => $role,
            'x' => round($x, 2), 'y' => round($y, 2), 'width' => round($w, 2), 'height' => round($h, 2),
        ];
        if (isset($o['bind'])) {
            $e['bind'] = $o['bind'];
        }
        if (! empty($o['rotation'])) {
            $e['rotation'] = $o['rotation'];
        }
        if (isset($o['opacity']) && $o['opacity'] < 1) {
            $e['opacity'] = round($o['opacity'], 3);
        }
        if (! empty($o['locked'])) {
            $e['locked'] = true;
        }
        if (! empty($o['flip_x'])) {
            $e['flip_x'] = true;
        }
        if (! empty($o['flip_y'])) {
            $e['flip_y'] = true;
        }
        if (isset($o['blend'])) {
            $e['blend'] = $o['blend'];
        }
        if (isset($o['shadow'])) {
            $s = $o['shadow'];
            $e['shadow'] = ['x' => $s[0], 'y' => $s[1], 'blur' => $s[2], 'color' => $this->pal->get($s[3] ?? 'ink'), 'opacity' => $s[4] ?? 0.25];
        }
        if (isset($o['anim'])) {
            $e['animation'] = $this->animation($o['anim']);
        }
        if (! empty($o['_tokens'])) {
            $e['tokens'] = $o['_tokens'];
        }

        return $e;
    }

    /** Shorthand: ['rise', delay_ms, duration_ms?, easing?] or full array. */
    private function animation(array $a): array
    {
        if (isset($a['in']) || isset($a['out']) || isset($a['loop'])) {
            return $a;
        }

        return ['in' => ['preset' => $a[0], 'delay_ms' => $a[1] ?? 0, 'duration_ms' => $a[2] ?? 700, 'easing' => $a[3] ?? 'ease-out']];
    }

    private function push(array $e): self
    {
        $this->els[] = $e;

        return $this;
    }

    /** @return float bottom y of the box */
    public function text(string $text, float $x, float $y, float $w, array $o = []): float
    {
        $fontKey = $this->type->key($o['font'] ?? 'body');
        $isHead = ($o['font'] ?? 'body') === 'head';
        $size = (float) $o['size'];
        $weight = $o['weight'] ?? ($isHead ? $this->type->headWeight : $this->type->bodyWeight);
        $lh = $o['lh'] ?? 1.3;
        $ls = $o['ls'] ?? 0.0;
        $case = $o['case'] ?? 'none';
        $role = $o['role'] ?? 'body';
        $h = $o['h'] ?? FontMetrics::height($text, $fontKey, $size, $weight, $w, $lh, $ls, $case, FontMetrics::HEADROOM, ! empty($o['italic']));
        $tok = [];
        if (($ct = $this->pal->tokenFor($o['color'] ?? 'ink')) !== null) {
            $tok['color'] = $ct;
        }
        if (($o['font'] ?? 'body') === 'head') {
            $tok['font'] = 'brand.fonts.heading';
        } elseif (($o['font'] ?? 'body') === 'body') {
            $tok['font'] = 'brand.fonts.body';
        }
        $o['_tokens'] = $tok;
        $e = $this->common('text', $o['name'] ?? (self::ROLE_NAMES[$role] ?? 'Text'), $role, $x, $y, $w, $h, $o);
        $e += [
            'text' => $text, 'font' => $fontKey, 'fontSize' => round($size, 2), 'fontWeight' => (int) $weight,
            'align' => $o['align'] ?? 'left', 'valign' => $o['valign'] ?? 'top', 'lineHeight' => $lh,
            'letterSpacing' => $ls, 'textCase' => $case, 'color' => $this->pal->get($o['color'] ?? 'ink'),
        ];
        if (! empty($o['italic'])) {
            $e['italic'] = true;
        }
        if (($o['fit'] ?? 'shrink') !== 'none') {
            // Every text shrinks to fit its box: replaceable copy survives a longer value, and a wider brand font cannot
            // push a label out of its card or break a word. Sample copy always fits at its authored size (tested).
            $e['autoFit'] = $o['fit'] ?? 'shrink';
        }
        if (! empty($o['underline'])) {
            $e['underline'] = true;
        }
        $this->push($e);

        return round($y + $h, 2);
    }

    private function paint(string|array|null $fill): string|array|null
    {
        if ($fill === null) {
            return null;
        }

        return is_array($fill) ? $fill : $this->pal->get($fill);
    }

    public function shape(string $kind, float $x, float $y, float $w, float $h, string|array|null $fill, array $o = []): self
    {
        $tok = [];
        if (is_string($fill) && ($ft = $this->pal->tokenFor($fill)) !== null) {
            $tok['fill'] = $ft;
        }
        if (isset($o['stroke']) && ($st = $this->pal->tokenFor($o['stroke'][0])) !== null) {
            $tok['stroke_color'] = $st;
        }
        $o['_tokens'] = $tok;
        $e = $this->common('shape', $o['name'] ?? 'Shape', $o['role'] ?? 'decor', $x, $y, $w, $h, $o);
        $e['kind'] = $kind;
        $e['fill'] = $this->paint($fill);
        if (isset($o['stroke'])) {
            $s = $o['stroke'];
            $e['stroke'] = ['color' => $this->pal->get($s[0]), 'width' => $s[1], 'dash' => $s[2] ?? 'solid', 'cap' => $s[3] ?? 'butt'];
        }
        if (isset($o['radius'])) {
            $e['radius'] = round($o['radius'], 2);
        }
        if (isset($o['sides'])) {
            $e['sides'] = $o['sides'];
        }
        if (isset($o['points'])) {
            $e['points'] = $o['points'];
        }
        if (isset($o['inner'])) {
            $e['inner'] = $o['inner'];
        }
        if (isset($o['d'])) {
            $e['d'] = $o['d'];
        }

        return $this->push($e);
    }

    public function rect(float $x, float $y, float $w, float $h, string|array|null $fill, array $o = []): self
    {
        return $this->shape('rect', $x, $y, $w, $h, $fill, $o);
    }

    public function ellipse(float $x, float $y, float $w, float $h, string|array|null $fill, array $o = []): self
    {
        return $this->shape('ellipse', $x, $y, $w, $h, $fill, $o);
    }

    /** A thin rule drawn as a filled rectangle (unambiguous in every renderer). */
    public function rule(float $x, float $y, float $w, float $thickness, string $fill, array $o = []): self
    {
        return $this->shape('rect', $x, $y, $w, $thickness, $fill, $o + ['name' => 'Rule']);
    }

    public function path(string $d, float $x, float $y, float $w, float $h, string|array|null $fill, array $o = []): self
    {
        return $this->shape('path', $x, $y, $w, $h, $fill, $o + ['d' => $d]);
    }

    public function image(float $x, float $y, float $w, float $h, array $o = []): self
    {
        $e = $this->common('image', $o['name'] ?? 'Picture', $o['role'] ?? 'photo', $x, $y, $w, $h, $o);
        $e += ['asset_id' => null, 'fit' => 'cover'];
        if (isset($o['mask'])) {
            $e['mask'] = $o['mask'];
        }
        if (isset($o['radius'])) {
            $e['radius'] = round($o['radius'], 2);
        }
        if (isset($o['border'])) {
            $e['border'] = ['color' => $this->pal->get($o['border'][0]), 'width' => $o['border'][1]];
        }

        return $this->push($e);
    }

    /**
     * A picture slot that already looks designed: a coloured backing and a vector accent
     * around an empty image element. $frame: offset | tab | plain | ring | corner.
     */
    public function photo(float $x, float $y, float $w, float $h, ?string $bind, string $frame = 'offset', array $o = []): self
    {
        $back = $o['back'] ?? 'surface';
        $acc = $o['accent'] ?? 'accent';
        $name = $o['name'] ?? 'Picture';
        $d = round($this->u * 2.2, 2);
        $r = $o['radius'] ?? 0;
        switch ($frame) {
            case 'offset':
                $this->rect($x + $d, $y + $d, $w, $h, $acc, ['name' => $name.' accent block', 'radius' => $r]);
                $this->rect($x, $y, $w, $h, $back, ['name' => $name.' backing', 'radius' => $r]);
                break;
            case 'tab':
                $this->rect($x, $y, $w, $h, $back, ['name' => $name.' backing', 'radius' => $r]);
                $this->rect($x, $y + $h - $d * 1.6, $w, $d * 1.6, $acc, ['name' => $name.' accent strip']);
                $h -= $d * 1.6;
                break;
            case 'ring':
                $this->ellipse($x - $d, $y - $d, $w + 2 * $d, $h + 2 * $d, null, ['name' => $name.' ring', 'stroke' => [$acc, max(1.5, $this->u * 0.5)]]);
                $this->ellipse($x, $y, $w, $h, $back, ['name' => $name.' backing']);
                break;
            case 'corner':
                $this->rect($x, $y, $w, $h, $back, ['name' => $name.' backing', 'radius' => $r]);
                $this->rect($x - $d, $y - $d, $d * 5, $d * 5, $acc, ['name' => $name.' corner block']);
                break;
            default:
                $this->rect($x, $y, $w, $h, $back, ['name' => $name.' backing', 'radius' => $r]);
        }
        $io = ['bind' => $bind, 'name' => $name, 'radius' => $r];
        if ($frame === 'ring') {
            $io['mask'] = 'ellipse';
        }

        return $this->image($x, $y, $w, $h, $io);
    }

    public function icon(string $key, float $x, float $y, float $size, string $colour = 'ink', array $o = []): self
    {
        $o['_tokens'] = ($ct = $this->pal->tokenFor($colour)) !== null ? ['color' => $ct] : [];
        $e = $this->common('icon', $o['name'] ?? 'Icon', $o['role'] ?? 'decor', $x, $y, $size, $size, $o);
        $e += ['icon' => $key, 'color' => $this->pal->get($colour), 'strokeWidth' => $o['stroke'] ?? 1.75];

        return $this->push($e);
    }

    /** Children are positioned relative to the group origin ($x,$y). */
    public function group(string $name, float $x, float $y, callable $build, array $o = []): self
    {
        $inner = new self($o['w'] ?? $this->w, $o['h'] ?? $this->h, $this->pal, $this->type, $this->m, $this->ctx);
        $build($inner);
        $this->ctx = $inner->ctx;
        $w = 1.0;
        $h = 1.0;
        foreach ($inner->els as $c) {
            $w = max($w, $c['x'] + $c['width']);
            $h = max($h, $c['y'] + $c['height']);
        }
        $e = $this->common('group', $name, $o['role'] ?? 'other', $x, $y, $w, $h, $o);
        $e['children'] = $inner->els;

        return $this->push($e);
    }

    public function stack(float $x, float $y, float $w): Stack
    {
        return new Stack($this, $x, $y, $w);
    }

    // ---- composites -------------------------------------------------------------------------

    /** A button: filled rounded rectangle plus centred label (bound to cta). */
    public function button(string $label, float $x, float $y, float $w, float $h, string $fill, string $ink, array $o = []): self
    {
        $this->rect($x, $y, $w, $h, $fill, ['name' => 'Button', 'radius' => $o['radius'] ?? $h / 2] + (isset($o['stroke']) ? ['stroke' => $o['stroke']] : []) + (isset($o['anim']) ? ['anim' => $o['anim']] : []));
        $size = $o['size'] ?? round($h * 0.36, 2);
        $lh = 1.2;
        $this->text($label, $x, $y, $w, [
            'size' => $size, 'weight' => $o['weight'] ?? 700, 'font' => $o['font'] ?? 'body', 'color' => $ink, 'align' => 'center', 'valign' => 'middle', 'h' => $h,
            'role' => 'cta', 'bind' => $o['bind'] ?? 'cta', 'name' => 'Button label', 'lh' => $lh, 'ls' => $o['ls'] ?? 0.02, 'case' => $o['case'] ?? 'none',
        ] + (isset($o['anim']) ? ['anim' => $o['anim']] : []));

        return $this;
    }

    /** One icon + text row per item, stacked. Returns bottom y. */
    public function contactList(array $items, float $x, float $y, float $w, float $size, string $ink, string $iconInk, float $gap = 0.9): float
    {
        $row = $size * (1.3 + $gap);
        foreach ($items as $it) {
            $this->icon($it[0], $x, $y + ($size * 1.3 - $size * 1.15) / 2, $size * 1.15, $iconInk, ['name' => 'Icon for '.$it[2], 'role' => 'decor']);
            $this->text($it[1], $x + $size * 1.8, $y, $w - $size * 1.8, ['size' => $size, 'color' => $ink, 'role' => 'contact', 'bind' => $it[3] ?? null, 'name' => ucfirst($it[2]), 'lh' => 1.3, 'h' => $size * 1.3] + (isset($it[3]) ? [] : []));
            $y += $row;
        }

        return round($y - $size * $gap, 2);
    }

    /** Horizontal contact strip: items spread evenly across $w, icon above-left of text. */
    public function contactRow(array $items, float $x, float $y, float $w, float $size, string $ink, string $iconInk): float
    {
        $n = count($items);
        $cw = $w / $n;
        foreach ($items as $i => $it) {
            $cx = $x + $i * $cw;
            $this->icon($it[0], $cx, $y, $size * 1.15, $iconInk, ['name' => 'Icon for '.$it[2]]);
            $this->text($it[1], $cx + $size * 1.7, $y, $cw - $size * 1.9, ['size' => $size, 'color' => $ink, 'role' => 'contact', 'bind' => $it[3] ?? null, 'name' => ucfirst($it[2]), 'lh' => 1.3, 'h' => $size * 1.3]);
        }

        return round($y + $size * 1.3, 2);
    }

    // ---- output ----------------------------------------------------------------------------

    public function toArray(?string $name = null): array
    {
        $p = [];
        if ($name !== null) {
            $p['name'] = $name;
        }
        $p += ['width' => $this->w, 'height' => $this->h, 'background' => $this->background, 'duration_ms' => $this->duration];
        if ($this->transition !== null) {
            $p['transition'] = $this->transition;
        }
        $p['elements'] = $this->bgRect !== null ? [$this->bgRect, ...$this->els] : $this->els;

        return $p;
    }
}

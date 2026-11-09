<?php

// Dev tool: regenerates resources/design/elements.json (reusable vector design elements, patterns,
// gradient presets and neutral palettes). Everything is drawn here from shapes and paths; no external art.
// Usage: php scripts/dev/build-elements.php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/database/templates/Support/bootstrap.php';

use Database\Templates\Support\Page;
use Database\Templates\Support\Palette;
use Database\Templates\Support\TypePair;

// Two working colours; both follow the brand kit through tokens (primary = main, neutral = ink).
$pal = new Palette('#ffffff', '#1f2937', '#e4572e', '#f3f4f6', '#ffd166');
$ty = new TypePair('inter', 'inter');
$out = ['$comment' => 'Dot.Design reusable design elements. Every "element" is a schema v2 element tree the editor inserts as a group. Drawn for this repository from shapes and paths (MIT, no external artwork). Colours are the working pair #1f2937 and #e4572e and carry brand tokens. Regenerate with scripts/dev/build-elements.php.', 'version' => 1];

function one(Palette $pal, TypePair $ty, float $w, float $h, callable $draw, string $name): array
{
    $p = Page::make($w, $h, $pal, $ty, 0, 'x');
    $p->group($name, 0, 0, $draw, ['w' => $w, 'h' => $h]);
    $els = $p->toArray()['elements'];

    return $els[0];
}

function item(string $key, string $name, string $cat, string $tags, array $element): array
{
    return ['key' => $key, 'name' => $name, 'category' => $cat, 'tags' => array_map('trim', explode(',', $tags)), 'width' => $element['width'], 'height' => $element['height'], 'element' => $element];
}

$mk = fn (float $w, float $h, callable $d, string $name) => one($pal, $ty, $w, $h, $d, $name);

// ---- dividers --------------------------------------------------------------------------------
$div = [];
$div[] = item('divider-thin', 'Thin rule', 'divider', 'line,rule,separator', $mk(400, 4, fn ($g) => $g->rect(0, 1, 400, 2, 'ink', ['name' => 'Rule']), 'Thin rule'));
$div[] = item('divider-thick', 'Thick rule', 'divider', 'line,rule,heavy', $mk(400, 10, fn ($g) => $g->rect(0, 0, 400, 8, 'ink', ['name' => 'Rule']), 'Thick rule'));
$div[] = item('divider-double', 'Double rule', 'divider', 'line,rule,formal', $mk(400, 12, function ($g) {
    $g->rect(0, 0, 400, 3, 'ink', ['name' => 'Upper rule']);
    $g->rect(0, 8, 400, 1.5, 'ink', ['name' => 'Lower rule']);
}, 'Double rule'));
$div[] = item('divider-dashed', 'Dashed rule', 'divider', 'line,dashed,separator', $mk(400, 4, fn ($g) => $g->rect(0, 0, 400, 0.01 + 1, null, ['name' => 'Dashed rule', 'stroke' => ['ink', 2, 'dashed']]), 'Dashed rule'));
$div[] = item('divider-dotted', 'Dotted rule', 'divider', 'line,dotted,separator', $mk(400, 6, fn ($g) => $g->rect(0, 2, 400, 1, null, ['name' => 'Dotted rule', 'stroke' => ['ink', 4, 'dotted', 'round']]), 'Dotted rule'));
$div[] = item('divider-diamond', 'Rule with diamond', 'divider', 'ornament,elegant,separator', $mk(400, 20, function ($g) {
    $g->rect(0, 9, 170, 2, 'accent', ['name' => 'Left rule']);
    $g->rect(230, 9, 170, 2, 'accent', ['name' => 'Right rule']);
    $g->shape('rect', 188, 2, 24, 24, 'accent', ['name' => 'Diamond', 'rotation' => 45, 'radius' => 0]);
}, 'Rule with diamond'));
$div[] = item('divider-dots', 'Rule with dots', 'divider', 'ornament,playful,separator', $mk(400, 16, function ($g) {
    $g->rect(14, 7, 372, 2, 'ink', ['name' => 'Rule']);
    $g->ellipse(0, 0, 16, 16, 'accent', ['name' => 'Left dot']);
    $g->ellipse(384, 0, 16, 16, 'accent', ['name' => 'Right dot']);
}, 'Rule with dots'));
$div[] = item('divider-wave', 'Wave rule', 'divider', 'wave,playful,separator', $mk(400, 24, fn ($g) => $g->path('M0 12C25 0 50 0 75 12S125 24 150 12S200 0 225 12S275 24 300 12S350 0 375 12S400 12 400 12', 0, 0, 400, 24, null, ['name' => 'Wave', 'stroke' => ['accent', 3, 'solid', 'round']]), 'Wave rule'));
$out['dividers'] = $div;

// ---- corners and marks -----------------------------------------------------------------------
$cor = [];
$cor[] = item('corner-bracket', 'Corner bracket', 'corner', 'frame,corner,minimal', $mk(80, 80, function ($g) {
    $g->rect(0, 0, 80, 6, 'ink', ['name' => 'Top bar']);
    $g->rect(0, 0, 6, 80, 'ink', ['name' => 'Side bar']);
}, 'Corner bracket'));
$cor[] = item('corner-bracket-pair', 'Two corner brackets', 'corner', 'frame,corner,pair', $mk(300, 200, function ($g) {
    $g->rect(0, 0, 60, 5, 'ink', ['name' => 'Top left bar']);
    $g->rect(0, 0, 5, 60, 'ink', ['name' => 'Top left side']);
    $g->rect(240, 195, 60, 5, 'ink', ['name' => 'Bottom right bar']);
    $g->rect(295, 140, 5, 60, 'ink', ['name' => 'Bottom right side']);
}, 'Two corner brackets'));
$cor[] = item('corner-crop-marks', 'Crop marks', 'corner', 'print,crop,trim', $mk(300, 200, function ($g) {
    foreach ([[10, 0, 0, 10], [290, 0, 290, 10], [10, 200, 0, 190], [290, 200, 290, 190]] as $i => $c) {
        $g->rect($i % 2 === 0 ? 0 : 280, $i < 2 ? 0 : 199, 20, 1, 'ink', ['name' => 'Horizontal mark']);
        $g->rect($i % 2 === 0 ? 0 : 299, $i < 2 ? 0 : 180, 1, 20, 'ink', ['name' => 'Vertical mark']);
    }
}, 'Crop marks'));
$cor[] = item('corner-flourish', 'Corner flourish', 'corner', 'ornament,elegant,corner', $mk(110, 110, fn ($g) => $g->path('M4 106V40C4 20 20 4 40 4H106M18 106V52C18 33 33 18 52 18H106M32 106V62C32 47 47 32 62 32H106', 0, 0, 110, 110, null, ['name' => 'Flourish lines', 'stroke' => ['accent', 2.5, 'solid', 'round']]), 'Corner flourish'));
$cor[] = item('corner-triangle', 'Corner triangle', 'corner', 'corner,bold,shape', $mk(160, 160, fn ($g) => $g->path('M0 0H160L0 160Z', 0, 0, 160, 160, 'accent', ['name' => 'Triangle']), 'Corner triangle'));
$cor[] = item('registration-target', 'Registration target', 'corner', 'print,mark,target', $mk(64, 64, function ($g) {
    $g->ellipse(8, 8, 48, 48, null, ['name' => 'Ring', 'stroke' => ['ink', 2]]);
    $g->ellipse(22, 22, 20, 20, null, ['name' => 'Inner ring', 'stroke' => ['ink', 2]]);
    $g->rect(31, 0, 2, 64, 'ink', ['name' => 'Vertical hair']);
    $g->rect(0, 31, 64, 2, 'ink', ['name' => 'Horizontal hair']);
}, 'Registration target'));
$out['corners'] = $cor;

// ---- badges and ribbons ----------------------------------------------------------------------
$bad = [];
$bad[] = item('badge-circle', 'Round badge', 'badge', 'seal,circle,label', $mk(200, 200, function ($g) {
    $g->ellipse(0, 0, 200, 200, 'accent', ['name' => 'Disc']);
    $g->ellipse(14, 14, 172, 172, null, ['name' => 'Inner ring', 'stroke' => ['bg', 3, 'dashed']]);
}, 'Round badge'));
$bad[] = item('badge-seal', 'Scalloped seal', 'badge', 'seal,award,quality', $mk(200, 200, function ($g) {
    $g->shape('star', 0, 0, 200, 200, 'accent', ['name' => 'Scallops', 'points' => 16, 'inner' => 0.88]);
    $g->ellipse(32, 32, 136, 136, null, ['name' => 'Inner ring', 'stroke' => ['bg', 3]]);
}, 'Scalloped seal'));
$bad[] = item('badge-sale-burst', 'Sale burst', 'badge', 'sale,burst,offer', $mk(220, 220, fn ($g) => $g->shape('star', 0, 0, 220, 220, 'accent', ['name' => 'Burst', 'points' => 14, 'inner' => 0.78]), 'Sale burst'));
$bad[] = item('badge-hexagon', 'Hexagon badge', 'badge', 'hexagon,label,tech', $mk(200, 180, fn ($g) => $g->shape('polygon', 0, 0, 200, 180, 'accent', ['name' => 'Hexagon', 'sides' => 6]), 'Hexagon badge'));
$bad[] = item('badge-shield', 'Shield badge', 'badge', 'shield,trust,security', $mk(160, 190, fn ($g) => $g->path('M80 4L152 28V92C152 138 120 170 80 186C40 170 8 138 8 92V28Z', 0, 0, 160, 190, 'accent', ['name' => 'Shield']), 'Shield badge'));
$bad[] = item('ribbon-banner', 'Ribbon banner', 'ribbon', 'ribbon,banner,label', $mk(360, 90, function ($g) {
    $g->path('M0 14H44V78H0L18 46Z', 0, 0, 44, 90, 'ink', ['name' => 'Left tail']);
    $g->path('M0 14H44L26 46L44 78H0Z', 316, 0, 44, 90, 'ink', ['name' => 'Right tail']);
    $g->rect(36, 0, 288, 76, 'accent', ['name' => 'Ribbon body']);
    $g->path('M0 0H24L0 14Z', 36, 76, 24, 14, 'ink', ['name' => 'Left fold']);
    $g->path('M0 0H24V14Z', 300, 76, 24, 14, 'ink', ['name' => 'Right fold']);
}, 'Ribbon banner'));
$bad[] = item('ribbon-corner', 'Corner ribbon', 'ribbon', 'ribbon,corner,new', $mk(160, 160, fn ($g) => $g->path('M0 70L70 0H110L0 110Z', 0, 0, 160, 160, 'accent', ['name' => 'Corner ribbon']), 'Corner ribbon'));
$bad[] = item('label-rounded', 'Rounded label', 'badge', 'label,pill,tag', $mk(220, 70, fn ($g) => $g->rect(0, 0, 220, 70, 'accent', ['name' => 'Pill', 'radius' => 35]), 'Rounded label'));
$out['badges'] = $bad;

// ---- speech bubbles --------------------------------------------------------------------------
$sp = [];
$sp[] = item('bubble-round', 'Round speech bubble', 'bubble', 'speech,chat,comment', $mk(260, 200, function ($g) {
    $g->rect(0, 0, 260, 160, 'accent', ['name' => 'Bubble', 'radius' => 44]);
    $g->path('M10 0H70L0 50Z', 40, 150, 70, 50, 'accent', ['name' => 'Tail']);
}, 'Round speech bubble'));
$sp[] = item('bubble-round-right', 'Speech bubble, tail right', 'bubble', 'speech,chat,reply', $mk(260, 200, function ($g) {
    $g->rect(0, 0, 260, 160, 'accent', ['name' => 'Bubble', 'radius' => 44]);
    $g->path('M0 0H60L70 50Z', 150, 150, 70, 50, 'accent', ['name' => 'Tail']);
}, 'Speech bubble, tail right'));
$sp[] = item('bubble-square', 'Square speech bubble', 'bubble', 'speech,callout,box', $mk(260, 200, function ($g) {
    $g->rect(0, 0, 260, 160, 'accent', ['name' => 'Bubble', 'radius' => 8]);
    $g->path('M0 0H60L0 40Z', 40, 160, 60, 40, 'accent', ['name' => 'Tail']);
}, 'Square speech bubble'));
$sp[] = item('bubble-outline', 'Outline speech bubble', 'bubble', 'speech,outline,comment', $mk(260, 200, function ($g) {
    $g->path('M44 4H216C240 4 256 20 256 44V116C256 140 240 156 216 156H110L40 196V156H44C20 156 4 140 4 116V44C4 20 20 4 44 4Z', 0, 0, 260, 200, null, ['name' => 'Bubble outline', 'stroke' => ['ink', 4, 'solid', 'round']]);
}, 'Outline speech bubble'));
$sp[] = item('bubble-thought', 'Thought bubble', 'bubble', 'thought,cloud,idea', $mk(260, 230, function ($g) {
    $g->ellipse(0, 0, 260, 160, 'accent', ['name' => 'Cloud']);
    $g->ellipse(40, 170, 28, 28, 'accent', ['name' => 'Dot large']);
    $g->ellipse(18, 206, 16, 16, 'accent', ['name' => 'Dot small']);
}, 'Thought bubble'));
$sp[] = item('bubble-shout', 'Shout bubble', 'bubble', 'shout,burst,exclaim', $mk(260, 220, fn ($g) => $g->shape('star', 0, 0, 260, 220, 'accent', ['name' => 'Shout', 'points' => 11, 'inner' => 0.72]), 'Shout bubble'));
$out['bubbles'] = $sp;

// ---- frames with picture slots ---------------------------------------------------------------
$fr = [];
$fr[] = item('frame-offset', 'Offset block frame', 'frame', 'photo,frame,bold', $mk(330, 330, function ($g) {
    $g->rect(24, 24, 300, 300, 'accent', ['name' => 'Accent block']);
    $g->rect(0, 0, 300, 300, 'surface', ['name' => 'Picture backing']);
    $g->image(0, 0, 300, 300, ['name' => 'Picture']);
}, 'Offset block frame'));
$fr[] = item('frame-polaroid', 'Snapshot frame', 'frame', 'photo,polaroid,snapshot', $mk(300, 360, function ($g) {
    $g->rect(0, 0, 300, 360, 'bg', ['name' => 'Card', 'shadow' => [0, 6, 18, 'ink', 0.2]]);
    $g->rect(20, 20, 260, 260, 'surface', ['name' => 'Picture backing']);
    $g->image(20, 20, 260, 260, ['name' => 'Picture']);
}, 'Snapshot frame'));
$fr[] = item('frame-circle', 'Circle frame', 'frame', 'photo,round,avatar', $mk(320, 320, function ($g) {
    $g->ellipse(0, 0, 320, 320, null, ['name' => 'Ring', 'stroke' => ['accent', 5]]);
    $g->ellipse(18, 18, 284, 284, 'surface', ['name' => 'Picture backing']);
    $g->image(18, 18, 284, 284, ['name' => 'Picture', 'mask' => 'ellipse']);
}, 'Circle frame'));
$fr[] = item('frame-rounded', 'Rounded frame', 'frame', 'photo,soft,rounded', $mk(320, 320, function ($g) {
    $g->rect(0, 0, 320, 320, 'surface', ['name' => 'Picture backing', 'radius' => 40]);
    $g->image(0, 0, 320, 320, ['name' => 'Picture', 'radius' => 40]);
}, 'Rounded frame'));
$fr[] = item('frame-double-line', 'Double line frame', 'frame', 'photo,elegant,line', $mk(340, 340, function ($g) {
    $g->rect(0, 0, 340, 340, null, ['name' => 'Outer line', 'stroke' => ['accent', 1.5]]);
    $g->rect(14, 14, 312, 312, 'surface', ['name' => 'Picture backing']);
    $g->image(14, 14, 312, 312, ['name' => 'Picture']);
}, 'Double line frame'));
$fr[] = item('frame-tab', 'Tab frame', 'frame', 'photo,tab,clean', $mk(320, 300, function ($g) {
    $g->rect(0, 0, 320, 300, 'surface', ['name' => 'Picture backing']);
    $g->image(0, 0, 320, 280, ['name' => 'Picture']);
    $g->rect(0, 280, 320, 20, 'accent', ['name' => 'Accent strip']);
}, 'Tab frame'));
$out['frames'] = $fr;

// ---- price tags and bursts -------------------------------------------------------------------
$pt = [];
$pt[] = item('price-tag', 'Hang tag', 'price-tag', 'price,tag,sale', $mk(220, 130, function ($g) {
    $g->path('M0 65L44 0H214C218 0 220 2 220 6V124C220 128 218 130 214 130H44Z', 0, 0, 220, 130, 'accent', ['name' => 'Tag']);
    $g->ellipse(30, 55, 20, 20, 'bg', ['name' => 'Hole']);
}, 'Hang tag'));
$pt[] = item('price-circle', 'Price circle', 'price-tag', 'price,circle,sticker', $mk(180, 180, function ($g) {
    $g->ellipse(0, 0, 180, 180, 'accent', ['name' => 'Disc']);
    $g->ellipse(12, 12, 156, 156, null, ['name' => 'Ring', 'stroke' => ['bg', 2.5]]);
}, 'Price circle'));
$pt[] = item('price-ribbon', 'Price ribbon', 'price-tag', 'price,ribbon,flag', $mk(260, 90, fn ($g) => $g->path('M0 0H260L230 45L260 90H0Z', 0, 0, 260, 90, 'accent', ['name' => 'Ribbon']), 'Price ribbon'));
$pt[] = item('price-notch', 'Notched price label', 'price-tag', 'price,label,notch', $mk(240, 100, fn ($g) => $g->path('M0 0H240V34L228 50L240 66V100H0V66L12 50L0 34Z', 0, 0, 240, 100, 'accent', ['name' => 'Label']), 'Notched price label'));
$out['price_tags'] = $pt;

$bu = [];
$bu[] = item('star-5', 'Five point star', 'star', 'star,rating,shape', $mk(180, 180, fn ($g) => $g->shape('star', 0, 0, 180, 180, 'accent', ['name' => 'Star', 'points' => 5, 'inner' => 0.45]), 'Five point star'));
$bu[] = item('star-burst-8', 'Eight point burst', 'star', 'burst,shine,shape', $mk(200, 200, fn ($g) => $g->shape('star', 0, 0, 200, 200, 'accent', ['name' => 'Burst', 'points' => 8, 'inner' => 0.6]), 'Eight point burst'));
$bu[] = item('star-burst-12', 'Twelve point burst', 'star', 'burst,sale,shape', $mk(200, 200, fn ($g) => $g->shape('star', 0, 0, 200, 200, 'accent', ['name' => 'Burst', 'points' => 12, 'inner' => 0.78]), 'Twelve point burst'));
$bu[] = item('sparkle', 'Sparkle', 'star', 'sparkle,magic,new', $mk(140, 140, fn ($g) => $g->path('M70 0C74 46 94 66 140 70C94 74 74 94 70 140C66 94 46 74 0 70C46 66 66 46 70 0Z', 0, 0, 140, 140, 'accent', ['name' => 'Sparkle']), 'Sparkle'));
$out['bursts'] = $bu;

// ---- arrows and callouts ---------------------------------------------------------------------
$ar = [];
$ar[] = item('arrow-straight', 'Straight arrow', 'arrow', 'arrow,pointer,direction', $mk(260, 40, function ($g) {
    $g->rect(0, 17, 220, 6, 'ink', ['name' => 'Shaft']);
    $g->path('M0 0L40 20L0 40Z', 220, 0, 40, 40, 'ink', ['name' => 'Head']);
}, 'Straight arrow'));
$ar[] = item('arrow-curved', 'Curved arrow', 'arrow', 'arrow,curved,hand-drawn', $mk(220, 140, function ($g) {
    $g->path('M8 130C20 40 110 10 190 40', 0, 0, 220, 140, null, ['name' => 'Curve', 'stroke' => ['ink', 5, 'solid', 'round']]);
    $g->path('M160 14L204 44L156 66', 0, 0, 220, 140, null, ['name' => 'Head', 'stroke' => ['ink', 5, 'solid', 'round']]);
}, 'Curved arrow'));
$ar[] = item('arrow-double', 'Double arrow', 'arrow', 'arrow,both,between', $mk(280, 40, function ($g) {
    $g->rect(34, 17, 212, 6, 'ink', ['name' => 'Shaft']);
    $g->path('M40 0L0 20L40 40Z', 0, 0, 40, 40, 'ink', ['name' => 'Left head']);
    $g->path('M0 0L40 20L0 40Z', 240, 0, 40, 40, 'ink', ['name' => 'Right head']);
}, 'Double arrow'));
$ar[] = item('arrow-chevrons', 'Chevron arrows', 'arrow', 'chevron,next,swipe', $mk(160, 60, function ($g) {
    foreach ([0, 46, 92] as $i => $x) {
        $g->path('M0 0L24 30L0 60', $x, 0, 28, 60, null, ['name' => 'Chevron '.($i + 1), 'stroke' => ['ink', 6, 'solid', 'round'], 'opacity' => 1 - $i * 0.25]);
    }
}, 'Chevron arrows'));
$ar[] = item('arrow-block', 'Block arrow', 'arrow', 'arrow,bold,direction', $mk(260, 120, fn ($g) => $g->path('M0 36H160V0L260 60L160 120V84H0Z', 0, 0, 260, 120, 'accent', ['name' => 'Block arrow']), 'Block arrow'));
$ar[] = item('callout-number', 'Numbered callout', 'callout', 'callout,number,step', $mk(80, 80, function ($g) {
    $g->ellipse(0, 0, 80, 80, 'accent', ['name' => 'Disc']);
    $g->text('1', 0, 0, 80, ['size' => 40, 'weight' => 800, 'color' => 'bg', 'align' => 'center', 'valign' => 'middle', 'h' => 80, 'role' => 'badge', 'name' => 'Number', 'lh' => 1]);
}, 'Numbered callout'));
$ar[] = item('callout-line', 'Callout with line', 'callout', 'callout,label,pointer', $mk(260, 90, function ($g) {
    $g->ellipse(0, 70, 20, 20, 'accent', ['name' => 'Anchor dot']);
    $g->path('M10 70L50 20H260', 0, 0, 260, 90, null, ['name' => 'Leader line', 'stroke' => ['ink', 3, 'solid', 'round']]);
}, 'Callout with line'));
$ar[] = item('underline-scribble', 'Hand drawn underline', 'callout', 'underline,emphasis,scribble', $mk(300, 40, fn ($g) => $g->path('M6 26C60 8 120 8 180 18S260 30 294 12', 0, 0, 300, 40, null, ['name' => 'Underline', 'stroke' => ['accent', 6, 'solid', 'round']]), 'Hand drawn underline'));
$out['arrows'] = $ar;

// ---- patterns (large groups on a 600 x 600 tile; ~16 to 130 elements each) -----------------------

/** Clip the square 0..$S by the band $c1 <= x + y <= $c2 (Sutherland-Hodgman); returns [[x,y],...]. */
function band(float $c1, float $c2, float $S): array
{
    $poly = [[0, 0], [$S, 0], [$S, $S], [0, $S]];
    foreach ([[1, $c1, true], [-1, -$c2, true]] as [$sign, $limit]) {
        $next = [];
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $a = $poly[$i];
            $b = $poly[($i + 1) % $n];
            $da = $sign * ($a[0] + $a[1]) - $limit;
            $db = $sign * ($b[0] + $b[1]) - $limit;
            if ($da >= 0) {
                $next[] = $a;
            }
            if (($da >= 0) !== ($db >= 0)) {
                $t = $da / ($da - $db);
                $next[] = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
            }
        }
        $poly = $next;
    }

    return $poly;
}

/** A closed polygon as one path element inside its own bounding box. */
function polyPath($g, array $pts, string $fill, string $name, array $o = []): void
{
    $xs = array_column($pts, 0);
    $ys = array_column($pts, 1);
    $x0 = min($xs);
    $y0 = min($ys);
    $w = max(1, max($xs) - $x0);
    $h = max(1, max($ys) - $y0);
    $d = '';
    foreach ($pts as $i => $q) {
        $d .= ($i === 0 ? 'M' : 'L').round($q[0] - $x0, 2).' '.round($q[1] - $y0, 2);
    }
    $g->path($d.'Z', $x0, $y0, $w, $h, $fill, ['name' => $name] + $o);
}

$pa = [];
$pa[] = item('pattern-dots', 'Dot grid', 'pattern', 'dots,grid,subtle', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 10; $r++) {
        for ($c = 0; $c < 10; $c++) {
            $g->ellipse(22 + $c * 60, 22 + $r * 60, 16, 16, 'ink', ['name' => 'Dot']);
        }
    }
}, 'Dot grid'));
$pa[] = item('pattern-stripes', 'Diagonal stripes', 'pattern', 'stripes,diagonal,bold', $mk(600, 600, function ($g) {
    for ($c = 60; $c < 1200; $c += 120) {
        $pts = band($c, $c + 60, 600);
        if (count($pts) >= 3) {
            polyPath($g, $pts, 'ink', 'Stripe');
        }
    }
}, 'Diagonal stripes'));
$pa[] = item('pattern-checks', 'Checkerboard', 'pattern', 'checks,chess,bold', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 8; $r++) {
        for ($c = 0; $c < 8; $c++) {
            if (($r + $c) % 2 === 0) {
                $g->rect($c * 75, $r * 75, 75, 75, 'ink', ['name' => 'Square']);
            }
        }
    }
}, 'Checkerboard'));
$pa[] = item('pattern-waves', 'Wave lines', 'pattern', 'waves,lines,flow', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 9; $r++) {
        $g->path('M0 20C50 0 100 0 150 20S250 40 300 20S400 0 450 20S550 40 600 20', 0, $r * 66 + 6, 600, 40, null, ['name' => 'Wave', 'stroke' => ['ink', 4, 'solid', 'round']]);
    }
}, 'Wave lines'));
$pa[] = item('pattern-grid', 'Fine grid', 'pattern', 'grid,graph,blueprint', $mk(600, 600, function ($g) {
    for ($i = 0; $i <= 10; $i++) {
        $g->rect($i * 59.5, 0, 1.5, 600, 'ink', ['name' => 'Vertical line']);
        $g->rect(0, $i * 59.5, 600, 1.5, 'ink', ['name' => 'Horizontal line']);
    }
}, 'Fine grid'));
$pa[] = item('pattern-confetti', 'Confetti', 'pattern', 'confetti,party,playful', $mk(600, 600, function ($g) {
    mt_srand(4521);
    $fills = ['accent', 'ink', 'extra'];
    for ($i = 0; $i < 56; $i++) {
        $x = mt_rand(0, 560);
        $y = mt_rand(0, 560);
        $s = mt_rand(14, 34);
        $f = $fills[$i % 3];
        $kind = $i % 3;
        if ($kind === 0) {
            $g->ellipse($x, $y, $s, $s, $f, ['name' => 'Confetti dot']);
        } elseif ($kind === 1) {
            $g->rect($x, $y, $s, $s * 0.4, $f, ['name' => 'Confetti strip', 'rotation' => mt_rand(-60, 60)]);
        } else {
            $g->shape('triangle', $x, $y, $s, $s, $f, ['name' => 'Confetti triangle', 'rotation' => mt_rand(-40, 40)]);
        }
    }
}, 'Confetti'));
$pa[] = item('pattern-contours', 'Contour lines', 'pattern', 'topographic,contours,organic', $mk(600, 600, function ($g) {
    for ($i = 0; $i < 9; $i++) {
        $r = 24 + $i * 32;
        $w = $r * 2 + 16;
        $c = $w / 2;
        $k = $r * 0.55;
        $d = sprintf('M%g %gC%g %g %g %g %g %gC%g %g %g %g %g %gC%g %g %g %g %g %gC%g %g %g %g %g %gZ', $c + $r, $c, $c + $r, $c + $k + $i, $c + $k, $c + $r, $c, $c + $r - 6, $c - $k - $i, $c + $r + 4, $c - $r - 3, $c + $k, $c - $r, $c, $c - $r + 4, $c - $k, $c - $k + 6, $c - $r - 2, $c, $c - $r, $c + $k + 8, $c - $r + 3, $c + $r, $c - $k + 3, $c + $r, $c);
        $g->path($d, 300 - $c, 300 - $c, $w, $w, null, ['name' => 'Contour', 'stroke' => ['ink', 2.5, 'solid', 'round']]);
    }
}, 'Contour lines'));
$pa[] = item('pattern-halftone', 'Halftone dots', 'pattern', 'halftone,dots,gradient', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 10; $r++) {
        for ($c = 0; $c < 10; $c++) {
            $d = 3 + $r * 5.4;
            $g->ellipse($c * 60 + 30 - $d / 2, $r * 60 + 30 - $d / 2, $d, $d, 'ink', ['name' => 'Halftone dot']);
        }
    }
}, 'Halftone dots'));
$pa[] = item('pattern-triangles', 'Triangle tiles', 'pattern', 'triangles,geometric,tiles', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 6; $r++) {
        for ($c = 0; $c < 6; $c++) {
            $g->shape('triangle', $c * 100, $r * 100, 100, 100, ($r + $c) % 2 === 0 ? 'ink' : 'accent', ['name' => 'Triangle'] + (($r + $c) % 2 === 0 ? [] : ['flip_y' => true]));
        }
    }
}, 'Triangle tiles'));
$pa[] = item('pattern-zigzag', 'Zigzag lines', 'pattern', 'zigzag,lines,retro', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 9; $r++) {
        $g->path('M0 24L30 0L60 24L90 0L120 24L150 0L180 24L210 0L240 24L270 0L300 24L330 0L360 24L390 0L420 24L450 0L480 24L510 0L540 24L570 0L600 24', 0, $r * 66 + 6, 600, 26, null, ['name' => 'Zigzag', 'stroke' => ['ink', 4, 'solid', 'round']]);
    }
}, 'Zigzag lines'));
$pa[] = item('pattern-rings', 'Overlapping rings', 'pattern', 'rings,circles,geometric', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 5; $r++) {
        for ($c = 0; $c < 5; $c++) {
            $g->ellipse($c * 100 + 4, $r * 100 + 4, 190 > 600 - ($c * 100 + 4) ? 96 : 190, 190 > 600 - ($r * 100 + 4) ? 96 : 190, null, ['name' => 'Ring', 'stroke' => [($r + $c) % 2 ? 'accent' : 'ink', 3]]);
        }
    }
}, 'Overlapping rings'));
$pa[] = item('pattern-plus', 'Plus signs', 'pattern', 'plus,cross,grid', $mk(600, 600, function ($g) {
    for ($r = 0; $r < 8; $r++) {
        for ($c = 0; $c < 8; $c++) {
            $g->path('M9 0H15V9H24V15H15V24H9V15H0V9H9Z', 18 + $c * 72, 18 + $r * 72, 24, 24, 'ink', ['name' => 'Plus']);
        }
    }
}, 'Plus signs'));
$out['patterns'] = $pa;

// ---- gradient presets ------------------------------------------------------------------------
$gp = static fn (string $key, string $name, string $kind, float $angle, array $stops, string $use): array => ['key' => $key, 'name' => $name, 'use' => $use, 'gradient' => ['kind' => $kind, 'angle' => $angle, 'stops' => array_map(fn ($s) => ['at' => $s[0], 'color' => $s[1]], $stops)]];
$out['gradients'] = [
    $gp('sunrise', 'Sunrise', 'linear', 160, [[0, '#ff7e5f'], [1, '#feb47b']], 'Warm backgrounds for food, lifestyle and promotions.'),
    $gp('ocean', 'Ocean', 'linear', 135, [[0, '#0f4c81'], [1, '#2bb3c0']], 'Calm, trustworthy panels for services and travel.'),
    $gp('forest', 'Forest', 'linear', 150, [[0, '#14532d'], [1, '#4ade80']], 'Natural and eco themes.'),
    $gp('dusk', 'Dusk', 'linear', 180, [[0, '#3b1d6e'], [0.55, '#c2417a'], [1, '#ffb36b']], 'Dramatic event and music backgrounds.'),
    $gp('paper', 'Soft paper', 'linear', 180, [[0, '#ffffff'], [1, '#e9e6df']], 'Quiet backgrounds for documents and menus.'),
    $gp('midnight', 'Midnight', 'linear', 180, [[0, '#0b1220'], [1, '#1d2b4a']], 'Dark, premium slides and posts.'),
    $gp('candy', 'Candy', 'linear', 120, [[0, '#ff6fb5'], [1, '#ffcf5c']], 'Playful, bright social posts.'),
    $gp('mint', 'Mint', 'linear', 140, [[0, '#d9f7ec'], [1, '#8fe0c8']], 'Fresh, light backgrounds for health and beauty.'),
    $gp('steel', 'Steel', 'linear', 160, [[0, '#2f3b4c'], [1, '#6c7a8d']], 'Corporate and technical panels.'),
    $gp('peach', 'Peach', 'linear', 170, [[0, '#ffe3d1'], [1, '#ffb89a']], 'Soft, friendly backgrounds.'),
    $gp('spotlight', 'Spotlight', 'radial', 0, [[0, '#4b5d8a'], [1, '#101829']], 'A glow behind a product or title.'),
    $gp('citrus', 'Citrus', 'linear', 130, [[0, '#ffd23f'], [1, '#ff8c1a']], 'Energetic sale and offer backgrounds.'),
];

// ---- palettes (brand-neutral colour sets, roles match the Brand Studio) -------------------------
$pl = static function (string $key, string $name, string $use, array $c): array {
    $roles = ['primary', 'secondary', 'accent', 'neutral', 'extra'];
    $names = ['Main', 'Second', 'Highlight', 'Text', 'Background'];
    $colors = [];
    foreach ($c as $i => $hex) {
        $colors[] = ['key' => $roles[$i] === 'extra' ? 'background' : $roles[$i], 'name' => $names[$i], 'hex' => $hex, 'role' => $roles[$i]];
    }
    $white = Database\Templates\Support\Colour::contrast('#ffffff', $c[0]);
    $dark = Database\Templates\Support\Colour::contrast($c[3], $c[0]);

    return ['key' => $key, 'name' => $name, 'use' => $use, 'colors' => $colors, 'text_on_main' => $white >= $dark ? ['hex' => '#ffffff', 'ratio' => round($white, 2)] : ['hex' => $c[3], 'ratio' => round($dark, 2)]];
};
$out['palettes'] = [
    $pl('harbour', 'Harbour', 'Calm and dependable: finance, consulting, logistics.', ['#0f3d5e', '#3a86a8', '#f4a259', '#1b2a38', '#eef3f6']),
    $pl('meadow', 'Meadow', 'Fresh and natural: gardens, organic food, wellness.', ['#2f6b3a', '#8fbf6a', '#f2c14e', '#1f2a1f', '#f3f6ec']),
    $pl('terracotta', 'Terracotta', 'Warm and handmade: crafts, cafes, homeware.', ['#b5522e', '#e1a374', '#2f5d62', '#2b211c', '#f6ede3']),
    $pl('berry', 'Berry', 'Bold and friendly: beauty, bakeries, gifts.', ['#8c1c55', '#d96c9a', '#f5c04a', '#2a1624', '#fbf1f5']),
    $pl('slate', 'Slate', 'Modern and neutral: software, agencies, studios.', ['#334155', '#64748b', '#38bdf8', '#0f172a', '#f1f5f9']),
    $pl('sunrise', 'Sunrise', 'Energetic and optimistic: events, fitness, retail.', ['#e4572e', '#ffb703', '#219ebc', '#1d1a1a', '#fff6e5']),
    $pl('forest-night', 'Forest night', 'Deep and premium: outdoor, hospitality, sustainable brands.', ['#14532d', '#22c55e', '#fde047', '#052e16', '#f0fdf4']),
    $pl('ink-gold', 'Ink and gold', 'Luxurious and formal: legal, jewellery, fine dining.', ['#111827', '#4b5563', '#c9a45c', '#0b0f19', '#faf7f0']),
    $pl('lavender', 'Lavender', 'Creative and modern: education, apps, creatives.', ['#5b3fd0', '#a78bfa', '#fb7185', '#1e1b4b', '#f5f3ff']),
    $pl('coral-reef', 'Coral reef', 'Playful and bright: travel, kids, entertainment.', ['#ef476f', '#118ab2', '#ffd166', '#073b4c', '#fff8ec']),
    $pl('olive-grove', 'Olive grove', 'Earthy and honest: farms, delis, artisans.', ['#566b34', '#b7c27d', '#d4783a', '#22261a', '#f5f1e4']),
    $pl('plum-peach', 'Plum and peach', 'Soft and stylish: salons, fashion, boutiques.', ['#5b2a5e', '#e08e79', '#f6c177', '#241528', '#fcf1ea']),
    $pl('teal-clean', 'Teal clean', 'Clear and clinical: health, dental, cleaning.', ['#0f766e', '#5eead4', '#f97316', '#134e4a', '#f0fdfa']),
    $pl('charcoal-red', 'Charcoal and red', 'Strong and direct: automotive, security, trades.', ['#1f1f1f', '#6b7280', '#d62828', '#0b0b0b', '#f4f4f2']),
    $pl('sky', 'Sky', 'Open and trustworthy: aviation, tech support, education.', ['#1d6fa5', '#7cc6e8', '#ff9f1c', '#0e2a3d', '#f1f8fc']),
    $pl('sand-sage', 'Sand and sage', 'Quiet and natural: interiors, spas, architecture.', ['#7a8b6f', '#d9cbb0', '#b5654a', '#2d2a24', '#f7f2e8']),
    $pl('royal', 'Royal', 'Confident and corporate: banking, training, events.', ['#2b2d8f', '#6c63ff', '#ffb400', '#14163f', '#f2f2ff']),
    $pl('citrus', 'Citrus', 'Sunny and youthful: juice bars, summer sales, clubs.', ['#f59e0b', '#84cc16', '#0ea5e9', '#1c1917', '#fffbeb']),
    $pl('rose-clay', 'Rose clay', 'Gentle and caring: childcare, florists, wellbeing.', ['#b4636b', '#e8b4b8', '#3d5a6c', '#2b1d20', '#fbf0f0']),
    $pl('monochrome', 'Monochrome', 'Minimal and editorial: photographers, galleries, portfolios.', ['#222222', '#6e6e6e', '#e63946', '#000000', '#f5f5f5']),
];

file_put_contents($root.'/resources/design/elements.json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
echo 'elements written: '.implode(', ', array_map(fn ($k) => $k.' '.count($out[$k]), array_keys(array_filter($out, 'is_array'))))."\n";

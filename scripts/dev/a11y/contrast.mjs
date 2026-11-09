// R-45 a11y: contrast of the design tokens in both themes, read from the built CSS (tokens.css), against WCAG 2.2:
// text 4.5:1 (large 3:1), interface parts and focus 3:1. Exit code 1 when a required pair fails.
//   node scripts/dev/a11y/contrast.mjs
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../../resources/css/ds/tokens.css', import.meta.url), 'utf8');
function block(start, end) { const i = css.indexOf(start); const j = css.indexOf(end, i + 1); return css.slice(i, j === -1 ? undefined : j); }
const vars = (b) => Object.fromEntries([...b.matchAll(/--lt-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,8})/g)].map((m) => [m[1], m[2]]));
const light = vars(block(':root {', ':root[data-theme="dark"]'));
const dark = { ...light, ...vars(block(':root[data-theme="dark"]', '@media (prefers-color-scheme: dark)')) };

const lum = (hex) => { const h = hex.slice(1); const v = h.length === 3 ? [...h].map((c) => parseInt(c + c, 16)) : [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)); const f = v.map((c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }); return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2]; };
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

// [foreground, background, minimum, what]
const PAIRS = [
    ['ink', 'panel', 4.5, 'body text on a panel'], ['ink', 'ground', 4.5, 'body text on the ground'], ['ink', 'panel-sunk', 4.5, 'text in an input'],
    ['ink-2', 'panel', 4.5, 'secondary text'], ['ink-2', 'ground', 4.5, 'secondary text on the ground'], ['ink-3', 'panel', 4.5, 'tertiary text and hints'], ['ink-3', 'ground', 4.5, 'tertiary text on the ground'], ['ink-3', 'panel-sunk', 4.5, 'hint inside an input'],
    ['accent', 'panel', 4.5, 'links and the accent text'], ['accent', 'accent-wash', 4.5, 'selected chip text'], ['accent-ink', 'accent', 4.5, 'accent button text'], ['btn-ink', 'btn', 4.5, 'primary button text'],
    ['ok', 'ok-wash', 4.5, 'success chip'], ['warn', 'warn-wash', 4.5, 'warning chip'], ['bad', 'bad-wash', 4.5, 'error chip'], ['ok', 'panel', 4.5, 'success text'], ['warn', 'panel', 4.5, 'warning text'], ['bad', 'panel', 4.5, 'error text'],
    ['edge', 'panel', 3, 'control border on a panel'], ['edge', 'ground', 3, 'control border on the ground'], ['edge', 'panel-sunk', 3, 'control border on a well'],
    ['focus', 'panel', 3, 'focus ring on a panel'], ['focus', 'ground', 3, 'focus ring on the ground'], ['focus', 'panel-sunk', 3, 'focus ring on a well'],
    ['accent', 'panel', 3, 'selection frame and handles on a panel'], ['ink-3', 'panel', 3, 'icon-only buttons'],
];
let failed = 0;
const rows = [];
for (const [fg, bg, min, what] of PAIRS) {
    const cells = [];
    for (const [name, t] of [['light', light], ['dark', dark]]) {
        if (!t[fg] || !t[bg]) { cells.push('n/a'); continue; }
        const r = ratio(t[fg], t[bg]);
        const ok = r >= min;
        if (!ok) failed++;
        cells.push(`${r.toFixed(2)}${ok ? '' : ' FAIL'}`);
    }
    rows.push(`${what.padEnd(40)} ${(fg + ' on ' + bg).padEnd(26)} need ${String(min).padEnd(4)} light ${cells[0].padEnd(10)} dark ${cells[1]}`);
}
console.log(rows.join('\n'));
console.log(failed ? `\n${failed} pair(s) below the requirement` : '\nEvery pair meets its requirement in both themes.');
process.exit(failed ? 1 : 0);

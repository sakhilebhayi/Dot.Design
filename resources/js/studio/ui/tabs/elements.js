/*
 * Elements tab: basic shapes, icons (searchable), and the library of badges,
 * frames, dividers, arrows and patterns drawn for this product. Tap or click to
 * add, or drag onto the page. Library items arrive in the brand's colours when a
 * kit exists. Gradients and palettes apply to the page and the selection.
 */
import { MIXED } from '../describe.js';
import { h, icon, libraryIcon, svgEl } from '../dom.js';
import { applyColour } from '../actions.js';
import { swatch } from '../fields.js';
import { brandedCopy, draggable, insert } from '../insert.js';
import { createPreviewRenderer, whenVisible } from '../template-render.js';
import { chipRow, errorNote, searchBox } from './common.js';
import { gradientCss } from '../inspector/index.js';

let libraryPromise = null;

const SHAPES = [
    ['rect', 'Rectangle', (s) => s.appendChild(svgEl('rect', { x: 3, y: 5, width: 18, height: 14, rx: 1 }))],
    ['ellipse', 'Circle', (s) => s.appendChild(svgEl('circle', { cx: 12, cy: 12, r: 9 }))],
    ['triangle', 'Triangle', (s) => s.appendChild(svgEl('path', { d: 'M12 4l9 16H3z' }))],
    ['star', 'Star', (s) => s.appendChild(svgEl('path', { d: 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z' }))],
    ['polygon', 'Hexagon', (s) => s.appendChild(svgEl('path', { d: 'M12 3l7.8 4.5v9L12 21l-7.8-4.5v-9z' }))],
    ['line', 'Line', (s) => s.appendChild(svgEl('path', { d: 'M4 12h16' }))],
    ['arrow', 'Arrow', (s) => s.appendChild(svgEl('path', { d: 'M4 12h16M14 6l6 6-6 6' }))],
];

function shapeIcon(draw) {
    const s = svgEl('svg', { viewBox: '0 0 24 24', fill: 'currentColor', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', class: 'st-libicon' });
    draw(s);
    const first = s.firstChild;
    if (first && first.getAttribute('d') && /M4 12h16/.test(first.getAttribute('d'))) first.setAttribute('fill', 'none');
    return s;
}

export function loadLibrary(ctx) {
    if (!libraryPromise) libraryPromise = ctx.api.get(ctx.url('design.studio.elements')).then((r) => { if (!r.ok) { libraryPromise = null; } return r; });
    return libraryPromise;
}

export function mountElements(el, ctx) {
    const renderer = createPreviewRenderer(ctx);
    const body = h('div', { class: 'st-elbody' });
    const iconEntries = Object.entries(ctx.config.icons || {}).filter(([k, v]) => v && typeof v === 'object').map(([key, v]) => ({ key, ...v }));
    const catLabels = { business: 'Business', services: 'Services', arrows: 'Arrows', symbols: 'Symbols', contact: 'Contact', food: 'Food', commerce: 'Shopping', social: 'Social', shapes: 'Shapes', checks: 'Ticks and marks' };
    let q = ''; let cat = 'all';

    // shapes
    const shapes = h('div', { class: 'st-tiles' }, ...SHAPES.map(([shape, label, draw]) => {
        const b = h('button', { class: 'st-tile', attrs: { type: 'button', 'aria-label': 'Add ' + label.toLowerCase(), title: label }, onClick: () => insert(ctx, { kind: 'shape', shape }) }, shapeIcon(draw), h('span', { class: 'st-tile__label', text: label }));
        draggable(b, () => ({ kind: 'shape', shape, name: label }));
        return b;
    }));

    // icons
    const iconGrid = h('div', { class: 'st-icons' });
    const iconNote = h('p', { class: 'st-note', hidden: true });
    function paintIcons() {
        const needle = q.toLowerCase();
        const list = iconEntries.filter((i) => (cat === 'all' || i.category === cat) && (!needle || i.key.includes(needle) || (i.tags || []).some((t) => t.includes(needle)) || (i.category || '').includes(needle)));
        iconGrid.replaceChildren(...list.map((i) => {
            const b = h('button', { class: 'st-iconbtn', attrs: { type: 'button', 'aria-label': 'Add icon: ' + i.key.replace(/-/g, ' '), title: i.key.replace(/-/g, ' ') }, onClick: () => insert(ctx, { kind: 'icon', key: i.key, name: i.key.replace(/-/g, ' ') }) }, libraryIcon(i));
            draggable(b, () => ({ kind: 'icon', key: i.key, name: i.key.replace(/-/g, ' ') }));
            return b;
        }));
        iconNote.hidden = list.length > 0; iconNote.textContent = 'No icons match. Try another word, such as phone, food or arrow.';
    }
    const search = searchBox({ label: 'Search icons', placeholder: 'Search icons, for example phone or food', onInput: (v) => { q = v; paintIcons(); } });
    const cats = chipRow([['all', 'All'], ...Object.keys(catLabels).map((k) => [k, catLabels[k]])], 'all', (v) => { cat = v; paintIcons(); }, 'Icon category');
    paintIcons();

    // library
    const lib = h('div', { class: 'st-library' }, h('div', { class: 'st-skeletonline lt-skeleton' }));
    const GROUPS = [['badges', 'Badges and stickers'], ['frames', 'Frames'], ['dividers', 'Dividers'], ['arrows', 'Arrows'], ['bubbles', 'Speech bubbles'], ['price_tags', 'Price tags'], ['bursts', 'Stars and bursts'], ['corners', 'Corners'], ['patterns', 'Patterns']];
    function libTile(item, kind) {
        const frame = h('span', { class: 'st-lib__frame', vars: { a: item.width + ' / ' + item.height } });
        const b = h('button', { class: 'st-lib', attrs: { type: 'button', 'aria-label': 'Add ' + item.name.toLowerCase(), title: item.name }, onClick: () => insert(ctx, { kind: 'library', element: item.element, fit: kind === 'patterns' ? 'fit' : undefined }) }, frame);
        draggable(b, () => ({ kind: 'library', element: item.element, name: item.name }));
        whenVisible(b, () => renderer.draw(frame, { width: item.width, height: item.height, background: { type: 'solid', color: '#ffffff' }, elements: [brandedCopy(item.element, ctx.brand)] }, { placeholders: false }), el.closest('.st-panel__body'));
        return b;
    }
    function paintLibrary(data) {
        const parts = [];
        for (const [key, title] of GROUPS) {
            const items = data[key] || [];
            if (!items.length) continue;
            parts.push(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: title }), h('div', { class: 'st-libgrid' + (key === 'dividers' || key === 'arrows' ? ' st-libgrid--wide' : '') }, ...items.map((i) => libTile(i, key)))));
        }
        // gradients: set the page background; palettes: apply a colour
        const grads = (data.gradients || []).map((g) => { const b = h('button', { class: 'st-gradchip', attrs: { type: 'button', 'aria-label': 'Use the gradient ' + g.name + ' as the background', title: g.name + ': ' + g.use }, onClick: () => insert(ctx, { kind: 'background', background: { type: 'gradient', color: g.gradient.stops[0].color, gradient: g.gradient, asset_id: null, fit: 'cover' } }) }); b.style.setProperty('--grad', gradientCss(g.gradient)); return b; });
        if (grads.length) parts.push(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Gradient backgrounds' }), h('div', { class: 'st-gradgrid' }, ...grads)));
        const pals = (data.palettes || []).map((p) => h('div', { class: 'st-palette' }, h('div', { class: 'st-palette__name', text: p.name }), h('div', { class: 'st-colour__chips' }, ...p.colors.map((c) => swatch(c.hex, { label: `${p.name}: ${c.name} ${c.hex}`, onClick: () => applyToSelectionOrBackground(ctx, c.hex) })))));
        if (pals.length) parts.push(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Colour palettes' }), h('p', { class: 'st-note', text: 'Click a colour to use it on what is selected, or on the page background when nothing is.' }), ...pals));
        lib.replaceChildren(...parts);
    }
    async function loadLib() {
        lib.replaceChildren(h('div', { class: 'st-skeletonline lt-skeleton' }));
        const r = await loadLibrary(ctx);
        if (!r.ok) { lib.replaceChildren(errorNote(r.message || 'The library could not be loaded.', loadLib)); return; }
        paintLibrary(r.data);
    }
    loadLib();
    ctx.on('brand', () => { loadLibrary(ctx).then((r) => { if (r.ok) paintLibrary(r.data); }); });

    body.append(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Shapes' }), shapes),
        h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Icons' }), search.el, cats, iconGrid, iconNote), lib);
    el.append(body);
}

export function applyToSelectionOrBackground(ctx, hex) {
    const sel = ctx.selection();
    if (sel.count) applyColour(ctx, sel.ids, hex);
    else ctx.run('page.update', { id: sel.page.id, changes: { background: { ...sel.page.background, type: 'solid', color: hex } } });
}
void MIXED; void icon;

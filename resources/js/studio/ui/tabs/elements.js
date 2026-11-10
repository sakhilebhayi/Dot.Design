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
import { addSize, brandedCopy, draggable, insert } from '../insert.js';
import { activePage } from '../describe.js';
import { userKey } from '../shell.js';
import { entryId, pushRecent, readRecents, writeRecents } from '../recents.js';
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
    const RECENT_KEY = userKey(ctx, 'recent-elements');
    let q = ''; let kind = 'all'; let iconCat = 'all';
    let recents = readRecents(RECENT_KEY);
    const lookup = new Map(); // entry id -> {build(), payload()}  (what "recently used" can bring back)

    // ---- the size peek: what this will look like on the page, before anything is added ----------------------------
    const peekPage = h('span', { class: 'st-peek__page', attrs: { 'aria-hidden': 'true' } }, h('span', { class: 'st-peek__item' }));
    const peekName = h('strong', { class: 'st-peek__name' });
    const peekSize = h('span', { class: 'st-peek__size lt-num' });
    const peek = h('div', { class: 'st-peek', hidden: true, attrs: { 'aria-hidden': 'true' } }, peekPage, h('div', { class: 'st-peek__text' }, peekName, peekSize));
    function showPeek(name, payload) {
        const page = activePage(ctx.studio.getState());
        const size = addSize(page, payload);
        if (!size) return;
        peekName.textContent = name;
        peekSize.textContent = `${Math.round(size.width)} by ${Math.round(size.height)} px, ${Math.max(1, Math.round((size.width / page.width) * 100))}% of the page width`;
        peekPage.style.setProperty('--pa', page.width + ' / ' + page.height);
        peekPage.firstChild.style.setProperty('--iw', Math.min(100, (size.width / page.width) * 100) + '%');
        peekPage.firstChild.style.setProperty('--ih', Math.min(100, (size.height / page.height) * 100) + '%');
        peek.hidden = false;
    }
    const hidePeek = () => { peek.hidden = true; };
    function hoverPeek(node, name, payloadFn) {
        node.addEventListener('pointerenter', () => showPeek(name, payloadFn()));
        node.addEventListener('focus', () => showPeek(name, payloadFn()));
        node.addEventListener('pointerleave', hidePeek);
        node.addEventListener('blur', hidePeek);
    }
    function used(entry) { recents = pushRecent(recents, entry); writeRecents(RECENT_KEY, recents); paintRecents(); }

    // ---- sections (built once; the search and the chips only hide and show) ---------------------------------------
    const sections = []; // {id, kinds, el, items:[{el, text}], count}
    function addSection(id, kinds, title, nodes, items, extra) {
        const count = h('span', { class: 'st-group__count lt-num' });
        const sec = h('section', { class: 'st-group st-elsec', data: { section: id } }, h('h3', { class: 'st-group__title' }, title, count), extra || null, ...nodes);
        const rec = { id, kinds, el: sec, items, countEl: count };
        sections.push(rec);
        return rec;
    }

    // shapes
    const shapeTile = (shape, label, draw) => {
        const payload = () => ({ kind: 'shape', shape, name: label });
        const b = h('button', { class: 'st-tile', attrs: { type: 'button', 'aria-label': 'Add ' + label.toLowerCase(), title: label }, onClick: () => { insert(ctx, { kind: 'shape', shape }); used({ t: 'shape', k: shape }); } }, shapeIcon(draw), h('span', { class: 'st-tile__label', text: label }));
        draggable(b, payload);
        hoverPeek(b, label, () => ({ kind: 'shape', shape }));
        return b;
    };
    for (const [shape, label, draw] of SHAPES) lookup.set('shape:' + shape, { build: () => shapeTile(shape, label, draw) });
    const shapeNodes = SHAPES.map(([shape, label, draw]) => shapeTile(shape, label, draw));
    addSection('shapes', ['shapes'], 'Shapes', [h('div', { class: 'st-tiles' }, ...shapeNodes)], SHAPES.map(([, label], i) => ({ el: shapeNodes[i], text: label.toLowerCase() + ' shape' })));

    // icons
    const iconBtn = (i) => {
        const nm = i.key.replace(/-/g, ' ');
        const b = h('button', { class: 'st-iconbtn', attrs: { type: 'button', 'aria-label': 'Add icon: ' + nm, title: nm }, onClick: () => { insert(ctx, { kind: 'icon', key: i.key, name: nm }); used({ t: 'icon', k: i.key }); } }, libraryIcon(i));
        draggable(b, () => ({ kind: 'icon', key: i.key, name: nm }));
        hoverPeek(b, nm.charAt(0).toUpperCase() + nm.slice(1) + ' icon', () => ({ kind: 'icon' }));
        return b;
    };
    for (const i of iconEntries) lookup.set('icon:' + i.key, { build: () => iconBtn(i) });
    const iconNodes = iconEntries.map(iconBtn);
    const iconGrid = h('div', { class: 'st-icons' }, ...iconNodes);
    const cats = chipRow([['all', 'All icons'], ...Object.keys(catLabels).filter((k) => iconEntries.some((i) => i.category === k)).map((k) => [k, catLabels[k]])], 'all', (v) => { iconCat = v; applyFilter(); }, 'Icon category');
    const iconSec = addSection('icons', ['icons'], 'Icons', [iconGrid], iconEntries.map((i, n) => ({ el: iconNodes[n], text: [i.key.replace(/-/g, ' '), ...(i.tags || []), i.category || ''].join(' ').toLowerCase(), cat: i.category })), cats);

    // library groups, gradients, palettes (arrive from the server)
    const GROUPS = [['badges', 'Badges and stickers', 'badges'], ['frames', 'Frames', 'frames'], ['dividers', 'Dividers', 'lines'], ['arrows', 'Arrows', 'lines'], ['bubbles', 'Speech bubbles', 'bubbles'], ['price_tags', 'Price tags', 'prices'], ['bursts', 'Stars and bursts', 'badges'], ['corners', 'Corners', 'frames'], ['patterns', 'Patterns', 'patterns']];
    const lib = h('div', { class: 'st-library' }, h('div', { class: 'st-skeletonline lt-skeleton' }));
    function libTile(item, groupKey) {
        const frame = h('span', { class: 'st-lib__frame', vars: { a: item.width + ' / ' + item.height } });
        const payload = () => ({ kind: 'library', element: item.element, width: item.width, height: item.height, fit: groupKey === 'patterns' ? 'fit' : undefined });
        const b = h('button', { class: 'st-lib', attrs: { type: 'button', 'aria-label': 'Add ' + item.name.toLowerCase(), title: item.name }, onClick: () => { insert(ctx, { kind: 'library', element: item.element, fit: groupKey === 'patterns' ? 'fit' : undefined }); used({ t: 'lib', g: groupKey, k: item.name }); } }, frame);
        draggable(b, () => ({ kind: 'library', element: item.element, name: item.name }));
        hoverPeek(b, item.name, payload);
        whenVisible(b, () => renderer.draw(frame, { width: item.width, height: item.height, background: { type: 'solid', color: '#ffffff' }, elements: [brandedCopy(item.element, ctx.brand)] }, { placeholders: false }), el.closest('.st-panel__body'));
        return b;
    }
    let libSections = [];
    function paintLibrary(data) {
        for (const r of libSections) { const i = sections.indexOf(r); if (i >= 0) sections.splice(i, 1); }
        libSections = [];
        const parts = [];
        for (const [key, title, chip] of GROUPS) {
            const items = data[key] || [];
            if (!items.length) continue;
            for (const item of items) lookup.set('lib:' + key + ':' + item.name, { build: () => libTile(item, key) });
            const nodes = items.map((i) => libTile(i, key));
            const sec = h('section', { class: 'st-group st-elsec', data: { section: key } }, h('h3', { class: 'st-group__title' }, title, h('span', { class: 'st-group__count lt-num' })), h('div', { class: 'st-libgrid' + (key === 'dividers' || key === 'arrows' ? ' st-libgrid--wide' : '') }, ...nodes));
            const rec = { id: key, kinds: [chip, key], el: sec, items: items.map((i, n) => ({ el: nodes[n], text: (i.name + ' ' + title + ' ' + (i.tags || []).join(' ')).toLowerCase() })), countEl: sec.querySelector('.st-group__count') };
            sections.push(rec); libSections.push(rec); parts.push(sec);
        }
        const grads = (data.gradients || []).map((g) => { const b = h('button', { class: 'st-gradchip', attrs: { type: 'button', 'aria-label': 'Use the gradient ' + g.name + ' as the background', title: g.name + ': ' + g.use }, onClick: () => insert(ctx, { kind: 'background', background: { type: 'gradient', color: g.gradient.stops[0].color, gradient: g.gradient, asset_id: null, fit: 'cover' } }) }); b.style.setProperty('--grad', gradientCss(g.gradient)); return b; });
        if (grads.length) {
            const sec = h('section', { class: 'st-group st-elsec', data: { section: 'gradients' } }, h('h3', { class: 'st-group__title' }, 'Gradient backgrounds', h('span', { class: 'st-group__count lt-num' })), h('div', { class: 'st-gradgrid' }, ...grads));
            const rec = { id: 'gradients', kinds: ['backgrounds'], el: sec, items: grads.map((b, n) => ({ el: b, text: ((data.gradients[n].name || '') + ' ' + (data.gradients[n].use || '') + ' gradient background').toLowerCase() })), countEl: sec.querySelector('.st-group__count') };
            sections.push(rec); libSections.push(rec); parts.push(sec);
        }
        const pals = (data.palettes || []).map((p) => h('div', { class: 'st-palette' }, h('div', { class: 'st-palette__name', text: p.name }), h('div', { class: 'st-colour__chips' }, ...p.colors.map((c) => swatch(c.hex, { label: `${p.name}: ${c.name} ${c.hex}`, onClick: () => applyToSelectionOrBackground(ctx, c.hex) })))));
        if (pals.length) {
            const sec = h('section', { class: 'st-group st-elsec', data: { section: 'palettes' } }, h('h3', { class: 'st-group__title' }, 'Colour palettes', h('span', { class: 'st-group__count lt-num' })), h('p', { class: 'st-note', text: 'Click a colour to use it on what is selected, or on the page background when nothing is.' }), ...pals);
            const rec = { id: 'palettes', kinds: ['colours'], el: sec, items: pals.map((n, i) => ({ el: n, text: ((data.palettes[i].name || '') + ' colour palette ' + data.palettes[i].colors.map((c) => c.name).join(' ')).toLowerCase() })), countEl: sec.querySelector('.st-group__count') };
            sections.push(rec); libSections.push(rec); parts.push(sec);
        }
        lib.replaceChildren(...parts);
        paintRecents();
        applyFilter();
    }

    // recently used
    const recentRow = h('div', { class: 'st-recent' });
    const recentSec = h('section', { class: 'st-group st-elsec st-elsec--recent', data: { section: 'recent' } }, h('h3', { class: 'st-group__title' }, 'Recently used'), recentRow);
    function paintRecents() {
        const nodes = recents.map((e) => { const l = lookup.get(entryId(e)); return l ? l.build() : null; }).filter(Boolean);
        recentRow.replaceChildren(...nodes);
        recentSec.dataset.empty = nodes.length ? '0' : '1';
        applyFilter();
    }

    // ---- search and chips ----------------------------------------------------------------------------------------
    const note = h('p', { class: 'st-note st-elnote', hidden: true });
    function applyFilter() {
        const needle = q.toLowerCase();
        let shown = 0;
        for (const sec of sections) {
            const inKind = kind === 'all' || sec.kinds.includes(kind);
            let n = 0;
            for (const it of sec.items) {
                const ok = inKind && (!needle || it.text.includes(needle)) && (sec.id !== 'icons' || iconCat === 'all' || it.cat === iconCat);
                it.el.hidden = !ok;
                if (ok) n += 1;
            }
            sec.el.hidden = n === 0 || !inKind;
            if (sec.countEl) sec.countEl.textContent = needle || kind !== 'all' ? n + ' shown' : String(sec.items.length);
            if (sec.id === 'icons') cats.hidden = !!needle;
            shown += n;
        }
        recentSec.hidden = recentSec.dataset.empty === '1' || !!needle || kind !== 'all';
        note.hidden = shown > 0 || !(lib.querySelector('.lt-skeleton') === null);
        note.textContent = q ? `Nothing matches "${q}". Try another word, such as phone, arrow, badge or sale.` : 'Nothing here.';
        if (shown === 0 && lib.querySelector('.lt-skeleton')) note.hidden = true;
    }
    const search = searchBox({ label: 'Search elements', placeholder: 'Search shapes, icons, badges', onInput: (v) => { q = v; applyFilter(); } });
    const KINDS = [['all', 'All'], ['shapes', 'Shapes'], ['icons', 'Icons'], ['badges', 'Badges'], ['frames', 'Frames'], ['lines', 'Lines and arrows'], ['bubbles', 'Speech bubbles'], ['prices', 'Price tags'], ['patterns', 'Patterns'], ['backgrounds', 'Backgrounds'], ['colours', 'Colours']];
    const chips = chipRow(KINDS, 'all', (v) => { kind = v; applyFilter(); }, 'Kinds of element');
    const head = h('div', { class: 'st-tabhead' }, search.el, chips);

    async function loadLib() {
        lib.replaceChildren(h('div', { class: 'st-skeletonline lt-skeleton' }));
        const r = await loadLibrary(ctx);
        if (!r.ok) { lib.replaceChildren(errorNote(r.message || 'The library could not be loaded.', loadLib)); return; }
        paintLibrary(r.data);
    }
    loadLib();
    ctx.on('brand', () => { loadLibrary(ctx).then((r) => { if (r.ok) paintLibrary(r.data); }); });

    body.append(recentSec, ...sections.map((s) => s.el), lib, note);
    el.append(head, body, peek);
    paintRecents();
    void iconSec;
}

export function applyToSelectionOrBackground(ctx, hex) {
    const sel = ctx.selection();
    if (sel.count) applyColour(ctx, sel.ids, hex);
    else ctx.run('page.update', { id: sel.page.id, changes: { background: { ...sel.page.background, type: 'solid', color: hex } } });
}
void MIXED; void icon;

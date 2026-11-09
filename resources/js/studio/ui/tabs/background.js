/*
 * Background tab: solid colour (brand, page and custom colours), gradient
 * (presets and a two-colour builder) and a picture from the person's uploads.
 */
import { MIXED } from '../describe.js';
import { h, icon } from '../dom.js';
import { colourChooser, numberField, segmented, selectField, fieldRow, swatch } from '../fields.js';
import { insert } from '../insert.js';
import { gradientCss } from '../inspector/index.js';
import { activePage } from '../describe.js';
import { loadLibrary } from './elements.js';

export function mountBackground(el, ctx) {
    const page = () => activePage(ctx.studio.getState());
    const kindSeg = segmented({ label: 'Background type', value: 'solid', items: [{ value: 'solid', label: 'Solid colour', text: 'Colour' }, { value: 'gradient', label: 'Gradient', text: 'Gradient' }, { value: 'image', label: 'Picture', text: 'Picture' }], onChange: (v) => show(v) });
    const panes = { solid: h('div', { class: 'st-bgpane' }), gradient: h('div', { class: 'st-bgpane' }), image: h('div', { class: 'st-bgpane' }) };
    el.append(h('div', { class: 'st-tabhead' }, kindSeg.el), panes.solid, panes.gradient, panes.image);

    function show(kind) { kindSeg.set(kind); for (const [k, p] of Object.entries(panes)) p.hidden = k !== kind; if (kind === 'image') paintImages(); if (kind === 'gradient') paintGradient(); if (kind === 'solid') paintSolid(); }

    // solid
    function paintSolid() {
        const b = page().background || {};
        panes.solid.replaceChildren(h('p', { class: 'st-note', text: 'Pick a colour for the whole page.' }),
            colourChooser(ctx, { value: b.type === 'solid' ? b.color : null, page: true, onPick: (hex, meta) => (meta.token ? ctx.run('brand.apply', { prop: 'background', token: meta.token }) : ctx.run('page.update', { id: page().id, changes: { background: { ...page().background, type: 'solid', color: hex } } }, { key: 'bg:' + page().id })) }));
    }

    // gradient
    function setGrad(g, key) { ctx.run('page.update', { id: page().id, changes: { background: { ...page().background, type: 'gradient', color: g.stops[0].color, gradient: g } } }, key ? { key } : undefined); }
    async function paintGradient() {
        const b = page().background || {};
        const cur = b.type === 'gradient' && b.gradient ? b.gradient : { kind: 'linear', angle: 135, stops: [{ at: 0, color: b.color || '#ffffff' }, { at: 1, color: '#d1d5db' }] };
        const presetBox = h('div', { class: 'st-gradgrid' }, h('div', { class: 'st-skeletonline lt-skeleton' }));
        const first = h('input', { class: 'st-colour__native', attrs: { type: 'color', 'aria-label': 'First colour' } }); first.value = cur.stops[0].color.slice(0, 7);
        const second = h('input', { class: 'st-colour__native', attrs: { type: 'color', 'aria-label': 'Second colour' } }); second.value = cur.stops[cur.stops.length - 1].color.slice(0, 7);
        const angle = numberField({ label: 'Angle', short: '∠', min: -360, max: 360, suffix: '°', value: cur.angle, onInput: (v) => setGrad({ ...cur2(), angle: v }, 'bgangle'), onCommit: (v) => setGrad({ ...cur2(), angle: v }, 'bgangle') });
        const kind = segmented({ label: 'Gradient shape', small: true, value: cur.kind, items: [{ value: 'linear', label: 'Straight', text: 'Straight' }, { value: 'radial', label: 'Circular', text: 'Circular' }], onChange: (v) => setGrad({ ...cur2(), kind: v }) });
        const cur2 = () => { const bb = page().background; const g = bb.type === 'gradient' && bb.gradient ? bb.gradient : cur; return g; };
        const build = () => setGrad({ ...cur2(), stops: [{ at: 0, color: first.value }, { at: 1, color: second.value }] }, 'bgstops');
        first.addEventListener('input', build); second.addEventListener('input', build);
        panes.gradient.replaceChildren(h('p', { class: 'st-note', text: 'Soft gradients make good backgrounds. Choose one, or build your own.' }), presetBox,
            h('h3', { class: 'st-group__title', text: 'Make your own' }), h('div', { class: 'st-line' }, first, h('span', { class: 'st-note', text: 'to' }), second), kind.el, angle.el);
        const r = await loadLibrary(ctx);
        if (!r.ok) { presetBox.replaceChildren(h('p', { class: 'st-note', text: 'The presets could not be loaded. You can still build your own below.' })); return; }
        presetBox.replaceChildren(...(r.data.gradients || []).map((g) => { const btn = h('button', { class: 'st-gradchip', attrs: { type: 'button', 'aria-label': `Use the gradient ${g.name}`, title: `${g.name}: ${g.use}` }, onClick: () => setGrad(g.gradient) }); btn.style.setProperty('--grad', gradientCss(g.gradient)); return btn; }));
    }

    // picture
    function paintImages() {
        const b = page().background || {};
        const list = Array.from(ctx.assets.values()).filter((a) => !a.placeholder).sort((a, b2) => b2.id - a.id).slice(0, 36);
        const fit = selectField({ label: 'How the picture fills the page', options: [{ value: 'cover', label: 'Fill the page' }, { value: 'contain', label: 'Show the whole picture' }, { value: 'fill', label: 'Stretch' }], value: b.fit || 'cover', onChange: (v) => ctx.run('page.update', { id: page().id, changes: { background: { ...page().background, fit: v } } }) });
        panes.image.replaceChildren(
            h('button', { class: 'lt-btn lt-btn--secondary lt-btn--block', attrs: { type: 'button' }, onClick: () => ctx.openTab('uploads', { focus: true }) }, icon('upload'), 'Upload a new picture'),
            list.length ? h('div', { class: 'st-pics' }, ...list.map((a) => h('button', { class: ['st-pic', b.asset_id === a.id && b.type === 'image' && 'is-on'], attrs: { type: 'button', 'aria-label': `Use ${a.name} as the background` }, onClick: () => ctx.run('page.update', { id: page().id, changes: { background: { type: 'image', color: b.color || '#ffffff', gradient: null, asset_id: a.id, fit: (b.fit || 'cover') } } }) }, h('img', { class: 'st-pic__img', attrs: { src: a.url, alt: '', loading: 'lazy' } })))) : h('p', { class: 'st-note', text: 'No pictures uploaded yet.' }),
            b.type === 'image' ? fieldRow('Fit', fit) : null,
            b.type === 'image' ? h('button', { class: 'lt-btn lt-btn--ghost lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.run('page.update', { id: page().id, changes: { background: { ...page().background, type: 'solid', asset_id: null } } }) }, 'Remove the picture') : null);
    }

    function sync() { const t = (page().background || {}).type || 'solid'; if (document.activeElement && el.contains(document.activeElement) && el.querySelector('.st-bgpane:not([hidden]) :focus')) return; show(t === 'image' ? 'image' : t === 'gradient' ? 'gradient' : 'solid'); }
    ctx.on('page', sync);
    ctx.on('assets', () => { if (!panes.image.hidden) paintImages(); });
    sync();
    void swatch; void MIXED; void insert;
    return { show: sync };
}

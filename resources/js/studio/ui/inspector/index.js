/*
 * The Pro inspector: every property of the selection, as registered inspector
 * sections (the same registry the other modules use). Each section shows only
 * when it applies and writes through the engine, so every change is undoable.
 */
import { selectionFrame } from '../../engine/selection.js';
import { applyColour, applyFont, setPageColour } from '../actions.js';
import { MIXED, common, effectiveBackground } from '../describe.js';
import { h, icon } from '../dom.js';
import { colourField, fieldRow, numberField, segmented, sliderField, toggleButton } from '../fields.js';
import { UNITS, displayUnit, formatUnit, parseLength, pxTo } from '../units.js';
import { change, colourRow, grid, labelled, line, numRow, segRow, selectRow, sliderRow, toggleRow } from './rows.js';

const FONT_WEIGHTS = { 100: 'Thin', 200: 'Extra light', 300: 'Light', 400: 'Regular', 500: 'Medium', 600: 'Semibold', 700: 'Bold', 800: 'Extra bold', 900: 'Black' };
const UNIT_KEY = 'dot-design-unit';

function readUnit(project, formats) { try { const v = localStorage.getItem(UNIT_KEY); if (UNITS.includes(v)) return v; } catch (e) { /* none */ } return displayUnit(project, formats); }
function saveUnit(u) { try { localStorage.setItem(UNIT_KEY, u); } catch (e) { /* none */ } }

export function registerInspector(ctx) {
    const reg = ctx.registerInspectorSection;
    const pro = ['pro'];
    const has = (sel, ...types) => sel.elements.some((e) => types.includes(e.type));
    const section = (spec) => reg({ modes: pro, ...spec });

    // ---- the page (nothing selected) -------------------------------------------------------------
    section({ id: 'page', label: 'Page', order: 10, when: (sel) => sel.count === 0, mount: mountPage });

    // ---- arrange ----------------------------------------------------------------------------------
    section({ id: 'arrange', label: 'Position and size', order: 10, when: (sel) => sel.count > 0, mount: mountArrange });
    section({ id: 'align', label: 'Align', order: 20, when: (sel) => sel.count > 0, mount: mountAlign });
    section({ id: 'text', label: 'Text', order: 30, when: (sel) => has(sel, 'text'), mount: mountText });
    section({ id: 'fill', label: 'Fill', order: 40, when: (sel) => has(sel, 'shape'), mount: mountFill });
    section({ id: 'icon', label: 'Icon', order: 41, when: (sel) => has(sel, 'icon'), mount: mountIcon });
    section({ id: 'image', label: 'Picture', order: 42, when: (sel) => sel.count === 1 && sel.kind === 'image', mount: mountImage });
    section({ id: 'stroke', label: 'Outline', order: 50, when: (sel) => has(sel, 'shape', 'text'), mount: mountStroke });
    section({ id: 'effects', label: 'Effects', order: 60, when: (sel) => sel.count > 0, mount: mountEffects });
    section({ id: 'identity', label: 'Layer', order: 70, when: (sel) => sel.count > 0, mount: mountIdentity });
}

// ================================================================================================
function mountPage(el, ctx) {
    const st = ctx.studio;
    const rows = [];
    const add = (node, up) => { el.appendChild(node); if (up) rows.push(up); };
    const page = () => ctx.selection().page;

    const name = h('input', { class: 'lt-input', attrs: { type: 'text', 'aria-label': 'Page name', maxlength: 80, placeholder: 'Page name' } });
    name.addEventListener('change', () => ctx.run('page.update', { id: page().id, changes: { name: name.value.trim() || null } }));
    name.addEventListener('keydown', (e) => { if (e.key === 'Enter') name.blur(); e.stopPropagation(); });
    add(fieldRow('Name', { el: name, input: name }), () => { if (document.activeElement !== name) name.value = page().name || ''; });

    const size = h('p', { class: 'st-note' });
    add(size, () => { const p = page(); const u = readUnit(ctx.config.project, ctx.config.formats); size.textContent = `${formatUnit(p.width, u)} by ${formatUnit(p.height, u)} ${u}. To make another size, use Other sizes in the top bar.`; });

    // background
    const bg = h('div', { class: 'st-subsec' });
    const kind = segmented({ label: 'Background type', small: true, value: 'solid', items: [{ value: 'solid', label: 'Solid colour', text: 'Colour' }, { value: 'gradient', label: 'Gradient', text: 'Gradient' }, { value: 'image', label: 'Picture', text: 'Picture' }], onChange: (v) => {
        const p = page(); const b = p.background || {};
        const base = { ...b, type: v };
        if (v === 'gradient' && !b.gradient) base.gradient = { kind: 'linear', angle: 135, stops: [{ at: 0, color: b.color || '#ffffff' }, { at: 1, color: '#d1d5db' }] };
        if (v === 'image' && !b.asset_id) { ctx.openTab('background', { focus: true }); return; }
        ctx.run('page.update', { id: p.id, changes: { background: base } });
    } });
    const bgColour = colourField({ ctx, label: 'Background colour', value: null, onPick: (hex, meta) => setPageColour(ctx, page(), hex, { token: meta.token || null }) });
    const more = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.openTab('background', { focus: true }) }, 'Gradients and pictures');
    bg.append(kind.el, bgColour.el, more);
    add(h('div', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Background' }), bg), () => { const b = page().background || {}; kind.set(b.type || 'solid'); bgColour.set(effectiveBackground(page()).color || b.color); });

    // view options persisted per person
    const tog = (label, iconName, key, extra) => { const t = toggleButton({ label, iconName, text: label, pressed: !!ctx.settings[key], onChange: (v) => { ctx.saveSetting({ [key]: v }); } }); if (extra) extra(t); return t; };
    const snap = tog('Snap to guides', 'move', 'snap'); const rulers = tog('Rulers', 'ruler', 'rulers'); const gridT = tog('Grid', 'grid', 'grid');
    // Bleed and safe area: the print edge outside the page and the margin inside it, from this size's own definition.
    const safeT = toggleButton({ label: 'Show bleed and safe area', iconName: 'crop', text: 'Bleed and safe area', pressed: false, onChange: (v) => ctx.safeArea && ctx.safeArea.set(v) });
    const safeNote = h('p', { class: 'st-note' });
    add(h('div', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'View' }), h('div', { class: 'st-toggles' }, snap.el, rulers.el, gridT.el, safeT.el), safeNote), () => {
        snap.set(ctx.settings.snap !== false); rulers.set(!!ctx.settings.rulers); gridT.set(!!ctx.settings.grid);
        const d = ctx.safeArea && ctx.safeArea.describe();
        safeT.el.disabled = !d; safeT.set(!!d && ctx.safeArea.on);
        if (!d) { safeNote.textContent = 'This size has no bleed or safe area defined.'; return; }
        // in the unit the person works in (millimetres for print), the same one the size fields use
        const unit = readUnit(ctx.config.project, ctx.config.formats);
        const fmtLen = (px) => formatUnit(px, unit) + ' ' + unit;
        const even = d.top === d.bottom && d.left === d.right && d.top === d.left;
        safeNote.textContent = `Safe area: keep text ${even ? 'inside ' + fmtLen(d.top) + ' of the edge' : 'inside the green line'}.${d.bleed ? ' Bleed: ' + fmtLen(d.bleed) + ' past the edge for printing (the red line).' : ''}`;
    });
    add(h('p', { class: 'st-note', id: 'st-gridnote' }), () => {});

    // guides
    const guides = h('div', { class: 'st-guides' });
    const guideBox = h('div', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Guides' }), guides, h('p', { class: 'st-note', text: 'Drag from a ruler onto the page to add a guide. Elements snap to guides.' }));
    let gKey = '';
    add(guideBox, () => {
        const p = page(); const list = p.guides || [];
        const key = JSON.stringify(list);
        if (key === gKey) return; gKey = key;
        guides.replaceChildren(...list.map((g, i) => {
            const f = numberField({ label: g.axis === 'x' ? 'Vertical guide' : 'Horizontal guide', short: g.axis === 'x' ? 'X' : 'Y', value: g.pos, onCommit: (v) => ctx.run('page.update', { id: page().id, changes: { guides: page().guides.map((q, j) => (j === i ? { ...q, pos: Math.round(v) } : q)) } }) });
            return h('div', { class: 'st-guide' }, f.el, h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Remove this guide' }, onClick: () => ctx.run('page.update', { id: page().id, changes: { guides: page().guides.filter((_, j) => j !== i) } }) }, icon('x')));
        }));
        if (!list.length) guides.appendChild(h('p', { class: 'st-note', text: 'No guides yet.' }));
    });
    return { update() { for (const r of rows) r(); } };
}

// ================================================================================================
function mountArrange(el, ctx) {
    const st = ctx.studio;
    const state = { unit: readUnit(ctx.config.project, ctx.config.formats), lock: false, lastKind: '' };
    const box = () => { const s = ctx.selection(); const f = selectionFrame(s.page, s.ids); return f ? f.box : null; };
    const stepPx = () => (state.unit === 'px' ? 1 : state.unit === 'mm' ? 0.5 / pxTo(1, 'mm') : 0.01 / pxTo(1, 'in'));
    const fmt = (v) => formatUnit(v, state.unit);
    const parse = (t) => parseLength(t, state.unit, null);
    const key = (p) => `inspector:${p}:${ctx.selection().ids.join(',')}`;
    const single = () => ctx.selection().count === 1;

    const fx = numberField({ label: 'X position', short: 'X', step: stepPx, format: fmt, parse, suffix: state.unit, onInput: (v) => moveTo('x', v), onCommit: (v) => moveTo('x', v) });
    const fy = numberField({ label: 'Y position', short: 'Y', step: stepPx, format: fmt, parse, suffix: state.unit, onInput: (v) => moveTo('y', v), onCommit: (v) => moveTo('y', v) });
    const fw = numberField({ label: 'Width', short: 'W', min: 1, step: stepPx, format: fmt, parse, suffix: state.unit, onInput: (v) => sizeTo('width', v), onCommit: (v) => sizeTo('width', v) });
    const fh = numberField({ label: 'Height', short: 'H', min: 1, step: stepPx, format: fmt, parse, suffix: state.unit, onInput: (v) => sizeTo('height', v), onCommit: (v) => sizeTo('height', v) });
    const fr = numberField({ label: 'Rotation', short: '°', min: -360, max: 360, step: 1, decimals: 1, suffix: '°', onInput: (v) => rotateTo(v), onCommit: (v) => rotateTo(v) });

    function moveTo(axis, v) {
        const s = ctx.selection(); const b = box(); if (!b) return;
        if (s.count === 1) ctx.run('element.move', { ids: s.ids, [axis]: v }, { key: key(axis) });
        else ctx.run('element.move', { ids: s.ids, dx: axis === 'x' ? v - b.x : 0, dy: axis === 'y' ? v - b.y : 0 }, { key: key(axis) });
    }
    function sizeTo(dim, v) {
        const s = ctx.selection(); if (s.count !== 1) return;
        ctx.run('element.resize', { id: s.ids[0], [dim]: v, keepRatio: state.lock, anchor: 'topleft' }, { key: key(dim) });
    }
    function rotateTo(v) { const s = ctx.selection(); if (s.count !== 1) return; ctx.run('element.rotate', { ids: s.ids, rotation: v }, { key: key('rot') }); }

    const lock = toggleButton({ label: 'Keep the shape when resizing', iconName: 'link', pressed: false, onChange: (v) => { state.lock = v; } });
    const unitBtn = h('button', { class: 'st-unitbtn', attrs: { type: 'button', 'aria-label': 'Units. Press to change.' }, text: state.unit, onClick: () => { state.unit = UNITS[(UNITS.indexOf(state.unit) + 1) % UNITS.length]; saveUnit(state.unit); unitBtn.textContent = state.unit; for (const f of [fx, fy, fw, fh]) { const u = f.el.querySelector('.st-num__unit'); if (u) u.textContent = state.unit; } update(ctx.selection()); } });
    const flipH = h('button', { class: 'st-tog', attrs: { type: 'button', 'aria-label': 'Flip horizontally', title: 'Flip horizontally' }, onClick: () => change(ctx, 'flipx', (e) => ({ flip_x: !e.flip_x })) }, icon('flip-h'));
    const flipV = h('button', { class: 'st-tog', attrs: { type: 'button', 'aria-label': 'Flip vertically', title: 'Flip vertically' }, onClick: () => change(ctx, 'flipy', (e) => ({ flip_y: !e.flip_y })) }, icon('flip-v'));
    const sizeRow = h('div', { class: 'st-fgrid st-fgrid--2' }, fw.el, fh.el);
    el.append(h('div', { class: 'st-line st-line--space' }, h('span', { class: 'st-note', text: 'Units' }), unitBtn),
        h('div', { class: 'st-fgrid st-fgrid--2' }, fx.el, fy.el), sizeRow,
        h('div', { class: 'st-fgrid st-fgrid--rot' }, fr.el, lock.el, flipH, flipV));

    function update(sel) {
        const b = box(); const one = sel.count === 1;
        fx.set(b ? b.x : undefined); fy.set(b ? b.y : undefined);
        fw.set(one && b ? b.width : undefined); fh.set(one && b ? b.height : undefined); fr.set(one && b ? b.rotation || 0 : undefined);
        sizeRow.hidden = !one; fr.el.hidden = !one; lock.el.hidden = !one;
        if (one && state.lastKind !== sel.ids[0]) { state.lastKind = sel.ids[0]; state.lock = sel.kind === 'image'; lock.set(state.lock); }
        flipH.hidden = !one; flipV.hidden = !one;
    }
    return { update };
}

// ================================================================================================
function mountAlign(el, ctx) {
    let to = 'selection';
    const alignTo = () => (ctx.selection().count > 1 ? to : 'page');
    const btn = (label, iconName, run) => h('button', { class: 'st-tog', attrs: { type: 'button', 'aria-label': label, title: label }, onClick: run }, icon(iconName));
    const A = [['Align left', 'align-left', 'align.left'], ['Align centre', 'align-center', 'align.center'], ['Align right', 'align-right', 'align.right'], ['Align top', 'align-top', 'align.top'], ['Align middle', 'align-middle', 'align.middle'], ['Align bottom', 'align-bottom', 'align.bottom']];
    const row = h('div', { class: 'st-iconrow' }, ...A.map(([l, i, n]) => btn(l, i, () => ctx.run(n, { ids: ctx.selection().ids, to: alignTo() }))));
    const target = segmented({ label: 'Align to', small: true, value: 'selection', items: [{ value: 'selection', label: 'Align to the selection', text: 'Selection' }, { value: 'page', label: 'Align to the page', text: 'Page' }], onChange: (v) => { to = v; } });
    const dist = h('div', { class: 'st-iconrow' },
        h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.run('distribute.horizontal', { ids: ctx.selection().ids }) }, icon('distribute'), 'Spread across'),
        h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.run('distribute.vertical', { ids: ctx.selection().ids }) }, icon('distribute'), 'Spread down'));
    const matchBox = h('div', { class: 'st-iconrow' },
        ...[['Match width', 'width'], ['Match height', 'height'], ['Match both', 'both']].map(([l, d]) => h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.run('size.match', { ids: ctx.selection().ids, dimension: d }) }, l)));
    el.append(target.el, row, dist, matchBox);
    return { update(sel) { target.el.hidden = sel.count < 2; dist.hidden = sel.count < 3; matchBox.hidden = sel.count < 2; } };
}

// ================================================================================================
function mountText(el, ctx) {
    const T = ['text'];
    const fonts = ctx.config.fonts || [];
    const rows = [];
    const add = (r, wrap) => { el.appendChild(wrap || r.el); rows.push(r); return r; };

    // font + weight
    const fontSel = selectRow(ctx, { label: 'Font', options: fonts.map((f) => ({ value: f.key, label: f.label })), get: (e) => e.font, set: (e, v) => ({ font: v, ...(e.tokens && e.tokens.font ? { tokens: dropKey(e.tokens, 'font') } : {}) }), types: T, key: 'font' });
    add(fontSel, fieldRow('Font', fontSel));
    const weightSel = selectRow(ctx, { label: 'Weight', options: Object.entries(FONT_WEIGHTS).map(([v, l]) => ({ value: v, label: l })), get: (e) => String(e.fontWeight), set: (e, v) => ({ fontWeight: parseInt(v, 10) }), types: T, key: 'weight' });
    const origUpdate = weightSel.update;
    weightSel.update = (sel) => {
        origUpdate(sel);
        const f = fonts.find((x) => x.key === common(sel.elements.filter((e) => e.type === 'text'), (e) => e.font));
        const allowed = f ? f.weights : null;
        weightSel.input.querySelectorAll('option').forEach((o) => { o.hidden = !!allowed && !allowed.includes(parseInt(o.value, 10)); o.disabled = o.hidden; });
    };
    add(weightSel, fieldRow('Weight', weightSel));

    const size = numRow(ctx, { label: 'Size', short: 'Aa', get: (e) => e.fontSize, set: (e, v) => ({ fontSize: v }), min: 4, max: 2000, step: 1, decimals: 1, types: T, key: 'fontsize' });
    const lh = numRow(ctx, { label: 'Line height', short: '↕', get: (e) => e.lineHeight, set: (e, v) => ({ lineHeight: v }), min: 0.6, max: 3, step: 0.05, decimals: 2, types: T, key: 'lineheight' });
    const ls = numRow(ctx, { label: 'Letter spacing', short: 'A↔', get: (e) => e.letterSpacing, set: (e, v) => ({ letterSpacing: v }), min: -0.2, max: 1, step: 0.01, decimals: 2, suffix: 'em', types: T, key: 'letterspacing' });
    el.appendChild(grid(3, size.el, lh.el, ls.el)); rows.push(size, lh, ls);

    const caseSeg = segRow(ctx, { label: 'Letter case', small: true, items: [{ value: 'none', label: 'As typed', text: 'Aa' }, { value: 'upper', label: 'UPPER CASE', text: 'AA' }, { value: 'lower', label: 'lower case', text: 'aa' }, { value: 'title', label: 'Title Case', text: 'Ab' }], get: (e) => e.textCase, set: (e, v) => ({ textCase: v }), types: T, key: 'case' });
    const alignSeg = segRow(ctx, { label: 'Alignment', small: true, items: [{ value: 'left', label: 'Align left', icon: 'align-left' }, { value: 'center', label: 'Centre', icon: 'align-center' }, { value: 'right', label: 'Align right', icon: 'align-right' }, { value: 'justify', label: 'Justify', icon: 'align-justify' }], get: (e) => e.align, set: (e, v) => ({ align: v }), types: T, key: 'align' });
    const valignSeg = segRow(ctx, { label: 'Vertical alignment', small: true, items: [{ value: 'top', label: 'Align to the top', icon: 'align-top' }, { value: 'middle', label: 'Middle', icon: 'align-middle' }, { value: 'bottom', label: 'Align to the bottom', icon: 'align-bottom' }], get: (e) => e.valign, set: (e, v) => ({ valign: v }), types: T, key: 'valign' });
    add(caseSeg, labelled('Case', caseSeg.el)); add(alignSeg, labelled('Align', alignSeg.el)); add(valignSeg, labelled('Vertical', valignSeg.el));
    const it = toggleRow(ctx, { label: 'Italic', iconName: 'italic', get: (e) => e.italic, set: (e, v) => ({ italic: v }), types: T, key: 'italic' });
    const un = toggleRow(ctx, { label: 'Underline', iconName: 'underline', get: (e) => e.underline, set: (e, v) => ({ underline: v }), types: T, key: 'underline' });
    const sk = toggleRow(ctx, { label: 'Strikethrough', iconName: 'strikethrough', get: (e) => e.strike, set: (e, v) => ({ strike: v }), types: T, key: 'strike' });
    add(it, labelled('Style', h('div', { class: 'st-iconrow' }, it.el, un.el, sk.el))); rows.push(un, sk);
    const fit = selectRow(ctx, { label: 'Box behaviour', options: [{ value: 'none', label: 'Keep the box size' }, { value: 'grow', label: 'Grow with the text' }, { value: 'shrink', label: 'Shrink text to fit' }], get: (e) => e.autoFit, set: (e, v) => ({ autoFit: v }), types: T, key: 'autofit' });
    add(fit, fieldRow('Text box', fit));
    const colour = colourRow(ctx, { label: 'Text colour', get: (e) => e.color, types: T, apply: (sel, hex, meta) => applyColour(ctx, sel.elements.filter((e) => e.type === 'text').map((e) => e.id), hex, { token: meta.token }) });
    add(colour, labelled('Colour', colour.el));

    // brand text styles
    const styles = (ctx.brand && ctx.brand.type_styles) || [];
    if (styles.length) {
        const sel = h('select', { class: 'lt-select st-select', attrs: { 'aria-label': 'Apply a brand text style' } }, h('option', { value: '', text: 'Choose a style' }), ...styles.map((s) => h('option', { value: s.key, text: s.label || s.key })));
        sel.addEventListener('change', () => {
            const s = styles.find((x) => x.key === sel.value); sel.value = '';
            if (!s) return;
            const slot = s.font; const key = ctx.brand.fonts && ctx.brand.fonts[slot];
            change(ctx, 'textstyle', (e) => ({ ...(key ? { font: key, tokens: { ...(e.tokens || {}), font: 'brand.fonts.' + slot } } : {}), ...(s.size ? { fontSize: s.size } : {}), ...(s.weight ? { fontWeight: s.weight } : {}), ...(s.line_height ? { lineHeight: s.line_height } : {}), ...(s.letter_spacing !== undefined && s.letter_spacing !== null ? { letterSpacing: s.letter_spacing } : {}), ...(['upper', 'lower', 'title', 'none'].includes(s.case) ? { textCase: s.case } : {}) }), T);
        });
        el.appendChild(fieldRow('Brand text style', { el: sel, input: sel }));
    }
    void applyFont;
    return { update(sel) { for (const r of rows) r.update(sel); } };
}
function dropKey(o, k) { const c = { ...o }; delete c[k]; return Object.keys(c).length ? c : null; }

// ================================================================================================
function mountFill(el, ctx) {
    const S = ['shape'];
    const rows = [];
    const first = () => ctx.selection().elements.find((e) => e.type === 'shape');
    const typeOf = (e) => (e.fill === null || e.fill === undefined ? 'none' : typeof e.fill === 'string' ? 'solid' : 'gradient');
    const kind = segRow(ctx, { label: 'Fill type', small: true, items: [{ value: 'none', label: 'No fill', text: 'None' }, { value: 'solid', label: 'Solid colour', text: 'Colour' }, { value: 'gradient', label: 'Gradient', text: 'Gradient' }], get: typeOf, types: S, key: 'filltype',
        set: (e, v) => { const cur = typeOf(e); if (v === cur) return {}; const base = cur === 'solid' ? e.fill : cur === 'gradient' ? e.fill.stops[0].color : '#e4e4e7'; if (v === 'none') return { fill: null }; if (v === 'solid') return { fill: base }; return { fill: { kind: 'linear', angle: 90, stops: [{ at: 0, color: base }, { at: 1, color: '#ffffff' }] } }; } });
    el.appendChild(kind.el); rows.push(kind);
    const solid = colourRow(ctx, { label: 'Fill colour', get: (e) => (typeof e.fill === 'string' ? e.fill : null), types: S, allowNone: true, onNone: () => change(ctx, 'fill', { fill: null }, S), apply: (sel, hex, meta) => applyColour(ctx, sel.elements.filter((e) => e.type === 'shape').map((e) => e.id), hex, { token: meta.token, prop: 'fill' }) });
    const solidWrap = labelled('Colour', solid.el); el.appendChild(solidWrap); rows.push(solid);

    // gradient
    const gradBox = h('div', { class: 'st-gradient' });
    el.appendChild(gradBox);
    let gKey = '';
    let stopRefs = [];
    const gradient = () => { const e = first(); return e && e.fill && typeof e.fill === 'object' ? e.fill : null; };
    const setGrad = (g, key) => change(ctx, key || 'gradient', { fill: g }, S);
    function buildGradient(g) {
        const preview = h('div', { class: 'st-gradient__bar', attrs: { 'aria-hidden': 'true' } });
        const kindSel = segmented({ label: 'Gradient shape', small: true, value: g.kind, items: [{ value: 'linear', label: 'Straight', text: 'Linear' }, { value: 'radial', label: 'Circular', text: 'Radial' }], onChange: (v) => setGrad({ ...gradient(), kind: v }) });
        const angle = numberField({ label: 'Angle', short: '∠', min: -360, max: 360, step: 1, suffix: '°', value: g.angle, onInput: (v) => setGrad({ ...gradient(), angle: v }, 'gangle'), onCommit: (v) => setGrad({ ...gradient(), angle: v }, 'gangle') });
        const stops = h('div', { class: 'st-stops' });
        stopRefs = [];
        g.stops.forEach((s, i) => {
            const pos = numberField({ label: 'Stop position', short: '%', min: 0, max: 100, step: 1, decimals: 0, suffix: '%', value: Math.round(s.at * 100), onInput: (v) => setGrad(patchStop(gradient(), i, { at: v / 100 }), 'gstop' + i), onCommit: (v) => setGrad(patchStop(gradient(), i, { at: v / 100 }), 'gstop' + i) });
            const col = colourField({ ctx, label: 'Stop colour ' + (i + 1), value: s.color, onPick: (hex) => setGrad(patchStop(gradient(), i, { color: hex }), 'gcol' + i) });
            const del = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Remove this colour stop' }, disabled: g.stops.length <= 2, onClick: () => setGrad({ ...gradient(), stops: gradient().stops.filter((_, j) => j !== i) }) }, icon('x'));
            if (g.stops.length <= 2) del.disabled = true;
            stops.appendChild(h('div', { class: 'st-stop', data: { i } }, col.el, pos.el, del));
            stopRefs.push({ pos, col });
        });
        const addStop = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, disabled: g.stops.length >= 6, onClick: () => { const cur = gradient(); const list = cur.stops.slice().sort((a, b) => a.at - b.at); const at = Math.min(1, (list[list.length - 1].at + list[list.length - 2].at) / 2 + 0.15); setGrad({ ...cur, stops: [...cur.stops, { at: Math.round(at * 100) / 100, color: '#9ca3af' }] }); } }, icon('plus'), 'Add a colour');
        gradBox.replaceChildren(preview, kindSel.el, angle.el, stops, addStop);
        gradBox._refs = { preview, kindSel, angle, stops };
    }
    function patchStop(g, i, p) { return { ...g, stops: g.stops.map((s, j) => (j === i ? { ...s, ...p } : s)) }; }
    const gradRow = { update(sel) {
        const g = gradient(); gradBox.hidden = !g || sel.elements.filter((e) => e.type === 'shape').some((e) => typeOf(e) !== 'gradient');
        if (!g) return;
        const key = g.stops.length + g.kind;
        if (key !== gKey) { gKey = key; buildGradient(g); }
        const r = gradBox._refs; if (!r) return;
        r.preview.style.setProperty('--grad', gradientCss(g));
        r.kindSel.set(g.kind); r.angle.set(g.angle);
        stopRefs.forEach((ref, i) => { const s = g.stops[i]; if (!s) return; ref.pos.set(Math.round(s.at * 100)); ref.col.set(s.color); });
    } };
    rows.push(gradRow);

    // shape specifics
    const radius = numRow(ctx, { label: 'Corner roundness', short: 'R', get: (e) => e.radius, set: (e, v) => ({ radius: v }), min: 0, max: 5000, step: 1, types: S, key: 'radius' });
    const sides = numRow(ctx, { label: 'Sides', short: 'N', get: (e) => e.sides, set: (e, v) => ({ sides: Math.round(v) }), min: 3, max: 12, step: 1, decimals: 0, types: S, key: 'sides' });
    const points = numRow(ctx, { label: 'Points', short: 'N', get: (e) => e.points, set: (e, v) => ({ points: Math.round(v) }), min: 3, max: 16, step: 1, decimals: 0, types: S, key: 'points' });
    const inner = numRow(ctx, { label: 'Inner size', short: 'In', get: (e) => e.inner, set: (e, v) => ({ inner: v }), min: 0.1, max: 0.95, step: 0.05, decimals: 2, types: S, key: 'inner' });
    const radiusBox = labelled('Corners', radius.el); const sidesBox = labelled('Sides', sides.el); const pointsBox = h('div', { class: 'st-fgrid st-fgrid--2' }, points.el, inner.el);
    el.append(radiusBox, sidesBox, pointsBox);
    rows.push(radius, sides, points, inner);
    return { update(sel) {
        const shapes = sel.elements.filter((e) => e.type === 'shape');
        const kinds = new Set(shapes.map((e) => e.kind));
        const lineOnly = shapes.length > 0 && shapes.every((e) => e.kind === 'line' || e.kind === 'arrow');
        kind.el.hidden = lineOnly; solidWrap.hidden = lineOnly || shapes.every((e) => typeOf(e) !== 'solid');
        radiusBox.hidden = !kinds.has('rect'); sidesBox.hidden = !kinds.has('polygon'); pointsBox.hidden = !kinds.has('star');
        for (const r of rows) r.update(sel);
    } };
}
export function gradientCss(g) {
    const stops = g.stops.slice().sort((a, b) => a.at - b.at).map((s) => `${s.color} ${Math.round(s.at * 100)}%`).join(', ');
    return g.kind === 'radial' ? `radial-gradient(circle, ${stops})` : `linear-gradient(${(g.angle || 0)}deg, ${stops})`;
}

// ================================================================================================
function mountStroke(el, ctx) {
    const rows = [];
    const T = ['shape', 'text'];
    const strokeOf = (e) => e.stroke;
    const on = h('button', { class: 'st-tog st-tog--wide', attrs: { type: 'button', 'aria-pressed': 'false', 'aria-label': 'Show an outline' }, onClick: () => { const els = ctx.selection().elements.filter((e) => T.includes(e.type)); const has = els.every((e) => e.stroke); change(ctx, 'stroke', (e) => (has ? { stroke: null } : { stroke: e.type === 'text' ? { color: '#ffffff', width: 2 } : { color: '#18181b', width: 2, dash: 'solid', cap: 'round' } }), T); } }, icon('square'), h('span', { text: 'Outline' }));
    el.appendChild(on);
    const colour = colourRow(ctx, { label: 'Outline colour', get: (e) => (e.stroke ? e.stroke.color : null), types: T, apply: (sel, hex, meta) => applyColour(ctx, sel.elements.filter((e) => T.includes(e.type)).map((e) => e.id), hex, { token: meta.token, prop: 'stroke_color' }) });
    const width = numRow(ctx, { label: 'Outline width', short: 'W', get: (e) => (e.stroke ? e.stroke.width : 0), set: (e, v) => ({ stroke: { ...(e.stroke || { color: '#18181b' }), width: v } }), min: 0, max: 100, step: 0.5, decimals: 1, types: T, key: 'strokew' });
    const dash = segRow(ctx, { label: 'Line style', small: true, items: [{ value: 'solid', label: 'Solid', text: 'Solid' }, { value: 'dashed', label: 'Dashed', text: 'Dashed' }, { value: 'dotted', label: 'Dotted', text: 'Dotted' }], get: (e) => (e.stroke && e.stroke.dash) || 'solid', set: (e, v) => ({ stroke: { ...e.stroke, dash: v } }), types: ['shape'], key: 'dash' });
    const body = h('div', { class: 'st-stroke__body' }, labelled('Colour', colour.el), width.el, labelled('Style', dash.el));
    el.appendChild(body);
    rows.push(colour, width, dash);
    return { update(sel) {
        const els = sel.elements.filter((e) => T.includes(e.type));
        const all = els.length > 0 && els.every((e) => e.stroke);
        on.setAttribute('aria-pressed', String(all)); body.hidden = !els.some((e) => e.stroke);
        dash.el.parentNode.hidden = !els.some((e) => e.type === 'shape');
        for (const r of rows) r.update(sel);
        void strokeOf;
    } };
}

// ================================================================================================
function mountEffects(el, ctx) {
    const rows = [];
    const op = sliderRow(ctx, { label: 'Opacity', get: (e) => Math.round(e.opacity * 100), set: (e, v) => ({ opacity: v / 100 }), min: 0, max: 100, step: 1, format: (v) => v + '%', key: 'opacity' });
    el.appendChild(fieldRow('Opacity', op)); rows.push(op);
    const blend = selectRow(ctx, { label: 'Blend', options: [['normal', 'Normal'], ['multiply', 'Multiply'], ['screen', 'Screen'], ['overlay', 'Overlay'], ['darken', 'Darken'], ['lighten', 'Lighten']].map(([value, label]) => ({ value, label })), get: (e) => e.blend, set: (e, v) => ({ blend: v }), key: 'blend' });
    el.appendChild(fieldRow('Blend', blend)); rows.push(blend);
    const defaults = { x: 0, y: 6, blur: 16, color: '#000000', opacity: 0.3 };
    const sOn = h('button', { class: 'st-tog st-tog--wide', attrs: { type: 'button', 'aria-pressed': 'false', 'aria-label': 'Shadow' }, onClick: () => { const has = ctx.selection().elements.every((e) => e.shadow); change(ctx, 'shadow', () => ({ shadow: has ? null : { ...defaults } })); } }, icon('layers'), h('span', { text: 'Shadow' }));
    const sx = numRow(ctx, { label: 'Shadow X', short: 'X', get: (e) => (e.shadow ? e.shadow.x : 0), set: (e, v) => ({ shadow: { ...(e.shadow || defaults), x: v } }), min: -200, max: 200, step: 1, key: 'shx' });
    const sy = numRow(ctx, { label: 'Shadow Y', short: 'Y', get: (e) => (e.shadow ? e.shadow.y : 0), set: (e, v) => ({ shadow: { ...(e.shadow || defaults), y: v } }), min: -200, max: 200, step: 1, key: 'shy' });
    const sb = numRow(ctx, { label: 'Shadow blur', short: 'B', get: (e) => (e.shadow ? e.shadow.blur : 0), set: (e, v) => ({ shadow: { ...(e.shadow || defaults), blur: v } }), min: 0, max: 200, step: 1, key: 'shb' });
    const so = sliderRow(ctx, { label: 'Shadow strength', get: (e) => Math.round((e.shadow ? e.shadow.opacity : 0.3) * 100), set: (e, v) => ({ shadow: { ...(e.shadow || defaults), opacity: v / 100 } }), min: 0, max: 100, step: 1, format: (v) => v + '%', key: 'sho' });
    const sc = colourRow(ctx, { label: 'Shadow colour', get: (e) => (e.shadow ? e.shadow.color : null), apply: (sel, hex, meta) => applyColour(ctx, sel.ids, hex, { token: meta.token, prop: 'shadow_color' }) });
    const sBody = h('div', { class: 'st-shadow' }, h('div', { class: 'st-fgrid st-fgrid--3' }, sx.el, sy.el, sb.el), labelled('Strength', so.el), labelled('Colour', sc.el));
    el.append(sOn, sBody); rows.push(sx, sy, sb, so, sc);
    return { update(sel) {
        const all = sel.elements.length > 0 && sel.elements.every((e) => e.shadow);
        sOn.setAttribute('aria-pressed', String(all)); sBody.hidden = !sel.elements.some((e) => e.shadow);
        for (const r of rows) r.update(sel);
    } };
}

// ================================================================================================
function mountImage(el, ctx) {
    const I = ['image'];
    const rows = [];
    const replace = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.pickPicture(ctx.selection().ids[0]) }, icon('image-plus'), 'Replace picture');
    const reposition = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.startReposition(ctx.selection().ids[0]) }, icon('move'), 'Move inside frame');
    el.appendChild(h('div', { class: 'st-iconrow' }, replace, reposition));
    const fit = segRow(ctx, { label: 'Fit', small: true, items: [{ value: 'cover', label: 'Fill the frame', text: 'Fill' }, { value: 'contain', label: 'Show the whole picture', text: 'Contain' }, { value: 'fill', label: 'Stretch to the frame', text: 'Stretch' }], get: (e) => e.fit, set: (e, v) => ({ fit: v }), types: I, key: 'fit' });
    el.appendChild(labelled('Fit', fit.el)); rows.push(fit);
    const zoom = sliderRow(ctx, { label: 'Zoom', get: (e) => e.zoom, set: (e, v) => ({ zoom: v }), min: 1, max: 8, step: 0.05, format: (v) => Math.round(v * 100) + '%', types: I, key: 'zoom' });
    el.appendChild(fieldRow('Zoom', zoom)); rows.push(zoom);
    const fxp = numRow(ctx, { label: 'Focus across', short: 'X', get: (e) => Math.round(e.focal.x * 100), set: (e, v) => ({ focal: { ...e.focal, x: v / 100 } }), min: 0, max: 100, step: 1, decimals: 0, suffix: '%', types: I, key: 'focalx' });
    const fyp = numRow(ctx, { label: 'Focus down', short: 'Y', get: (e) => Math.round(e.focal.y * 100), set: (e, v) => ({ focal: { ...e.focal, y: v / 100 } }), min: 0, max: 100, step: 1, decimals: 0, suffix: '%', types: I, key: 'focaly' });
    el.appendChild(h('div', { class: 'st-fgrid st-fgrid--2' }, fxp.el, fyp.el)); rows.push(fxp, fyp);
    const mask = selectRow(ctx, { label: 'Shape', options: [['none', 'Rectangle'], ['rounded', 'Rounded'], ['ellipse', 'Circle'], ['star', 'Star'], ['triangle', 'Triangle'], ['hexagon', 'Hexagon']].map(([value, label]) => ({ value, label })), get: (e) => e.mask, set: (e, v) => ({ mask: v }), types: I, key: 'mask' });
    el.appendChild(fieldRow('Cut to a shape', mask)); rows.push(mask);
    const rad = numRow(ctx, { label: 'Corner roundness', short: 'R', get: (e) => e.radius, set: (e, v) => ({ radius: v }), min: 0, max: 5000, step: 1, types: I, key: 'iradius' });
    el.appendChild(labelled('Corners', rad.el)); rows.push(rad);
    // border
    const bw = numRow(ctx, { label: 'Border width', short: 'W', get: (e) => (e.border ? e.border.width : 0), set: (e, v) => ({ border: v > 0 ? { color: (e.border && e.border.color) || '#ffffff', width: v } : null }), min: 0, max: 100, step: 1, types: I, key: 'bw' });
    const bc = colourRow(ctx, { label: 'Border colour', get: (e) => (e.border ? e.border.color : null), types: I, apply: (sel, hex) => change(ctx, 'bcolor', (e) => ({ border: { width: (e.border && e.border.width) || 2, color: hex } }), I) });
    el.appendChild(h('div', { class: 'st-fgrid st-fgrid--2' }, bw.el, bc.el)); rows.push(bw, bc);
    // filters
    const F = [['brightness', 'Brightness', 0, 2, 0.05, 1], ['contrast', 'Contrast', 0, 2, 0.05, 1], ['saturate', 'Colour strength', 0, 2, 0.05, 1], ['blur', 'Blur', 0, 40, 1, 0], ['grayscale', 'Black and white', 0, 1, 0.05, 0], ['sepia', 'Sepia', 0, 1, 0.05, 0], ['hue', 'Hue shift', -180, 180, 1, 0]];
    const filtBox = h('div', { class: 'st-filters' });
    for (const [k, label, min, max, step, def] of F) {
        const r = sliderRow(ctx, { label, get: (e) => (e.filters && e.filters[k] !== undefined ? e.filters[k] : def), set: (e, v) => { const f = { ...(e.filters || {}), [k]: v }; return { filters: Object.keys(f).every((q) => f[q] === (F.find((x) => x[0] === q) || [])[5]) ? null : f }; }, min, max, step, format: (v) => (k === 'hue' ? Math.round(v) + '°' : k === 'blur' ? Math.round(v) + ' px' : Math.round(v * 100) + '%'), types: I, key: 'f' + k });
        filtBox.appendChild(fieldRow(label, r)); rows.push(r);
    }
    const reset = h('button', { class: 'lt-btn lt-btn--ghost lt-btn--sm', attrs: { type: 'button' }, text: 'Reset filters', onClick: () => change(ctx, 'filters', { filters: null }, I) });
    filtBox.appendChild(reset);
    const det = h('details', { class: 'st-details' }, h('summary', { text: 'Filters' }), filtBox);
    el.appendChild(det);
    return { update(sel) { const e = sel.el; replace.lastChild.textContent = e && e.asset_id ? 'Replace picture' : 'Add a picture'; reposition.hidden = !e || !e.asset_id; for (const r of rows) r.update(sel); } };
}

function mountIcon(el, ctx) {
    const I = ['icon'];
    const colour = colourRow(ctx, { label: 'Icon colour', get: (e) => e.color, types: I, apply: (sel, hex, meta) => applyColour(ctx, sel.elements.filter((e) => e.type === 'icon').map((e) => e.id), hex, { token: meta.token }) });
    const sw = numRow(ctx, { label: 'Line thickness', short: 'T', get: (e) => e.strokeWidth, set: (e, v) => ({ strokeWidth: v }), min: 0.5, max: 6, step: 0.25, decimals: 2, types: I, key: 'iconstroke' });
    el.append(labelled('Colour', colour.el), sw.el);
    return { update(sel) { colour.update(sel); sw.update(sel); } };
}

// ================================================================================================
function mountIdentity(el, ctx) {
    const name = h('input', { class: 'lt-input', attrs: { type: 'text', 'aria-label': 'Layer name', maxlength: 80, placeholder: 'Name this layer' } });
    name.addEventListener('change', () => { const s = ctx.selection(); if (s.count === 1) ctx.run('element.rename', { id: s.ids[0], name: name.value.trim() || null }); });
    name.addEventListener('keydown', (e) => { if (e.key === 'Enter') name.blur(); e.stopPropagation(); });
    const lock = toggleButton({ label: 'Lock', iconName: 'lock', text: 'Lock', pressed: false, onChange: (v) => ctx.run('element.lock', { ids: ctx.selection().ids, value: v }) });
    const hide = toggleButton({ label: 'Hide', iconName: 'eye-off', text: 'Hide', pressed: false, onChange: (v) => ctx.run('element.hide', { ids: ctx.selection().ids, value: v }) });
    const group = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.run('element.group', { ids: ctx.selection().ids }) }, icon('group'), 'Group');
    const ungroup = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.run('element.ungroup', { ids: ctx.selection().ids }) }, icon('ungroup'), 'Ungroup');
    const order = h('div', { class: 'st-iconrow' }, ...[['Bring to front', 'bring-front', 'front'], ['Move forward', 'chevrons-up', 'forward'], ['Move back', 'chevrons-down', 'back'], ['Send to back', 'send-back', 'back-most']].map(([l, i, to]) => h('button', { class: 'st-tog', attrs: { type: 'button', 'aria-label': l, title: l }, onClick: () => ctx.run('element.reorder', { ids: ctx.selection().ids, to }) }, icon(i))));
    el.append(name, h('div', { class: 'st-iconrow' }, lock.el, hide.el), h('div', { class: 'st-iconrow' }, group, ungroup), labelled('Order', order));
    return { update(sel) {
        name.hidden = sel.count !== 1;
        if (sel.count === 1 && document.activeElement !== name) { name.value = sel.el.name || ''; name.placeholder = 'Name this layer'; }
        lock.set(sel.locked); hide.set(sel.hidden); group.hidden = !sel.canGroup; ungroup.hidden = !sel.canUngroup;
    } };
}
export { MIXED, sliderField };

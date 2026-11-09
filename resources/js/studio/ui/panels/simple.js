/*
 * The Simple mode side panel: one panel that shows only what applies to the
 * selection, in plain words. It never shows a control that does not apply.
 */
import { applyColour } from '../actions.js';
import { MIXED, announceSelection, common, elementLabel } from '../describe.js';
import { h, icon } from '../dom.js';
import { altTextField, fieldRow, segmented, sliderField, swatch } from '../fields.js';
import { openFontPopover, opacityOf, textSize, fontLabel } from '../quick.js';
import { actionButton, group, quickColours, rowOf, wrapRow } from './parts.js';

const TITLES = { none: 'Page', text: 'Text', image: 'Picture', shape: 'Shape', icon: 'Icon', group: 'Group', multi: 'Several items' };

export function mountSimplePanel(ctx, shell) {
    const host = shell.simpleHost;
    let rows = [];
    let key = '';

    function orderRow(sel) {
        return group('Arrange',
            wrapRow(actionButton('Move forward', 'chevrons-up', () => ctx.run('element.reorder', { ids: sel.ids, to: 'forward' }), { block: false }), actionButton('Move back', 'chevrons-down', () => ctx.run('element.reorder', { ids: sel.ids, to: 'back' }), { block: false })));
    }
    function transparency(sel) {
        const f = sliderField({ label: 'Make it transparent', min: 0, max: 100, step: 1, value: 100 - (opacityOf(sel) === MIXED ? 100 : opacityOf(sel)), format: (v) => (v === 0 ? 'Solid' : v + '% see-through'), onInput: (v) => ctx.run('element.update', { ids: sel.ids, changes: { opacity: (100 - v) / 100 } }, { key: 'opacity:' + sel.ids.join(',') }) });
        return { el: group('Make it transparent', f.el), update(s) { const o = opacityOf(s); f.set(o === MIXED ? 0 : 100 - o); } };
    }
    function finish(sel) {
        return group('',
            wrapRow(actionButton('Duplicate', 'copy', () => ctx.run('element.duplicate', { ids: sel.ids }), { block: false }), actionButton(sel.locked ? 'Unlock' : 'Lock', sel.locked ? 'unlock' : 'lock', () => ctx.run('element.lock', { ids: sel.ids, value: !sel.locked }), { block: false, pressed: sel.locked })),
            actionButton('Delete', 'trash', () => ctx.run('element.remove', { ids: sel.ids }), { danger: true }));
    }

    function pageRows() {
        const out = [];
        const bgChips = h('div', { class: 'st-colour__chips' });
        const paint = () => {
            const kit = ctx.brand; const page = ctx.selection().page;
            const cur = page.background && page.background.type === 'solid' ? page.background.color.toLowerCase() : null;
            const list = [];
            if (kit && kit.colors) for (const c of kit.colors.slice(0, 8)) list.push({ hex: c.hex, token: 'brand.colors.' + c.key, label: c.name || c.key });
            for (const hex of ['#ffffff', '#f3f4f6', '#111827']) list.push({ hex, label: hex });
            bgChips.replaceChildren(...list.map((c) => swatch(c.hex, { label: c.label, selected: cur === c.hex.toLowerCase(), onClick: () => (c.token ? ctx.run('brand.apply', { prop: 'background', token: c.token }) : ctx.run('page.update', { id: page.id, changes: { background: { ...page.background, type: 'solid', color: c.hex } } })) })));
        };
        paint();
        out.push({ el: group('Background colour', bgChips, h('button', { class: 'lt-btn lt-btn--ghost lt-btn--sm st-linkbtn', attrs: { type: 'button' }, onClick: () => ctx.openTab('background', { focus: true }) }, 'More background options')), update: paint });
        out.push({ el: group('Add to your page',
            actionButton('Add a heading', 'text', () => ctx.insert({ kind: 'text', props: ctx.textPreset('heading') })),
            actionButton('Add a picture', 'image-plus', () => ctx.openTab('uploads', { focus: true })),
            actionButton('Add a shape or icon', 'shape', () => ctx.openTab('elements', { focus: true }))), update() {} });
        const info = h('p', { class: 'st-note' });
        const sizeText = () => { const p = ctx.selection().page; return `This page is ${Math.round(p.width)} by ${Math.round(p.height)} px. Click anything on it to change it.`; };
        info.textContent = sizeText();
        out.push({ el: info, update() { info.textContent = sizeText(); } });
        return out;
    }

    function textRows(sel) {
        const out = [];
        out.push({ el: actionButton('Edit text', 'pen', () => ctx.studio.beginTextEdit(sel.el.id, { selectAll: true }), { primary: true }), update() {} });
        const sizeVal = h('span', { class: 'st-sizeval lt-num' });
        const size = group('Text size', rowOf(actionButton('Smaller', 'minus', () => textSize.smaller(ctx, sel), { block: false }), sizeVal, actionButton('Bigger', 'plus', () => textSize.bigger(ctx, sel), { block: false })));
        const setSize = (s) => { const v = common(s.elements, (e) => e.fontSize); sizeVal.textContent = v === MIXED ? 'Mixed' : Math.round(v) + ' px'; };
        setSize(sel);
        out.push({ el: size, update: (s) => { sel = s; setSize(s); } });
        const qc = quickColours(ctx, sel);
        out.push({ el: group('Colour', qc.el), update: (s) => { sel = s; qc.update(s); } });
        const fontBtn = h('button', { class: 'st-fontbtn', attrs: { type: 'button', 'aria-haspopup': 'dialog', 'aria-label': 'Change font' } }, h('span', { class: 'st-fontbtn__name' }), icon('chevron-down', 'lt-icon--sm'));
        const paintFont = (s) => { const f = common(s.elements, (e) => e.font); const name = fontBtn.querySelector('.st-fontbtn__name'); name.textContent = f === MIXED ? 'Mixed fonts' : fontLabel(ctx, f); if (f !== MIXED) name.style.fontFamily = ctx.studio.fonts.stackFor(f); };
        fontBtn.addEventListener('click', () => openFontPopover(ctx, fontBtn, ctx.selection()));
        paintFont(sel);
        out.push({ el: group('Font', fontBtn), update: paintFont });
        const align = segmented({ label: 'Alignment', value: common(sel.elements, (e) => e.align), small: true, items: [{ value: 'left', label: 'Align left', icon: 'align-left' }, { value: 'center', label: 'Centre', icon: 'align-center' }, { value: 'right', label: 'Align right', icon: 'align-right' }], onChange: (v) => ctx.run('element.update', { ids: sel.ids, changes: { align: v } }) });
        const bold = h('button', { class: 'st-tog', attrs: { type: 'button', 'aria-label': 'Bold', 'aria-pressed': 'false' }, onClick: () => { const on = !(common(ctx.selection().elements, (e) => e.fontWeight >= 600) === true); ctx.run('element.update', { ids: ctx.selection().ids, changes: { fontWeight: on ? 700 : 400 } }); } }, icon('bold'));
        const italic = h('button', { class: 'st-tog', attrs: { type: 'button', 'aria-label': 'Italic', 'aria-pressed': 'false' }, onClick: () => { const on = !(common(ctx.selection().elements, (e) => e.italic) === true); ctx.run('element.update', { ids: ctx.selection().ids, changes: { italic: on } }); } }, icon('italic'));
        const styleBox = group('Style', rowOf(bold, italic), align.el);
        const paintStyle = (s) => { align.set(common(s.elements, (e) => e.align)); bold.setAttribute('aria-pressed', String(common(s.elements, (e) => e.fontWeight >= 600) === true)); italic.setAttribute('aria-pressed', String(common(s.elements, (e) => e.italic) === true)); };
        paintStyle(sel);
        out.push({ el: styleBox, update: paintStyle });
        return out;
    }

    function imageRows(sel) {
        const out = [];
        const el = sel.el;
        out.push({ el: actionButton(sel.emptyPicture ? 'Add a picture' : 'Replace picture', 'image-plus', () => ctx.pickPicture(el.id), { primary: true }), update() {} });
        if (!sel.emptyPicture) {
            const fit = segmented({ label: 'How the picture fills its space', value: el.fit, items: [{ value: 'cover', label: 'Fill the space', text: 'Fill the space' }, { value: 'contain', label: 'Show the whole picture', text: 'Whole picture' }], onChange: (v) => ctx.run('element.update', { ids: [el.id], changes: { fit: v } }) });
            out.push({ el: group('How it fits', fit.el), update: (s) => fit.set(s.el && s.el.fit) });
            const zoom = sliderField({ label: 'Zoom picture', min: 1, max: 4, step: 0.05, value: el.zoom, format: (v) => Math.round(v * 100) + '%', onInput: (v) => ctx.run('element.update', { ids: [el.id], changes: { zoom: v } }, { key: 'zoom:' + el.id }) });
            const move = actionButton('Move the picture inside its frame', 'move', () => ctx.startReposition(el.id));
            out.push({ el: group('Position', move, fieldRow('Zoom', zoom)), update: (s) => zoom.set(s.el ? s.el.zoom : 1) });
        }
        if (!sel.emptyPicture) { const alt = altTextField({ value: el.alt, onCommit: (v) => ctx.run('element.update', { ids: [el.id], changes: { alt: v } }, { key: 'alt:' + el.id }) }); out.push({ el: group('For screen readers', alt.el), update: (s) => alt.set(s.el ? s.el.alt : null) }); }
        const tr = transparency(sel); out.push(tr);
        const radius = sliderField({ label: 'Rounded corners', min: 0, max: Math.round(Math.min(el.width, el.height) / 2), step: 1, value: el.radius || 0, format: (v) => (v === 0 ? 'Square' : Math.round(v) + ' px'), onInput: (v) => ctx.run('element.update', { ids: [el.id], changes: { radius: v } }, { key: 'radius:' + el.id }) });
        out.push({ el: group('Rounded corners', radius.el), update: (s) => radius.set(s.el ? s.el.radius || 0 : 0) });
        return out;
    }

    function shapeRows(sel) {
        const out = [];
        const qc = quickColours(ctx, sel);
        out.push({ el: group('Colour', qc.el), update: (s) => { sel = s; qc.update(s); } });
        const tr = transparency(sel); out.push(tr);
        if (sel.kind === 'shape' && sel.el.kind === 'rect') {
            const el = sel.el;
            const radius = sliderField({ label: 'Rounded corners', min: 0, max: Math.round(Math.min(el.width, el.height) / 2), step: 1, value: el.radius || 0, format: (v) => (v === 0 ? 'Square' : Math.round(v) + ' px'), onInput: (v) => ctx.run('element.update', { ids: [el.id], changes: { radius: v } }, { key: 'radius:' + el.id }) });
            out.push({ el: group('Rounded corners', radius.el), update: (s) => radius.set(s.el ? s.el.radius || 0 : 0) });
        }
        return out;
    }

    function multiRows(sel) {
        const out = [];
        const al = (label, iconName, name) => actionButton(label, iconName, () => ctx.run(name, { ids: sel.ids }), { block: false });
        out.push({ el: group('Line up', h('div', { class: 'st-iconrow' }, ...[['Left', 'align-left', 'align.left'], ['Middle', 'align-center', 'align.center'], ['Right', 'align-right', 'align.right'], ['Top', 'align-top', 'align.top'], ['Centre', 'align-middle', 'align.middle'], ['Bottom', 'align-bottom', 'align.bottom']].map(([l, i, n]) => h('button', { class: 'st-tog', attrs: { type: 'button', 'aria-label': 'Line up: ' + l, title: 'Line up: ' + l }, onClick: () => ctx.run(n, { ids: sel.ids }) }, icon(i))))), update() {} });
        void al;
        if (sel.count >= 3) out.push({ el: group('Spread evenly', wrapRow(actionButton('Across', 'distribute', () => ctx.run('distribute.horizontal', { ids: sel.ids }), { block: false }), actionButton('Down', 'distribute', () => ctx.run('distribute.vertical', { ids: sel.ids }), { block: false }))), update() {} });
        out.push({ el: group('Make same size', wrapRow(actionButton('Width', 'rect-h', () => ctx.run('size.match', { ids: sel.ids, dimension: 'width' }), { block: false }), actionButton('Height', 'rect-v', () => ctx.run('size.match', { ids: sel.ids, dimension: 'height' }), { block: false }))), update() {} });
        out.push({ el: actionButton('Group together', 'group', () => ctx.run('element.group', { ids: sel.ids }), { primary: true }), update() {} });
        const tr = transparency(sel); out.push(tr);
        return out;
    }

    function groupRows(sel) {
        const out = [{ el: actionButton('Ungroup', 'ungroup', () => ctx.run('element.ungroup', { ids: sel.ids }), { primary: true }), update() {} }];
        out.push(transparency(sel));
        return out;
    }

    function render() {
        const sel = ctx.selection();
        const k = [sel.kind, sel.ids.join(','), sel.locked, sel.emptyPicture, ctx.mode].join('|');
        if (k !== key) {
            key = k;
            host.replaceChildren();
            rows = [];
            shell.setSimpleTitle(sel.count === 1 ? TITLES[sel.kind] : TITLES[sel.kind]);
            const head = sel.count ? h('div', { class: 'st-simple__what' }, h('strong', { text: announceSelection(sel) })) : null;
            if (head) host.appendChild(head);
            if (sel.locked) {
                host.appendChild(h('p', { class: 'st-note', text: 'This is locked in place so it cannot be moved by accident.' }));
                host.appendChild(actionButton('Unlock', 'unlock', () => ctx.run('element.lock', { ids: sel.ids, value: false }), { primary: true }));
            } else {
                rows = sel.kind === 'none' ? pageRows() : sel.kind === 'text' ? textRows(sel) : sel.kind === 'image' ? imageRows(sel) : sel.kind === 'multi' ? multiRows(sel) : sel.kind === 'group' ? groupRows(sel) : shapeRows(sel);
                if (sel.kind !== 'none') { rows.push({ el: orderRow(sel), update() {} }); rows.push({ el: finish(sel), update() {} }); }
                host.append(...rows.map((r) => r.el));
            }
        } else {
            for (const r of rows) { try { r.update(sel); } catch (e) { console.error('[studio] panel update failed', e); } }
        }
        void elementLabel;
    }
    ctx.on('selection', render); ctx.on('change', render); ctx.on('page', render); ctx.on('mode', () => { key = ''; render(); }); ctx.on('brand', () => { key = ''; render(); });
    ctx.on('reposition', () => { key = ''; render(); });
    render();
    void applyColour;
}

/*
 * The floating toolbar next to the selection. Simple mode: the things people do
 * most (edit text, colour, font, size, replace picture, duplicate, delete).
 * Pro mode: a short strip (edit, duplicate, lock, delete, more). On a phone it
 * docks above the bottom bar as the selection strip.
 */
import { find } from '../doc/tree.js';
import { frameHandlePoints, selectionFrame } from '../engine/selection.js';
import { pickFile } from './actions.js';
import { openContextMenu } from './contextmenu.js';
import { announceSelection } from './describe.js';
import { COMPACT_QUERY, announce, h, icon, isCompact, setVars, throttleFrame } from './dom.js';
import { openColourPopover, openFontPopover, openLayerMenu, textSize } from './quick.js';

export function createFloating(ctx, shell) {
    const studio = ctx.studio;
    const root = shell.stage;
    const bar = h('div', { class: 'st-float', hidden: true, attrs: { role: 'toolbar', 'aria-label': 'Selection actions', 'data-studio-ignore': '' } });
    shell.stageWrap.appendChild(bar);
    let dragging = false;

    const btn = (label, iconName, onClick, { text, pressed, tip = true } = {}) => {
        const b = h('button', { class: ['st-float__btn', text && 'st-float__btn--text'], attrs: { type: 'button', 'aria-label': label, 'aria-pressed': pressed === undefined ? null : String(!!pressed), 'data-tip': tip && !text ? label : null, 'data-tip-side': 'bottom' }, onClick: (e) => onClick(e.currentTarget) }, iconName ? icon(iconName) : null, text ? h('span', { text }) : null);
        return b;
    };
    const sep = () => h('span', { class: 'st-float__sep', attrs: { 'aria-hidden': 'true' } });

    // The phone strip: Edit text / Replace picture, Colour, Layer, Duplicate, More (all the options in the sheet).
    function buildCompact(sel) {
        const items = [];
        const el = sel.el;
        const more = btn('All options', 'settings', () => ctx.openEditSheet(), { text: 'More' });
        if (sel.locked) return [btn('Unlock', 'unlock', () => ctx.run('element.lock', { ids: sel.ids, value: false }), { text: 'Unlock' }), more];
        if (sel.kind === 'text') items.push(btn('Edit text', 'pen', () => studio.beginTextEdit(el.id, { selectAll: true }), { text: 'Edit text' }), btn('Colour', 'palette', (b) => openColourPopover(ctx, b, sel)), btn('Font', 'text', (b) => openFontPopover(ctx, b, sel)));
        else if (sel.kind === 'image') items.push(btn(sel.emptyPicture ? 'Add a picture' : 'Replace picture', 'image-plus', () => ctx.pickPicture(el.id), { text: sel.emptyPicture ? 'Add a picture' : 'Replace picture' }));
        else if (sel.kind === 'shape' || sel.kind === 'icon') items.push(btn('Colour', 'palette', (b) => openColourPopover(ctx, b, sel)));
        else if (sel.kind === 'multi') items.push(btn('Group together', 'group', () => ctx.run('element.group', { ids: sel.ids }), { text: 'Group' }));
        else if (sel.kind === 'group') items.push(btn('Ungroup', 'ungroup', () => ctx.run('element.ungroup', { ids: sel.ids }), { text: 'Ungroup' }));
        items.push(btn('Layer order', 'layers', (b) => openLayerMenu(ctx, b, sel)), btn('Duplicate', 'copy', () => ctx.run('element.duplicate', { ids: sel.ids })), btn('Delete', 'trash', () => ctx.run('element.remove', { ids: sel.ids })), more);
        return items;
    }

    function build(sel) {
        if (isCompact()) return buildCompact(sel);
        const simple = ctx.mode === 'simple';
        const items = [];
        const el = sel.el;
        const del = btn('Delete', 'trash', () => { ctx.run('element.remove', { ids: sel.ids }); });
        const dup = btn('Duplicate', 'copy', () => { ctx.run('element.duplicate', { ids: sel.ids }); });
        const lock = btn(sel.locked ? 'Unlock' : 'Lock in place', sel.locked ? 'unlock' : 'lock', () => { ctx.run('element.lock', { ids: sel.ids, value: !sel.locked }); }, { pressed: sel.locked });
        const more = btn('More actions', 'more', (b) => { const r = b.getBoundingClientRect(); openContextMenu(ctx, { x: r.left, y: r.bottom + 4 }); });
        if (sel.locked) return [lock, more];
        if (sel.kind === 'text') {
            items.push(btn('Edit text', 'pen', () => studio.beginTextEdit(el.id, { selectAll: true }), { text: simple ? 'Edit text' : undefined }));
            if (simple) items.push(btn('Make text smaller', 'minus', () => textSize.smaller(ctx, sel)), btn('Make text bigger', 'plus', () => textSize.bigger(ctx, sel)));
            items.push(btn('Colour', 'palette', (b) => openColourPopover(ctx, b, sel)));
            if (simple) items.push(btn('Font', 'text', (b) => openFontPopover(ctx, b, sel)));
        } else if (sel.kind === 'image') {
            items.push(btn(sel.emptyPicture ? 'Add a picture' : 'Replace picture', 'image-plus', () => ctx.pickPicture(el.id), { text: sel.emptyPicture ? 'Add a picture' : simple ? 'Replace picture' : undefined }));
            if (!sel.emptyPicture && simple) items.push(btn('Move the picture inside its frame', 'move', () => ctx.startReposition(el.id)));
        } else if (sel.kind === 'shape' || sel.kind === 'icon') {
            items.push(btn('Colour', 'palette', (b) => openColourPopover(ctx, b, sel)));
        } else if (sel.kind === 'multi') {
            items.push(btn('Group together', 'group', () => ctx.run('element.group', { ids: sel.ids })));
            items.push(btn('Line up to the left', 'align-left', () => ctx.run('align.left', { ids: sel.ids })));
            items.push(btn('Line up to the middle', 'align-center', () => ctx.run('align.center', { ids: sel.ids })));
        } else if (sel.kind === 'group') {
            items.push(btn('Ungroup', 'ungroup', () => ctx.run('element.ungroup', { ids: sel.ids })));
        }
        if (items.length) items.push(sep());
        items.push(dup);
        if (!simple) items.push(lock);
        items.push(del, more);
        return items;
    }

    const place = throttleFrame(() => {
        const s = studio.getState();
        const sel = ctx.selection();
        if (ctx.readOnly || !sel.count || s.editingId || dragging) { bar.hidden = true; return; }
        bar.hidden = false;
        const compact = isCompact();
        bar.dataset.compact = compact ? '1' : '0';
        if (compact) { bar.style.removeProperty('--fx'); bar.style.removeProperty('--fy'); return; }
        const frame = selectionFrame(sel.page, sel.ids);
        if (!frame) { bar.hidden = true; return; }
        const pts = frameHandlePoints(frame);
        const corners = [pts.nw, pts.ne, pts.se, pts.sw].map((p) => studio.viewport.pageToScreen(p.x, p.y));
        const xs = corners.map((c) => c.x); const ys = corners.map((c) => c.y);
        const rr = root.getBoundingClientRect(); const wr = shell.stageWrap.getBoundingClientRect();
        const ox = rr.left - wr.left; const oy = rr.top - wr.top;
        const cx = ox + (Math.min(...xs) + Math.max(...xs)) / 2;
        const top = oy + Math.min(...ys);
        const bottom = oy + Math.max(...ys);
        const bw = bar.offsetWidth || 240; const bh = bar.offsetHeight || 44;
        let y = top - bh - 14;
        if (y < oy + 6) y = bottom + 34;
        y = Math.min(y, wr.height - bh - 8);
        const x = Math.max(ox + 6, Math.min(cx - bw / 2, wr.width - bw - 8));
        setVars(bar, { fx: x + 'px', fy: y + 'px' });
    });

    let lastKey = '';
    function render() {
        const sel = ctx.selection();
        // rebuild only when the kind of toolbar changes, so a popover anchored to a button survives a colour edit
        const key = [ctx.mode, isCompact(), sel.kind, sel.locked, sel.emptyPicture, sel.count > 1 ? sel.count : sel.ids[0]].join('|');
        if (key !== lastKey) { lastKey = key; bar.replaceChildren(...build(sel)); }
        place();
    }
    ctx.on('selection', () => { render(); const sel = ctx.selection(); announce(announceSelection(sel)); });
    ctx.on('change', render); ctx.on('viewport', place); ctx.on('mode', () => { lastKey = ''; render(); }); ctx.on('page', () => { lastKey = ''; render(); });
    studio.on('text-edit', ({ phase }) => { if (phase === 'start' || phase === 'end') { render(); } });
    root.addEventListener('pointerdown', (e) => { if (e.button === 0 || e.pointerType === 'touch') { dragging = true; place(); } }, true);
    window.addEventListener('pointerup', () => { if (dragging) { dragging = false; place(); } });
    window.addEventListener('pointercancel', () => { if (dragging) { dragging = false; place(); } });
    new ResizeObserver(place).observe(shell.stageWrap);
    if (typeof matchMedia === 'function') matchMedia(COMPACT_QUERY).addEventListener('change', () => { lastKey = ''; render(); });
    render();

    // helper used by several panels
    ctx.pickPicture = async (elementId) => {
        ctx.uploadTarget = elementId || null;
        ctx.openTab('uploads', { focus: true });
        ctx.emit('upload-target', { id: elementId });
    };
    void find; void pickFile;
    return { place, render };
}

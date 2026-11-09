/*
 * Move a picture inside its frame (the focal point) by dragging it, with the
 * zoom in the panel. While active, drags on the picture are taken here and never
 * reach the engine, so the frame itself does not move. Esc or a click outside ends it.
 */
import { find } from '../doc/tree.js';
import { activePage } from './describe.js';
import { h } from './dom.js';

export function imageLayout(el, asset) {
    const w = el.width; const hh = el.height;
    if (!asset || !asset.width || !asset.height) return null;
    const iw = asset.width; const ih = asset.height;
    let dw; let dh;
    if (el.fit === 'fill') { dw = w * el.zoom; dh = hh * el.zoom; } else { const s = (el.fit === 'contain' ? Math.min(w / iw, hh / ih) : Math.max(w / iw, hh / ih)) * el.zoom; dw = iw * s; dh = ih * s; }
    return { dw, dh, canX: dw > w + 0.5, canY: dh > hh + 0.5 };
}

/** The focal point that puts the picture's offset at (x, y) inside a w x h frame (inverse of the renderer). */
export function focalFor(el, layout, x, y) {
    const w = el.width; const hh = el.height;
    const cx = layout.canX ? Math.min(0, Math.max(w - layout.dw, x)) : (w - layout.dw) / 2;
    const cy = layout.canY ? Math.min(0, Math.max(hh - layout.dh, y)) : (hh - layout.dh) / 2;
    return {
        x: layout.canX ? Math.min(1, Math.max(0, (w / 2 - cx) / layout.dw)) : el.focal.x,
        y: layout.canY ? Math.min(1, Math.max(0, (hh / 2 - cy) / layout.dh)) : el.focal.y,
    };
}

export function installReposition(ctx, shell) {
    const studio = ctx.studio;
    const root = shell.stage;
    let id = null;
    const hint = h('div', { class: 'st-reposition', hidden: true, attrs: { role: 'status', 'data-studio-ignore': '' } },
        h('span', { text: 'Drag the picture to move it inside its frame.' }),
        h('button', { class: 'lt-btn lt-btn--sm', attrs: { type: 'button' }, text: 'Done', onClick: () => stop() }));
    shell.stageWrap.appendChild(hint);

    function stop() { id = null; hint.hidden = true; root.classList.remove('is-repositioning'); ctx.emit('reposition', { id: null }); }
    function start(elementId) {
        const page = activePage(studio.getState());
        const hit = find(page.elements, elementId);
        if (!hit || hit.element.type !== 'image' || hit.element.asset_id == null) { ctx.toast('Add a picture first, then you can move it inside its frame.', { kind: 'warn' }); return; }
        id = elementId; hint.hidden = false; root.classList.add('is-repositioning');
        studio.select([elementId]);
        ctx.emit('reposition', { id });
    }
    ctx.startReposition = start;
    ctx.stopReposition = stop;
    ctx.repositioning = () => id;

    let drag = null;
    root.addEventListener('pointerdown', (e) => {
        if (id === null || e.button !== 0) return;
        const page = activePage(studio.getState());
        const hit = find(page.elements, id);
        if (!hit) { stop(); return; }
        const el = hit.element;
        const rr = root.getBoundingClientRect();
        const p = studio.viewport.screenToPage(e.clientX - rr.left, e.clientY - rr.top);
        const inside = p.x >= el.x && p.x <= el.x + el.width && p.y >= el.y && p.y <= el.y + el.height && !hit.ancestors.length && !el.rotation;
        if (!inside) { stop(); return; }
        const layout = imageLayout(el, ctx.assets.get(Number(el.asset_id)));
        if (!layout) { ctx.toast('This picture cannot be moved inside its frame.', { kind: 'warn' }); stop(); return; }
        e.stopImmediatePropagation(); e.preventDefault();
        const w = el.width; const hh = el.height;
        const startX = layout.canX ? Math.min(0, Math.max(w - layout.dw, w / 2 - el.focal.x * layout.dw)) : 0;
        const startY = layout.canY ? Math.min(0, Math.max(hh - layout.dh, hh / 2 - el.focal.y * layout.dh)) : 0;
        drag = { sx: e.clientX, sy: e.clientY, startX, startY, layout, el };
        root.setPointerCapture(e.pointerId);
    }, true);
    root.addEventListener('pointermove', (e) => {
        if (!drag) return;
        e.stopImmediatePropagation();
        const z = studio.viewport.zoom;
        const f = focalFor(drag.el, drag.layout, drag.startX + (e.clientX - drag.sx) / z, drag.startY + (e.clientY - drag.sy) / z);
        ctx.run('element.update', { ids: [drag.el.id], changes: { focal: f } }, { key: 'focal:' + drag.el.id });
    }, true);
    const end = (e) => { if (drag) { e.stopImmediatePropagation(); drag = null; } };
    root.addEventListener('pointerup', end, true); root.addEventListener('pointercancel', end, true);
    document.addEventListener('keydown', (e) => { if (id !== null && e.key === 'Escape') { e.stopPropagation(); stop(); } }, true);
    ctx.on('selection', () => { if (id !== null) { const sel = ctx.selection(); if (!(sel.count === 1 && sel.el.id === id)) stop(); } });
    studio.on('activate', ({ id: eid, type }) => { if (type === 'image' && !ctx.readOnly) { const page = activePage(studio.getState()); const hit = find(page.elements, eid); if (hit && hit.element.asset_id == null) ctx.pickPicture(eid); else start(eid); } });
}

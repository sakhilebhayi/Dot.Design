/*
 * The stage around the engine canvas: crop marks at the trim corners, the grid,
 * rulers and guides (Pro), the guided empty state, drop targets for panel items
 * and files, and pan/zoom for a read-only viewer (the engine's own input is off
 * for them). Overlays are children of the engine root with pointer-events off.
 */
import { hitTest } from '../engine/selection.js';
import { DND_TYPE, insert } from './insert.js';
import { activePage } from './describe.js';
import { h, icon, setVars, throttleFrame } from './dom.js';
import { drawRuler } from './ruler.js';
import { displayUnit, toPx } from './units.js';

const RULER = 22;

export function gridSizeFor(page) {
    const short = Math.min(page.width, page.height);
    const raw = short / 36;
    const nice = [5, 10, 20, 25, 50, 100, 200];
    return nice.reduce((a, b) => (Math.abs(b - raw) < Math.abs(a - raw) ? b : a), 10);
}

export function createStage(ctx, shell) {
    const studio = ctx.studio;
    const wrap = shell.stageWrap;
    const root = shell.stage;
    const unit = displayUnit(ctx.config.project, ctx.config.formats);

    const crops = h('div', { class: 'st-crops lt-crop', attrs: { 'aria-hidden': 'true', 'data-studio-ignore': '' } });
    const grid = h('div', { class: 'st-grid', attrs: { 'aria-hidden': 'true', 'data-studio-ignore': '' } });
    const emptyCard = h('div', { class: 'st-empty', hidden: true, attrs: { 'data-studio-ignore': '' } });
    const dropHint = h('div', { class: 'st-drop', hidden: true, attrs: { 'aria-hidden': 'true' } }, icon('image-plus'), h('span', { text: 'Drop to add to your design' }));
    const rulerH = h('canvas', { class: 'st-ruler st-ruler--h', attrs: { 'aria-hidden': 'true' } });
    const rulerV = h('canvas', { class: 'st-ruler st-ruler--v', attrs: { 'aria-hidden': 'true' } });
    const rulerCorner = h('div', { class: 'st-ruler__corner', attrs: { 'aria-hidden': 'true' } });
    const guideLine = h('div', { class: 'st-guideline', hidden: true, attrs: { 'aria-hidden': 'true' } });
    root.append(grid, crops);
    wrap.append(rulerCorner, rulerH, rulerV, emptyCard, dropHint, guideLine);

    // ---- geometry that follows the viewport -----------------------------------------------------
    function pageRect() {
        const v = studio.viewport.view; const p = activePage(studio.getState());
        return { x: v.x, y: v.y, w: p.width * v.zoom, h: p.height * v.zoom, zoom: v.zoom, page: p };
    }
    const layout = throttleFrame(() => {
        const r = pageRect();
        setVars(crops, { x: r.x + 'px', y: r.y + 'px', w: r.w + 'px', h: r.h + 'px' });
        const showGrid = !!ctx.settings.grid && ctx.mode === 'pro';
        grid.hidden = !showGrid;
        if (showGrid) { const gs = gridSizeFor(r.page) * r.zoom; setVars(grid, { x: r.x + 'px', y: r.y + 'px', w: r.w + 'px', h: r.h + 'px', gs: gs + 'px' }); }
        paintRulers(r);
        paintEmpty(r);
    });

    function rulersOn() { return ctx.mode === 'pro' && !!ctx.settings.rulers; }
    function paintRulers(r) {
        const on = rulersOn();
        wrap.dataset.rulers = on ? '1' : '0';
        for (const el of [rulerH, rulerV, rulerCorner]) el.hidden = !on;
        if (!on) return;
        drawRuler(rulerH, { horizontal: true, origin: r.x, zoom: r.zoom, unit, pageSize: r.page.width });
        drawRuler(rulerV, { horizontal: false, origin: r.y, zoom: r.zoom, unit, pageSize: r.page.height });
    }

    function paintEmpty(r) {
        const page = r.page;
        const empty = !ctx.readOnly && page.elements.length === 0 && !studio.getState().editingId;
        emptyCard.hidden = !empty;
        if (!empty) return;
        const wr = wrap.getBoundingClientRect(); const rr = root.getBoundingClientRect();
        const cx = (rr.left - wr.left) + r.x + r.w / 2; const cy = (rr.top - wr.top) + r.y + r.h / 2;
        setVars(emptyCard, { cx: cx + 'px', cy: cy + 'px' });
    }
    emptyCard.append(
        h('div', { class: 'st-empty__title', text: 'This page is empty' }),
        h('p', { class: 'st-empty__text', text: 'Start with a headline, add a picture, or pick a template.' }),
        h('div', { class: 'st-empty__actions' },
            h('button', { class: 'lt-btn lt-btn--sm', attrs: { type: 'button' }, onClick: () => insert(ctx, { kind: 'text', props: textPreset(ctx, 'heading') }) }, icon('text'), 'Add a heading'),
            h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.openTab('uploads', { focus: true }) }, icon('image-plus'), 'Add a picture'),
            h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: () => ctx.openTab('templates', { focus: true }) }, icon('template'), 'Use a template')));

    for (const ev of ['viewport', 'page', 'change', 'mode', 'settings']) ctx.on(ev, layout);
    studio.on('text-edit', layout);
    new ResizeObserver(layout).observe(wrap);
    layout();

    // ---- preferences the engine reads -------------------------------------------------------------
    function applyPrefs() {
        const page = activePage(studio.getState());
        const pro = ctx.mode === 'pro';
        studio.setPrefs({ snap: ctx.settings.snap !== false, grid: pro && ctx.settings.grid ? gridSizeFor(page) : 0, showGuides: true });
    }
    for (const ev of ['mode', 'settings', 'page']) ctx.on(ev, applyPrefs);
    applyPrefs();

    // ---- guides: drag from a ruler ----------------------------------------------------------------
    function startGuide(axis, e) {
        if (e.button !== 0) return;
        const rr = root.getBoundingClientRect();
        guideLine.hidden = false; guideLine.dataset.axis = axis;
        const move = (ev) => { setVars(guideLine, axis === 'y' ? { gy: (ev.clientY - wrap.getBoundingClientRect().top) + 'px' } : { gx: (ev.clientX - wrap.getBoundingClientRect().left) + 'px' }); };
        const up = (ev) => {
            window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); guideLine.hidden = true;
            const pt = studio.viewport.screenToPage(ev.clientX - rr.left, ev.clientY - rr.top);
            const page = activePage(studio.getState());
            const pos = Math.round(axis === 'y' ? pt.y : pt.x);
            const limit = axis === 'y' ? page.height : page.width;
            const inside = axis === 'y' ? ev.clientY > rr.top + 4 : ev.clientX > rr.left + 4;
            if (!inside || pos < -limit * 0.5 || pos > limit * 1.5) return;
            ctx.run('page.update', { id: page.id, changes: { guides: [...(page.guides || []), { axis, pos }].slice(0, 40) } });
        };
        window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
        e.preventDefault();
    }
    rulerH.addEventListener('pointerdown', (e) => startGuide('y', e));
    rulerV.addEventListener('pointerdown', (e) => startGuide('x', e));

    // ---- drops ------------------------------------------------------------------------------------
    function pointFromEvent(e) { const rr = root.getBoundingClientRect(); return studio.viewport.screenToPage(e.clientX - rr.left, e.clientY - rr.top); }
    const hasFiles = (dt) => dt && Array.from(dt.types || []).includes('Files');
    const hasPayload = (dt) => dt && Array.from(dt.types || []).includes(DND_TYPE);
    if (!ctx.readOnly) {
        root.addEventListener('dragover', (e) => { if (hasFiles(e.dataTransfer) || hasPayload(e.dataTransfer)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; dropHint.hidden = !hasFiles(e.dataTransfer); } });
        root.addEventListener('dragleave', (e) => { if (!root.contains(e.relatedTarget)) dropHint.hidden = true; });
        root.addEventListener('drop', async (e) => {
            dropHint.hidden = true; document.body.classList.remove('st-dragging');
            const point = pointFromEvent(e);
            if (hasFiles(e.dataTransfer)) { e.preventDefault(); ctx.placeFiles(Array.from(e.dataTransfer.files), { point }); return; }
            const raw = e.dataTransfer && e.dataTransfer.getData(DND_TYPE);
            if (!raw) return;
            e.preventDefault();
            let payload; try { payload = JSON.parse(raw); } catch (err) { return; }
            const page = activePage(studio.getState());
            if (payload.kind === 'asset') {
                const hit = hitTest(page, point.x, point.y, {});
                if (hit) { const el = (function find(list) { for (const x of list) { if (x.id === hit.id) return x; } return null; }(page.elements)); if (el && el.type === 'image') { ctx.addAssets([payload.asset]); ctx.run('element.update', { ids: [el.id], changes: { asset_id: payload.asset.id } }); ctx.studio.select([el.id]); return; } }
            }
            insert(ctx, payload, { point });
        });
        studio.on('paste-files', ({ files }) => ctx.placeFiles(files, {}));
        window.addEventListener('dragenter', (e) => { if (hasFiles(e.dataTransfer)) document.body.classList.add('st-dragging'); });
        window.addEventListener('dragend', () => document.body.classList.remove('st-dragging'));
        window.addEventListener('drop', () => document.body.classList.remove('st-dragging'));
        // a file dropped outside the canvas must not make the browser open it
        window.addEventListener('dragover', (e) => { if (hasFiles(e.dataTransfer)) e.preventDefault(); });
        window.addEventListener('drop', (e) => { if (hasFiles(e.dataTransfer) && !root.contains(e.target)) e.preventDefault(); });
    }

    // ---- read-only viewers: pan and zoom without the editing input -------------------------------
    if (ctx.readOnly) {
        studio.setInputEnabled(false);
        const pts = new Map(); let last = null; let pinch = null;
        const local = (e) => { const r = root.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
        root.addEventListener('wheel', (e) => {
            e.preventDefault();
            if (e.ctrlKey || e.metaKey) studio.viewport.setZoom(studio.viewport.zoom * Math.exp(-e.deltaY * 0.01), local(e)); else studio.viewport.panBy(-e.deltaX, -e.deltaY);
        }, { passive: false });
        root.addEventListener('pointerdown', (e) => { root.setPointerCapture(e.pointerId); pts.set(e.pointerId, local(e)); last = local(e); if (pts.size === 2) { const [a, b] = Array.from(pts.values()); pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), z: studio.viewport.zoom }; } });
        root.addEventListener('pointermove', (e) => {
            if (!pts.has(e.pointerId)) return;
            const p = local(e);
            if (pts.size === 2 && pinch) { pts.set(e.pointerId, p); const [a, b] = Array.from(pts.values()); const d = Math.hypot(a.x - b.x, a.y - b.y); studio.viewport.setZoom(pinch.z * (d / pinch.d), { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }); return; }
            studio.viewport.panBy(p.x - last.x, p.y - last.y); last = p; pts.set(e.pointerId, p);
        });
        const end = (e) => { pts.delete(e.pointerId); pinch = null; last = pts.size ? Array.from(pts.values())[0] : null; };
        root.addEventListener('pointerup', end); root.addEventListener('pointercancel', end);
        root.dataset.readonly = '1';
    }

    return { layout, unit, toPx, pageRect };
}

/** Text presets sized to the page and using the brand fonts when a kit exists. */
export function textPreset(ctx, kind) {
    const page = activePage(ctx.studio.getState());
    const short = Math.min(page.width, page.height);
    const kit = ctx.brand;
    const heading = kit && kit.fonts && kit.fonts.heading; const body = kit && kit.fonts && kit.fonts.body;
    const neutral = kit && kit.colors && kit.colors.find((c) => c.role === 'neutral');
    const colour = neutral ? { color: neutral.hex, tokens: { color: 'brand.colors.' + neutral.key } } : {};
    const hTok = heading ? { font: heading } : {};
    if (kind === 'heading') return { text: 'Add a heading', fontSize: Math.round(short * 0.085), fontWeight: 700, lineHeight: 1.1, ...hTok, ...colour, ...(heading ? { tokens: { ...(colour.tokens || {}), font: 'brand.fonts.heading' } } : {}), name: 'Heading', role: 'headline' };
    if (kind === 'subheading') return { text: 'Add a subheading', fontSize: Math.round(short * 0.05), fontWeight: 500, lineHeight: 1.2, ...(body ? { font: body } : {}), ...colour, ...(body ? { tokens: { ...(colour.tokens || {}), font: 'brand.fonts.body' } } : {}), name: 'Subheading', role: 'subhead' };
    return { text: 'Add a little bit of body text', fontSize: Math.round(short * 0.03), fontWeight: 400, lineHeight: 1.4, ...(body ? { font: body } : {}), ...colour, ...(body ? { tokens: { ...(colour.tokens || {}), font: 'brand.fonts.body' } } : {}), name: 'Body text', role: 'body' };
}

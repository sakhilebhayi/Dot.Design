/*
 * Read-only viewer for the share-link page: renders pages with the SAME
 * renderer as the editor, with zoom and pan but no selection or editing.
 *
 *   const v = createViewer({ root, pages, assets, fonts, icons });
 *   v.setPage(1); v.next(); v.fit(); v.on('page', ...); v.destroy();
 */
import { normalisePage } from '../doc/schema.js';
import { createFontManager } from './fonts.js';
import { createIconRegistry } from './icons.js';
import { SVG_NS, createLiveRenderer, renderPageToSvgString } from './render.js';
import { createCanvasMeasurer } from './text-layout.js';
import { createViewport, pinchView } from './viewport.js';

export function createViewer(options = {}) {
    const root = options.root;
    if (!root || root.nodeType !== 1) throw new Error('createViewer needs a root element.');
    const doc = root.ownerDocument;
    const listeners = new Map();
    const emit = (e, p) => (listeners.get(e) || []).forEach((fn) => { try { fn(p); } catch (err) { /* listener errors never break the viewer */ } });

    const pages = (options.pages || []).map((p) => normalisePage(p, { project: { width: 1080, height: 1080 } }));
    if (!pages.length) throw new Error('createViewer needs at least one page.');
    const assets = new Map((Array.isArray(options.assets) ? options.assets : []).map((a) => [Number(a.id), a]));
    const fm = Array.isArray(options.fonts) ? { manifest: options.fonts } : options.fonts || { manifest: [] };
    const fonts = createFontManager({ manifest: fm.manifest || [], baseUrl: fm.baseUrl || '' });
    const measure = createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight });
    const icons = createIconRegistry(options.icons);
    const assetUrl = (id) => { const a = assets.get(Number(id)); return a ? a.url || null : null; };
    const assetSize = (id) => { const a = assets.get(Number(id)); return a && a.width && a.height ? { width: a.width, height: a.height } : null; };

    root.classList.add('studio-viewer');
    Object.assign(root.style, { position: root.style.position || 'relative', overflow: 'hidden', touchAction: 'none', userSelect: 'none' });
    const stage = doc.createElement('div');
    Object.assign(stage.style, { position: 'absolute', left: '0', top: '0', transformOrigin: '0 0' });
    const svg = doc.createElementNS(SVG_NS, 'svg');
    svg.style.display = 'block';
    stage.appendChild(svg);
    root.appendChild(stage);

    const renderer = createLiveRenderer(svg, { document: doc, measure, icons, assetUrl, assetSize, fonts });
    const r0 = root.getBoundingClientRect();
    const viewport = createViewport({ width: r0.width || 800, height: r0.height || 600, pageWidth: pages[0].width, pageHeight: pages[0].height });
    let index = 0;

    function apply(v) { stage.style.transform = `translate(${v.x}px, ${v.y}px) scale(${v.zoom})`; emit('viewport', v); }
    viewport.onChange(apply);

    function show() {
        const p = pages[index];
        Object.assign(stage.style, { width: p.width + 'px', height: p.height + 'px' });
        viewport.setPageSize(p.width, p.height);
        renderer.render(p);
        apply(viewport.view);
        emit('page', { index, count: pages.length });
    }

    fonts.ensureLoaded(fonts.usedSpecs(pages)).then(() => { measure.clear(); renderer.invalidate(); show(); });
    viewport.fit();
    show();

    // pan with one pointer, pinch with two, wheel pans and ctrl-wheel zooms
    const pts = new Map();
    let drag = null;
    let pinch = null;
    const local = (e) => { const r = root.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
    const down = (e) => {
        root.setPointerCapture && root.setPointerCapture(e.pointerId);
        pts.set(e.pointerId, local(e));
        if (pts.size === 2) { const [a, b] = Array.from(pts.values()); pinch = { start: { ...viewport.view }, mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, dist: Math.hypot(a.x - b.x, a.y - b.y) }; drag = null; } else if (pts.size === 1) drag = { last: local(e) };
    };
    const move = (e) => {
        if (!pts.has(e.pointerId)) return;
        const p = local(e);
        pts.set(e.pointerId, p);
        if (pinch && pts.size >= 2) {
            const [a, b] = Array.from(pts.values());
            viewport.setView(pinchView(pinch.start, pinch.mid, pinch.dist, { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, Math.hypot(a.x - b.x, a.y - b.y)));
        } else if (drag) { viewport.panBy(p.x - drag.last.x, p.y - drag.last.y); drag.last = p; }
    };
    const up = (e) => { pts.delete(e.pointerId); if (pts.size < 2) pinch = null; if (!pts.size) drag = null; else if (pts.size === 1) drag = { last: Array.from(pts.values())[0] }; };
    const wheel = (e) => { e.preventDefault(); viewport.wheel(e, 'scroll', local(e)); };
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => { const r = root.getBoundingClientRect(); viewport.setViewportSize(r.width, r.height); }) : null;
    if (ro) ro.observe(root);
    root.addEventListener('pointerdown', down);
    root.addEventListener('pointermove', move);
    root.addEventListener('pointerup', up);
    root.addEventListener('pointercancel', up);
    root.addEventListener('wheel', wheel, { passive: false });

    return {
        get index() { return index; },
        get count() { return pages.length; },
        setPage(i) { if (i < 0 || i >= pages.length || i === index) return false; index = i; viewport.fit(); show(); return true; },
        next() { return this.setPage(index + 1); },
        prev() { return this.setPage(index - 1); },
        fit: () => viewport.fit(),
        actual: () => viewport.actual(),
        zoomIn: () => viewport.zoomStep(1),
        zoomOut: () => viewport.zoomStep(-1),
        get zoom() { return viewport.zoom; },
        on(e, fn) { if (!listeners.has(e)) listeners.set(e, new Set()); listeners.get(e).add(fn); return () => listeners.get(e).delete(fn); },
        renderPageToSvg(i, opts = {}) { return pages[i] ? renderPageToSvgString(pages[i], { forExport: true, ...opts, measure, icons, assetUrl, assetSize, fonts }) : ''; },
        destroy() {
            root.removeEventListener('pointerdown', down);
            root.removeEventListener('pointermove', move);
            root.removeEventListener('pointerup', up);
            root.removeEventListener('pointercancel', up);
            root.removeEventListener('wheel', wheel);
            if (ro) ro.disconnect();
            renderer.destroy();
            stage.remove();
            listeners.clear();
        },
    };
}

/*
 * Viewport maths and state. A view is {zoom, x, y}: the page's top-left sits at
 * screen point (x, y) (relative to the viewport element) and one page unit is
 * `zoom` screen pixels. Pure helpers first, then a small stateful wrapper.
 */
export const ZOOM_MIN = 0.05;
export const ZOOM_MAX = 8;

export function clampZoom(z) {
    if (typeof z !== 'number' || !Number.isFinite(z)) return 1;
    return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, z));
}

export function fitZoom(vw, vh, pw, ph, padding = 48) {
    const z = Math.min(Math.max(1, vw - 2 * padding) / pw, Math.max(1, vh - 2 * padding) / ph);
    return clampZoom(z);
}

export function fillZoom(vw, vh, pw, ph) {
    return clampZoom(Math.max(vw / pw, vh / ph));
}

/** Zoom to `zoom` keeping the page point under screen point (sx, sy) fixed. */
export function zoomAt(view, zoom, sx, sy) {
    const z = clampZoom(zoom);
    const px = (sx - view.x) / view.zoom;
    const py = (sy - view.y) / view.zoom;
    return { zoom: z, x: sx - px * z, y: sy - py * z };
}

/**
 * Keep the page reachable: a page smaller than the viewport is centred on that
 * axis, a larger one may be panned until `margin` pixels of it remain visible.
 */
export function constrain(view, vw, vh, pw, ph, margin = 80) {
    const w = pw * view.zoom;
    const h = ph * view.zoom;
    let x = view.x;
    let y = view.y;
    if (w <= vw - 2 * margin) x = (vw - w) / 2;
    else x = Math.min(vw - margin, Math.max(margin - w, x));
    if (h <= vh - 2 * margin) y = (vh - h) / 2;
    else y = Math.min(vh - margin, Math.max(margin - h, y));
    return { zoom: view.zoom, x, y };
}

export function centeredView(vw, vh, pw, ph, zoom) {
    return { zoom, x: (vw - pw * zoom) / 2, y: (vh - ph * zoom) / 2 };
}

/** Two-finger gesture: scale by the distance ratio, translate by the midpoint move. */
export function pinchView(start, startMid, startDist, mid, dist) {
    const z = clampZoom(start.zoom * (startDist > 0 ? dist / startDist : 1));
    const px = (startMid.x - start.x) / start.zoom;
    const py = (startMid.y - start.y) / start.zoom;
    return { zoom: z, x: mid.x - px * z, y: mid.y - py * z };
}

/** Zoom factor for a wheel event: notched mouse wheels step 10%, trackpad pinch is continuous. */
export function wheelFactor(e) {
    const dy = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
    const notched = !e.ctrlKey && Math.abs(dy) >= 50 ? true : e.ctrlKey && Math.abs(dy) >= 50 && Number.isInteger(dy);
    if (notched) return dy < 0 ? 1.1 : 1 / 1.1;
    return Math.exp(-Math.max(-60, Math.min(60, dy)) * 0.01);
}

export function createViewport(options = {}) {
    let vw = options.width || 800;
    let vh = options.height || 600;
    let pw = options.pageWidth || 1080;
    let ph = options.pageHeight || 1080;
    let view = centeredView(vw, vh, pw, ph, 1);
    let auto = true; // follow fit until the user zooms or pans
    const padding = options.padding ?? 48;
    const listeners = new Set();

    function emit() {
        for (const fn of Array.from(listeners)) fn(view);
    }

    function set(next, manual) {
        const c = constrain(next, vw, vh, pw, ph);
        if (manual) auto = false;
        if (c.zoom === view.zoom && c.x === view.x && c.y === view.y) return view;
        view = c;
        emit();
        return view;
    }

    const api = {
        get view() { return view; },
        get zoom() { return view.zoom; },
        get auto() { return auto; },
        onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
        setViewportSize(w, h) {
            if (w === vw && h === vh) return;
            vw = Math.max(1, w); vh = Math.max(1, h);
            if (auto) api.fit(); else set(view);
        },
        setPageSize(w, h) {
            pw = w; ph = h;
            if (auto) api.fit(); else set(view);
        },
        fit() {
            const z = fitZoom(vw, vh, pw, ph, padding);
            auto = true;
            return set(centeredView(vw, vh, pw, ph, z), false);
        },
        fill() { return set(centeredView(vw, vh, pw, ph, fillZoom(vw, vh, pw, ph)), true); },
        actual() { return set(centeredView(vw, vh, pw, ph, 1), true); },
        setZoom(z, anchor) {
            const a = anchor || { x: vw / 2, y: vh / 2 };
            return set(zoomAt(view, z, a.x, a.y), true);
        },
        zoomBy(factor, anchor) { return api.setZoom(view.zoom * factor, anchor); },
        zoomStep(dir, anchor) { return api.zoomBy(dir > 0 ? 1.25 : 0.8, anchor); },
        panBy(dx, dy) { return set({ zoom: view.zoom, x: view.x + dx, y: view.y + dy }, true); },
        setView(v) { return set(v, true); },
        /** Handle a wheel event: returns true when it was consumed. */
        wheel(e, mode = 'scroll', anchor) {
            const zoomIt = e.ctrlKey || e.metaKey || mode === 'zoom';
            if (zoomIt) { api.zoomBy(wheelFactor(e), anchor); return true; }
            const m = e.deltaMode === 1 ? 16 : 1;
            const dx = e.shiftKey && !e.deltaX ? -e.deltaY * m : -e.deltaX * m;
            const dy = e.shiftKey && !e.deltaX ? 0 : -e.deltaY * m;
            api.panBy(dx, dy);
            return true;
        },
        screenToPage(sx, sy) { return { x: (sx - view.x) / view.zoom, y: (sy - view.y) / view.zoom }; },
        pageToScreen(px, py) { return { x: view.x + px * view.zoom, y: view.y + py * view.zoom }; },
        size() { return { width: vw, height: vh, pageWidth: pw, pageHeight: ph }; },
    };
    return api;
}

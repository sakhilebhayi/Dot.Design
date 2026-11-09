/*
 * Raster export (PNG, JPG) and the shared canvas helpers (PDF pages, GIF/WebM frames).
 * Everything is injected through `deps` so the whole path is testable in node with stubs:
 *   deps.studio                      engine (getState, renderPageToSvgAsync)
 *   deps.loadImage(url)              -> {bytes}
 *   deps.createCanvas(w, h)          -> canvas ({width, height, getContext})
 *   deps.loadSvgImage(svg, w, h)     -> Promise<drawable>
 *   deps.canvasToBlob(canvas, mime, quality) -> Promise<Blob-like {size, type, arrayBuffer()}>
 *   deps.limits()                    -> {maxSide, maxArea}
 *
 * Honest rules: fonts and pictures are awaited BEFORE drawing; a canvas that is too large for the browser is
 * detected and the export is scaled down with a message (never a blank file); a blank result is detected and
 * retried smaller, then reported.
 */
import { ExportError, prepareSvg, standalone } from './export-svg.js';

export const MAX_SCALE = 8;
export const MIN_JPG_QUALITY = 0.4;

/** spec: {mode:'scale', scale} | {mode:'width', width} | {mode:'dpi', dpi} (page units are px at 72 dpi). */
export function resolveTarget(page, spec) {
    let scale = 1;
    if (spec.mode === 'width') scale = spec.width / page.width;
    else if (spec.mode === 'dpi') scale = spec.dpi / 72;
    else scale = spec.scale ?? 1;
    if (!(scale > 0) || !Number.isFinite(scale)) throw new ExportError('bad-size', 'That size is not valid.', 'Pick a size from the list or type a width in pixels.');
    const width = Math.max(1, Math.round(page.width * scale));
    const height = spec.mode === 'width' ? Math.max(1, Math.round((spec.width * page.height) / page.width)) : Math.max(1, Math.round(page.height * scale));
    return { width, height, scale: width / page.width };
}

/** Shrinks (keeping the aspect ratio) until the size fits the limits. Returns {width, height, reduced, from}. */
export function fitToLimits(width, height, limits) {
    const maxSide = limits.maxSide || 16384;
    const maxArea = limits.maxArea || 100000000;
    let k = 1;
    if (width > maxSide || height > maxSide) k = Math.min(k, maxSide / Math.max(width, height));
    if (width * height * k * k > maxArea) k = Math.min(k, Math.sqrt(maxArea / (width * height)));
    if (k >= 1) return { width, height, reduced: false, from: null };
    return { width: Math.max(1, Math.floor(width * k)), height: Math.max(1, Math.floor(height * k)), reduced: true, from: { width, height } };
}

export function reductionMessage(from, to) {
    return `This browser cannot make an image of ${from.width} by ${from.height} pixels, so it was made ${to.width} by ${to.height} instead. Use a smaller size for the full resolution, or export from a desktop browser.`;
}

/** True when a pixel drawn in the last corner reads back (a dead or failed canvas answers zeros or throws). */
export function probeCanvas(canvas) {
    try {
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return false;
        ctx.fillStyle = '#ff00ff';
        ctx.fillRect(canvas.width - 1, canvas.height - 1, 1, 1);
        const px = ctx.getImageData(canvas.width - 1, canvas.height - 1, 1, 1).data;
        const ok = px[0] === 255 && px[1] === 0 && px[2] === 255 && px[3] === 255;
        ctx.clearRect(canvas.width - 1, canvas.height - 1, 1, 1);
        return ok;
    } catch (e) {
        return false;
    }
}

/**
 * drawSvgToCanvas(deps, svg, {width, height, flatten}) -> {canvas, width, height, reducedFrom}
 * Retries at 75% and 50% when the canvas turns out unusable.
 */
export async function drawSvgToCanvas(deps, svg, { width, height, flatten = false, signal } = {}) {
    const limits = deps.limits();
    const fit = fitToLimits(width, height, limits);
    let w = fit.width;
    let h = fit.height;
    let reducedFrom = fit.reduced ? fit.from : null;
    for (let attempt = 0; attempt < 3; attempt += 1) {
        if (signal && signal.aborted) throw new ExportError('cancelled', 'Cancelled.');
        const canvas = deps.createCanvas(w, h);
        if (probeCanvas(canvas)) {
            let image;
            try {
                image = await deps.loadSvgImage(standalone(svg, { width: w, height: h, declaration: false }), w, h);
            } catch (e) {
                throw new ExportError('svg-load', 'The browser could not draw this page as a picture.', 'Try again. If it keeps happening, remove very large pictures or effects from the page.');
            }
            const ctx = canvas.getContext('2d');
            if (flatten) { ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h); }
            ctx.drawImage(image, 0, 0, w, h);
            return { canvas, width: w, height: h, reducedFrom: reducedFrom || (w !== width || h !== height ? { width, height } : null) };
        }
        canvas.width = 0; canvas.height = 0;
        w = Math.max(1, Math.floor(w * 0.75));
        h = Math.max(1, Math.floor(h * 0.75));
        reducedFrom = reducedFrom || { width, height };
    }
    throw new ExportError('canvas', 'This browser could not make an image that large.', 'Choose a smaller size (for example 1x) and try again.', { width, height });
}

export function disposeCanvas(canvas) {
    try { canvas.width = 0; canvas.height = 0; } catch (e) { /* nothing to free */ }
}

/**
 * exportPageRaster(deps, {pageIndex, format: 'png'|'jpg', target, quality, transparent, signal, onStage})
 *   -> {blob, bytes(), width, height, scale, mime, reducedFrom, message?}
 */
export async function exportPageRaster(deps, { pageIndex, format = 'png', target, quality = 0.92, transparent = false, signal, onStage } = {}) {
    const stage = (s) => { if (onStage) onStage(s); };
    const isJpg = format === 'jpg' || format === 'jpeg';
    const page = deps.studio.getState().doc.pages[pageIndex];
    if (!page) throw new ExportError('no-page', 'That page does not exist any more.', 'Close the dialog and choose a page again.');
    const size = resolveTarget(page, target);
    stage('Getting fonts and pictures ready');
    // fonts are awaited inside renderPageToSvgAsync, pictures inside prepareSvg: nothing is drawn before both are in
    const prepared = await prepareSvg(deps, { pageIndex, embedFonts: true, transparent: transparent && !isJpg, inline: true, signal });
    stage('Drawing');
    const drawn = await drawSvgToCanvas(deps, prepared.svg, { width: size.width, height: size.height, flatten: isJpg, signal });
    stage('Saving the file');
    const mime = isJpg ? 'image/jpeg' : 'image/png';
    let blob;
    try {
        blob = await deps.canvasToBlob(drawn.canvas, mime, isJpg ? Math.min(1, Math.max(MIN_JPG_QUALITY, quality)) : undefined);
    } catch (e) {
        blob = null;
    }
    disposeCanvas(drawn.canvas);
    if (!blob || !blob.size) throw new ExportError('encode', 'The picture could not be saved.', 'Choose a smaller size and try again.');
    return {
        blob,
        bytes: async () => new Uint8Array(await blob.arrayBuffer()),
        width: drawn.width,
        height: drawn.height,
        scale: drawn.width / page.width,
        mime,
        reducedFrom: drawn.reducedFrom,
        message: drawn.reducedFrom ? reductionMessage(drawn.reducedFrom, { width: drawn.width, height: drawn.height }) : '',
    };
}

/** Real PNG/JPEG header sizes for verification (also used by the audit of what we produced). */
export function imageSizeOf(bytes) {
    if (bytes[0] === 0x89 && bytes[1] === 0x50) {
        const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        return { type: 'png', width: dv.getUint32(16), height: dv.getUint32(20) };
    }
    if (bytes[0] === 0xff && bytes[1] === 0xd8) {
        let i = 2;
        while (i < bytes.length) {
            if (bytes[i] !== 0xff) { i += 1; continue; }
            const marker = bytes[i + 1];
            if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
                return { type: 'jpeg', height: (bytes[i + 5] << 8) | bytes[i + 6], width: (bytes[i + 7] << 8) | bytes[i + 8] };
            }
            i += 2 + ((bytes[i + 2] << 8) | bytes[i + 3]);
        }
    }
    return null;
}

// ---- browser deps ---------------------------------------------------------------------

let limitCache = null;

export function detectCanvasLimits(createCanvas, ua = '') {
    if (limitCache) return limitCache;
    let maxSide = 4096;
    for (const side of [32767, 16384, 8192, 4096]) {
        try {
            const c = createCanvas(side, 1);
            if (probeCanvas(c)) { maxSide = side; disposeCanvas(c); break; }
            disposeCanvas(c);
        } catch (e) { /* try the next size */ }
    }
    let maxArea = 100000000;
    try {
        const c = createCanvas(4096, 4096);
        const ok = probeCanvas(c);
        disposeCanvas(c);
        if (!ok) maxArea = 4096 * 2048;
        else if (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && typeof navigator !== 'undefined' && navigator.maxTouchPoints > 1)) maxArea = 16777216;
    } catch (e) { maxArea = 4096 * 2048; }
    limitCache = { maxSide, maxArea };
    return limitCache;
}

export function resetLimitCache() { limitCache = null; }

/** Real browser dependencies. `fetchImpl` defaults to fetch with same-origin credentials. */
export function browserDeps(studio, { fetchImpl } = {}) {
    const doc = studio.root ? studio.root.ownerDocument : document;
    const createCanvas = (w, h) => { const c = doc.createElement('canvas'); c.width = w; c.height = h; return c; };
    const doFetch = fetchImpl || ((url) => fetch(url, { credentials: 'same-origin' }));
    return {
        studio,
        createCanvas,
        limits: () => detectCanvasLimits(createCanvas, typeof navigator !== 'undefined' ? navigator.userAgent : ''),
        async loadImage(url) {
            const res = await doFetch(url);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return { bytes: new Uint8Array(await res.arrayBuffer()) };
        },
        async loadSvgImage(svg) {
            const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
            try {
                const img = new Image();
                img.decoding = 'sync';
                const loaded = new Promise((resolve, reject) => { img.onload = () => resolve(); img.onerror = () => reject(new Error('image failed')); });
                img.src = url;
                await loaded;
                if (img.decode) { try { await img.decode(); } catch (e) { /* load already succeeded */ } }
                return img;
            } finally {
                setTimeout(() => URL.revokeObjectURL(url), 0);
            }
        },
        canvasToBlob(canvas, mime, quality) {
            return new Promise((resolve) => { try { canvas.toBlob((b) => resolve(b), mime, quality); } catch (e) { resolve(null); } });
        },
    };
}

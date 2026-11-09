/*
 * Design thumbnails. After the server confirms a save, wait 5 seconds of quiet,
 * draw page 1 to a small PNG (300 KB at most) and send it. Failure is silent for
 * the person (the chip and the designs list never depend on it) and only logged
 * to the console in development.
 */
export const MAX_BYTES = 300 * 1024;
export const THUMB_EDGE = 480;

/** The scheduler is pure so it can be tested with fake timers: render() -> Blob|null, upload(blob) -> Promise<boolean>. */
export function createThumbnailScheduler({ render, upload, signature, delay = 5000, timers = { set: (fn, ms) => setTimeout(fn, ms), clear: (t) => clearTimeout(t) }, log = () => {} }) {
    let handle = null; let busy = false; let again = false; let lastSig = null; let destroyed = false;
    async function run() {
        handle = null;
        if (destroyed) return;
        if (busy) { again = true; return; }
        busy = true;
        try {
            const sig = signature();
            if (sig === lastSig) return;
            const blob = await render();
            if (!blob) return;
            if (blob.size > MAX_BYTES) { log('thumbnail too large', blob.size); return; }
            const ok = await upload(blob);
            if (ok) lastSig = sig;
        } catch (e) { log('thumbnail failed', e); } finally {
            busy = false;
            if (again) { again = false; schedule(); }
        }
    }
    function schedule() { if (destroyed) return; if (handle !== null) timers.clear(handle); handle = timers.set(run, delay); }
    return { schedule, cancel() { if (handle !== null) timers.clear(handle); handle = null; }, destroy() { destroyed = true; if (handle !== null) timers.clear(handle); }, get pending() { return handle !== null; } };
}

/** Fetch each picture an SVG references and inline it, because an SVG drawn as an image cannot load anything itself. */
export async function inlineImages(svg, fetchImpl = fetch) {
    const urls = Array.from(new Set(Array.from(svg.matchAll(/<image\b[^>]*?\bhref="([^"]+)"/g)).map((m) => m[1]).filter((u) => !u.startsWith('data:')))).slice(0, 12);
    let out = svg;
    for (const u of urls) {
        try {
            const res = await fetchImpl(u, { credentials: 'same-origin' });
            if (!res.ok) continue;
            const blob = await res.blob();
            const data = await new Promise((resolve, reject) => { const fr = new FileReader(); fr.onload = () => resolve(fr.result); fr.onerror = reject; fr.readAsDataURL(blob); });
            out = out.split(`href="${u}"`).join(`href="${data}"`);
        } catch (e) { /* the thumbnail simply lacks that picture */ }
    }
    return out;
}

function loadImage(src) { return new Promise((resolve, reject) => { const img = new Image(); img.onload = () => resolve(img); img.onerror = reject; img.src = src; }); }
const toBlob = (canvas, type, q) => new Promise((resolve) => canvas.toBlob(resolve, type, q));

/** Render page 1 to a PNG no bigger than 300 KB (smaller canvas, then WebP, until it fits). */
export async function renderThumbnail(studio) {
    let svg = await studio.renderPageToSvgAsync(0, { embedFonts: true, idPrefix: 'th-' });
    if (!svg) return null;
    svg = await inlineImages(svg);
    const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    try {
        const img = await loadImage(url);
        const page = studio.getState().doc.pages[0];
        for (const edge of [THUMB_EDGE, 360, 240]) {
            const k = edge / Math.max(page.width, page.height);
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(page.width * k)); canvas.height = Math.max(1, Math.round(page.height * k));
            const g = canvas.getContext('2d');
            g.fillStyle = '#ffffff'; g.fillRect(0, 0, canvas.width, canvas.height);
            g.drawImage(img, 0, 0, canvas.width, canvas.height);
            const png = await toBlob(canvas, 'image/png');
            if (png && png.size <= MAX_BYTES) return png;
            const webp = await toBlob(canvas, 'image/webp', 0.8);
            if (webp && webp.size <= MAX_BYTES && webp.type === 'image/webp') return webp;
        }
        return null;
    } finally { URL.revokeObjectURL(url); }
}

export function installThumbnails(ctx) {
    if (ctx.readOnly) return null;
    const studio = ctx.studio;
    const dev = typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.DEV;
    const sched = createThumbnailScheduler({
        signature: () => { const p = studio.getState().doc.pages[0]; return JSON.stringify([p.width, p.height, p.background, p.elements]); },
        render: () => renderThumbnail(studio),
        upload: async (blob) => { const r = await ctx.api.put(ctx.url('design.projects.thumbnail.update'), blob, { headers: { 'Content-Type': blob.type } }); return r.ok; },
        log: (...a) => { if (dev) console.debug('[studio]', ...a); },
    });
    studio.on('save-state', ({ state }) => { if (state === 'saved') sched.schedule(); });
    return sched;
}

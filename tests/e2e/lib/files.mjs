// Independent readers for exported files: nothing here uses the code that wrote them.
import zlib from 'node:zlib';
import { decodePng } from './png.mjs';
import { browser } from './harness.mjs';

export async function readStream(download) {
    const { readFile } = await import('node:fs/promises');
    const path = await download.path();
    return readFile(path);
}

export function pngInfo(buf) { const d = decodePng(buf); return d; }

export function jpegSize(buf) {
    if (buf[0] !== 0xff || buf[1] !== 0xd8) throw new Error('not a JPEG');
    let p = 2;
    while (p < buf.length) {
        if (buf[p] !== 0xff) { p++; continue; }
        const m = buf[p + 1];
        if (m >= 0xc0 && m <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(m)) return { height: buf.readUInt16BE(p + 5), width: buf.readUInt16BE(p + 7) };
        p += 2 + buf.readUInt16BE(p + 2);
    }
    throw new Error('no SOF marker');
}

/** Decodes any browser-readable image in a scratch page and returns a sampler, size and a data-URL-free pixel getter. */
export async function decodeInBrowser(buf, mime, points) {
    const b = await browser();
    const ctx = await b.newContext();
    const page = await ctx.newPage();
    try {
        await page.goto('about:blank');
        return await page.evaluate(async ({ b64, mime, points }) => {
            const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
            const bmp = await createImageBitmap(new Blob([bin], { type: mime }));
            const cv = new OffscreenCanvas(bmp.width, bmp.height); const g = cv.getContext('2d'); g.drawImage(bmp, 0, 0);
            return { width: bmp.width, height: bmp.height, samples: (points || []).map(([x, y]) => Array.from(g.getImageData(Math.min(bmp.width - 1, Math.max(0, Math.round(x))), Math.min(bmp.height - 1, Math.max(0, Math.round(y))), 1, 1).data)) };
        }, { b64: buf.toString('base64'), mime, points });
    } finally { await ctx.close(); }
}

/** PDF: page count, MediaBoxes, and the embedded JPEG streams (the writer draws one JPEG per page). */
export function pdfInfo(buf) {
    const text = buf.toString('latin1');
    if (!text.startsWith('%PDF-')) throw new Error('not a PDF');
    const pages = [...text.matchAll(/\/Type\s*\/Page(?!s)\b[^]*?\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/g)].map((m) => ({ w: +m[3] - +m[1], h: +m[4] - +m[2] }));
    const count = (text.match(/\/Type\s*\/Page(?!s)\b/g) || []).length;
    const pagesObj = text.match(/\/Type\s*\/Pages[^]*?\/Count\s+(\d+)/);
    const jpegs = [];
    let i = 0;
    while ((i = buf.indexOf(Buffer.from([0xff, 0xd8, 0xff]), i)) !== -1) { const e = buf.indexOf(Buffer.from([0xff, 0xd9]), i); if (e === -1) break; jpegs.push(buf.subarray(i, e + 2)); i = e + 2; }
    return { count, declared: pagesObj ? +pagesObj[1] : null, mediaBoxes: pages, jpegs, startsOk: true, eof: text.trimEnd().endsWith('%%EOF') };
}

/** GIF: header, logical screen size, frame count (image descriptors), loop extension. */
export function gifInfo(buf) {
    const head = buf.toString('ascii', 0, 6);
    if (head !== 'GIF89a' && head !== 'GIF87a') throw new Error('not a GIF');
    const width = buf.readUInt16LE(6), height = buf.readUInt16LE(8);
    let p = 13; const flags = buf[10];
    if (flags & 0x80) p += 3 * (1 << ((flags & 7) + 1));
    let frames = 0, loop = false; const delays = [];
    while (p < buf.length) {
        const b = buf[p];
        if (b === 0x3b) break;
        if (b === 0x21) {
            const label = buf[p + 1];
            if (label === 0xf9) delays.push(buf.readUInt16LE(p + 4));
            if (label === 0xff && buf.toString('ascii', p + 3, p + 14).startsWith('NETSCAPE')) loop = true;
            p += 2; while (buf[p] !== 0) p += buf[p] + 1; p++;
        } else if (b === 0x2c) {
            frames++;
            const lf = buf[p + 9]; p += 10;
            if (lf & 0x80) p += 3 * (1 << ((lf & 7) + 1));
            p++; while (buf[p] !== 0) p += buf[p] + 1; p++;
        } else throw new Error(`bad GIF block 0x${b.toString(16)} at ${p}`);
    }
    return { width, height, frames, loop, delays };
}

/** WebM: EBML header and at least one Cluster; duration and size read back by a <video> element in a scratch page. */
export async function webmInfo(buf) {
    const ebml = buf.readUInt32BE(0) === 0x1a45dfa3;
    const hasCluster = buf.indexOf(Buffer.from([0x1f, 0x43, 0xb6, 0x75])) !== -1;
    const b = await browser();
    const ctx = await b.newContext(); const page = await ctx.newPage();
    try {
        await page.goto('about:blank');
        const meta = await page.evaluate(async (b64) => {
            const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
            const v = document.createElement('video'); v.muted = true; v.src = URL.createObjectURL(new Blob([bin], { type: 'video/webm' }));
            await new Promise((res, rej) => { v.onloadedmetadata = res; v.onerror = () => rej(new Error('video error')); setTimeout(() => rej(new Error('timeout')), 8000); });
            let d = v.duration;
            if (!isFinite(d)) { v.currentTime = 1e9; await new Promise((r) => { v.onseeked = r; v.ontimeupdate = r; setTimeout(r, 3000); }); d = v.duration; }
            return { width: v.videoWidth, height: v.videoHeight, duration: d };
        }, buf.toString('base64'));
        return { ebml, hasCluster, ...meta };
    } finally { await ctx.close(); }
}

/** SVG text -> {root attrs, parsed ok, size once rendered by Chrome as an image}. */
export async function svgInfo(text) {
    const b = await browser();
    const ctx = await b.newContext(); const page = await ctx.newPage();
    try {
        await page.goto('about:blank');
        return await page.evaluate(async (svg) => {
            const doc = new DOMParser().parseFromString(svg, 'image/svg+xml');
            const err = doc.querySelector('parsererror');
            const root = doc.documentElement;
            const img = new Image();
            const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
            const loaded = await new Promise((res) => { img.onload = () => res(true); img.onerror = () => res(false); img.src = url; });
            const external = [...doc.querySelectorAll('[href],[src]')].map((e) => e.getAttribute('href') || e.getAttribute('src')).filter((h) => h && !h.startsWith('data:') && !h.startsWith('#'));
            return { parsed: !err, rootName: root.localName, width: root.getAttribute('width'), height: root.getAttribute('height'), viewBox: root.getAttribute('viewBox'), rendered: loaded, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight, external, textNodes: doc.querySelectorAll('text').length };
        }, text);
    } finally { await ctx.close(); }
}

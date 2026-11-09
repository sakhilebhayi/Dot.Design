/*
 * SVG export and the SVG preparation every raster export shares.
 *
 * The editor's single renderer produces the page SVG (text stays <text>); this module makes it STANDALONE:
 *  - images referenced by URL are fetched and embedded as data URIs (an SVG drawn as a picture, or opened on
 *    another computer, cannot load them from the server),
 *  - fonts are embedded as data URIs by the engine (embedFonts), optional for the .svg file, always on for rasters,
 *  - the page background can be removed (transparent PNG),
 *  - the result is audited: no script, no event handlers, no foreignObject, no external reference of any kind.
 */
import { toBase64 } from './bytes.js';

export class ExportError extends Error {
    constructor(code, message, hint = '', details = {}) {
        super(message);
        this.name = 'ExportError';
        this.code = code;
        this.hint = hint;
        this.details = details;
    }
}

const IMAGE_HREF = /(<image\b[^>]*?\s)href="([^"]*)"/g;

export function imageRefs(svg) {
    const out = new Set();
    for (const m of svg.matchAll(IMAGE_HREF)) {
        const u = decodeEntities(m[2]);
        if (!u.startsWith('data:')) out.add(u);
    }
    return Array.from(out);
}

function decodeEntities(s) {
    return s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

/** Image type from the first bytes, never from a header the server sent. Returns null for anything else (SVG is refused). */
export function sniffImage(bytes) {
    const b = bytes;
    if (b.length > 8 && b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4e && b[3] === 0x47) return 'image/png';
    if (b.length > 3 && b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return 'image/jpeg';
    if (b.length > 6 && b[0] === 0x47 && b[1] === 0x49 && b[2] === 0x46 && b[3] === 0x38) return 'image/gif';
    if (b.length > 12 && b[0] === 0x52 && b[1] === 0x49 && b[2] === 0x46 && b[3] === 0x46 && b[8] === 0x57 && b[9] === 0x45 && b[10] === 0x42 && b[11] === 0x50) return 'image/webp';
    return null;
}

/**
 * inlineImages(svg, load, {onProgress}) -> Promise<string>
 * `load(url)` resolves {bytes: Uint8Array}. Every picture is awaited before the string is returned.
 */
export async function inlineImages(svg, load, { onProgress, signal } = {}) {
    const urls = imageRefs(svg);
    const map = new Map();
    let done = 0;
    await Promise.all(urls.map(async (url) => {
        let res;
        try {
            res = await load(url);
        } catch (e) {
            throw new ExportError('image-load', 'A picture on this page could not be loaded.', 'Check your connection and try again. If the picture was deleted, replace it first.', { url });
        }
        const mime = sniffImage(res.bytes);
        if (!mime) throw new ExportError('image-type', 'A picture on this page is not a type that can be exported.', 'Replace it with a PNG, JPG, WebP or GIF picture.', { url });
        map.set(url, `data:${mime};base64,${toBase64(res.bytes)}`);
        done += 1;
        if (onProgress) onProgress(done, urls.length);
        if (signal && signal.aborted) throw new ExportError('cancelled', 'Cancelled.');
    }));
    return svg.replace(IMAGE_HREF, (all, pre, href) => {
        const uri = map.get(decodeEntities(href));
        return uri ? `${pre}href="${uri}"` : all;
    });
}

/** Removes the page background (the data-bg rect and a background picture) so the file keeps real transparency. */
export function stripBackground(svg) {
    return svg
        .replace(/<rect\b[^>]*\sdata-bg="1"[^>]*\/>/, '')
        .replace(/<g clip-path="url\(#[^")]*bgclip\)">.*?<\/g>/, '');
}

/** Problems that would make the file not self-contained or unsafe. Empty array = fine. */
export function auditSvg(svg) {
    const issues = [];
    if (/<script\b/i.test(svg)) issues.push('contains a script');
    if (/<foreignObject\b/i.test(svg)) issues.push('contains foreignObject');
    if (/\son[a-z]+\s*=/i.test(svg)) issues.push('contains an event handler');
    if (/javascript:/i.test(svg)) issues.push('contains a javascript: address');
    if (/<!ENTITY|<!DOCTYPE/i.test(svg)) issues.push('contains a DTD');
    for (const m of svg.matchAll(/\s(?:xlink:)?href="([^"]*)"/g)) {
        if (!m[1].startsWith('data:image/') && !m[1].startsWith('#')) issues.push(`links to ${m[1].slice(0, 60)}`);
    }
    for (const m of svg.matchAll(/url\(\s*(["']?)([^)"']*)\1\s*\)/g)) {
        if (!m[2].startsWith('#') && !m[2].startsWith('data:')) issues.push(`references ${m[2].slice(0, 60)}`);
    }
    if (/@import/i.test(svg)) issues.push('imports a stylesheet');
    return issues;
}

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Adds a title and the XML declaration; sets the pixel size (viewBox keeps the drawing scale). */
export function standalone(svg, { title, width, height, declaration = true } = {}) {
    let out = svg;
    if (width && height) out = out.replace(/^<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/, `<svg$1 width="${Math.round(width)}" height="${Math.round(height)}"`);
    if (title) out = out.replace(/^(<svg[^>]*>)/, `$1<title>${esc(title)}</title>`);
    return (declaration ? '<?xml version="1.0" encoding="UTF-8"?>\n' : '') + out;
}

/**
 * prepareSvg(deps, {pageIndex, embedFonts, transparent, inline}) -> {svg, width, height}
 * deps.studio.renderPageToSvgAsync waits for the fonts the page uses. deps.loadImage(url) -> {bytes}.
 */
export async function prepareSvg(deps, { pageIndex, embedFonts = true, transparent = false, inline = true, onProgress, signal } = {}) {
    const { studio } = deps;
    const page = studio.getState().doc.pages[pageIndex];
    if (!page) throw new ExportError('no-page', 'That page does not exist any more.', 'Close the dialog and choose a page again.');
    let svg;
    try {
        svg = await studio.renderPageToSvgAsync(pageIndex, { embedFonts });
    } catch (e) {
        throw new ExportError('render', 'This page could not be drawn for export.', 'Try again. If it keeps happening, reload the editor (your work is saved).');
    }
    if (!svg) throw new ExportError('render', 'This page could not be drawn for export.', 'Try again.');
    if (signal && signal.aborted) throw new ExportError('cancelled', 'Cancelled.');
    if (transparent) svg = stripBackground(svg);
    if (inline) svg = await inlineImages(svg, deps.loadImage, { onProgress, signal });
    return { svg, width: page.width, height: page.height, page };
}

/** The finished .svg file: self-contained, titled, audited. Throws ExportError when the audit finds a problem. */
export async function exportSvg(deps, { pageIndex, embedFonts = true, transparent = false, title, signal } = {}) {
    const prepared = await prepareSvg(deps, { pageIndex, embedFonts, transparent, inline: true, signal });
    const text = standalone(prepared.svg, { title, width: prepared.width, height: prepared.height });
    const issues = auditSvg(text);
    if (issues.length) throw new ExportError('svg-audit', 'The SVG was not saved because it is not self-contained.', `It ${issues[0]}. Remove that element and try again.`, { issues });
    return { text, bytes: new TextEncoder().encode(text), width: prepared.width, height: prepared.height, mime: 'image/svg+xml' };
}

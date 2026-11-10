/*
 * File names for downloads: <design-name>-<page>-<w>x<h>.<ext>, safe on every file system.
 */

const RESERVED = /^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i;

export function slug(input, fallback = 'design', max = 48) {
    let s = String(input == null ? '' : input)
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^A-Za-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase();
    if (s.length > max) s = s.slice(0, max).replace(/-+$/g, '');
    if (!s || RESERVED.test(s)) s = fallback;
    return s;
}

/**
 * buildFilename({design, page, pageNumber, width, height, ext, suffix?})
 * page: the page's own name when it has one, otherwise "page-N".
 */
export function buildFilename({ design, page, pageNumber, width, height, ext, suffix }) {
    const parts = [slug(design, 'design')];
    parts.push(page ? slug(page, `page-${pageNumber || 1}`, 32) : `page-${pageNumber || 1}`);
    if (suffix) parts.push(slug(suffix, '', 16));
    const size = Number.isFinite(width) && Number.isFinite(height) ? `${Math.round(width)}x${Math.round(height)}` : '';
    const body = [...parts.filter(Boolean), size].filter(Boolean).join('-');
    return `${body}.${String(ext || 'bin').replace(/[^a-z0-9]/gi, '').toLowerCase() || 'bin'}`;
}

/** Whole-design files (PDF, ZIP, GIF of all pages): <design-name>[-<w>x<h>].<ext> */
export function buildDesignFilename({ design, width, height, ext, suffix }) {
    const parts = [slug(design, 'design')];
    if (suffix) parts.push(slug(suffix, '', 16));
    if (Number.isFinite(width) && Number.isFinite(height)) parts.push(`${Math.round(width)}x${Math.round(height)}`);
    return `${parts.filter(Boolean).join('-')}.${String(ext || 'bin').replace(/[^a-z0-9]/gi, '').toLowerCase() || 'bin'}`;
}

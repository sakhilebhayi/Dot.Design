/*
 * Print readiness (bleed and crop marks), pure and shared by every export path.
 *
 * Page units for print formats are points (1 unit = 1/72 in), so 3 mm of bleed is 8.504 units. A page with bleed and marks is
 * laid out like this (all distances from the trim edge, outwards):
 *
 *     trim edge ......... the finished size
 *     bleed ............. background and anything that runs off the page continues this far (0, 3 or 5 mm)
 *     gap (1 mm) ........ nothing is drawn, so the marks never touch the bleed
 *     mark (5 mm) ....... the crop mark lines
 *
 * PDF output also writes TrimBox and BleedBox so a print shop's software finds the trim without guessing.
 */
export const BLEED_CHOICES = [0, 3, 5];
export const MARK_GAP_MM = 1;
export const MARK_LEN_MM = 5;
export const COLOUR_NOTE = 'Exports are RGB. CMYK conversion is not provided: ask your print shop to convert, or check a printed proof.';

export const mmToUnits = (mm) => (mm * 72) / 25.4;

/** The distance from the trim edge to the edge of the file, in page units. */
export function padUnits({ bleedMm = 0, marks = false }) {
    const b = mmToUnits(bleedMm || 0);
    return marks ? b + mmToUnits(MARK_GAP_MM + MARK_LEN_MM) : b;
}

export function hasPrintBoxes(opts) { return !!opts && ((opts.bleedMm || 0) > 0 || !!opts.marks); }

const r3 = (n) => String(Math.round(n * 1000) / 1000);

/** The crop marks as an SVG fragment (black hairlines, outside the bleed). Coordinates are in page units around the trim. */
export function cropMarksSvg({ width, height, bleedMm = 0 }) {
    const gap = mmToUnits(bleedMm) + mmToUnits(MARK_GAP_MM);
    const len = mmToUnits(MARK_LEN_MM);
    const d = [];
    for (const x of [0, width]) { d.push(`M${r3(x)} ${r3(-gap)}V${r3(-gap - len)}`, `M${r3(x)} ${r3(height + gap)}V${r3(height + gap + len)}`); }
    for (const y of [0, height]) { d.push(`M${r3(-gap)} ${r3(y)}H${r3(-gap - len)}`, `M${r3(width + gap)} ${r3(y)}H${r3(width + gap + len)}`); }
    return `<path d="${d.join('')}" fill="none" stroke="#000000" stroke-width="0.25" data-crop-marks="1"/>`;
}

/**
 * Re-frames a page SVG (the string the renderer wrote) for print: a bigger viewBox, the background rectangle extended into the
 * bleed, everything clipped to the bleed box, and crop marks outside it. Returns the new SVG and its size in page units.
 */
export function applyPrintBoxes(svg, { width, height, bleedMm = 0, marks = false }) {
    const bleed = mmToUnits(bleedMm);
    const pad = padUnits({ bleedMm, marks });
    const W = width + 2 * pad;
    const H = height + 2 * pad;
    let out = svg.replace(/^<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"\s+viewBox="[^"]*"/, (all, pre) => `<svg${pre} width="${r3(W)}" height="${r3(H)}" viewBox="${r3(-pad)} ${r3(-pad)} ${r3(W)} ${r3(H)}"`);
    if (out === svg) return { svg, width: W, height: H, pad, changed: false };
    if (bleed > 0) {
        out = out.replace(/<rect\b([^>]*?)\sdata-bg="1"([^>]*)\/>/, (all, a, b) => {
            const attrs = `${a}${b}`.replace(/\swidth="[^"]*"/, '').replace(/\sheight="[^"]*"/, '');
            return `<rect x="${r3(-bleed)}" y="${r3(-bleed)}" width="${r3(width + 2 * bleed)}" height="${r3(height + 2 * bleed)}"${attrs} data-bg="1"/>`;
        });
    }
    const clip = `<clipPath id="dd-bleedclip"><rect x="${r3(-bleed)}" y="${r3(-bleed)}" width="${r3(width + 2 * bleed)}" height="${r3(height + 2 * bleed)}"/></clipPath>`;
    out = out.replace(/^(<svg[^>]*>)/, `$1<defs>${clip}</defs><g clip-path="url(#dd-bleedclip)">`);
    out = out.replace(/<\/svg>$/, `</g>${marks ? cropMarksSvg({ width, height, bleedMm }) : ''}</svg>`);
    return { svg: out, width: W, height: H, pad, changed: true };
}

/**
 * Top-level elements that end exactly on a page edge (within half a unit). With a bleed they stop at the trim line, so the
 * person is told to extend them past the edge. Rotated elements are skipped (their box is not axis aligned).
 */
export function elementsOnEdge(page) {
    const out = [];
    const eps = 0.5;
    for (const e of page.elements || []) {
        if (e.hidden || e.rotation) continue;
        const l = e.x; const t = e.y; const r = e.x + e.width; const b = e.y + e.height;
        const onEdge = [Math.abs(l) <= eps, Math.abs(t) <= eps, Math.abs(r - page.width) <= eps, Math.abs(b - page.height) <= eps].some(Boolean);
        const inside = l >= -eps && t >= -eps && r <= page.width + eps && b <= page.height + eps;
        const big = (r - l) * (b - t) >= page.width * page.height * 0.02;
        if (onEdge && inside && big) out.push(e);
    }
    return out;
}

export function edgeMessage(page, bleedMm) {
    if (!(bleedMm > 0)) return '';
    const hits = elementsOnEdge(page);
    if (!hits.length) return '';
    return `${hits.length === 1 ? 'One element ends' : `${hits.length} elements end`} exactly at the page edge, so ${hits.length === 1 ? 'it does' : 'they do'} not continue into the ${bleedMm} mm bleed. Drag ${hits.length === 1 ? 'it' : 'them'} ${bleedMm} mm past the edge before printing.`;
}

// Size units for the custom canvas. The editor works in px at 72 dpi (px = mm / 25.4 * 72); print export multiplies later.
export const UNITS = ['px', 'mm', 'cm', 'in'];
export const DPI = 72;
const MM_PER_IN = 25.4;
const MM_PER = { mm: 1, cm: 10, in: MM_PER_IN };

export function toMm(value, unit, dpi = DPI) {
    if (unit === 'px') return (value / dpi) * MM_PER_IN;
    if (!(unit in MM_PER)) throw new Error(`Unknown unit ${unit}`);
    return value * MM_PER[unit];
}

export function toPx(value, unit, dpi = DPI) {
    if (unit === 'px') return value;
    return (toMm(value, unit, dpi) / MM_PER_IN) * dpi;
}

export function fromPx(px, unit, dpi = DPI) {
    if (unit === 'px') return px;
    return (toMm(px, 'px', dpi) / MM_PER[unit]);
}

/** Reads what a person typed: "21,5" and "21.5" both mean 21.5; anything else is NaN. */
export function parseSize(text) {
    if (typeof text === 'number') return Number.isFinite(text) ? text : NaN;
    const s = String(text ?? '').trim().replace(',', '.');
    if (!/^\d*\.?\d+$|^\d+\.$/.test(s)) return NaN;
    return Number(s);
}

/** Shortest sensible rendering of a size: 21, 21.5, 29.7, never 29.700000001. */
export function formatNumber(n) {
    if (!Number.isFinite(n)) return '';
    const r = Math.round(n * 100) / 100;
    return String(r);
}

const PAPERS = [
    ['A0', 841, 1189], ['A1', 594, 841], ['A2', 420, 594], ['A3', 297, 420], ['A4', 210, 297],
    ['A5', 148, 210], ['A6', 105, 148], ['DL', 99, 210],
];

/** "A4" when the size is within a millimetre of a standard paper size (either way up), else null. */
export function paperName(widthPx, heightPx, dpi = DPI) {
    const w = toMm(widthPx, 'px', dpi);
    const h = toMm(heightPx, 'px', dpi);
    for (const [name, a, b] of PAPERS) {
        if ((Math.abs(w - a) <= 1 && Math.abs(h - b) <= 1) || (Math.abs(w - b) <= 1 && Math.abs(h - a) <= 1)) return name;
    }
    return null;
}

/**
 * Checks a custom size and converts it. Returns {ok, px: {width, height}, errors: {width?, height?}, notes: [..]}.
 * Limits are in px (min_px, max_px); messages are given in the unit the person is using.
 */
export function validateSize({ width, height, unit }, limits = { min_px: 16, max_px: 10000 }, dpi = DPI) {
    const errors = {};
    const notes = [];
    const px = {};
    if (!UNITS.includes(unit)) return { ok: false, px: null, errors: { unit: 'Pick px, mm, cm or in.' }, notes };

    for (const [key, label, raw] of [['width', 'Width', width], ['height', 'Height', height]]) {
        const n = parseSize(raw);
        if (raw === '' || raw === null || raw === undefined) { errors[key] = `${label} is needed.`; continue; }
        if (!Number.isFinite(n)) { errors[key] = `${label} should be a number, for example 210.`; continue; }
        const p = toPx(n, unit, dpi);
        const rounded = Math.round(p);
        if (rounded < limits.min_px) {
            errors[key] = `${label} is too small. The smallest size is ${limits.min_px} px (${formatNumber(fromPx(limits.min_px, unit, dpi))} ${unit}).`;
        } else if (rounded > limits.max_px) {
            errors[key] = `${label} is too large. The largest size is ${limits.max_px.toLocaleString('en-ZA').replace(/ /g, ' ')} px (${formatNumber(fromPx(limits.max_px, unit, dpi))} ${unit}).`;
        } else {
            px[key] = rounded;
        }
    }

    const ok = Object.keys(errors).length === 0;
    if (ok) {
        const paper = paperName(px.width, px.height, dpi);
        if (paper) notes.push(`This is ${paper} paper.`);
        if (px.width * px.height > 25_000_000) notes.push('A very large canvas can be slow to edit.');
    }
    return { ok, px: ok ? px : null, errors, notes };
}

/** The default name for a custom canvas: "Custom 21 x 29.7 cm". */
export function customName(width, height, unit) {
    return `Custom ${formatNumber(parseSize(width))} x ${formatNumber(parseSize(height))} ${unit}`;
}

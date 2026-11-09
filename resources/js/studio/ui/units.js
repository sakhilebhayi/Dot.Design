/*
 * Unit-aware numbers for the Pro fields. The document is always in page units
 * (px at 72 dpi); a design made for print shows millimetres or inches in the
 * fields and converts both ways. Pure, unit tested.
 */
export const UNITS = ['px', 'mm', 'in'];
const PER_PX = { px: 1, mm: 25.4 / 72, in: 1 / 72 };
const DECIMALS = { px: 0, mm: 1, in: 2 };

export function pxTo(value, unit) { return value * (PER_PX[unit] || 1); }
export function toPx(value, unit) { return value / (PER_PX[unit] || 1); }

export function formatUnit(valuePx, unit) {
    const v = pxTo(valuePx, unit);
    const d = DECIMALS[unit] ?? 0;
    const s = v.toFixed(d);
    return d ? s.replace(/\.?0+$/, '') : s;
}

/** Parse what a person typed ("12", "12.5", "12mm", "1in", "10px", "50%" of a basis) into px. Returns null when it is not a number. */
export function parseLength(text, unit, basisPx) {
    const t = String(text).trim().toLowerCase().replace(',', '.');
    if (!t) return null;
    const m = /^(-?\d*\.?\d+)\s*(px|mm|in|cm|%)?$/.exec(t);
    if (!m) return null;
    const n = parseFloat(m[1]);
    if (!Number.isFinite(n)) return null;
    const u = m[2] || unit;
    if (u === '%') return basisPx ? (n / 100) * basisPx : null;
    if (u === 'cm') return toPx(n * 10, 'mm');
    return toPx(n, u);
}

/** The display unit for a project: print formats show their own unit, everything else px. */
export function displayUnit(project, formats) {
    const f = formats && project && project.format_key ? formats.find((x) => x.key === project.format_key) : null;
    return f && UNITS.includes(f.unit) ? f.unit : 'px';
}

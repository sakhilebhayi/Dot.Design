/*
 * Locale-free number text for the UI. The browser's own number input shows "5,0" in many locales and
 * Number.prototype.toLocaleString follows the reader's locale too. Every figure the editor shows (seconds,
 * millimetres, percentages) goes through these two functions instead, so a design always reads "5.0 s".
 *
 *   fmtFixed(5, 1)            -> "5.0"
 *   fmtSeconds(2500)          -> "2.5"
 *   parseDecimal("5,5")       -> 5.5   (a person may type a comma; both are accepted on the way in)
 */

/** Fixed decimals with a dot, never a comma, never a thousands separator. */
export function fmtFixed(value, decimals = 1) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '';
    const d = Math.max(0, Math.min(6, decimals | 0));
    const t = (Math.round(n * 10 ** d) / 10 ** d).toFixed(d);
    return t === '-0.0' || /^-0(\.0+)?$/.test(t) ? t.slice(1) : t;
}

/** Milliseconds as seconds text with one decimal: 2500 -> "2.5". */
export const fmtSeconds = (ms, decimals = 1) => fmtFixed(ms / 1000, decimals);

/** Reads what a person typed: "5", "5.0", "5,0", " 1 200,5 " (spaces ignored). Returns NaN when it is not a number. */
export function parseDecimal(text) {
    if (typeof text === 'number') return text;
    let s = String(text == null ? '' : text).trim().replace(/[\s  ]/g, '');
    if (s === '') return NaN;
    const lastComma = s.lastIndexOf(',');
    const lastDot = s.lastIndexOf('.');
    if (lastComma > -1 && lastDot > -1) {
        // both present: the later one is the decimal mark, the other is a thousands separator
        s = lastComma > lastDot ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
    } else if (lastComma > -1) s = s.replace(',', '.');
    if (!/^[+-]?(\d+\.?\d*|\.\d+)$/.test(s)) return NaN;
    return Number(s);
}

/** Clamp then snap to a step (step 0.5 from min). */
export function snapTo(value, { min = -Infinity, max = Infinity, step = 0 } = {}) {
    let v = Math.min(max, Math.max(min, value));
    if (step > 0 && Number.isFinite(min)) v = min + Math.round((v - min) / step) * step;
    else if (step > 0) v = Math.round(v / step) * step;
    return Math.min(max, Math.max(min, Math.round(v * 1e6) / 1e6));
}

/* Colour arithmetic for the assistant helpers (pure). WCAG 2.x contrast, same maths as App\Services\Design\Contrast. */

export function parseHex(hex) {
    if (typeof hex !== 'string') return null;
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.exec(hex.trim());
    if (!m) return null;
    let h = m[1];
    if (h.length === 3) h = h.split('').map((c) => c + c).join('');
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
}

export function toHex([r, g, b]) {
    const c = (v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0');
    return `#${c(r)}${c(g)}${c(b)}`;
}

export function normaliseHex(hex) {
    const rgb = parseHex(hex);
    return rgb ? toHex(rgb) : null;
}

export function luminance(hex) {
    const rgb = parseHex(hex);
    if (!rgb) return 0;
    const [r, g, b] = rgb.map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(a, b) {
    const la = luminance(a);
    const lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

export function distance(a, b) {
    const x = parseHex(a);
    const y = parseHex(b);
    if (!x || !y) return Infinity;
    return Math.hypot(x[0] - y[0], x[1] - y[1], x[2] - y[2]);
}

/** 0..1 colourfulness: greys and near-black/white are neutral and are never recoloured. */
export function saturation(hex) {
    const rgb = parseHex(hex);
    if (!rgb) return 0;
    const max = Math.max(...rgb);
    const min = Math.min(...rgb);
    return max === 0 ? 0 : (max - min) / max;
}

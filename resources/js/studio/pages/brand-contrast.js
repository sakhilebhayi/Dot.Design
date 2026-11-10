/*
 * Colour arithmetic for the Brand Studio and the brand-update review (WCAG 2.x contrast on sRGB), the plain-language verdicts
 * people read, and the small hex helpers the rest of the pages share. Pure: no DOM, no network.
 * The PHP twin is App\Services\Design\Contrast; the two must agree (tests/js/brand-contrast.test.mjs pins known values).
 */

const HEX6 = /^#[0-9a-fA-F]{6}$/;
const HEX3 = /^#[0-9a-fA-F]{3}$/;
const HEX8 = /^#[0-9a-fA-F]{8}$/;

export const WHITE = '#ffffff';
export const NEAR_BLACK = '#111111';

/** True for #rgb, #rrggbb and #rrggbbaa. */
export function isHex(value) {
    return typeof value === 'string' && (HEX3.test(value) || HEX6.test(value) || HEX8.test(value));
}

/** The #rrggbb a kit stores (kits hold 3 and 6 digit codes): lower case, 3 digits expanded. Returns null when it is not a colour code. */
export function normaliseHex(value) {
    if (typeof value !== 'string') return null;
    const v = value.trim().toLowerCase();
    if (HEX3.test(v)) return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
    if (HEX6.test(v)) return v;
    return null;
}

/** What a person may have typed: "1a2b3c", "#1A2B3C", " #abc ". Gives a kit colour code or null. */
export function parseTypedHex(value) {
    if (typeof value !== 'string') return null;
    const v = value.trim();
    return normaliseHex(v.startsWith('#') ? v : '#' + v);
}

/** [r, g, b] of a colour code; an 8 digit code is read without its alpha. Returns null when it is not a colour. */
export function toRgb(hex) {
    if (typeof hex !== 'string') return null;
    let h = hex.trim().toLowerCase();
    if (HEX3.test(h)) h = '#' + h[1] + h[1] + h[2] + h[2] + h[3] + h[3];
    if (HEX8.test(h)) h = h.slice(0, 7);
    if (!HEX6.test(h)) return null;
    return [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)];
}

export function luminance(hex) {
    const rgb = toRgb(hex);
    if (!rgb) return null;
    const [r, g, b] = rgb.map((c) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Contrast ratio 1 to 21, or null when either side is not a colour. */
export function ratio(a, b) {
    const la = luminance(a);
    const lb = luminance(b);
    if (la === null || lb === null) return null;
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** Whichever of near black and white reads better on this colour. */
export function readableOn(hex) {
    const onDark = ratio(hex, NEAR_BLACK);
    const onWhite = ratio(hex, WHITE);
    if (onDark === null || onWhite === null) return NEAR_BLACK;
    return onDark >= onWhite ? NEAR_BLACK : WHITE;
}

/**
 * The plain-language verdict for a text colour on a background colour.
 *   4.5 and up  -> "Good for body text" (and so for headlines)
 *   3 to 4.5    -> "Good for headlines": large text only
 *   under 3     -> "Hard to read"
 * `large` marks text that is itself large (24px and up, or 19px bold and up): it only needs 3.
 */
export function verdict(contrast, { large = false } = {}) {
    if (contrast === null || contrast === undefined || Number.isNaN(contrast)) {
        return { level: 'unknown', label: 'Pick two colours', detail: 'Choose a text colour and a background colour to check them together.', ok: false };
    }
    const shown = Math.round(contrast * 10) / 10;
    if (contrast >= 4.5) return { level: 'body', label: 'Good for body text', detail: `${shown} to 1. Easy to read at any size, headlines included.`, ok: true, ratio: shown };
    if (contrast >= 3) {
        return large
            ? { level: 'headline', label: 'Good for headlines', detail: `${shown} to 1. Fine at this large size.`, ok: true, ratio: shown }
            : { level: 'headline', label: 'Good for headlines', detail: `${shown} to 1. Use it for large text only, not for small print.`, ok: false, ratio: shown };
    }
    return { level: 'hard', label: 'Hard to read', detail: `${shown} to 1. Many people will struggle to read text in this pair.`, ok: false, ratio: shown };
}

/** True when this much contrast is not enough for text of this size (3 for large text, 4.5 otherwise). */
export function belowText(contrast, large = false) {
    return contrast !== null && contrast < (large ? 3 : 4.5);
}

/** Text size is "large" in WCAG terms from 24px, or 19px when bold. Sizes are design pixels, which is how the page is judged. */
export function isLargeText(fontSize, fontWeight) {
    const size = Number(fontSize) || 0;
    return size >= 24 || (size >= 18.66 && Number(fontWeight) >= 700);
}

/**
 * Each colour of a palette against white and near black, with the verdict for the better text colour on it.
 * Used for the "How your colours read" strip.
 */
export function readability(colors) {
    return (colors || []).map((c) => {
        const text = readableOn(c.hex);
        const r = ratio(c.hex, text);
        return { key: c.key, name: c.name, hex: c.hex, text, ratio: r, verdict: verdict(r) };
    });
}

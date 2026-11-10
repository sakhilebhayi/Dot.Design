/*
 * Text boxes against the text inside them. Pure helpers over the engine's text layout (the same layout the
 * renderer and every export use), so "how tall is the text really" has one answer.
 *
 * The engine already keeps text inside its box: `shrink` boxes pick the largest size that fits (so the longest
 * word can end flush against the box edge) and `grow` boxes get taller as the text does. What looked like an
 * overflow in review was a box taller than its text (a template box drawn for three lines now holding two) with a
 * `none` or `shrink` behaviour. Two things deal with it here: `fitHeight` (the "Fit box to text" action) and
 * `extentOf` (the text's real extent, drawn as an inner guide in Pro).
 */
import { layoutText } from '../engine/text-layout.js';

/** The real extent of the text inside its box, in the element's own space: {x, y, w, h, fontSize, rows, overflow}. */
export function extentOf(el, measurer) {
    const lay = layoutText(el, measurer);
    const widest = lay.lines.reduce((m, l) => Math.max(m, l.width), 0);
    const w = Math.min(el.width, Math.max(widest, 1));
    const x = el.align === 'center' ? (el.width - w) / 2 : el.align === 'right' ? el.width - w : 0;
    return { x, y: lay.offsetY, w, h: lay.contentHeight, fontSize: lay.fontSize, rows: lay.lines.length, overflow: lay.overflow, boxH: el.height };
}

/**
 * How far the box is from hugging its text: ratio (box height over text height), and whether the action is worth
 * offering. `slack` is the share of the box that is empty before we say so.
 */
export function boxFit(el, measurer, slack = 0.12) {
    const ex = extentOf(el, measurer);
    const need = Math.max(1, Math.ceil(ex.h * 100) / 100);
    const gap = el.height - need;
    return { ...ex, need, gap, ratio: el.height / need, offer: Math.abs(gap) > Math.max(2, el.height * slack) && el.autoFit !== 'grow' };
}

/**
 * The element patch that makes the box hug its text: the box height becomes the text height (at the size the
 * engine really draws it), and the top edge moves for middle and bottom aligned text so the text does not jump.
 * Returns {height, y} or null when nothing would change.
 */
export function fitHeight(el, measurer) {
    const f = boxFit(el, measurer, 0);
    if (Math.abs(f.gap) < 0.5) return null;
    const rad = ((el.rotation || 0) * Math.PI) / 180;
    // Resizing happens about the box centre, so move the centre to keep one local point still: the top edge for top
    // aligned text, the middle for middle aligned, the bottom for bottom aligned (the text does not jump).
    const keep = el.valign === 'middle' ? 0.5 : el.valign === 'bottom' ? 1 : 0;
    const d = (keep - 0.5) * f.gap;
    const cx = el.x + el.width / 2 - d * Math.sin(rad);
    const cy = el.y + el.height / 2 + d * Math.cos(rad);
    const r2 = (v) => Math.round(v * 100) / 100;
    return { height: f.need, x: r2(cx - el.width / 2), y: r2(cy - f.need / 2) };
}

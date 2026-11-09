/*
 * Ruler maths (pure, unit tested) and drawing. rulerTicks() returns the ticks
 * for one axis; drawRuler() paints them on a canvas using the theme colours.
 */
import { pxTo, toPx } from './units.js';

const STEPS = [1, 2, 5];

/** The smallest "nice" step (in display units) that is at least minPx wide on screen. */
export function niceStep(pxPerUnit, minPx = 64) {
    const want = minPx / pxPerUnit;
    let pow = Math.pow(10, Math.floor(Math.log10(want)));
    for (let i = 0; i < 8; i += 1) {
        for (const s of STEPS) if (s * pow >= want) return s * pow;
        pow *= 10;
    }
    return pow;
}

/**
 * rulerTicks({origin, zoom, length, unit}) -> [{pos, label, major}]
 *   origin  screen position of page coordinate 0 along this axis (CSS px from the ruler start)
 *   zoom    screen px per page px
 *   length  ruler length in CSS px
 *   unit    'px' | 'mm' | 'in'
 */
export function rulerTicks({ origin, zoom, length, unit = 'px', pageSize = Infinity }) {
    const pxPerUnit = zoom * (1 / pxTo(1, unit));
    const step = niceStep(pxPerUnit);
    const minor = step / 5;
    const startUnit = Math.floor((0 - origin) / pxPerUnit / minor) * minor;
    const endUnit = Math.ceil((length - origin) / pxPerUnit / minor) * minor;
    const out = [];
    const count = Math.round((endUnit - startUnit) / minor);
    if (!Number.isFinite(count) || count > 4000) return out;
    for (let i = 0; i <= count; i += 1) {
        const u = +(startUnit + i * minor).toFixed(6);
        const pos = origin + u * pxPerUnit;
        if (pos < -1 || pos > length + 1) continue;
        const major = Math.abs(u / step - Math.round(u / step)) < 1e-6;
        out.push({ pos, major, value: u, label: major ? (Math.abs(step) >= 1 ? String(Math.round(u)) : String(+u.toFixed(2))) : null, outside: toPx(u, unit) < 0 || toPx(u, unit) > pageSize });
    }
    return out;
}

export function drawRuler(canvas, { horizontal, origin, zoom, unit, pageSize }) {
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.clientWidth; const hgt = canvas.clientHeight;
    if (!w || !hgt) return;
    if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(hgt * dpr)) { canvas.width = Math.round(w * dpr); canvas.height = Math.round(hgt * dpr); }
    const g = canvas.getContext('2d');
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.clearRect(0, 0, w, hgt);
    const cs = getComputedStyle(canvas);
    const ink = cs.getPropertyValue('--lt-ink-3').trim() || '#5e6670';
    const rule = cs.getPropertyValue('--lt-rule-strong').trim() || '#b7bdc4';
    const ticks = rulerTicks({ origin, zoom, length: horizontal ? w : hgt, unit, pageSize });
    g.font = '10px ui-sans-serif, system-ui, sans-serif';
    g.textBaseline = 'top';
    g.lineWidth = 1;
    for (const t of ticks) {
        const x = Math.round(t.pos) + 0.5;
        const len = t.major ? 9 : 4;
        g.strokeStyle = t.outside ? rule : ink;
        g.globalAlpha = t.outside ? 0.6 : 0.9;
        g.beginPath();
        if (horizontal) { g.moveTo(x, hgt); g.lineTo(x, hgt - len); } else { g.moveTo(w, x); g.lineTo(w - len, x); }
        g.stroke();
        if (t.label) {
            g.fillStyle = ink;
            g.globalAlpha = t.outside ? 0.55 : 1;
            if (horizontal) g.fillText(t.label, x + 3, 2); else { g.save(); g.translate(2, x - 3); g.rotate(-Math.PI / 2); g.fillText(t.label, 0, 0); g.restore(); }
        }
    }
    g.globalAlpha = 1;
}

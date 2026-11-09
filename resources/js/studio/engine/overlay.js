/*
 * Selection chrome drawn in SCREEN pixels on top of the page: frames, resize
 * and rotate handles (44px touch targets), snap guides, spacing hints, marquee,
 * hover outline. It is rebuilt each frame from a small model (about 20 nodes);
 * the page itself is never touched. Colours come from CSS custom properties on
 * the studio root with sane fallbacks:
 *   --studio-select (frame + handles), --studio-guide (snap guides), --studio-hover
 * Nothing here sets a style attribute (SVG presentation attributes only).
 */
import { frameHandlePoints } from './selection.js';
import { SVG_NS } from './render.js';

export const HANDLE_SIZE = 9;
export const HANDLE_HIT = 9; // radius in px for mouse
export const HANDLE_HIT_TOUCH = 22; // radius for 44px touch targets
export const ROTATE_OFFSET = 26;

function node(doc, tag, attrs) {
    const el = doc.createElementNS(SVG_NS, tag);
    for (const k in attrs) if (attrs[k] !== null && attrs[k] !== undefined) el.setAttribute(k, attrs[k]);
    return el;
}

/** Which resize handles to offer for a frame (side handles hide on tiny boxes, grow text has no top/bottom). */
export function handlesFor(frame, zoom) {
    const w = frame.box.width * zoom;
    const h = frame.box.height * zoom;
    const list = ['nw', 'ne', 'se', 'sw'];
    const el = frame.single ? frame.elements[0] : null;
    const growText = !!el && el.type === 'text' && el.autoFit === 'grow';
    if (w >= 44 && !growText) list.push('n', 's');
    if (h >= 44 || growText) list.push('e', 'w');
    return list;
}

/** Screen positions of every handle for a frame: {handles:{name:{x,y}}, rotate:{x,y}, corners:[...]} */
export function framePoints(frame, viewport) {
    const pts = frameHandlePoints(frame);
    const toScreen = (p) => viewport.pageToScreen(p.x, p.y);
    const s = {};
    for (const k of ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']) s[k] = toScreen(pts[k]);
    const centre = toScreen(pts.center);
    const dx = s.n.x - centre.x;
    const dy = s.n.y - centre.y;
    const len = Math.hypot(dx, dy) || 1;
    const rotate = { x: s.n.x + (dx / len) * ROTATE_OFFSET, y: s.n.y + (dy / len) * ROTATE_OFFSET };
    return { handles: s, rotate, centre, corners: [s.nw, s.ne, s.se, s.sw] };
}

/** Nearest handle under a screen point, or null. Returns {type:'resize'|'rotate', handle?}. */
export function hitHandle(frame, viewport, sx, sy, coarse) {
    if (!frame || frame.allLocked) return null;
    const r = coarse ? HANDLE_HIT_TOUCH : HANDLE_HIT;
    const fp = framePoints(frame, viewport);
    if (Math.hypot(sx - fp.rotate.x, sy - fp.rotate.y) <= r) return { type: 'rotate' };
    let best = null;
    for (const name of handlesFor(frame, viewport.zoom)) {
        const p = fp.handles[name];
        const d = Math.hypot(sx - p.x, sy - p.y);
        if (d <= r && (!best || d < best.d)) best = { type: 'resize', handle: name, d };
    }
    return best;
}

const CURSORS = ['ew-resize', 'nwse-resize', 'ns-resize', 'nesw-resize'];
const BASE_ANGLE = { e: 0, se: 45, s: 90, sw: 135, w: 180, nw: 225, n: 270, ne: 315 };

export function cursorFor(handle, rotationDeg) {
    const a = (((BASE_ANGLE[handle] + rotationDeg) % 180) + 180) % 180;
    return CURSORS[Math.round(a / 45) % 4];
}

export function createOverlay(svg, viewport, options = {}) {
    const doc = options.document || svg.ownerDocument;
    const root = options.root || svg.parentNode;
    let colors = { select: '#2563eb', guide: '#e11d6a', hover: '#60a5fa' };

    function refreshTheme() {
        if (typeof getComputedStyle !== 'function' || !root) return;
        const cs = getComputedStyle(root);
        const pick = (name, fallback) => (cs.getPropertyValue(name) || '').trim() || fallback;
        colors = { select: pick('--studio-select', colors.select), guide: pick('--studio-guide', colors.guide), hover: pick('--studio-hover', colors.hover) };
    }
    refreshTheme();

    function clear() {
        while (svg.firstChild) svg.removeChild(svg.firstChild);
    }

    function poly(points, stroke, width, extra) {
        return node(doc, 'polygon', { points: points.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '), fill: 'none', stroke, 'stroke-width': width, 'stroke-linejoin': 'round', ...extra });
    }

    /**
     * model: {frame, elementFrames:[frame], hover:frame|null, guides, spacing, marquee, badge, coarse, editing, showHandles}
     */
    function draw(model) {
        clear();
        const z = viewport.zoom;
        const toScreen = (x, y) => viewport.pageToScreen(x, y);

        // user guides on the page
        if (model.pageGuides && model.pageGuides.length) {
            const size = viewport.size();
            for (const g of model.pageGuides) {
                const a = g.axis === 'x' ? toScreen(g.pos, 0) : toScreen(0, g.pos);
                svg.appendChild(node(doc, 'line', g.axis === 'x'
                    ? { x1: a.x, y1: 0, x2: a.x, y2: size.height, stroke: colors.guide, 'stroke-width': 1, 'stroke-opacity': 0.5 }
                    : { x1: 0, y1: a.y, x2: size.width, y2: a.y, stroke: colors.guide, 'stroke-width': 1, 'stroke-opacity': 0.5 }));
            }
        }

        if (model.hover && !model.editing) {
            svg.appendChild(poly(framePoints(model.hover, viewport).corners, colors.hover, 1.5));
        }

        if (model.frame && !model.editing) {
            const fp = framePoints(model.frame, viewport);
            if (!model.frame.single) {
                for (const f of model.elementFrames || []) svg.appendChild(poly(framePoints(f, viewport).corners, colors.select, 1, { 'stroke-opacity': 0.55 }));
            }
            // a pale halo under the frame keeps it readable on both dark and light artwork
            svg.appendChild(poly(fp.corners, '#ffffff', 3.5, { 'stroke-opacity': 0.8 }));
            svg.appendChild(poly(fp.corners, colors.select, 1.5, model.frame.allLocked ? { 'stroke-dasharray': '4 3' } : null));
            if (model.showHandles !== false && !model.frame.allLocked) {
                const s = model.coarse ? HANDLE_SIZE + 5 : HANDLE_SIZE;
                svg.appendChild(node(doc, 'line', { x1: fp.handles.n.x, y1: fp.handles.n.y, x2: fp.rotate.x, y2: fp.rotate.y, stroke: colors.select, 'stroke-width': 1 }));
                svg.appendChild(node(doc, 'circle', { cx: fp.rotate.x, cy: fp.rotate.y, r: s / 2 + 1, fill: '#fff', stroke: colors.select, 'stroke-width': 1.5, 'data-handle': 'rotate' }));
                for (const name of handlesFor(model.frame, z)) {
                    const p = fp.handles[name];
                    svg.appendChild(node(doc, 'rect', { x: p.x - s / 2, y: p.y - s / 2, width: s, height: s, rx: 2, fill: '#fff', stroke: colors.select, 'stroke-width': 1.5, 'data-handle': name }));
                }
            }
            if (model.badge) {
                const bx = (fp.corners[2].x + fp.corners[3].x) / 2;
                const by = Math.max(fp.corners[2].y, fp.corners[3].y) + 18;
                const w = model.badge.length * 6.6 + 12;
                svg.appendChild(node(doc, 'rect', { x: bx - w / 2, y: by - 11, width: w, height: 20, rx: 4, fill: colors.select }));
                const t = node(doc, 'text', { x: bx, y: by + 3, 'text-anchor': 'middle', 'font-size': 11, 'font-family': 'ui-monospace, Menlo, monospace', fill: '#fff' });
                t.textContent = model.badge;
                svg.appendChild(t);
            }
        }

        for (const g of model.guides || []) {
            const a = g.axis === 'x' ? toScreen(g.pos, g.from) : toScreen(g.from, g.pos);
            const b = g.axis === 'x' ? toScreen(g.pos, g.to) : toScreen(g.to, g.pos);
            svg.appendChild(node(doc, 'line', { x1: a.x, y1: a.y, x2: b.x, y2: b.y, stroke: colors.guide, 'stroke-width': 1 }));
        }
        for (const sp of model.spacing || []) {
            const horizontal = sp.axis === 'x';
            const a = horizontal ? toScreen(sp.from, sp.at) : toScreen(sp.at, sp.from);
            const b = horizontal ? toScreen(sp.to, sp.at) : toScreen(sp.at, sp.to);
            svg.appendChild(node(doc, 'line', { x1: a.x, y1: a.y, x2: b.x, y2: b.y, stroke: colors.guide, 'stroke-width': 1 }));
            const tick = (p) => svg.appendChild(node(doc, 'line', horizontal ? { x1: p.x, y1: p.y - 4, x2: p.x, y2: p.y + 4, stroke: colors.guide, 'stroke-width': 1 } : { x1: p.x - 4, y1: p.y, x2: p.x + 4, y2: p.y, stroke: colors.guide, 'stroke-width': 1 }));
            tick(a); tick(b);
            const t = node(doc, 'text', { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 - 5, 'text-anchor': 'middle', 'font-size': 10, 'font-family': 'ui-monospace, Menlo, monospace', fill: colors.guide });
            t.setAttribute('stroke', '#ffffff');
            t.setAttribute('stroke-width', '3');
            t.setAttribute('paint-order', 'stroke');
            t.textContent = String(Math.round(sp.gap));
            svg.appendChild(t);
        }

        if (model.marquee) {
            const m = model.marquee;
            const a = toScreen(m.left, m.top);
            const b = toScreen(m.right, m.bottom);
            svg.appendChild(node(doc, 'rect', { x: a.x, y: a.y, width: b.x - a.x, height: b.y - a.y, fill: colors.select, 'fill-opacity': 0.08, stroke: colors.select, 'stroke-width': 1 }));
        }
    }

    return { draw, clear, refreshTheme, get colors() { return colors; } };
}


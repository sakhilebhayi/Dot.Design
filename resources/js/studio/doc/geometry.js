/*
 * Pure geometry for the Studio engine. No DOM.
 *
 * Conventions (same as the v1 editor): x/y is the top-left of an element's
 * UNROTATED box, rotation is degrees clockwise about its centre, y grows
 * downward, flips mirror about the centre. A child of a group is positioned
 * relative to the group's own top-left, so `elementMatrix` maps an element's
 * local space to its parent's space and `worldMatrix` composes the chain.
 *
 * Matrices are [a, b, c, d, e, f] exactly as SVG matrix(a b c d e f):
 *   x' = a*x + c*y + e,  y' = b*x + d*y + f
 */

export const DEG = Math.PI / 180;

export function norm(v) {
    return v + 0; // turns -0 into 0
}

export function clamp(v, min, max) {
    return Math.min(max, Math.max(min, v));
}

/** Round to 3 decimals so documents do not accumulate float noise. */
export function round3(v) {
    return norm(Math.round(v * 1000) / 1000);
}

export function rotateVector(x, y, deg) {
    const rad = deg * DEG;
    const cos = Math.cos(rad);
    const sin = Math.sin(rad);
    return { x: x * cos - y * sin, y: x * sin + y * cos };
}

export function rotatePoint(x, y, cx, cy, deg) {
    const v = rotateVector(x - cx, y - cy, deg);
    return { x: cx + v.x, y: cy + v.y };
}

// ---- matrices -------------------------------------------------------------

export const IDENTITY = Object.freeze([1, 0, 0, 1, 0, 0]);

export function multiply(m, n) {
    // result = m * n  (apply n first, then m)
    return [
        m[0] * n[0] + m[2] * n[1],
        m[1] * n[0] + m[3] * n[1],
        m[0] * n[2] + m[2] * n[3],
        m[1] * n[2] + m[3] * n[3],
        m[0] * n[4] + m[2] * n[5] + m[4],
        m[1] * n[4] + m[3] * n[5] + m[5],
    ];
}

export function invert(m) {
    const det = m[0] * m[3] - m[1] * m[2];
    if (!det) return null;
    const a = m[3] / det;
    const b = -m[1] / det;
    const c = -m[2] / det;
    const d = m[0] / det;
    return [a, b, c, d, -(a * m[4] + c * m[5]), -(b * m[4] + d * m[5])];
}

export function applyMatrix(m, x, y) {
    return { x: m[0] * x + m[2] * y + m[4], y: m[1] * x + m[3] * y + m[5] };
}

/** Apply only the linear part (for deltas). */
export function applyLinear(m, x, y) {
    return { x: m[0] * x + m[2] * y, y: m[1] * x + m[3] * y };
}

/** Local space of an element -> its parent's space. */
export function elementMatrix(el) {
    const w = el.width;
    const h = el.height;
    const cx = w / 2;
    const cy = h / 2;
    const rad = (el.rotation || 0) * DEG;
    const cos = Math.cos(rad);
    const sin = Math.sin(rad);
    const fx = el.flip_x ? -1 : 1;
    const fy = el.flip_y ? -1 : 1;
    const a = cos * fx;
    const b = sin * fx;
    const c = -sin * fy;
    const d = cos * fy;
    return [a, b, c, d, el.x + cx - (a * cx + c * cy), el.y + cy - (b * cx + d * cy)];
}

/** Matrix of a chain of ancestors (outermost first) so child-local -> page space. */
export function chainMatrix(ancestors) {
    let m = IDENTITY.slice();
    for (const g of ancestors) m = multiply(m, elementMatrix(g));
    return m;
}

// ---- boxes ----------------------------------------------------------------

export function boxCorners(box) {
    const cx = box.x + box.width / 2;
    const cy = box.y + box.height / 2;
    const r = box.rotation || 0;
    return [
        rotatePoint(box.x, box.y, cx, cy, r),
        rotatePoint(box.x + box.width, box.y, cx, cy, r),
        rotatePoint(box.x + box.width, box.y + box.height, cx, cy, r),
        rotatePoint(box.x, box.y + box.height, cx, cy, r),
    ];
}

function boundsFromPoints(points) {
    let left = Infinity;
    let top = Infinity;
    let right = -Infinity;
    let bottom = -Infinity;
    for (const p of points) {
        if (p.x < left) left = p.x;
        if (p.x > right) right = p.x;
        if (p.y < top) top = p.y;
        if (p.y > bottom) bottom = p.y;
    }
    return makeBounds(left, top, right, bottom);
}

export function makeBounds(left, top, right, bottom) {
    return {
        left, top, right, bottom,
        x: left, y: top,
        width: right - left, height: bottom - top,
        cx: (left + right) / 2, cy: (top + bottom) / 2,
    };
}

/** Axis-aligned bounds of a possibly rotated box (rotation only; flips do not change the AABB). */
export function boundsOf(box) {
    if (!box.rotation) return makeBounds(box.x, box.y, box.x + box.width, box.y + box.height);
    return boundsFromPoints(boxCorners(box));
}

export function boundsOfMany(boxes) {
    if (!boxes.length) return null;
    let left = Infinity;
    let top = Infinity;
    let right = -Infinity;
    let bottom = -Infinity;
    for (const box of boxes) {
        const b = box.left !== undefined && box.right !== undefined ? box : boundsOf(box);
        if (b.left < left) left = b.left;
        if (b.right > right) right = b.right;
        if (b.top < top) top = b.top;
        if (b.bottom > bottom) bottom = b.bottom;
    }
    return makeBounds(left, top, right, bottom);
}

/** Corners of an element's box after applying `m` (element-local rect of w x h at 0,0). */
export function transformedCorners(m, width, height) {
    return [applyMatrix(m, 0, 0), applyMatrix(m, width, 0), applyMatrix(m, width, height), applyMatrix(m, 0, height)];
}

/** Bounds of an element's box in the space `parentMatrix` maps its parent into. */
export function elementBounds(el, parentMatrix) {
    if (!parentMatrix) return boundsOf(el);
    return boundsFromPoints(transformedCorners(multiply(parentMatrix, elementMatrix(el)), el.width, el.height));
}

export function pointInRotatedRect(px, py, box) {
    const cx = box.x + box.width / 2;
    const cy = box.y + box.height / 2;
    const p = rotatePoint(px, py, cx, cy, -(box.rotation || 0));
    return p.x >= box.x && p.x <= box.x + box.width && p.y >= box.y && p.y <= box.y + box.height;
}

export function pointInBounds(px, py, b) {
    return px >= b.left && px <= b.right && py >= b.top && py <= b.bottom;
}

export function rectsIntersect(a, b) {
    return a.left <= b.right && a.right >= b.left && a.top <= b.bottom && a.bottom >= b.top;
}

export function rectContains(outer, inner) {
    return inner.left >= outer.left && inner.right <= outer.right && inner.top >= outer.top && inner.bottom <= outer.bottom;
}

/** Normalised rectangle from two corner points (marquee). */
export function rectFromPoints(x1, y1, x2, y2) {
    return makeBounds(Math.min(x1, x2), Math.min(y1, y2), Math.max(x1, x2), Math.max(y1, y2));
}

/** True when a rotated box overlaps an axis-aligned rect (separating axis test). */
export function rotatedBoxIntersectsRect(box, rect) {
    if (!box.rotation) return rectsIntersect(boundsOf(box), rect);
    const corners = boxCorners(box);
    const rectCorners = [
        { x: rect.left, y: rect.top }, { x: rect.right, y: rect.top },
        { x: rect.right, y: rect.bottom }, { x: rect.left, y: rect.bottom },
    ];
    const axes = [{ x: 1, y: 0 }, { x: 0, y: 1 }];
    const rad = box.rotation * DEG;
    axes.push({ x: Math.cos(rad), y: Math.sin(rad) }, { x: -Math.sin(rad), y: Math.cos(rad) });
    for (const ax of axes) {
        let aMin = Infinity; let aMax = -Infinity; let bMin = Infinity; let bMax = -Infinity;
        for (const p of corners) { const d = p.x * ax.x + p.y * ax.y; if (d < aMin) aMin = d; if (d > aMax) aMax = d; }
        for (const p of rectCorners) { const d = p.x * ax.x + p.y * ax.y; if (d < bMin) bMin = d; if (d > bMax) bMax = d; }
        if (aMax < bMin || bMax < aMin) return false;
    }
    return true;
}

export function normaliseAngle(deg) {
    let a = deg % 360;
    if (a > 180) a -= 360;
    if (a <= -180) a += 360;
    return norm(a);
}

/** Rotation (0 = handle straight above the centre) that points the rotate handle at the pointer. */
export function angleFromPointer(cx, cy, px, py, snap) {
    let deg = (Math.atan2(px - cx, -(py - cy)) * 180) / Math.PI;
    if (snap) deg = Math.round(deg / 15) * 15;
    return norm(Math.round(deg * 100) / 100);
}

/**
 * Decompose a 2x2 orthogonal linear part into {rotation, flip_x, flip_y} such
 * that elementMatrix reproduces it. Used when a group is dissolved.
 */
export function decomposeLinear(a, b, c, d) {
    const det = a * d - b * c;
    if (det >= 0) {
        return { rotation: normaliseAngle(Math.atan2(b, a) / DEG), flip_x: false, flip_y: false };
    }
    // reflection: R(theta) * diag(1,-1) = [[cos, sin],[sin, -cos]]
    return { rotation: normaliseAngle(Math.atan2(b, a) / DEG), flip_x: false, flip_y: true };
}

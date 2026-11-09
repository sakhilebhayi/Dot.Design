/*
 * Move / resize / rotate maths on boxes. Pure: boxes in, boxes out.
 *
 * A box is {x, y, width, height, rotation?} in the space of the element's
 * parent (the page, or a group's local space). Deltas passed in must already
 * be in that space. Handles are named by compass point: n ne e se s sw w nw.
 */
import { angleFromPointer, boundsOf, boundsOfMany, clamp, norm, rotatePoint, rotateVector, round3 } from '../doc/geometry.js';
import { LIMITS } from '../doc/schema.js';

export const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
export const MIN_SIZE = 8;

export function isCorner(handle) {
    return handle.length === 2;
}

export function nudge(key, shift) {
    const step = shift ? 10 : 1;
    if (key === 'ArrowLeft') return { dx: -step, dy: 0 };
    if (key === 'ArrowRight') return { dx: step, dy: 0 };
    if (key === 'ArrowUp') return { dx: 0, dy: -step };
    if (key === 'ArrowDown') return { dx: 0, dy: step };
    return { dx: 0, dy: 0 };
}

/**
 * Keyboard resize (Alt + arrow): the size after one step, never below MIN_SIZE. Right and Down grow, Left and Up shrink,
 * Shift is the big step. `width` and `height` are unchanged on the axis the key does not touch.
 */
export function keyboardResize(el, key, shift) {
    const { dx, dy } = nudge(key, shift);
    return { width: Math.max(MIN_SIZE, el.width + dx), height: Math.max(MIN_SIZE, el.height + dy) };
}

/**
 * New box after dragging `handle` by the parent-space pointer delta (dx, dy).
 * The delta is moved into the element's own (unrotated) frame, the size is
 * changed there, and the centre is shifted so the side opposite the handle
 * stays where it was on screen. Options:
 *   keepRatio  corner handles keep the aspect ratio
 *   fromCenter both sides move (the centre stays put)
 *   minWidth / minHeight (default MIN_SIZE; a handle never flips through zero)
 *   maxSize    (default schema max_size)
 */
export function resizeBox(box, handle, dx, dy, options = {}) {
    const minW = options.minWidth ?? MIN_SIZE;
    const minH = options.minHeight ?? MIN_SIZE;
    const maxS = options.maxSize ?? LIMITS.max_size;
    const rotation = box.rotation || 0;
    const sx = handle.includes('e') ? 1 : handle.includes('w') ? -1 : 0;
    const sy = handle.includes('s') ? 1 : handle.includes('n') ? -1 : 0;
    const local = rotateVector(dx, dy, -rotation);
    // a flipped element's handles sit on the opposite side: its local axes are mirrored
    const fx = options.flipX ? -1 : 1;
    const fy = options.flipY ? -1 : 1;
    local.x *= fx;
    local.y *= fy;
    const k = options.fromCenter ? 2 : 1;

    let width = box.width + sx * local.x * k;
    let height = box.height + sy * local.y * k;

    if (options.keepRatio && sx !== 0 && sy !== 0) {
        let scale = Math.max(width / box.width, height / box.height);
        scale = Math.max(scale, minW / box.width, minH / box.height);
        scale = Math.min(scale, maxS / box.width, maxS / box.height);
        width = box.width * scale;
        height = box.height * scale;
    } else {
        width = clamp(width, minW, maxS);
        height = clamp(height, minH, maxS);
    }
    if (sx === 0) width = box.width;
    if (sy === 0) height = box.height;

    let cx = box.x + box.width / 2;
    let cy = box.y + box.height / 2;
    if (!options.fromCenter) {
        const shift = rotateVector((fx * sx * (width - box.width)) / 2, (fy * sy * (height - box.height)) / 2, rotation);
        cx += shift.x;
        cy += shift.y;
    }
    return { x: round3(cx - width / 2), y: round3(cy - height / 2), width: round3(width), height: round3(height) };
}

/** Rotation for a rotate-handle drag. Shift snaps to 15 degrees. */
export function rotationFromPointer(box, px, py, snap) {
    const cx = box.x + box.width / 2;
    const cy = box.y + box.height / 2;
    let deg = angleFromPointer(cx, cy, px, py, snap);
    if (deg > 180) deg -= 360;
    return clamp(deg, -360, 360);
}

/**
 * Factors by which a unit scale (sx, sy) stretches an element's own width and
 * height axes when the element is rotated by `rotation` degrees. At 0 degrees
 * they are (sx, sy), at 90 they swap, and a uniform scale is always uniform.
 */
export function axisFactors(sx, sy, rotation) {
    const r = (rotation * Math.PI) / 180;
    const c = Math.cos(r);
    const s = Math.sin(r);
    return {
        lw: Math.sqrt(sx * sx * c * c + sy * sy * s * s),
        lh: Math.sqrt(sx * sx * s * s + sy * sy * c * c),
    };
}

/**
 * Scale a set of boxes as one unit. `items` are boxes with ids, `bounds` is
 * their union box (rotation 0). Returns {bounds, boxes: {id: box}, sx, sy}.
 * Text items may carry fontSize; it is scaled only when `scaleFont` is true
 * (a proportional corner drag).
 */
export function resizeUnit(items, bounds, handle, dx, dy, options = {}) {
    const next = resizeBox({ x: bounds.x, y: bounds.y, width: bounds.width, height: bounds.height, rotation: 0 }, handle, dx, dy, options);
    const sx = next.width / bounds.width;
    const sy = next.height / bounds.height;
    const boxes = {};
    for (const it of items) {
        const { lw, lh } = axisFactors(sx, sy, it.rotation || 0);
        const cx = it.x + it.width / 2;
        const cy = it.y + it.height / 2;
        const ncx = next.x + (cx - bounds.x) * sx;
        const ncy = next.y + (cy - bounds.y) * sy;
        const w = clamp(it.width * lw, 1, LIMITS.max_size);
        const h = clamp(it.height * lh, 1, LIMITS.max_size);
        const out = { x: round3(ncx - w / 2), y: round3(ncy - h / 2), width: round3(w), height: round3(h), rotation: it.rotation || 0 };
        if (options.scaleFont && typeof it.fontSize === 'number') out.fontSize = clamp(round3(it.fontSize * Math.sqrt(lw * lh)), 4, 2000);
        boxes[it.id] = out;
    }
    return { bounds: next, boxes, sx, sy };
}

/** Rotate boxes about a pivot by `delta` degrees: each centre orbits the pivot and each rotation grows by delta. */
export function rotateUnit(items, pivot, delta) {
    const boxes = {};
    for (const it of items) {
        const c = rotatePoint(it.x + it.width / 2, it.y + it.height / 2, pivot.x, pivot.y, delta);
        let r = (it.rotation || 0) + delta;
        r = ((r + 180) % 360 + 360) % 360 - 180;
        boxes[it.id] = { x: round3(c.x - it.width / 2), y: round3(c.y - it.height / 2), width: it.width, height: it.height, rotation: norm(round3(r)) };
    }
    return boxes;
}

export function moveBoxes(items, dx, dy) {
    const boxes = {};
    for (const it of items) boxes[it.id] = { x: round3(it.x + dx), y: round3(it.y + dy), width: it.width, height: it.height, rotation: it.rotation || 0 };
    return boxes;
}

/** Union bounds of items (rotation aware), or null. */
export function unionBounds(items) {
    return boundsOfMany(items.map((it) => boundsOf(it)));
}

/** Clamp a coordinate into the schema's allowed range. */
export function clampCoord(v) {
    return clamp(v, -LIMITS.max_coord, LIMITS.max_coord);
}

/*
 * Grouping and scaling helpers (pure). A group's children are positioned
 * relative to the group's own top-left, and the group's box always hugs them
 * (tree.refitGroup keeps that true). Dissolving a group composes the group's
 * transform into each child so nothing moves on the page.
 */
import { applyMatrix, boundsOf, decomposeLinear, elementMatrix, normaliseAngle, round3 } from '../doc/geometry.js';
import { LIMITS } from '../doc/schema.js';
import { scalePath } from '../doc/path.js';
import { isGroup, maxDepth } from '../doc/tree.js';
import { axisFactors } from './transform.js';

/**
 * makeGroup(elements, id) -> group element built from sibling elements (in
 * their z-order), or {error}. Children are re-based to the group's top-left.
 */
export function makeGroup(elements, id, name = null) {
    if (!elements.length) return { error: { code: 'empty', message: 'Nothing to group.' } };
    let left = Infinity; let top = Infinity; let right = -Infinity; let bottom = -Infinity;
    for (const el of elements) {
        const b = boundsOf(el);
        left = Math.min(left, b.left); top = Math.min(top, b.top); right = Math.max(right, b.right); bottom = Math.max(bottom, b.bottom);
    }
    let depth = 0;
    for (const el of elements) depth = Math.max(depth, maxDepth(el));
    if (depth + 1 > LIMITS.max_group_depth) return { error: { code: 'depth', message: `Groups can nest ${LIMITS.max_group_depth} deep at most.` } };
    const children = elements.map((el) => ({ ...el, x: round3(el.x - left), y: round3(el.y - top) }));
    return {
        group: {
            id, type: 'group', name, role: 'other', bind: null,
            x: round3(left), y: round3(top), width: Math.max(1, round3(right - left)), height: Math.max(1, round3(bottom - top)),
            rotation: 0, opacity: 1, locked: false, hidden: false, flip_x: false, flip_y: false, blend: 'normal',
            shadow: null, animation: null, tokens: null, children,
        },
    };
}

/**
 * Children of `group` expressed in the group's parent space. When the group is
 * a plain translation (no rotation or flip) children only shift; otherwise
 * each child's rotation, flips and centre are recomputed so it renders
 * identically. The group's opacity is folded into each child.
 */
export function dissolveGroup(group) {
    const m = elementMatrix(group);
    const identityLinear = Math.abs(m[0] - 1) < 1e-12 && Math.abs(m[1]) < 1e-12 && Math.abs(m[2]) < 1e-12 && Math.abs(m[3] - 1) < 1e-12;
    return group.children.map((c) => {
        let next;
        if (identityLinear) {
            next = { ...c, x: round3(c.x + group.x), y: round3(c.y + group.y) };
        } else {
            const cm = elementMatrix(c);
            const a = m[0] * cm[0] + m[2] * cm[1];
            const b = m[1] * cm[0] + m[3] * cm[1];
            const cc = m[0] * cm[2] + m[2] * cm[3];
            const d = m[1] * cm[2] + m[3] * cm[3];
            const centre = applyMatrix(m, c.x + c.width / 2, c.y + c.height / 2);
            const dec = decomposeLinear(a, b, cc, d);
            next = { ...c, x: round3(centre.x - c.width / 2), y: round3(centre.y - c.height / 2), rotation: normaliseAngle(round3(dec.rotation)), flip_x: dec.flip_x, flip_y: dec.flip_y };
        }
        if (group.opacity !== 1) next.opacity = round3((c.opacity ?? 1) * group.opacity);
        if (group.hidden) next.hidden = true;
        return next;
    });
}

/**
 * Scale an element about the origin of its parent space: its centre moves by
 * (sx, sy), its own axes stretch by the rotation-aware factors, text size
 * follows only a uniform scale, and a group scales every descendant.
 */
export function scaleElement(el, sx, sy, scaleFont = false) {
    const { lw, lh } = axisFactors(sx, sy, el.rotation || 0);
    const cx = (el.x + el.width / 2) * sx;
    const cy = (el.y + el.height / 2) * sy;
    const w = Math.max(1, round3(el.width * lw));
    const h = Math.max(1, round3(el.height * lh));
    const next = { ...el, x: round3(cx - w / 2), y: round3(cy - h / 2), width: w, height: h };
    if (el.type === 'text' && scaleFont && Math.abs(lw - lh) < 1e-6) next.fontSize = Math.min(2000, Math.max(4, round3(el.fontSize * lw)));
    if (el.type === 'shape' && el.kind === 'path' && el.d) next.d = scalePath(el.d, w / el.width, h / el.height);
    if (isGroup(el)) {
        // children live in the group's local axes, stretched by (lw, lh)
        next.children = el.children.map((c) => scaleElement(c, lw, lh, scaleFont));
    }
    return next;
}

/** Resize an element's box. Groups scale their contents so they fill the new box. */
export function resizeElementTo(el, box, scaleFont = false) {
    if (!isGroup(el)) {
        const next = { ...el, x: box.x, y: box.y, width: box.width, height: box.height };
        if (el.type === 'shape' && el.kind === 'path' && el.d && (box.width !== el.width || box.height !== el.height)) next.d = scalePath(el.d, box.width / el.width, box.height / el.height);
        if (box.rotation !== undefined) next.rotation = box.rotation;
        if (box.fontSize !== undefined && el.type === 'text') next.fontSize = box.fontSize;
        return next;
    }
    const sx = box.width / el.width;
    const sy = box.height / el.height;
    const children = el.children.map((c) => scaleElement({ ...c }, sx, sy, scaleFont));
    const next = { ...el, x: box.x, y: box.y, width: box.width, height: box.height, children };
    if (box.rotation !== undefined) next.rotation = box.rotation;
    return next;
}

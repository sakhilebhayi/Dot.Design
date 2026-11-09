/*
 * Document schema v2 for the browser: defaults, normalisation, validation and
 * the v1 -> v2 migration. The rules (limits, enums, ranges, defaults) are read
 * from resources/design/document-schema.json, the same file the PHP validator
 * reads, so the two runtimes cannot drift. Hand-written code here only
 * interprets that file.
 *
 * Everything that comes from outside (server, clipboard, AI, templates) goes
 * through normaliseElement/normalisePage: unknown keys are stripped, numbers
 * are clamped, enums fall back, colours must be hex, ids are repaired.
 */
import SCHEMA from '../../../design/document-schema.json' with { type: 'json' };
import { createIdGenerator, isValidId } from './ids.js';
import { parsePath } from './path.js';
import { childrenBounds, refitGroup } from './tree.js';

export { SCHEMA };
export const LIMITS = Object.freeze({ ...SCHEMA.limits });
export const ENUMS = SCHEMA.enums;
export const TYPES = ENUMS.type;
export const DEFAULT_FONT = 'inter';

const HEX = new RegExp(SCHEMA.value_types.hex);
const FONT_KEY = new RegExp(SCHEMA.value_types.font_key);
const BIND_KEY = new RegExp(SCHEMA.value_types.bind_key);
const ICON_KEY = new RegExp(SCHEMA.value_types.icon_key);
const TOKEN_REF = new RegExp(SCHEMA.value_types.token_ref);

const COMMON = SCHEMA.element.common;
const TEXT = SCHEMA.element.text;
const SHAPE = SCHEMA.element.shape;
const IMAGE = SCHEMA.element.image;
const ICON = SCHEMA.element.icon;

export const TOKEN_PROPS = ['color', 'fill', 'stroke_color', 'shadow_color', 'font'];

export const DEFAULT_SIZE = Object.freeze({
    text: { width: 320, height: 48 },
    shape: { width: 200, height: 200 },
    image: { width: 320, height: 240 },
    icon: { width: 64, height: 64 },
    group: { width: 1, height: 1 },
});

const defaultIds = createIdGenerator();

// ---- primitives -----------------------------------------------------------

export function isHex(v) {
    return typeof v === 'string' && HEX.test(v);
}

function isObj(v) {
    return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function report(issues, path, message, code = 'invalid') {
    if (issues) issues.push({ path, message, code });
}

function num(spec, v, path, issues, fallback) {
    let n = v;
    if (typeof n === 'string' && n.trim() !== '' && Number.isFinite(Number(n))) n = Number(n);
    if (typeof n !== 'number' || !Number.isFinite(n)) {
        if (v !== undefined && v !== null) report(issues, path, 'Expected a number.');
        return fallback;
    }
    if (spec.type === 'integer') n = Math.round(n);
    const min = spec.min ?? -Infinity;
    const max = spec.max ?? Infinity;
    if (n < min || n > max) {
        report(issues, path, `Value ${n} is outside ${min}..${max}; clamped.`, 'clamped');
        n = Math.min(max, Math.max(min, n));
    }
    return n + 0;
}

function enumOf(name, v, path, issues, fallback) {
    const list = Array.isArray(name) ? name : ENUMS[name];
    if (list.includes(v)) return v;
    if (v !== undefined && v !== null) report(issues, path, `"${v}" is not one of ${list.join(', ')}.`);
    return fallback;
}

function bool(v, fallback) {
    return typeof v === 'boolean' ? v : fallback;
}

function hex(v, path, issues, fallback) {
    if (isHex(v)) return v;
    if (v !== undefined && v !== null) report(issues, path, 'Expected a hex colour like #1a2b3c.');
    return fallback;
}

function str(spec, v, path, issues, fallback) {
    if (typeof v !== 'string') {
        if (v !== undefined && v !== null) report(issues, path, 'Expected text.');
        return fallback;
    }
    const max = spec.max ?? Infinity;
    if (v.length > max) {
        report(issues, path, `Text longer than ${max}; truncated.`, 'clamped');
        return v.slice(0, max);
    }
    return v;
}

// ---- compound values ------------------------------------------------------

export function normaliseGradient(raw, path = 'gradient', issues) {
    if (!isObj(raw)) return null;
    const spec = SCHEMA.paint.gradient;
    const kind = enumOf('gradient_kind', raw.kind, `${path}.kind`, issues, 'linear');
    const angle = num(spec.angle, raw.angle, `${path}.angle`, issues, spec.angle.default);
    const stopSpec = spec.stops;
    const stops = [];
    if (Array.isArray(raw.stops)) {
        for (const s of raw.stops.slice(0, stopSpec.max)) {
            if (!isObj(s) || !isHex(s.color)) { report(issues, `${path}.stops`, 'Bad gradient stop dropped.'); continue; }
            stops.push({ at: num(stopSpec.item.at, s.at, `${path}.stops.at`, issues, 0), color: s.color });
        }
    }
    if (stops.length < stopSpec.min) return null;
    stops.sort((a, b) => a.at - b.at);
    return { kind, angle, stops };
}

export function normalisePaint(raw, path, issues, fallback = null) {
    if (raw === null) return null;
    if (isHex(raw)) return raw;
    if (isObj(raw)) {
        const g = normaliseGradient(raw, path, issues);
        if (g) return g;
    }
    if (raw !== undefined) report(issues, path, 'Expected a hex colour, a gradient or null.');
    return fallback;
}

function colorWidth(spec, raw, path, issues, extra) {
    if (!isObj(raw)) return null;
    const color = hex(raw.color, `${path}.color`, issues, null);
    if (color === null) return null;
    const out = { color, width: num(spec.props.width, raw.width, `${path}.width`, issues, 1) };
    if (extra) Object.assign(out, extra(raw));
    return out;
}

function normaliseShadow(raw, path, issues) {
    if (!isObj(raw)) return null;
    const p = COMMON.shadow.props;
    return {
        x: num(p.x, raw.x, `${path}.x`, issues, 0),
        y: num(p.y, raw.y, `${path}.y`, issues, 4),
        blur: num(p.blur, raw.blur, `${path}.blur`, issues, 12),
        color: hex(raw.color, `${path}.color`, issues, '#000000'),
        opacity: num(p.opacity, raw.opacity, `${path}.opacity`, issues, 0.25),
    };
}

function normaliseAnim(raw, path, issues) {
    if (!isObj(raw)) return null;
    const p = COMMON.animation.props;
    const out = {};
    for (const phase of ['in', 'out']) {
        const r = raw[phase];
        if (!isObj(r)) continue;
        const preset = enumOf(phase === 'in' ? 'anim_preset_in' : 'anim_preset_out', r.preset, `${path}.${phase}.preset`, issues, 'none');
        out[phase] = {
            preset,
            delay_ms: num(p[phase].delay_ms, r.delay_ms, `${path}.${phase}.delay_ms`, issues, 0),
            duration_ms: num(p[phase].duration_ms, r.duration_ms, `${path}.${phase}.duration_ms`, issues, 600),
            easing: enumOf('anim_easing', r.easing, `${path}.${phase}.easing`, issues, 'ease-out'),
        };
    }
    if (isObj(raw.loop)) {
        out.loop = {
            preset: enumOf('anim_preset_loop', raw.loop.preset, `${path}.loop.preset`, issues, 'none'),
            duration_ms: num(p.loop.duration_ms, raw.loop.duration_ms, `${path}.loop.duration_ms`, issues, 2000),
        };
    }
    return Object.keys(out).length ? out : null;
}

function normaliseTokens(raw, path, issues) {
    if (!isObj(raw)) return null;
    const out = {};
    for (const [k, v] of Object.entries(raw)) {
        if (!TOKEN_PROPS.includes(k) || typeof v !== 'string' || !TOKEN_REF.test(v)) { report(issues, `${path}.${k}`, 'Bad token reference dropped.'); continue; }
        out[k] = v;
    }
    return Object.keys(out).length ? out : null;
}

function normaliseFilters(raw, path, issues) {
    if (!isObj(raw)) return null;
    const p = IMAGE.filters.props;
    const out = {};
    for (const key of Object.keys(p)) {
        if (raw[key] === undefined) continue;
        const v = num(p[key], raw[key], `${path}.${key}`, issues, undefined);
        if (v !== undefined) out[key] = v;
    }
    return Object.keys(out).length ? out : null;
}

// ---- migration ------------------------------------------------------------

/** v1 elements (text | rectangle | image) -> v2. Pure, idempotent, never mutates. */
export function migrateElement(el) {
    if (!isObj(el)) return el;
    if (el.type === 'rectangle') {
        const { type, fill, ...rest } = el;
        return { ...rest, type: 'shape', kind: 'rect', fill: fill === undefined ? SHAPE.fill.default : fill };
    }
    if (el.type === 'text' && el.font === undefined) {
        return { ...el, font: DEFAULT_FONT };
    }
    return el;
}

export function needsMigration(el) {
    return isObj(el) && (el.type === 'rectangle' || (el.type === 'text' && el.font === undefined));
}

// ---- defaults -------------------------------------------------------------

function specDefault(spec) {
    return spec && Object.prototype.hasOwnProperty.call(spec, 'default') ? spec.default : undefined;
}

/** Full default element of a type (without id/x/y), in schema key order. */
export function defaults(type, kind) {
    if (!TYPES.includes(type)) throw new Error('Unknown element type: ' + type);
    const size = DEFAULT_SIZE[type];
    const out = {
        type,
        name: null,
        role: 'other',
        bind: null,
        x: 0,
        y: 0,
        width: size.width,
        height: size.height,
        rotation: 0,
        opacity: 1,
        locked: false,
        hidden: false,
        flip_x: false,
        flip_y: false,
        blend: 'normal',
        shadow: null,
        animation: null,
        tokens: null,
    };
    if (type === 'text') {
        Object.assign(out, {
            text: '', font: DEFAULT_FONT, fontSize: TEXT.fontSize.default, fontWeight: TEXT.fontWeight.default,
            italic: false, underline: false, strike: false, align: 'left', valign: 'top',
            lineHeight: TEXT.lineHeight.default, letterSpacing: 0, textCase: 'none', color: TEXT.color.default,
            autoFit: 'none', stroke: null,
        });
    } else if (type === 'shape') {
        Object.assign(out, {
            kind: kind || 'rect', fill: SHAPE.fill.default, stroke: null, radius: 0, sides: SHAPE.sides.default,
            points: SHAPE.points.default, inner: SHAPE.inner.default, d: null,
        });
        if (out.kind === 'line' || out.kind === 'arrow') { out.height = 4; out.fill = null; out.stroke = { color: '#18181b', width: 4, dash: 'solid', cap: 'round' }; }
    } else if (type === 'image') {
        Object.assign(out, { asset_id: null, fit: 'cover', focal: { x: 0.5, y: 0.5 }, zoom: 1, mask: 'none', radius: 0, border: null, filters: null });
    } else if (type === 'icon') {
        Object.assign(out, { icon: 'star', color: ICON.color.default, strokeWidth: ICON.strokeWidth.default });
    } else if (type === 'group') {
        out.children = [];
    }
    return out;
}

// ---- element normalisation ------------------------------------------------

/**
 * normaliseElement(raw, opts) -> element | null
 *   opts.issues   array collecting {path, message, code}
 *   opts.path     path prefix for issues
 *   opts.taken    Set of ids already used on the page (ids are made unique)
 *   opts.genId    () => id for repairs (defaults to a random generator)
 *   opts.fontKeys Set of known font keys (unknown keys fall back and are reported)
 *   opts.depth    current group depth (internal)
 *   opts.counter  {n} running element count (internal)
 */
export function normaliseElement(rawIn, opts = {}) {
    const issues = opts.issues;
    const path = opts.path || 'element';
    const depth = opts.depth || 0;
    const taken = opts.taken || new Set();
    const genId = opts.genId || (() => defaultIds.next(taken));
    const raw = migrateElement(rawIn);
    if (!isObj(raw)) { report(issues, path, 'Element is not an object; dropped.'); return null; }
    if (!TYPES.includes(raw.type)) { report(issues, path, `Unknown element type "${raw.type}"; dropped.`); return null; }
    const type = raw.type;
    const size = DEFAULT_SIZE[type];

    let id = raw.id;
    if (typeof id === 'number' && Number.isInteger(id)) id = String(id);
    let generated = false;
    if (!isValidId(id)) { if (id !== undefined && id !== null) report(issues, `${path}.id`, 'Invalid id replaced.'); id = genId(); generated = true; }
    if (!generated && taken.has(id)) { report(issues, `${path}.id`, 'Duplicate id replaced.'); id = genId(); }
    taken.add(id);

    const el = { id, type };
    el.name = raw.name === null || raw.name === undefined ? null : str(COMMON.name, raw.name, `${path}.name`, issues, null);
    el.role = enumOf('role', raw.role, `${path}.role`, issues, 'other');
    el.bind = typeof raw.bind === 'string' && BIND_KEY.test(raw.bind) ? raw.bind : null;
    el.x = num(COMMON.x, raw.x, `${path}.x`, issues, 0);
    el.y = num(COMMON.y, raw.y, `${path}.y`, issues, 0);
    el.width = num(COMMON.width, raw.width, `${path}.width`, issues, size.width);
    el.height = num(COMMON.height, raw.height, `${path}.height`, issues, size.height);
    el.rotation = num(COMMON.rotation, raw.rotation, `${path}.rotation`, issues, 0);
    el.opacity = num(COMMON.opacity, raw.opacity, `${path}.opacity`, issues, 1);
    el.locked = bool(raw.locked, false);
    el.hidden = bool(raw.hidden, false);
    el.flip_x = bool(raw.flip_x, false);
    el.flip_y = bool(raw.flip_y, false);
    el.blend = enumOf('blend', raw.blend, `${path}.blend`, issues, 'normal');
    el.shadow = normaliseShadow(raw.shadow, `${path}.shadow`, issues);
    el.animation = normaliseAnim(raw.animation, `${path}.animation`, issues);
    el.tokens = normaliseTokens(raw.tokens, `${path}.tokens`, issues);

    if (type === 'text') {
        el.text = str(TEXT.text, raw.text, `${path}.text`, issues, '');
        let font = typeof raw.font === 'string' && FONT_KEY.test(raw.font) ? raw.font : DEFAULT_FONT;
        if (raw.font !== undefined && font !== raw.font) report(issues, `${path}.font`, 'Invalid font key; default used.');
        if (opts.fontKeys && !opts.fontKeys.has(font)) { report(issues, `${path}.font`, `Unknown font "${font}"; default used.`, 'font'); font = DEFAULT_FONT; }
        el.font = font;
        el.fontSize = num(TEXT.fontSize, raw.fontSize, `${path}.fontSize`, issues, TEXT.fontSize.default);
        el.fontWeight = num(TEXT.fontWeight, raw.fontWeight, `${path}.fontWeight`, issues, TEXT.fontWeight.default);
        el.italic = bool(raw.italic, false);
        el.underline = bool(raw.underline, false);
        el.strike = bool(raw.strike, false);
        el.align = enumOf('text_align', raw.align, `${path}.align`, issues, 'left');
        el.valign = enumOf('text_valign', raw.valign, `${path}.valign`, issues, 'top');
        el.lineHeight = num(TEXT.lineHeight, raw.lineHeight, `${path}.lineHeight`, issues, TEXT.lineHeight.default);
        el.letterSpacing = num(TEXT.letterSpacing, raw.letterSpacing, `${path}.letterSpacing`, issues, 0);
        el.textCase = enumOf('text_case', raw.textCase, `${path}.textCase`, issues, 'none');
        el.color = hex(raw.color, `${path}.color`, issues, TEXT.color.default);
        el.autoFit = enumOf('auto_fit', raw.autoFit, `${path}.autoFit`, issues, 'none');
        el.stroke = colorWidth(TEXT.stroke, raw.stroke, `${path}.stroke`, issues);
    } else if (type === 'shape') {
        el.kind = enumOf('shape_kind', raw.kind, `${path}.kind`, issues, 'rect');
        el.fill = raw.fill === undefined ? SHAPE.fill.default : normalisePaint(raw.fill, `${path}.fill`, issues, SHAPE.fill.default);
        el.stroke = colorWidth(SHAPE.stroke, raw.stroke, `${path}.stroke`, issues, (r) => ({
            dash: enumOf('dash', r.dash, `${path}.stroke.dash`, issues, 'solid'),
            cap: enumOf(SHAPE.stroke.props.cap, r.cap, `${path}.stroke.cap`, issues, 'butt'),
        }));
        el.radius = num(SHAPE.radius, raw.radius, `${path}.radius`, issues, 0);
        el.sides = num(SHAPE.sides, raw.sides, `${path}.sides`, issues, SHAPE.sides.default);
        el.points = num(SHAPE.points, raw.points, `${path}.points`, issues, SHAPE.points.default);
        el.inner = num(SHAPE.inner, raw.inner, `${path}.inner`, issues, SHAPE.inner.default);
        el.d = null;
        if (el.kind === 'path') {
            const parsed = parsePath(raw.d);
            if (parsed.ok) el.d = parsed.d;
            else { report(issues, `${path}.d`, parsed.error); }
        }
    } else if (type === 'image') {
        const a = raw.asset_id;
        el.asset_id = Number.isInteger(a) && a > 0 ? a : null;
        el.fit = enumOf('image_fit', raw.fit, `${path}.fit`, issues, 'cover');
        const f = isObj(raw.focal) ? raw.focal : {};
        el.focal = { x: num(IMAGE.focal.props.x, f.x, `${path}.focal.x`, issues, 0.5), y: num(IMAGE.focal.props.y, f.y, `${path}.focal.y`, issues, 0.5) };
        el.zoom = num(IMAGE.zoom, raw.zoom, `${path}.zoom`, issues, 1);
        el.mask = enumOf('image_mask', raw.mask, `${path}.mask`, issues, 'none');
        el.radius = num(IMAGE.radius, raw.radius, `${path}.radius`, issues, 0);
        el.border = colorWidth(IMAGE.border, raw.border, `${path}.border`, issues);
        el.filters = normaliseFilters(raw.filters, `${path}.filters`, issues);
        if (el.asset_id !== null && opts.assetExists && !opts.assetExists(el.asset_id)) {
            report(issues, `${path}.asset_id`, 'Picture is not available here; slot emptied.', 'asset');
            el.asset_id = null;
        }
    } else if (type === 'icon') {
        el.icon = typeof raw.icon === 'string' && ICON_KEY.test(raw.icon) ? raw.icon : 'star';
        if (raw.icon !== undefined && el.icon !== raw.icon) report(issues, `${path}.icon`, 'Invalid icon key; default used.');
        el.color = hex(raw.color, `${path}.color`, issues, ICON.color.default);
        el.strokeWidth = num(ICON.strokeWidth, raw.strokeWidth, `${path}.strokeWidth`, issues, ICON.strokeWidth.default);
    } else if (type === 'group') {
        el.children = [];
        const list = Array.isArray(raw.children) ? raw.children : [];
        if (depth + 1 >= LIMITS.max_group_depth && list.some((c) => isObj(c) && c.type === 'group')) {
            report(issues, `${path}.children`, `Groups nest at most ${LIMITS.max_group_depth} deep; deeper groups were flattened.`, 'limit');
        }
        for (let i = 0; i < list.length; i += 1) {
            let childRaw = list[i];
            if (depth + 1 >= LIMITS.max_group_depth && isObj(childRaw) && childRaw.type === 'group') {
                // flatten the too-deep group: lift its children one level, keeping them where they were
                const lifted = Array.isArray(childRaw.children) ? childRaw.children : [];
                for (const g of lifted) {
                    const moved = isObj(g) ? { ...g, x: (Number(g.x) || 0) + (Number(childRaw.x) || 0), y: (Number(g.y) || 0) + (Number(childRaw.y) || 0) } : g;
                    if (isObj(moved) && moved.type === 'group') continue;
                    const c = normaliseElement(moved, { ...opts, path: `${path}.children[${i}]`, depth: depth + 1, taken, genId });
                    if (c) el.children.push(c);
                }
                continue;
            }
            const c = normaliseElement(childRaw, { ...opts, path: `${path}.children[${i}]`, depth: depth + 1, taken, genId });
            if (c) el.children.push(c);
        }
        if (!el.children.length) { report(issues, path, 'Empty group dropped.'); return null; }
        const b = childrenBounds(el.children);
        // Re-derive the box from the children (a stored size that disagrees is never trusted).
        return refitGroup({ ...el, width: Math.max(LIMITS.min_size, round(b.right - b.left)), height: Math.max(LIMITS.min_size, round(b.bottom - b.top)) });
    }
    return el;
}

function round(v) {
    return Math.round(v * 1000) / 1000;
}

// ---- page normalisation ---------------------------------------------------

export function normaliseBackground(raw, issues, path = 'background') {
    const spec = SCHEMA.page.background;
    const r = isObj(raw) ? raw : {};
    const out = {
        type: enumOf('background_type', r.type, `${path}.type`, issues, 'solid'),
        color: hex(r.color, `${path}.color`, issues, spec.color.default),
        gradient: r.gradient ? normaliseGradient(r.gradient, `${path}.gradient`, issues) : null,
        asset_id: Number.isInteger(r.asset_id) && r.asset_id > 0 ? r.asset_id : null,
        fit: enumOf('image_fit', r.fit, `${path}.fit`, issues, 'cover'),
    };
    if (out.type === 'gradient' && !out.gradient) out.type = 'solid';
    if (out.type === 'image' && out.asset_id === null) out.type = 'solid';
    return out;
}

function normaliseGuides(raw, issues) {
    const spec = SCHEMA.page.guides;
    if (!Array.isArray(raw)) return [];
    const out = [];
    for (const g of raw.slice(0, spec.max)) {
        if (!isObj(g) || !['x', 'y'].includes(g.axis) || typeof g.pos !== 'number' || !Number.isFinite(g.pos)) { report(issues, 'guides', 'Bad guide dropped.'); continue; }
        out.push({ axis: g.axis, pos: round(g.pos) });
    }
    return out;
}

/** Count of elements including group children. */
export function countElements(elements) {
    let n = 0;
    for (const e of elements) { n += 1; if (e.type === 'group' && e.children) n += countElements(e.children); }
    return n;
}

export function treeDepth(elements) {
    let d = 0;
    for (const e of elements) if (e.type === 'group') d = Math.max(d, 1 + treeDepth(e.children || []));
    return d;
}

/**
 * normalisePage(raw, opts) -> page
 *   opts.project {width, height}  defaults for the page size
 *   plus every normaliseElement option
 */
export function normalisePage(raw, opts = {}) {
    const issues = opts.issues;
    const r = isObj(raw) ? raw : {};
    const project = opts.project || { width: 1080, height: 1080 };
    const spec = SCHEMA.page;
    const taken = opts.taken || new Set();
    const genId = opts.genId || (() => defaultIds.next(taken));
    const list = Array.isArray(r.elements) ? r.elements : [];
    const elements = [];
    for (let i = 0; i < list.length; i += 1) {
        if (countElements(elements) >= LIMITS.max_elements_per_page) {
            report(issues, 'elements', `A page holds at most ${LIMITS.max_elements_per_page} elements; the rest were not loaded.`, 'limit');
            break;
        }
        const e = normaliseElement(list[i], { ...opts, path: `elements[${i}]`, taken, genId, depth: 0 });
        if (e) elements.push(e);
    }
    const page = {
        id: r.id === undefined ? null : r.id,
        name: r.name === null || r.name === undefined ? null : str(spec.name, r.name, 'name', issues, null),
        width: num(spec.width, r.width, 'width', issues, project.width),
        height: num(spec.height, r.height, 'height', issues, project.height),
        background: normaliseBackground(r.background ?? (isHex(r.background_color) ? { color: r.background_color } : null), issues),
        duration_ms: num(spec.duration_ms, r.duration_ms, 'duration_ms', issues, spec.duration_ms.default),
        transition: enumOf('page_transition', r.transition, 'transition', issues, 'none'),
        guides: normaliseGuides(r.guides, issues),
        elements,
        revision: Number.isInteger(r.revision) ? r.revision : 1,
    };
    return page;
}

/**
 * Validate only the page-level properties present in `changes` (page.update).
 * `current` supplies the existing background so a partial background merges.
 */
export function normalisePageChanges(changes, current = {}, issues) {
    const spec = SCHEMA.page;
    const out = {};
    const c = isObj(changes) ? changes : {};
    if ('name' in c) out.name = c.name === null ? null : str(spec.name, c.name, 'name', issues, null);
    if ('width' in c) out.width = num(spec.width, c.width, 'width', issues, current.width);
    if ('height' in c) out.height = num(spec.height, c.height, 'height', issues, current.height);
    if ('background' in c) out.background = normaliseBackground({ ...(current.background || {}), ...(isObj(c.background) ? c.background : {}) }, issues);
    if ('duration_ms' in c) out.duration_ms = num(spec.duration_ms, c.duration_ms, 'duration_ms', issues, current.duration_ms);
    if ('transition' in c) out.transition = enumOf('page_transition', c.transition, 'transition', issues, current.transition);
    if ('guides' in c) out.guides = normaliseGuides(c.guides, issues);
    return out;
}

/**
 * Validate only the properties in `changes` for an existing element and
 * return {...el, ...validChanges}; unchanged keys keep their object identity.
 */
export function applyElementChanges(el, changes, opts = {}) {
    const forbidden = new Set(['id', 'type', 'children']);
    const keys = Object.keys(changes).filter((k) => !forbidden.has(k));
    if (!keys.length) return el;
    const merged = { ...el };
    for (const k of keys) merged[k] = changes[k];
    if (el.type === 'group') merged.children = [{ type: 'shape', kind: 'rect', x: 0, y: 0, width: 1, height: 1 }];
    const n = normaliseElement(merged, { ...opts, taken: new Set(), genId: () => el.id, depth: 0 });
    if (!n) return el;
    let out = el;
    for (const k of keys) {
        if (!(k in n)) continue;
        const a = el[k];
        const b = n[k];
        const same = a === b || (a !== null && b !== null && typeof a === 'object' && typeof b === 'object' && JSON.stringify(a) === JSON.stringify(b));
        if (same) continue;
        if (out === el) out = { ...el };
        out[k] = b;
    }
    return out;
}

/** Normalise a whole document {format, pages} (no mutation). */
export function normaliseDocument(raw, opts = {}) {
    const r = isObj(raw) ? raw : {};
    const format = isObj(r.format) ? r.format : {};
    const fw = num({ min: 16, max: LIMITS.max_size }, format.width, 'format.width', opts.issues, 1080);
    const fh = num({ min: 16, max: LIMITS.max_size }, format.height, 'format.height', opts.issues, 1080);
    const src = Array.isArray(r.pages) ? r.pages : [];
    if (src.length > LIMITS.max_pages) report(opts.issues, 'pages', `A project holds at most ${LIMITS.max_pages} pages.`, 'limit');
    const pages = src.slice(0, LIMITS.max_pages).map((p) => normalisePage(p, { ...opts, project: { width: fw, height: fh }, taken: new Set() }));
    return { format: { ...format, width: fw, height: fh }, pages };
}

// ---- validation -----------------------------------------------------------

/**
 * validate(page | element, opts) -> {ok, errors, normalised}
 * Strict: any value normalisation would have to change is reported.
 */
export function validate(value, opts = {}) {
    const issues = [];
    const isPage = isObj(value) && Array.isArray(value.elements);
    const o = { ...opts, issues };
    const normalised = isPage ? normalisePage(value, o) : normaliseElement(value, { ...o, taken: new Set() });
    const errors = issues.slice();
    if (isPage) {
        const bytes = JSON.stringify(value.elements).length;
        if (bytes > LIMITS.max_json_bytes) errors.push({ path: 'elements', code: 'limit', message: `Page content is ${bytes} bytes; the limit is ${LIMITS.max_json_bytes}.` });
        if (countElements(value.elements) > LIMITS.max_elements_per_page) errors.push({ path: 'elements', code: 'limit', message: `More than ${LIMITS.max_elements_per_page} elements.` });
        if (treeDepth(value.elements) > LIMITS.max_group_depth) errors.push({ path: 'elements', code: 'limit', message: `Groups nest deeper than ${LIMITS.max_group_depth}.` });
    }
    return { ok: errors.length === 0 && normalised !== null, errors, normalised };
}

/** Serialised size of a page's content in bytes (UTF-16 units, an upper bound for ASCII-heavy JSON). */
export function pageBytes(page) {
    return JSON.stringify(page.elements).length;
}

/** The payload a page save sends: content only, no revision. */
export function pagePayload(page) {
    return {
        name: page.name,
        width: page.width,
        height: page.height,
        background: page.background,
        duration_ms: page.duration_ms,
        transition: page.transition,
        guides: page.guides,
        elements: page.elements,
    };
}

export function createElement(type, props = {}, opts = {}) {
    const base = { ...defaults(type, props.kind), ...props };
    const taken = opts.taken || new Set();
    const genId = opts.genId || (() => defaultIds.next(taken));
    if (!isValidId(base.id) || taken.has(base.id)) base.id = genId();
    return normaliseElement(base, { ...opts, taken, genId });
}

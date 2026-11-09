/*
 * Every document mutation is a named command. A command is a pure function
 *
 *     (state, args, env) -> outcome
 *
 * where `state` is the engine state ({doc:{format,pages}, activePageId,
 * selection, group, ...}), `env` carries the injected services (id generator,
 * text measurer, brand, font keys) and `outcome` is either
 *
 *     {error:{code,message}}                      nothing changes
 *     {noop:true}                                 nothing changes, not an error
 *     {pages?, selection?, group?, activePageId?, result?}   the new state parts
 *
 * Commands never mutate: they return new pages that share every untouched
 * object with the old ones. The inverse of every command is therefore exact:
 * history keeps the previous pages array and undo puts it back (history.js).
 * `runCommand` applies an outcome to a state and enforces the schema limits.
 */
import { boundsOf, clamp, makeBounds, round3 } from '../doc/geometry.js';
import {
    LIMITS, TYPES, applyElementChanges, countElements, createElement, defaults, normaliseBackground, normaliseElement,
    normalisePage, normalisePageChanges, pageBytes, treeDepth,
} from '../doc/schema.js';
import {
    cloneWithNewIds, collectIds, expandMany, find, insertInto, isGroup, removeIds, reorderIds, updateById, updateMany,
} from '../doc/tree.js';
import { ALIGN_MODES, alignDeltas, distributeDeltas, matchSizes } from './align.js';
import { parse as parseClip } from './clipboard.js';
import { dissolveGroup, makeGroup, resizeElementTo } from './group.js';
import { pruneNested, sanitise } from './selection.js';
import { clampCoord } from './transform.js';
import { growHeight } from './text-layout.js';

// ---- helpers --------------------------------------------------------------

export function err(code, message) {
    return { error: { code, message } };
}

const idList = (args) => (Array.isArray(args.ids) ? args.ids : args.ids !== undefined ? [args.ids] : args.id !== undefined ? [args.id] : []);

function pageIndex(state, pageId) {
    const id = pageId === undefined || pageId === null ? state.activePageId : pageId;
    return state.doc.pages.findIndex((p) => p.id === id);
}

/** Run `fn(page, index)` on one page. fn returns {page, selection?, group?, result?} | {error} | {noop}. */
function onPage(state, args, fn) {
    const i = pageIndex(state, args.pageId);
    if (i < 0) return err('no-page', 'That page does not exist.');
    const page = state.doc.pages[i];
    const r = fn(page, i);
    if (!r || r.error || r.noop) return r || { noop: true };
    if (r.page === page && !r.selection && !r.activePageId) return { noop: true, result: r.result };
    const pages = state.doc.pages.slice();
    pages[i] = r.page;
    return { pages, selection: r.selection, group: r.group, activePageId: r.activePageId, result: r.result };
}

function isLockedHit(hit) {
    return hit.element.locked || hit.ancestors.some((a) => a.locked);
}

/** Resolve ids against a page: existing, outermost-only, split into editable and skipped (locked). */
function resolve(page, ids, { allowLocked = false } = {}) {
    const pruned = pruneNested(page, ids.filter((id) => find(page.elements, id)));
    const targets = [];
    const skipped = [];
    for (const id of pruned) {
        const hit = find(page.elements, id);
        if (!allowLocked && isLockedHit(hit)) skipped.push(id);
        else targets.push({ id, element: hit.element, ancestors: hit.ancestors, siblings: hit.siblings, index: hit.index });
    }
    return { targets, skipped };
}

function idMaker(env, taken) {
    return () => {
        const id = env.genId(taken);
        taken.add(id);
        return id;
    };
}

function fitText(el, env) {
    if (el.type !== 'text' || el.autoFit !== 'grow' || !env.measure) return el;
    const h = growHeight(el, env.measure);
    return Math.abs(h - el.height) < 0.01 ? el : { ...el, height: h };
}

function normOpts(env, extra = {}) {
    return { fontKeys: env.fontKeys || undefined, ...extra };
}

function sameLevel(page, ids) {
    // all ids must share a parent; returns {parentId, ancestors} or null
    let parent;
    let anc;
    let set = false;
    for (const id of ids) {
        const hit = find(page.elements, id);
        if (!hit) return null;
        const pid = hit.ancestors.length ? hit.ancestors[hit.ancestors.length - 1].id : null;
        if (!set) { parent = pid; anc = hit.ancestors; set = true; } else if (pid !== parent) return null;
    }
    return { parentId: parent === undefined ? null : parent, ancestors: anc || [] };
}

function patchMap(targets, fn) {
    const m = new Map();
    for (const t of targets) m.set(t.id, (el) => fn(el, t));
    return m;
}

function withResult(page, elements) {
    return elements === page.elements ? page : { ...page, elements };
}

// ---- element commands -----------------------------------------------------

function add(state, args, env) {
    return onPage(state, args, (page) => {
        const { pageId: _p, index: _i, parentId: _g, select: _s, center: _c, props, element, ...inline } = args;
        const raw = element ? { ...element } : { ...inline, ...(props || {}) };
        if (!TYPES.includes(raw.type)) return err('bad-type', 'Unknown element type.');
        const base = { ...defaults(raw.type, raw.kind), ...raw };
        if (raw.x === undefined) base.x = round3((page.width - base.width) / 2);
        if (raw.y === undefined) base.y = round3((page.height - base.height) / 2);
        const taken = collectIds(page.elements);
        const issues = [];
        let el = normaliseElement(base, normOpts(env, { issues, taken, genId: idMaker(env, taken) }));
        if (!el) return err('invalid', issues[0] ? issues[0].message : 'That element is not valid.');
        el = fitText(el, env);
        let parentId = args.parentId ?? null;
        if (parentId) {
            const g = find(page.elements, parentId);
            if (!g || !isGroup(g.element)) return err('no-group', 'That group does not exist.');
            if (isLockedHit(g) || g.element.locked) return err('locked', 'That group is locked.');
        }
        const elements = insertInto(page.elements, parentId, args.index, [el]);
        return { page: withResult(page, elements), selection: args.select === false ? undefined : [el.id], result: { id: el.id, issues } };
    });
}

function remove(state, args) {
    return onPage(state, args, (page) => {
        const { targets, skipped } = resolve(page, idList(args));
        if (!targets.length) return { noop: true, result: { skipped } };
        const ids = new Set(targets.map((t) => t.id));
        const elements = removeIds(page.elements, ids);
        const sel = state.selection.filter((s) => find(elements, s));
        return { page: withResult(page, elements), selection: sel, result: { removed: [...ids], skipped } };
    });
}

function update(state, args, env) {
    const changes = args.changes || {};
    return onPage(state, args, (page) => {
        const { targets, skipped } = resolve(page, idList(args), { allowLocked: false });
        // Locked elements may still be unlocked / hidden / renamed.
        const lockedOk = idList(args).map((id) => find(page.elements, id)).filter((h) => h && isLockedHit(h) && Object.keys(changes).every((k) => ['locked', 'hidden', 'name'].includes(k)));
        const all = targets.concat(lockedOk.map((h) => ({ id: h.element.id, element: h.element })));
        if (!all.length) return { noop: true, result: { skipped } };
        const per = args.perElement || {};
        const patches = patchMap(all, (el) => {
            let c = per[el.id] ? { ...changes, ...per[el.id] } : changes;
            if (el.type === 'group') {
                const { width, height, ...rest } = c;
                c = rest; // a group's size is derived; resize it with element.resize
            }
            const n = applyElementChanges(el, c, normOpts(env));
            return n === el ? el : fitText(n, env);
        });
        const elements = updateMany(page.elements, patches);
        return { page: withResult(page, elements), result: { skipped } };
    });
}

function setBoxes(page, boxes, env, { allowLocked = false } = {}) {
    const { targets, skipped } = resolve(page, Object.keys(boxes), { allowLocked });
    if (!targets.length) return { noop: true, result: { skipped } };
    const patches = patchMap(targets, (el) => {
        const b = boxes[el.id];
        const box = {
            x: round3(clampCoord(b.x ?? el.x)),
            y: round3(clampCoord(b.y ?? el.y)),
            width: round3(clamp(b.width ?? el.width, LIMITS.min_size, LIMITS.max_size)),
            height: round3(clamp(b.height ?? el.height, LIMITS.min_size, LIMITS.max_size)),
        };
        if (b.rotation !== undefined) box.rotation = round3(clamp(b.rotation, -360, 360));
        if (b.fontSize !== undefined) box.fontSize = round3(clamp(b.fontSize, 4, 2000));
        let next;
        if (box.width === el.width && box.height === el.height && box.fontSize === undefined) {
            next = { ...el, x: box.x, y: box.y };
            if (box.rotation !== undefined) next.rotation = box.rotation;
        } else next = resizeElementTo(el, box, !!b.scaleFont);
        if (next.x === el.x && next.y === el.y && next.width === el.width && next.height === el.height && next.rotation === el.rotation && next.fontSize === el.fontSize && next.children === el.children) return el;
        return fitText(next, env);
    });
    const elements = updateMany(page.elements, patches);
    return { page: withResult(page, elements), result: { skipped } };
}

function transform(state, args, env) {
    return onPage(state, args, (page) => setBoxes(page, args.boxes || {}, env));
}

function move(state, args, env) {
    return onPage(state, args, (page) => {
        let boxes = {};
        if (args.positions) {
            for (const [id, p] of Object.entries(args.positions)) boxes[id] = { x: p.x, y: p.y };
        } else {
            const ids = idList(args);
            if (args.x !== undefined || args.y !== undefined) {
                for (const id of ids) boxes[id] = { x: args.x, y: args.y };
            } else {
                const dx = Number(args.dx) || 0;
                const dy = Number(args.dy) || 0;
                for (const id of ids) {
                    const hit = find(page.elements, id);
                    if (hit) boxes[id] = { x: hit.element.x + dx, y: hit.element.y + dy };
                }
            }
        }
        const r = setBoxes(page, boxes, env);
        return r;
    });
}

function resize(state, args, env) {
    return onPage(state, args, (page) => {
        const hit = find(page.elements, args.id);
        if (!hit) return err('no-element', 'That element does not exist.');
        const el = hit.element;
        let w = args.width ?? el.width;
        let h = args.height ?? el.height;
        if (args.keepRatio) {
            if (args.width !== undefined && args.height === undefined) h = (el.height / el.width) * w;
            else if (args.height !== undefined && args.width === undefined) w = (el.width / el.height) * h;
        }
        const cx = el.x + el.width / 2;
        const cy = el.y + el.height / 2;
        const box = { x: args.x ?? (args.anchor === 'topleft' ? el.x : cx - w / 2), y: args.y ?? (args.anchor === 'topleft' ? el.y : cy - h / 2), width: w, height: h, scaleFont: !!args.scaleFont };
        return setBoxes(page, { [el.id]: box }, env);
    });
}

function rotate(state, args, env) {
    return onPage(state, args, (page) => {
        const { targets } = resolve(page, idList(args));
        if (!targets.length) return { noop: true };
        const wrap = (a) => ((a + 180) % 360 + 360) % 360 - 180;
        const boxes = {};
        if (args.unit && targets.length > 1 && args.delta !== undefined) {
            const bs = targets.map((t) => boundsOf(t.element));
            const left = Math.min(...bs.map((b) => b.left)); const right = Math.max(...bs.map((b) => b.right));
            const top = Math.min(...bs.map((b) => b.top)); const bottom = Math.max(...bs.map((b) => b.bottom));
            const px = (left + right) / 2; const py = (top + bottom) / 2;
            const rad = (args.delta * Math.PI) / 180;
            const cos = Math.cos(rad); const sin = Math.sin(rad);
            for (const t of targets) {
                const el = t.element;
                const cx = el.x + el.width / 2 - px; const cy = el.y + el.height / 2 - py;
                const nx = px + cx * cos - cy * sin; const ny = py + cx * sin + cy * cos;
                boxes[el.id] = { x: nx - el.width / 2, y: ny - el.height / 2, rotation: wrap((el.rotation || 0) + args.delta) };
            }
        } else {
            for (const t of targets) {
                const el = t.element;
                const r = args.rotation !== undefined ? args.rotation : (el.rotation || 0) + (args.delta || 0);
                boxes[el.id] = { rotation: wrap(r) };
            }
        }
        return setBoxes(page, boxes, env);
    });
}

function reorder(state, args) {
    return onPage(state, args, (page) => {
        const { targets } = resolve(page, idList(args));
        if (!targets.length) return { noop: true };
        const to = args.to;
        if (!['forward', 'back', 'front', 'back-most'].includes(to)) return err('bad-arg', 'to must be forward, back, front or back-most.');
        const elements = reorderIds(page.elements, new Set(targets.map((t) => t.id)), to);
        return { page: withResult(page, elements) };
    });
}

function duplicate(state, args, env) {
    return onPage(state, args, (page) => {
        const { targets } = resolve(page, idList(args), { allowLocked: true });
        if (!targets.length) return { noop: true };
        const off = args.inPlace ? { x: 0, y: 0 } : typeof args.offset === 'number' ? { x: args.offset, y: args.offset } : args.offset || { x: 16, y: 16 };
        const taken = collectIds(page.elements);
        const mk = idMaker(env, taken);
        const copies = [];
        const expand = new Map();
        for (const t of targets) {
            expand.set(t.id, (el) => {
                const c = cloneWithNewIds(el, mk);
                c.x = round3(clampCoord(el.x + off.x));
                c.y = round3(clampCoord(el.y + off.y));
                c.locked = false;
                copies.push(c.id);
                return [el, c];
            });
        }
        const elements = expandMany(page.elements, expand);
        return { page: withResult(page, elements), selection: copies, result: { ids: copies } };
    });
}

function group(state, args, env) {
    return onPage(state, args, (page) => {
        const { targets, skipped } = resolve(page, idList(args));
        if (skipped.length) return err('locked', 'Unlock the locked elements first.');
        if (targets.length < 1) return { noop: true };
        const level = sameLevel(page, targets.map((t) => t.id));
        if (!level) return err('mixed-parents', 'Only elements on the same level can be grouped.');
        const siblings = targets[0].siblings;
        const ids = new Set(targets.map((t) => t.id));
        const ordered = siblings.filter((e) => ids.has(e.id));
        const taken = collectIds(page.elements);
        const g = makeGroup(ordered, idMaker(env, taken)(), null);
        if (g.error) return g;
        const highest = Math.max(...ordered.map((e) => siblings.indexOf(e)));
        const pos = siblings.slice(0, highest).filter((e) => !ids.has(e.id)).length;
        let elements;
        if (level.parentId === null) {
            const rest = siblings.filter((e) => !ids.has(e.id));
            rest.splice(pos, 0, g.group);
            elements = rest;
        } else {
            elements = updateById(page.elements, level.parentId, (p) => {
                const rest = p.children.filter((e) => !ids.has(e.id));
                rest.splice(pos, 0, g.group);
                return { ...p, children: rest };
            });
        }
        const next = withResult(page, elements);
        if (treeDepth(next.elements) > LIMITS.max_group_depth) return err('depth', `Groups can nest ${LIMITS.max_group_depth} deep at most.`);
        return { page: next, selection: [g.group.id], result: { id: g.group.id } };
    });
}

function ungroup(state, args) {
    return onPage(state, args, (page) => {
        const { targets, skipped } = resolve(page, idList(args));
        const groups = targets.filter((t) => isGroup(t.element));
        if (!groups.length) return { noop: true, result: { skipped } };
        const out = [];
        const expand = new Map();
        for (const t of groups) {
            expand.set(t.id, (g) => {
                const kids = dissolveGroup(g);
                kids.forEach((k) => out.push(k.id));
                return kids;
            });
        }
        const elements = expandMany(page.elements, expand);
        return { page: withResult(page, elements), selection: out, result: { ids: out } };
    });
}

function flag(key) {
    return (state, args) => onPage(state, args, (page) => {
        const { targets } = resolve(page, idList(args), { allowLocked: true });
        if (!targets.length) return { noop: true };
        const value = typeof args.value === 'boolean' ? args.value : !targets[0].element[key];
        const patches = patchMap(targets, (el) => (el[key] === value ? el : { ...el, [key]: value }));
        const elements = updateMany(page.elements, patches);
        // a hidden or locked element cannot stay selected on the canvas unless via layers
        return { page: withResult(page, elements), result: { value } };
    });
}

function rename(state, args, env) {
    return onPage(state, args, (page) => {
        const hit = find(page.elements, args.id);
        if (!hit) return err('no-element', 'That element does not exist.');
        const name = args.name === null || args.name === '' ? null : String(args.name).slice(0, LIMITS.max_name_length);
        const elements = updateById(page.elements, args.id, (el) => (el.name === name ? el : { ...el, name }));
        return { page: withResult(page, elements) };
    });
}

function textEdit(state, args, env) {
    return onPage(state, args, (page) => {
        const hit = find(page.elements, args.id);
        if (!hit || hit.element.type !== 'text') return err('no-element', 'That text does not exist.');
        if (isLockedHit(hit)) return err('locked', 'That text is locked.');
        const text = String(args.text ?? '').slice(0, LIMITS.max_text_length);
        const elements = updateById(page.elements, args.id, (el) => (el.text === text ? el : fitText({ ...el, text }, env)));
        return { page: withResult(page, elements) };
    });
}

// ---- align / distribute / match -------------------------------------------

function alignCommand(mode) {
    return (state, args, env) => onPage(state, args, (page) => {
        const { targets } = resolve(page, idList(args));
        if (!targets.length) return { noop: true };
        const level = sameLevel(page, targets.map((t) => t.id));
        if (!level) return err('mixed-parents', 'Only elements on the same level can be aligned together.');
        const items = targets.map((t) => ({ id: t.id, bounds: boundsOf(t.element) }));
        let ref;
        const toPage = (args.to === 'page' || (args.to === undefined && targets.length === 1)) && level.parentId === null;
        if (toPage) ref = makeBounds(0, 0, page.width, page.height);
        else {
            ref = makeBounds(Math.min(...items.map((i) => i.bounds.left)), Math.min(...items.map((i) => i.bounds.top)), Math.max(...items.map((i) => i.bounds.right)), Math.max(...items.map((i) => i.bounds.bottom)));
        }
        const d = alignDeltas(items, mode, ref);
        const boxes = {};
        for (const t of targets) boxes[t.id] = { x: t.element.x + d[t.id].dx, y: t.element.y + d[t.id].dy };
        return setBoxes(page, boxes, env);
    });
}

function distributeCommand(axis) {
    return (state, args, env) => onPage(state, args, (page) => {
        const { targets } = resolve(page, idList(args));
        if (targets.length < 3) return err('too-few', 'Select at least three elements to distribute.');
        if (!sameLevel(page, targets.map((t) => t.id))) return err('mixed-parents', 'Only elements on the same level can be distributed together.');
        const d = distributeDeltas(targets.map((t) => ({ id: t.id, bounds: boundsOf(t.element) })), axis);
        const boxes = {};
        for (const t of targets) boxes[t.id] = { x: t.element.x + d[t.id].dx, y: t.element.y + d[t.id].dy };
        return setBoxes(page, boxes, env);
    });
}

function sizeMatch(state, args, env) {
    return onPage(state, args, (page) => {
        const ids = idList(args);
        const { targets } = resolve(page, ids);
        if (targets.length < 2) return err('too-few', 'Select at least two elements to match their size.');
        const ordered = ids.map((id) => targets.find((t) => t.id === id)).filter(Boolean);
        const dim = args.dimension || 'both';
        const d = matchSizes(ordered.map((t) => ({ id: t.id, width: t.element.width, height: t.element.height })), dim, args.to || 'first');
        const boxes = {};
        for (const t of ordered) {
            const r = d[t.id];
            if (!r) continue;
            const el = t.element;
            const w = r.width ?? el.width;
            const h = r.height ?? el.height;
            boxes[t.id] = { x: el.x + el.width / 2 - w / 2, y: el.y + el.height / 2 - h / 2, width: w, height: h };
        }
        return setBoxes(page, boxes, env);
    });
}

// ---- paste ----------------------------------------------------------------

function paste(state, args, env) {
    return onPage(state, args, (page) => {
        let elements = args.elements;
        let srcPage = null;
        if (typeof args.payload === 'string') {
            const parsed = parseClip(args.payload, { fontKeys: env.fontKeys, assetExists: env.assetExists });
            if (!parsed.ok) return err('bad-clipboard', parsed.reason === 'foreign' || parsed.reason === 'not-json' ? 'That is not Dot.Design content.' : 'The clipboard content could not be used (' + parsed.reason + ').');
            elements = parsed.elements;
            srcPage = parsed.page;
        } else if (Array.isArray(elements)) {
            const issues = [];
            const t0 = new Set();
            elements = elements.map((e) => normaliseElement(e, normOpts(env, { issues, taken: t0, genId: idMaker(env, t0), assetExists: env.assetExists || undefined }))).filter(Boolean);
        }
        if (!elements || !elements.length) return err('empty', 'Nothing to paste.');
        const taken = collectIds(page.elements);
        const mk = idMaker(env, taken);
        const off = args.inPlace ? { x: 0, y: 0 } : typeof args.offset === 'number' ? { x: args.offset, y: args.offset } : args.offset || { x: 16, y: 16 };
        const copies = elements.map((e) => {
            const c = cloneWithNewIds(e, mk);
            c.x = round3(clampCoord(e.x + off.x));
            c.y = round3(clampCoord(e.y + off.y));
            return fitText(c, env);
        });
        void srcPage;
        const next = withResult(page, insertInto(page.elements, null, undefined, copies));
        return { page: next, selection: copies.map((c) => c.id), group: null, result: { ids: copies.map((c) => c.id) } };
    });
}

// ---- brand ----------------------------------------------------------------

export function resolveToken(brand, token) {
    if (!brand || typeof token !== 'string') return undefined;
    let m = /^brand\.colors\.([a-z0-9-]+)$/.exec(token);
    if (m) {
        const list = Array.isArray(brand.colors) ? brand.colors : Object.entries(brand.colors || {}).map(([key, hex]) => ({ key, hex: typeof hex === 'string' ? hex : hex && hex.hex }));
        const c = list.find((x) => x && x.key === m[1]);
        return c && typeof c.hex === 'string' ? c.hex : undefined;
    }
    m = /^brand\.fonts\.(heading|body|accent)$/.exec(token);
    if (m) return brand.fonts && typeof brand.fonts[m[1]] === 'string' ? brand.fonts[m[1]] : undefined;
    return undefined;
}

/** Set a token-carrying property on an element; returns the new element or null if the prop does not apply. */
function setTokenProp(el, prop, value) {
    if (prop === 'color' && (el.type === 'text' || el.type === 'icon')) return { ...el, color: value };
    if (prop === 'fill' && el.type === 'shape') return { ...el, fill: value };
    if (prop === 'stroke_color') {
        if (el.type === 'shape' || el.type === 'text') return el.stroke ? { ...el, stroke: { ...el.stroke, color: value } } : null;
        if (el.type === 'image') return el.border ? { ...el, border: { ...el.border, color: value } } : null;
        return null;
    }
    if (prop === 'shadow_color') return el.shadow ? { ...el, shadow: { ...el.shadow, color: value } } : null;
    if (prop === 'font' && el.type === 'text') return { ...el, font: value };
    return null;
}

function brandApply(state, args, env) {
    const brand = args.brand || (env.brand && env.brand());
    if (args.update) {
        // Accepting a brand update: re-resolve every recorded token (all pages unless scoped).
        const pages = state.doc.pages.slice();
        let changed = false;
        const missing = [];
        pages.forEach((page, i) => {
            if (args.pageIds && !args.pageIds.includes(page.id)) return;
            const walkMap = new Map();
            const visit = (list) => {
                for (const el of list) {
                    if (el.tokens && !el.locked) {
                        walkMap.set(el.id, (cur) => {
                            let next = cur;
                            for (const [prop, token] of Object.entries(cur.tokens || {})) {
                                const v = resolveToken(brand, token);
                                if (v === undefined) { missing.push({ id: cur.id, token }); continue; }
                                const n = setTokenProp(next, prop, v);
                                if (n) next = n;
                            }
                            return fitText(next, env);
                        });
                    }
                    if (isGroup(el)) visit(el.children);
                }
            };
            visit(page.elements);
            const elements = updateMany(page.elements, walkMap);
            if (elements !== page.elements) { pages[i] = { ...page, elements }; changed = true; }
        });
        if (!changed) return { noop: true, result: { missing } };
        return { pages, result: { missing } };
    }
    return onPage(state, args, (page) => {
        const value = resolveToken(brand, args.token);
        if (value === undefined) return err('no-token', 'That brand colour or font is not in the brand kit.');
        if (args.prop === 'background') {
            if (!/^#/.test(value)) return err('bad-token', 'Only colours can be a background.');
            const background = normaliseBackground({ ...page.background, type: 'solid', color: value });
            return { page: { ...page, background } };
        }
        const { targets } = resolve(page, idList(args));
        if (!targets.length) return { noop: true };
        const applied = [];
        const patches = patchMap(targets, (el) => {
            const n = setTokenProp(el, args.prop, value);
            if (!n) return el;
            applied.push(el.id);
            return fitText({ ...n, tokens: { ...(el.tokens || {}), [args.prop]: args.token } }, env);
        });
        const elements = updateMany(page.elements, patches);
        return { page: withResult(page, elements), result: { applied } };
    });
}

// ---- page commands --------------------------------------------------------

function newPageId(env, state) {
    const taken = new Set(state.doc.pages.map((p) => String(p.id)));
    let id;
    do { id = env.genId(taken).replace(/^e_/, 'new_'); } while (taken.has(id));
    return id;
}

function pageAdd(state, args, env) {
    if (state.doc.pages.length >= LIMITS.max_pages) return err('limit', `A project holds at most ${LIMITS.max_pages} pages.`);
    const cur = state.doc.pages[pageIndex(state, args.after)] || state.doc.pages[state.doc.pages.length - 1];
    const base = { width: cur ? cur.width : state.doc.format.width, height: cur ? cur.height : state.doc.format.height, ...(args.page || {}) };
    const page = normalisePage({ ...base, id: newPageId(env, state), elements: args.page && args.page.elements ? args.page.elements : [], revision: 0 }, { project: state.doc.format, fontKeys: env.fontKeys, genId: undefined });
    const pages = state.doc.pages.slice();
    const at = args.index === undefined ? pages.length : clamp(args.index, 0, pages.length);
    pages.splice(at, 0, page);
    return { pages, activePageId: page.id, selection: [], group: null, result: { id: page.id } };
}

function pageRemove(state, args) {
    const i = pageIndex(state, args.id);
    if (i < 0) return err('no-page', 'That page does not exist.');
    if (state.doc.pages.length <= 1) return err('last-page', 'A project needs at least one page.');
    const pages = state.doc.pages.slice();
    pages.splice(i, 1);
    const active = pages[Math.min(i, pages.length - 1)].id;
    const wasActive = state.doc.pages[i].id === state.activePageId;
    return { pages, activePageId: wasActive ? active : state.activePageId, selection: wasActive ? [] : state.selection, group: wasActive ? null : state.group, result: { removed: args.id } };
}

function pageDuplicate(state, args, env) {
    const i = pageIndex(state, args.id);
    if (i < 0) return err('no-page', 'That page does not exist.');
    if (state.doc.pages.length >= LIMITS.max_pages) return err('limit', `A project holds at most ${LIMITS.max_pages} pages.`);
    const src = state.doc.pages[i];
    const taken = new Set();
    const mk = idMaker(env, taken);
    const copy = { ...src, id: newPageId(env, state), revision: 0, name: src.name ? `${src.name} copy`.slice(0, LIMITS.max_name_length) : null, elements: src.elements.map((e) => cloneWithNewIds(e, mk)) };
    const pages = state.doc.pages.slice();
    pages.splice(i + 1, 0, copy);
    return { pages, activePageId: copy.id, selection: [], group: null, result: { id: copy.id } };
}

function pageReorder(state, args) {
    const i = pageIndex(state, args.id);
    if (i < 0) return err('no-page', 'That page does not exist.');
    const to = clamp(Math.round(args.to), 0, state.doc.pages.length - 1);
    if (to === i) return { noop: true };
    const pages = state.doc.pages.slice();
    const [p] = pages.splice(i, 1);
    pages.splice(to, 0, p);
    return { pages };
}

function pageUpdate(state, args) {
    const i = pageIndex(state, args.id ?? args.pageId);
    if (i < 0) return err('no-page', 'That page does not exist.');
    const page = state.doc.pages[i];
    const issues = [];
    const c = normalisePageChanges(args.changes || {}, page, issues);
    let next = page;
    for (const [k, v] of Object.entries(c)) {
        if (JSON.stringify(page[k]) !== JSON.stringify(v)) { if (next === page) next = { ...page }; next[k] = v; }
    }
    if (next === page) return { noop: true };
    const pages = state.doc.pages.slice();
    pages[i] = next;
    return { pages, result: { issues } };
}

// ---- whole document -------------------------------------------------------

function replacePages(state, args, env) {
    if (!Array.isArray(args.pages) || !args.pages.length) return err('empty', 'There are no pages to apply.');
    if (args.pages.length > LIMITS.max_pages) return err('limit', `A project holds at most ${LIMITS.max_pages} pages.`);
    const issues = [];
    const existing = new Map(state.doc.pages.map((p) => [p.id, p]));
    const pages = args.pages.map((raw, i) => {
        const p = normalisePage(raw, { project: state.doc.format, issues, fontKeys: env.fontKeys, assetExists: env.assetExists, taken: new Set(), genId: undefined });
        if (p.id === null || p.id === undefined) p.id = newPageId(env, { doc: { pages: state.doc.pages.concat(args.pages.slice(0, i).map((_, k) => ({ id: `new_tmp${k}` }))) } });
        const prev = existing.get(p.id);
        p.revision = prev ? prev.revision : p.revision;
        return p;
    });
    const seen = new Set();
    for (const p of pages) { if (seen.has(p.id)) p.id = newPageId(env, { doc: { pages: pages } }); seen.add(p.id); }
    const active = pages.some((p) => p.id === state.activePageId) ? state.activePageId : pages[0].id;
    return { pages, activePageId: args.activePageId && pages.some((p) => p.id === args.activePageId) ? args.activePageId : active, selection: args.selection || [], group: null, result: { pages: pages.length, issues } };
}

function reflow(state, args, env) {
    if (!env.measure) return { noop: true };
    const pages = state.doc.pages.map((page) => {
        const patches = new Map();
        const visit = (list) => {
            for (const el of list) {
                if (el.type === 'text' && el.autoFit === 'grow') patches.set(el.id, (cur) => fitText(cur, env));
                if (isGroup(el)) visit(el.children);
            }
        };
        visit(page.elements);
        const elements = updateMany(page.elements, patches);
        return elements === page.elements ? page : { ...page, elements };
    });
    return pages.every((p, i) => p === state.doc.pages[i]) ? { noop: true } : { pages };
}

// ---- registry -------------------------------------------------------------

export const COMMANDS = {
    'element.add': add,
    'element.remove': remove,
    'element.update': update,
    'element.transform': transform,
    'element.move': move,
    'element.resize': resize,
    'element.rotate': rotate,
    'element.reorder': reorder,
    'element.duplicate': duplicate,
    'element.group': group,
    'element.ungroup': ungroup,
    'element.lock': flag('locked'),
    'element.hide': flag('hidden'),
    'element.rename': rename,
    'text.edit': textEdit,
    'page.add': pageAdd,
    'page.remove': pageRemove,
    'page.duplicate': pageDuplicate,
    'page.reorder': pageReorder,
    'page.update': pageUpdate,
    'distribute.horizontal': distributeCommand('x'),
    'distribute.vertical': distributeCommand('y'),
    'size.match': sizeMatch,
    paste,
    'brand.apply': brandApply,
    'doc.replacePages': replacePages,
    'doc.reflow': reflow,
};
for (const mode of ALIGN_MODES) COMMANDS[`align.${mode}`] = alignCommand(mode);

/** Commands that are applied without an undo step (derived state, not user intent). */
export const SYSTEM_COMMANDS = new Set(['doc.reflow']);

export const COMMAND_NAMES = Object.keys(COMMANDS);

// ---- applying an outcome --------------------------------------------------

function checkPages(prevPages, pages, live) {
    const prev = new Set(prevPages);
    for (const p of pages) {
        if (prev.has(p)) continue;
        if (countElements(p.elements) > LIMITS.max_elements_per_page) return err('limit', `A page holds at most ${LIMITS.max_elements_per_page} elements.`);
        if (treeDepth(p.elements) > LIMITS.max_group_depth) return err('depth', `Groups can nest ${LIMITS.max_group_depth} deep at most.`);
        if (!live && pageBytes(p) > LIMITS.max_json_bytes) return err('too-large', `This page has too much content (the limit is ${Math.round(LIMITS.max_json_bytes / 1024)} KB).`);
    }
    return null;
}

/**
 * runCommand(state, name, args, env, {live}) ->
 *   {ok:true, state, changed, result} | {ok:false, error}
 * `state` is returned with doc/selection/group/activePageId updated; callers
 * own history and notification.
 */
export function runCommand(state, name, args, env, opts = {}) {
    const fn = COMMANDS[name];
    if (!fn) return { ok: false, error: { code: 'unknown-command', message: `Unknown command "${name}".` } };
    let out;
    try {
        out = fn(state, args || {}, env);
    } catch (e) {
        return { ok: false, error: { code: 'command-failed', message: e && e.message ? e.message : String(e) } };
    }
    if (out.error) return { ok: false, error: out.error };
    if (out.noop || (!out.pages && out.selection === undefined && out.group === undefined && !out.activePageId)) return { ok: true, state, changed: false, result: out.result };
    const pages = out.pages || state.doc.pages;
    if (out.pages) {
        const bad = checkPages(state.doc.pages, pages, !!opts.live);
        if (bad) return { ok: false, error: bad.error };
    }
    const activePageId = out.activePageId !== undefined ? out.activePageId : (pages.some((p) => p.id === state.activePageId) ? state.activePageId : pages[0].id);
    const page = pages.find((p) => p.id === activePageId) || pages[0];
    const group = out.group !== undefined ? out.group : (state.group && find(page.elements, state.group) ? state.group : null);
    const selection = sanitise(page, out.selection !== undefined ? out.selection : state.selection);
    const next = { ...state, doc: out.pages ? { ...state.doc, pages } : state.doc, activePageId: page.id, selection, group };
    return { ok: true, state: next, changed: true, result: out.result };
}

export { createElement };

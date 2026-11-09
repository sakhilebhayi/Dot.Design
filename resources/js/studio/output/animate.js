/*
 * Animation evaluator: a PURE function of (page, time). No DOM, no clock, no randomness: the same
 * (page, t) always gives the same result, which is what makes a preview, a GIF frame and a WebM frame agree.
 *
 *   evaluate(page, tMs) -> Map<elementId, State>
 *   State = { opacity, translateX, translateY, scale, rotate, clip?, blur?, textProgress? }
 *     opacity  multiplier 0..1 on top of the element's own opacity
 *     translateX/Y  page units, scale about the element centre, rotate in degrees about the centre
 *     clip     {x0,y0,x1,y1} fractions of the element's own box that stay visible (wipes)
 *     blur     page units (standard deviation)
 *     textProgress  0..1 share of characters shown (type)
 *
 * Timeline of one element (all times from the start of the page):
 *   in    starts at in.delay_ms and lasts in.duration_ms. Before it starts the element sits in the "from" state
 *         (hidden for most presets), so a delayed entrance never flashes.
 *   loop  runs from the end of the entrance (or from 0 with no entrance) until the exit starts.
 *   out   in.delay_ms is not used here: out.delay_ms is how long before the END of the page the exit has finished
 *         (0 = the exit ends exactly when the page ends). It never starts before the entrance ended.
 * Only top-level elements animate (a group animates as one piece); children of a group are not animated on their own.
 */

export const IN_PRESETS = ['none', 'fade', 'rise', 'drop', 'slide-left', 'slide-right', 'zoom-in', 'pop', 'wipe-left', 'wipe-up', 'type', 'blur-in'];
export const OUT_PRESETS = ['none', 'fade', 'sink', 'slide-left', 'slide-right', 'zoom-out', 'wipe-left'];
export const LOOP_PRESETS = ['none', 'pulse', 'float', 'spin', 'wiggle'];
export const EASINGS = ['linear', 'ease-out', 'ease-in-out', 'spring'];
export const DEFAULT_PAGE_MS = 5000;
export const DEFAULT_IN_MS = 600;
export const DEFAULT_LOOP_MS = 2000;

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const TAU = Math.PI * 2;

export function ease(name, p) {
    const x = clamp(p, 0, 1);
    if (x === 0 || x === 1) return x;
    switch (name) {
        case 'linear': return x;
        case 'ease-in-out': return x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2;
        case 'spring': return 1 - Math.exp(-7 * x) * Math.cos(2.5 * Math.PI * x);
        case 'ease-out':
        default: return 1 - Math.pow(1 - x, 3);
    }
}

function backOut(x) {
    if (x <= 0) return 0;
    if (x >= 1) return 1;
    const c1 = 1.70158;
    const c3 = c1 + 1;
    return 1 + c3 * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2);
}

export function pageDuration(page) {
    const d = Number(page && page.duration_ms);
    return Number.isFinite(d) && d > 0 ? d : DEFAULT_PAGE_MS;
}

const hasPreset = (phase) => !!phase && typeof phase.preset === 'string' && phase.preset !== 'none';

/** Start and end (ms) of each phase of an element, or null for a phase it does not have. */
export function elementTimeline(el, duration) {
    const a = el && el.animation;
    if (!a) return { in: null, out: null, loop: null };
    const out = { in: null, out: null, loop: null };
    let inEnd = 0;
    if (hasPreset(a.in)) {
        const start = Math.max(0, Number(a.in.delay_ms) || 0);
        const dur = Math.max(1, Number(a.in.duration_ms) || DEFAULT_IN_MS);
        out.in = { start, end: start + dur, duration: dur, preset: a.in.preset, easing: a.in.easing || 'ease-out' };
        inEnd = out.in.end;
    }
    let outStart = duration;
    if (hasPreset(a.out)) {
        const dur = Math.max(1, Number(a.out.duration_ms) || DEFAULT_IN_MS);
        const hold = Math.max(0, Number(a.out.delay_ms) || 0);
        const end = duration - hold;
        const start = Math.max(inEnd, end - dur);
        out.out = { start, end: start + dur, duration: dur, preset: a.out.preset, easing: a.out.easing || 'ease-out' };
        outStart = start;
    }
    if (hasPreset(a.loop)) {
        const period = Math.max(1, Number(a.loop.duration_ms) || DEFAULT_LOOP_MS);
        out.loop = { start: inEnd, end: outStart, period, preset: a.loop.preset };
    }
    return out;
}

/** Elements that carry any animation (top level, visible). */
export function animatedElements(page) {
    return (page.elements || []).filter((el) => !el.hidden && el.animation && (hasPreset(el.animation.in) || hasPreset(el.animation.out) || hasPreset(el.animation.loop)));
}

function identity() {
    return { opacity: 1, translateX: 0, translateY: 0, scale: 1, rotate: 0 };
}

function intersect(a, b) {
    if (!a) return b;
    if (!b) return a;
    return { x0: Math.max(a.x0, b.x0), y0: Math.max(a.y0, b.y0), x1: Math.min(a.x1, b.x1), y1: Math.min(a.y1, b.y1) };
}

function applyIn(state, preset, e, p, el, page) {
    const k = 1 - e; // how far from rest
    const rise = 0.06 * page.height;
    switch (preset) {
        case 'fade': state.opacity *= e; break;
        case 'rise': state.translateY += k * rise; state.opacity *= e; break;
        case 'drop': state.translateY -= k * rise; state.opacity *= e; break;
        case 'slide-left': state.translateX -= k * (el.x + el.width + 8); break;
        case 'slide-right': state.translateX += k * (page.width - el.x + 8); break;
        case 'zoom-in': state.scale *= 0.6 + 0.4 * e; state.opacity *= clamp(e, 0, 1); break;
        case 'pop': state.scale *= backOut(p); state.opacity *= clamp(p * 4, 0, 1); break;
        case 'wipe-left': state.clip = intersect(state.clip, { x0: 0, y0: 0, x1: clamp(e, 0, 1), y1: 1 }); break;
        case 'wipe-up': state.clip = intersect(state.clip, { x0: 0, y0: 1 - clamp(e, 0, 1), x1: 1, y1: 1 }); break;
        case 'type':
            if (el.type === 'text') state.textProgress = clamp(e, 0, 1);
            else state.clip = intersect(state.clip, { x0: 0, y0: 0, x1: clamp(e, 0, 1), y1: 1 });
            break;
        case 'blur-in': state.blur = (state.blur || 0) + k * 0.02 * Math.min(page.width, page.height); state.opacity *= clamp(e, 0, 1); break;
        default: break;
    }
}

function applyOut(state, preset, e, el, page) {
    const sink = 0.06 * page.height;
    switch (preset) {
        case 'fade': state.opacity *= 1 - e; break;
        case 'sink': state.translateY += e * sink; state.opacity *= 1 - e; break;
        case 'slide-left': state.translateX -= e * (el.x + el.width + 8); break;
        case 'slide-right': state.translateX += e * (page.width - el.x + 8); break;
        case 'zoom-out': state.scale *= 1 - 0.4 * e; state.opacity *= 1 - clamp(e, 0, 1); break;
        case 'wipe-left': state.clip = intersect(state.clip, { x0: 0, y0: 0, x1: 1 - clamp(e, 0, 1), y1: 1 }); break;
        default: break;
    }
}

function applyLoop(state, preset, phase, el, page) {
    const s = Math.sin(TAU * phase);
    switch (preset) {
        case 'pulse': state.scale *= 1 + 0.05 * s; break;
        case 'float': state.translateY += 0.012 * Math.min(page.width, page.height) * s; break;
        case 'spin': state.rotate += 360 * phase; break;
        case 'wiggle': state.rotate += 4 * Math.sin(TAU * phase * 3) * (1 - phase * 0.5); break;
        default: break;
    }
}

/** State of one element at time t, or null when it has no animation. */
export function evaluateElement(el, tMs, page, duration = pageDuration(page)) {
    if (!el.animation) return null;
    const tl = elementTimeline(el, duration);
    if (!tl.in && !tl.out && !tl.loop) return null;
    const t = Number.isFinite(tMs) ? tMs : 0;
    const state = identity();
    if (tl.in) {
        const p = clamp((t - tl.in.start) / tl.in.duration, 0, 1);
        if (p < 1) applyIn(state, tl.in.preset, ease(tl.in.easing, p), p, el, page);
    }
    if (tl.out && t > tl.out.start) {
        const p = clamp((t - tl.out.start) / tl.out.duration, 0, 1);
        applyOut(state, tl.out.preset, ease(tl.out.easing, p), el, page);
    }
    if (tl.loop && t >= tl.loop.start && t < tl.loop.end) {
        const phase = (((t - tl.loop.start) % tl.loop.period) + tl.loop.period) % tl.loop.period / tl.loop.period;
        applyLoop(state, tl.loop.preset, phase, el, page);
    }
    state.opacity = clamp(state.opacity, 0, 1);
    if (state.clip && (state.clip.x1 - state.clip.x0 >= 1 && state.clip.y1 - state.clip.y0 >= 1)) delete state.clip;
    if (state.blur !== undefined && state.blur < 0.01) delete state.blur;
    return state;
}

export function evaluate(page, tMs, opts = {}) {
    const duration = opts.duration || pageDuration(page);
    const map = new Map();
    for (const el of page.elements || []) {
        if (el.hidden) continue;
        const s = evaluateElement(el, tMs, page, duration);
        if (s) map.set(el.id, s);
    }
    return map;
}

export const isIdentity = (s) => s.opacity === 1 && s.translateX === 0 && s.translateY === 0 && s.scale === 1 && s.rotate === 0 && !s.clip && !s.blur && s.textProgress === undefined;

// ---- reading order, stagger, smart defaults --------------------------------------

/** Top-down, left-to-right, with elements on about the same row treated as a row. */
export function readingOrder(page) {
    const rows = 16;
    const band = Math.max(1, page.height / rows);
    return (page.elements || [])
        .filter((el) => !el.hidden && el.role !== 'background')
        .map((el, i) => ({ el, i, row: Math.floor((el.y + el.height / 2) / band) }))
        .sort((a, b) => a.row - b.row || a.el.x - b.el.x || a.i - b.i)
        .map((o) => o.el);
}

function coversPage(el, page) {
    return el.width * el.height >= page.width * page.height * 0.85;
}

/**
 * staggerPatches(page, {stepMs, startMs}) -> [{id, animation}] : entrance delays in reading order, for elements that
 * already have an entrance. Order of delays only; presets and durations stay as they are.
 */
export function staggerPatches(page, { stepMs = 150, startMs = 0 } = {}) {
    const patches = [];
    let n = 0;
    for (const el of readingOrder(page)) {
        const a = el.animation;
        if (!a || !hasPreset(a.in)) continue;
        patches.push({ id: el.id, animation: { ...a, in: { ...a.in, delay_ms: Math.round(startMs + n * stepMs) } } });
        n += 1;
    }
    return patches;
}

const ROLE_DEFAULTS = {
    headline: { preset: 'rise', duration: 700 },
    subhead: { preset: 'fade', duration: 600 },
    body: { preset: 'fade', duration: 600 },
    logo: { preset: 'pop', duration: 600 },
    photo: { preset: 'zoom-in', duration: 800 },
    cta: { preset: 'rise', duration: 500 },
    price: { preset: 'pop', duration: 600 },
    badge: { preset: 'pop', duration: 500 },
    contact: { preset: 'fade', duration: 500 },
};

/**
 * proposeDefaults(page) -> {patches: [{id, animation}], skipped: n}: smart defaults by role (headline rises, subhead fades,
 * logo pops, picture zooms in, ...), staggered in reading order. Elements that already have an animation, backgrounds,
 * decorative shapes and anything covering the page are left alone. It only proposes: the caller applies it as one undo step.
 */
export function proposeDefaults(page, { stepMs = 180 } = {}) {
    const patches = [];
    let n = 0;
    let skipped = 0;
    for (const el of readingOrder(page)) {
        if (el.animation && (hasPreset(el.animation.in) || hasPreset(el.animation.loop))) { skipped += 1; continue; }
        let def = ROLE_DEFAULTS[el.role];
        if (!def && el.type === 'image' && !coversPage(el, page)) def = ROLE_DEFAULTS.photo;
        if (!def && el.type === 'text') def = ROLE_DEFAULTS.body;
        if (!def && el.type === 'icon') def = ROLE_DEFAULTS.badge;
        if (!def || coversPage(el, page) || el.role === 'decor') { skipped += 1; continue; }
        patches.push({ id: el.id, animation: { ...(el.animation || {}), in: { preset: def.preset, delay_ms: Math.round(n * stepMs), duration_ms: def.duration, easing: 'ease-out' } } });
        n += 1;
    }
    return { patches, skipped };
}

/** Longest end time over all phases, so the UI can warn when the page is shorter than its animations. */
export function contentEnd(page) {
    const d = pageDuration(page);
    let end = 0;
    for (const el of animatedElements(page)) {
        const tl = elementTimeline(el, d);
        if (tl.in) end = Math.max(end, tl.in.end);
    }
    return end;
}

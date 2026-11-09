/*
 * Tiny DOM helpers for the editor shell: element builder, icons, announcements.
 * No framework. Styling is always a class; the only inline style is dynamic
 * geometry (setVars / setBox), written through the CSSOM so a strict CSP is fine.
 */
const SVG_NS = 'http://www.w3.org/2000/svg';
let iconSet = {};

export function setIconSet(map) { iconSet = map && typeof map === 'object' ? map : {}; }

/** An inline icon from the design system set (24 grid, 1.75 stroke). Unknown names draw a dot. */
export function icon(name, cls = '') {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.75');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('class', ('lt-icon ' + cls).trim());
    svg.innerHTML = iconSet[name] || iconSet['circle-dot'] || '<circle cx="12" cy="12" r="2"/>';
    return svg;
}

/** Draw a library icon (resources/design/icons.json entry: {d} or {paths}) as a small SVG. */
export function libraryIcon(entry, cls = '') {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.75');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', ('st-libicon ' + cls).trim());
    const ds = typeof entry === 'string' ? [entry] : Array.isArray(entry && entry.paths) ? entry.paths.map((p) => (typeof p === 'string' ? p : p.d)) : entry && entry.d ? [entry.d] : [];
    for (const d of ds) {
        const p = document.createElementNS(SVG_NS, 'path');
        p.setAttribute('d', d);
        svg.appendChild(p);
    }
    return svg;
}

function append(parent, child) {
    if (child === null || child === undefined || child === false) return;
    if (Array.isArray(child)) { child.forEach((c) => append(parent, c)); return; }
    parent.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
}

/**
 * h('button', {class:'lt-btn', onClick: fn, aria: {label:'x'}, data: {id:1}, attrs: {type:'button'}}, 'Label', icon('x'))
 * props: class | text | html is deliberately not supported (no innerHTML from data).
 */
export function h(tag, props, ...children) {
    const el = document.createElement(tag);
    if (props) {
        for (const [k, v] of Object.entries(props)) {
            if (v === undefined || v === null || v === false) continue;
            if (k === 'class') el.className = Array.isArray(v) ? v.filter(Boolean).join(' ') : v;
            else if (k === 'text') el.textContent = v;
            else if (k === 'aria') for (const [a, av] of Object.entries(v)) { if (av !== undefined && av !== null) el.setAttribute('aria-' + a, String(av)); }
            else if (k === 'data') for (const [a, av] of Object.entries(v)) { if (av !== undefined && av !== null) el.dataset[a] = String(av); }
            else if (k === 'attrs') for (const [a, av] of Object.entries(v)) { if (av !== undefined && av !== null && av !== false) el.setAttribute(a, av === true ? '' : String(av)); }
            else if (k === 'vars') setVars(el, v);
            else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
            else if (k in el && k !== 'list') { try { el[k] = v; } catch (e) { el.setAttribute(k, String(v)); } } else el.setAttribute(k, String(v));
        }
    }
    children.forEach((c) => append(el, c));
    return el;
}

export function svgEl(tag, attrs) {
    const el = document.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs || {})) el.setAttribute(k, String(v));
    return el;
}

/** Dynamic geometry/colour only: CSS custom properties through the CSSOM. */
export function setVars(el, vars) {
    for (const [k, v] of Object.entries(vars)) {
        if (v === null || v === undefined) el.style.removeProperty('--' + k);
        else el.style.setProperty('--' + k, String(v));
    }
}

/** Absolute box in CSS pixels, through the CSSOM. */
export function setBox(el, box) {
    for (const [k, v] of Object.entries(box)) {
        if (v === null || v === undefined) el.style.removeProperty(k);
        else el.style.setProperty(k, typeof v === 'number' ? v + 'px' : v);
    }
}

/** Replace an element's children, skipping null and false (replaceChildren would print them as text). */
export function replace(el, ...children) {
    const flat = children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false);
    el.replaceChildren(...flat);
    return el;
}

export function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }

export function debounce(fn, ms) {
    let t = null;
    const d = (...a) => { clearTimeout(t); t = setTimeout(() => { t = null; fn(...a); }, ms); };
    d.cancel = () => { clearTimeout(t); t = null; };
    d.flush = (...a) => { if (t !== null) { clearTimeout(t); t = null; fn(...a); } };
    return d;
}

export function throttleFrame(fn) {
    let queued = false; let last = [];
    return (...a) => {
        last = a;
        if (queued) return;
        queued = true;
        const run = () => { queued = false; fn(...last); };
        if (typeof requestAnimationFrame === 'function') requestAnimationFrame(run); else setTimeout(run, 16);
    };
}

let liveRegion = null;
/** Polite screen-reader announcement (selection, changes). */
export function announce(message) {
    if (!liveRegion) {
        liveRegion = h('div', { class: 'lt-sr', attrs: { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' } });
        document.body.appendChild(liveRegion);
    }
    liveRegion.textContent = '';
    setTimeout(() => { if (liveRegion) liveRegion.textContent = message; }, 30);
}

export function uid(prefix = 'st') {
    uid.n = (uid.n || 0) + 1;
    return prefix + '-' + uid.n;
}

export const isTouch = () => typeof matchMedia === 'function' && matchMedia('(pointer: coarse)').matches;
/*
 * Three layouts. Phone (and a phone on its side): panels are sheets over the stage. Tablet (768 to 1024 wide): the
 * left panel is docked and the inspector is a drawer the person can fold away. Desktop: everything docked.
 * "Compact" means the sheet model: below 768 px wide, or short and not wide (a phone in landscape).
 */
export const COMPACT_QUERY = '(max-width: 47.9375rem), (max-height: 30rem) and (max-width: 64rem)';
export const DRAWER_QUERY = '(min-width: 47.9376rem) and (max-width: 64rem) and (min-height: 30.0625rem)';
export const isCompact = () => typeof matchMedia === 'function' && matchMedia(COMPACT_QUERY).matches;
export const isDrawer = () => typeof matchMedia === 'function' && matchMedia(DRAWER_QUERY).matches;
/** A phone held sideways: sheets, but too short for a docked selection strip over the page. */
export const isLandscapePhone = () => typeof matchMedia === 'function' && matchMedia('(max-height: 30rem) and (max-width: 64rem)').matches;
export const layoutMode = () => (isCompact() ? 'phone' : isDrawer() ? 'tablet' : 'desktop');
export const reducedMotion = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Trap Tab inside a container (dialogs, sheets). Returns a release function. */
export function trapFocus(container) {
    const sel = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const onKey = (e) => {
        if (e.key !== 'Tab') return;
        const list = Array.from(container.querySelectorAll(sel)).filter((n) => n.offsetParent !== null || n === document.activeElement);
        if (!list.length) return;
        const first = list[0]; const last = list[list.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    container.addEventListener('keydown', onKey);
    return () => container.removeEventListener('keydown', onKey);
}

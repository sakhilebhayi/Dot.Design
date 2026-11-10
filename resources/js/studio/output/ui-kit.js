/*
 * Tiny DOM helpers shared by the export, animation and resize modules. No framework, no innerHTML with data:
 * text goes in through textContent, so nothing a user typed (a design name, a page name) can become markup.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

/** h('button', {class: 'lt-btn', onclick: fn, 'aria-label': 'x', dataset: {k: 'v'}}, 'Text', childNode) */
export function h(tag, props = {}, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(props || {})) {
        if (v === undefined || v === null || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'dataset') Object.assign(el.dataset, v);
        else if (k === 'style' && typeof v === 'object') { for (const [sk, sv] of Object.entries(v)) { if (sk.startsWith('--')) el.style.setProperty(sk, String(sv)); else el.style[sk] = sv; } }
        else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
        else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'selected' || k === 'open') el[k] = v;
        else el.setAttribute(k, v === true ? '' : String(v));
    }
    append(el, children);
    return el;
}

export function append(el, children) {
    for (const c of children.flat(Infinity)) {
        if (c === null || c === undefined || c === false) continue;
        el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    }
    return el;
}

export function clear(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
    return el;
}

const PATHS = {
    download: 'M12 3v12M7 10l5 5 5-5M5 21h14',
    play: 'M7 4.5v15l12-7.5z',
    pause: 'M8 5v14M16 5v14',
    stop: 'M6 6h12v12H6z',
    repeat: 'M17 2l4 4-4 4M3 11V10a4 4 0 0 1 4-4h14M7 22l-4-4 4-4M21 13v1a4 4 0 0 1-4 4H3',
    x: 'M18 6L6 18M6 6l12 12',
    check: 'M20 6L9 17l-5-5',
    chevron: 'M6 9l6 6 6-6',
    wand: 'M15 4V2M15 16v-2M8 9H6M24 9h-2M17.8 11.8L19 13M17.8 6.2L19 5M3 21l9-9M12.2 6.2L11 5',
    plus: 'M12 5v14M5 12h14',
    external: 'M15 3h6v6M10 14L21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6',
    warn: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
};

export function icon(name, cls = 'lt-icon lt-icon--sm') {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', name === 'play' || name === 'stop' ? 'currentColor' : 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.75');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', cls);
    const p = document.createElementNS(SVG_NS, 'path');
    p.setAttribute('d', PATHS[name] || PATHS.check);
    svg.appendChild(p);
    return svg;
}

/** Download a Uint8Array or Blob as a file. Returns the object URL's revoke timer so tests can flush it. */
export function saveFile(data, name, mime) {
    const blob = data instanceof Blob ? data : new Blob([data], { type: mime || 'application/octet-stream' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.rel = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
}

export function debounce(fn, ms) {
    let t = null;
    const d = (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    d.cancel = () => clearTimeout(t);
    return d;
}

/** Accessible toggle chip. */
export function chip({ label, pressed, onClick, title, disabled, cls = '' }) {
    return h('button', { type: 'button', class: `lt-chip ${cls}`.trim(), 'aria-pressed': pressed ? 'true' : 'false', title, disabled, onclick: onClick }, label);
}

export function field(label, control, hint) {
    return h('div', { class: 'lt-field' }, h('span', { class: 'lt-label' }, label), control, hint ? h('span', { class: 'lt-hint' }, hint) : null);
}

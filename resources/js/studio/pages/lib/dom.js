// Tiny element builder. No innerHTML anywhere: text always goes in as text.
export function h(tag, props, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(props || {})) {
        if (value === undefined || value === null || value === false) continue;
        if (key === 'class') el.className = value;
        else if (key === 'text') el.textContent = value;
        else if (key === 'dataset') Object.assign(el.dataset, value);
        else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
        else if (key === 'style' && typeof value === 'object') for (const [k, v] of Object.entries(value)) el.style.setProperty(k, v);
        else if (value === true) el.setAttribute(key, '');
        else el.setAttribute(key, String(value));
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        el.append(child.nodeType ? child : document.createTextNode(String(child)));
    }
    return el;
}

export function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
    return node;
}

/** Icon from the server-rendered sprite is not available to scripts, so scripts draw the few they need themselves. */
export function icon(name, size = 'sm') {
    const paths = {
        check: 'M20 6 9 17l-5-5',
        x: 'M18 6 6 18M6 6l12 12',
        'arrow-left': 'm12 19-7-7 7-7M19 12H5',
        'arrow-right': 'M5 12h14M12 5l7 7-7 7',
        upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
        plus: 'M5 12h14M12 5v14',
        info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20ZM12 16v-4M12 8h.01',
        alert: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20ZM12 8v4M12 16h.01',
        search: 'm21 21-4.3-4.3M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z',
        layers: 'm12 2 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 17l9 5 9-5',
    };
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', `lt-icon lt-icon--${size}`);
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.75');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', paths[name] || paths.info);
    svg.append(p);
    return svg;
}

export function readBoot(id) {
    const node = document.getElementById(id);
    if (!node) return null;
    try { return JSON.parse(node.textContent || 'null'); } catch (e) { return null; }
}

/** Debounce with a cancel handle. */
export function debounce(fn, ms) {
    let t = null;
    const wrapped = (...args) => { clearTimeout(t); t = setTimeout(() => { t = null; fn(...args); }, ms); };
    wrapped.cancel = () => { clearTimeout(t); t = null; };
    wrapped.flush = (...args) => { clearTimeout(t); t = null; fn(...args); };
    return wrapped;
}

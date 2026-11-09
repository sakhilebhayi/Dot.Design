/*
 * Small browser helpers shared by the Brand Studio, the assets library and the settings page: a fetch wrapper that never throws
 * on an HTTP error, DOM building without innerHTML, a confirm dialog on the native <dialog>, the boot JSON and the lazy font manager.
 * Anything pure and testable lives in its own module (brand-contrast, brand-palette, brand-review, assets-queue ...).
 */
import { createFontManager } from '../engine/fonts.js';

export function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * api(method, url, body) -> { ok, status, data, message, errors }. JSON in and out (a FormData body is sent as it is).
 * A network failure or a page that is not JSON becomes { ok: false, status: 0, message } so callers show a sentence, never a stack.
 */
export async function api(method, url, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() };
    const init = { method, headers, credentials: 'same-origin' };
    if (body instanceof FormData) init.body = body;
    else if (body !== undefined) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }

    let res;
    try {
        res = await fetch(url, init);
    } catch (e) {
        return { ok: false, status: 0, data: null, message: 'We could not reach the server. Check your connection and try again.', errors: {} };
    }

    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }

    return { ok: res.ok, status: res.status, data, message: res.ok ? null : messageFor(res.status, data), errors: (data && data.errors) || {} };
}

/** A plain sentence for a failed response. The server's own message wins when it has one. */
export function messageFor(status, data) {
    if (data && typeof data.message === 'string' && data.message !== '' && status !== 419 && status !== 500) return data.message;
    if (status === 401) return 'You were signed out. Sign in again, then retry.';
    if (status === 403) return 'Your role in this workspace does not allow that.';
    if (status === 404) return 'That could not be found. It may have been deleted.';
    if (status === 413) return 'That is larger than the server accepts.';
    if (status === 419) return 'This page has expired. Reload it, then try again.';
    if (status === 429) return 'Too many requests in a short time. Wait a minute and try again.';
    return 'Something went wrong on our side. Nothing was lost. Try again in a moment.';
}

/** First message of a Laravel errors bag ({field: [msg]}), or null. */
export function firstError(errors) {
    for (const key of Object.keys(errors || {})) {
        const v = errors[key];
        if (Array.isArray(v) && v.length) return v[0];
        if (typeof v === 'string') return v;
    }
    return null;
}

export function toast(message, kind = 'ok') {
    if (typeof window.dotToast === 'function') window.dotToast(message, kind);
}

/** el('button', {class: 'lt-btn', type: 'button', onclick: fn}, ['Save']) . Text children are text nodes; null and false are skipped. */
export function el(tag, attrs, children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'style' && typeof v === 'object') for (const [p, val] of Object.entries(v)) node.style.setProperty(p, val);
        else if (k === 'text') node.textContent = v;
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
        else if (k === 'dataset') for (const [dk, dv] of Object.entries(v)) node.dataset[dk] = dv;
        else node.setAttribute(k, v === true ? '' : String(v));
    }
    append(node, children);
    return node;
}

export function append(node, children) {
    for (const child of [].concat(children === undefined ? [] : children)) {
        if (child === null || child === undefined || child === false) continue;
        node.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
    }
    return node;
}

export function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
    return node;
}

/** An icon from the page's own set: the layout renders <template data-icon> only for toasts, so icons are drawn from one tiny sprite of paths. */
const ICON_PATHS = {
    plus: '<path d="M12 5v14M5 12h14"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>',
    'arrow-up': '<path d="m18 15-6-6-6 6"/>',
    'arrow-down': '<path d="m6 9 6 6 6-6"/>',
    grip: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
    upload: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
    images: '<path d="M18 22H4a2 2 0 0 1-2-2V6"/><path d="m22 13-1.3-1.3a2.4 2.4 0 0 0-3.4 0L11 18"/><circle cx="12" cy="8" r="2"/><rect width="16" height="16" x="6" y="2" rx="2"/>',
    folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    pipette: '<path d="m2 22 1-1h3l9-9M3 21v-3l9-9"/><path d="m15 6 3.4-3.4a2.1 2.1 0 1 1 3 3L18 9l.4.4a2.1 2.1 0 1 1-3 3l-3.8-3.8a2.1 2.1 0 1 1 3-3z"/>',
    refresh: '<path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/>',
    alert: '<path d="m10.3 3.9-8.5 14.7A2 2 0 0 0 3.5 21.6h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    'circle-check': '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8zM7 7h.01"/>',
};

export function icon(name, size) {
    const wrap = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    wrap.setAttribute('viewBox', '0 0 24 24');
    wrap.setAttribute('fill', 'none');
    wrap.setAttribute('stroke', 'currentColor');
    wrap.setAttribute('stroke-width', '1.75');
    wrap.setAttribute('stroke-linecap', 'round');
    wrap.setAttribute('stroke-linejoin', 'round');
    wrap.setAttribute('aria-hidden', 'true');
    wrap.setAttribute('focusable', 'false');
    wrap.setAttribute('class', 'lt-icon' + (size ? ' lt-icon--' + size : ''));
    // The paths above are fixed strings in this file, never user content.
    const doc = new DOMParser().parseFromString('<svg xmlns="http://www.w3.org/2000/svg">' + (ICON_PATHS[name] || ICON_PATHS.info) + '</svg>', 'image/svg+xml');
    for (const n of Array.from(doc.documentElement.childNodes)) wrap.appendChild(document.importNode(n, true));
    return wrap;
}

export function readBoot(id) {
    const node = document.getElementById(id);
    if (!node) return {};
    try { return JSON.parse(node.textContent || '{}'); } catch (e) { return {}; }
}

/** confirmDialog({title, body, confirmLabel, cancelLabel, danger, disabled}) -> Promise<boolean>. Built on the native <dialog> like the ds ones. */
export function confirmDialog({ title, body, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false, hideConfirm = false }) {
    return new Promise((resolve) => {
        const dialog = el('dialog', { class: 'lt-dialog', 'aria-labelledby': 'cf-title' });
        const finish = (value) => { dialog.close(); dialog.remove(); resolve(value); };
        const bodyNode = typeof body === 'string' ? el('p', { text: body }) : body;
        append(dialog, el('div', { class: 'lt-dialog__wrap' }, el('div', { class: 'lt-dialog__box lt-dialog__box--sm' }, [
            el('div', { class: 'lt-dialog__head' }, [el('h2', { class: 'lt-dialog__title', id: 'cf-title', text: title })]),
            el('div', { class: 'lt-dialog__body' }, bodyNode),
            el('div', { class: 'lt-dialog__foot' }, [
                el('button', { type: 'button', class: 'lt-btn lt-btn--secondary', onclick: () => finish(false), text: cancelLabel }),
                hideConfirm ? null : el('button', { type: 'button', class: 'lt-btn' + (danger ? ' lt-btn--danger' : ''), onclick: () => finish(true), text: confirmLabel, autofocus: true }),
            ]),
        ])));
        dialog.addEventListener('cancel', (e) => { e.preventDefault(); finish(false); });
        dialog.addEventListener('click', (e) => { if (e.target.classList && e.target.classList.contains('lt-dialog__wrap')) finish(false); });
        document.body.appendChild(dialog);
        dialog.showModal();
    });
}

// ---- fonts -----------------------------------------------------------------------------------------------------

let manager = null;

/** One font manager per page, built from the manifest the page rendered. Fonts load only when something asks for them. */
export function fontManager(manifest) {
    if (!manager) manager = createFontManager({ manifest: manifest || [] });
    return manager;
}

/** Load the regular and bold of these fonts (once), then call done() so a specimen can redraw. Failures keep the fallback stack. */
export function loadFonts(keys, done) {
    const fm = manager;
    if (!fm) return Promise.resolve();
    const wanted = Array.from(new Set(keys.filter((k) => k && fm.has(k))));
    if (!wanted.length) return Promise.resolve();
    return fm.ensureLoaded(wanted).then(() => { if (done) done(); });
}

export function fontStack(key) {
    return manager ? manager.stackFor(key) : 'sans-serif';
}

export function fontLabel(key) {
    const f = manager && manager.get(key);
    return f ? f.label : String(key || '');
}

/** Call back once when an element first comes near the viewport (or at once where IntersectionObserver is missing). */
export function whenVisible(node, fn) {
    if (!('IntersectionObserver' in window)) { fn(); return; }
    const io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) { io.disconnect(); fn(); }
    }, { rootMargin: '200px' });
    io.observe(node);
}

export function debounce(fn, ms) {
    let t = null;
    const wrapped = (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    wrapped.cancel = () => clearTimeout(t);
    return wrapped;
}

export function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    return Math.max(0, Math.round(n / 1024)) + ' KB';
}

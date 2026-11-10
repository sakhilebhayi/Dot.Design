/*
 * Preview: the design as a viewer would see it, drawn by the same renderer the
 * exports use (empty picture spots are left out). Chrome is hidden; arrow keys
 * change page; Escape leaves.
 */
import { h, icon, setVars } from './dom.js';

export function parseSvg(markup) {
    try {
        const doc = new DOMParser().parseFromString(markup, 'image/svg+xml');
        if (doc.querySelector('parsererror') || !doc.documentElement || doc.documentElement.localName !== 'svg') return null;
        return document.importNode(doc.documentElement, true);
    } catch (e) { return null; }
}

export function createPreview(ctx) {
    const studio = ctx.studio;
    let el = null; let index = 0; let token = 0;
    let prevFocus = null;

    async function draw() {
        const t = ++token;
        const pages = studio.getState().doc.pages;
        index = Math.max(0, Math.min(index, pages.length - 1));
        const p = pages[index];
        const markup = await studio.renderPageToSvgAsync(index, { idPrefix: 'pv-' });
        if (t !== token || !el) return;
        const svg = parseSvg(markup);
        el.querySelector('.st-preview__page').replaceChildren(...(svg ? [svg] : []));
        setVars(el.querySelector('.st-preview__page'), { pw: p.width, ph: p.height });
        el.querySelector('.st-preview__count').textContent = `Page ${index + 1} of ${pages.length}`;
        el.querySelector('[data-dir="-1"]').disabled = index === 0;
        el.querySelector('[data-dir="1"]').disabled = index >= pages.length - 1;
        ctx.announce && ctx.announce(`Preview, page ${index + 1} of ${pages.length}`);
    }

    function open() {
        if (el) return;
        prevFocus = document.activeElement;
        studio.endTextEdit && studio.endTextEdit(true);
        index = Math.max(0, studio.getState().doc.pages.findIndex((p) => p.id === studio.getState().activePageId));
        const exit = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: close }, icon('x'), 'Exit preview');
        const go = (d) => h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': d < 0 ? 'Previous page' : 'Next page', 'data-dir': String(d) }, onClick: () => { index += d; draw(); } }, icon(d < 0 ? 'chevron-left' : 'chevron-right'));
        el = h('div', { class: 'st-preview', attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Preview' } },
            h('div', { class: 'st-preview__bar' }, exit, h('div', { class: 'st-preview__pager' }, go(-1), h('span', { class: 'st-preview__count lt-num' }), go(1))),
            h('div', { class: 'st-preview__stage' }, h('div', { class: 'st-preview__page lt-frame' })));
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { e.preventDefault(); close(); } else if (e.key === 'ArrowRight' && index < studio.getState().doc.pages.length - 1) { index += 1; draw(); } else if (e.key === 'ArrowLeft' && index > 0) { index -= 1; draw(); }
        });
        ctx.root.appendChild(el);
        ctx.root.classList.add('is-preview');
        studio.setInputEnabled(false);
        exit.focus();
        draw();
    }
    function close() {
        if (!el) return;
        token += 1; el.remove(); el = null;
        ctx.root.classList.remove('is-preview');
        if (!ctx.readOnly) studio.setInputEnabled(true);
        if (prevFocus && prevFocus.focus) prevFocus.focus({ preventScroll: true });
        ctx.emit('preview', { open: false });
    }
    return { open, close, isOpen: () => !!el };
}

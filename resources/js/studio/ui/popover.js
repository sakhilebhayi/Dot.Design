/*
 * A small anchored popover. On a phone it becomes a bottom sheet. Closes on an
 * outside press, Escape, or when the anchor leaves the page. Focus moves in and
 * is restored to the anchor on close.
 */
import { h, icon as iconNode, isCompact, trapFocus } from './dom.js';

let openPop = null;

export function closePopover() { if (openPop) openPop.close(); }

/**
 * openPopover(anchor, (el, api) => cleanup?, {label, align:'start'|'end', side:'bottom'|'top'|'right', className, width})
 */
export function openPopover(anchor, build, opts = {}) {
    closePopover();
    const compact = isCompact() && !opts.at;
    const el = h('div', { class: ['st-pop', compact && 'st-pop--sheet', opts.className], attrs: { role: 'dialog', 'aria-label': opts.label || 'Options', tabindex: '-1' } });
    if (opts.width && !compact) el.style.setProperty('--pop-w', typeof opts.width === 'number' ? opts.width + 'px' : opts.width);
    // Inside <main> so a menu is never content outside every landmark; it is fixed-position, so where it sits in the tree is invisible.
    const host = document.querySelector('.st-stagewrap') || document.querySelector('.st-app') || document.body;
    host.appendChild(el);
    let cleanup = null;
    let closed = false;
    const prevFocus = document.activeElement;

    function place() {
        if (compact && !opts.at) return;
        if (!opts.at && (!anchor || !anchor.getBoundingClientRect)) return;
        const r = opts.at ? { left: opts.at.x, right: opts.at.x, top: opts.at.y, bottom: opts.at.y } : anchor.getBoundingClientRect();
        const pw = el.offsetWidth; const ph = el.offsetHeight;
        const vw = window.innerWidth; const vh = window.innerHeight;
        let x; let y;
        const side = opts.side || 'bottom';
        if (side === 'right') { x = r.right + 8; y = r.top; if (x + pw > vw - 8) x = r.left - pw - 8; } else {
            x = opts.align === 'end' ? r.right - pw : r.left;
            y = side === 'top' ? r.top - ph - 8 : r.bottom + 8;
            if (side === 'bottom' && y + ph > vh - 8 && r.top - ph - 8 > 8) y = r.top - ph - 8;
            if (side === 'top' && y < 8) y = r.bottom + 8;
        }
        x = Math.max(8, Math.min(x, vw - pw - 8));
        y = Math.max(8, Math.min(y, vh - ph - 8));
        el.style.setProperty('--pop-x', x + 'px');
        el.style.setProperty('--pop-y', y + 'px');
    }

    const api = {
        el,
        close() {
            if (closed) return; closed = true;
            document.removeEventListener('pointerdown', onDown, true);
            document.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', place);
            release();
            try { if (cleanup) cleanup(); } catch (e) { /* ignore */ }
            el.remove();
            if (openPop === api) openPop = null;
            if (opts.onClose) opts.onClose();
            if (opts.restoreFocus !== false && prevFocus && prevFocus.focus && document.contains(prevFocus)) prevFocus.focus({ preventScroll: true });
        },
        place,
    };
    const onDown = (e) => { if (el.contains(e.target) || (anchor && anchor.contains(e.target))) return; if (e.target.closest && e.target.closest('.st-pop')) return; api.close(); };
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); api.close(); } };
    const release = trapFocus(el);
    document.addEventListener('pointerdown', onDown, true);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', place);
    cleanup = build(el, api) || null;
    place();
    requestAnimationFrame(() => { place(); if (opts.focus !== false) { const f = el.querySelector('input:not([type=hidden]), button, select, textarea, [tabindex="0"]'); (f || el).focus({ preventScroll: true }); } });
    openPop = api;
    return api;
}

/** A menu popover: items [{label, icon?, onClick, disabled?, danger?, checked?, sep?, hint?}] with arrow-key navigation. */
export function openMenu(anchor, items, opts = {}) {
    return openPopover(anchor, (el, pop) => {
        el.classList.add('st-pop--menu');
        el.setAttribute('role', 'menu');
        const list = h('div', { class: 'st-menu' });
        const buttons = [];
        for (const it of items) {
            if (it.sep) { list.appendChild(h('div', { class: 'lt-menu__sep', attrs: { role: 'separator' } })); continue; }
            if (it.heading) { list.appendChild(h('div', { class: 'lt-menu__label', text: it.heading })); continue; }
            const b = h('button', {
                class: ['lt-menu__item', it.danger && 'is-danger', it.checked && 'is-current'],
                attrs: { type: 'button', role: it.checked !== undefined ? 'menuitemradio' : 'menuitem', 'aria-checked': it.checked === undefined ? null : String(!!it.checked), disabled: it.disabled },
                onClick: () => { if (it.disabled) return; pop.close(); if (it.onClick) it.onClick(); },
            }, it.icon ? iconNode(it.icon) : null, h('span', { class: 'st-menu__label', text: it.label }), it.hint ? h('span', { class: 'st-menu__hint', text: it.hint }) : null);
            if (it.disabled) b.disabled = true;
            buttons.push(b);
            list.appendChild(b);
        }
        el.appendChild(list);
        el.addEventListener('keydown', (e) => {
            const i = buttons.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); buttons[(i + 1) % buttons.length].focus(); } else if (e.key === 'ArrowUp') { e.preventDefault(); buttons[(i - 1 + buttons.length) % buttons.length].focus(); } else if (e.key === 'Home') { e.preventDefault(); buttons[0].focus(); } else if (e.key === 'End') { e.preventDefault(); buttons[buttons.length - 1].focus(); }
        });
        return null;
    }, { label: opts.label || 'Menu', align: opts.align, side: opts.side, width: opts.width, className: opts.className, at: opts.at });
}


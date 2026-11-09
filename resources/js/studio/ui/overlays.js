/*
 * Toasts, the dialog host and confirm(). One native <dialog> hosts every
 * registered dialog (registerDialog / openDialog); confirm() makes its own so it
 * can sit on top of one. While any dialog is open the engine ignores the
 * keyboard and pointer (setInputEnabled), so typing in a dialog never moves an
 * element behind it.
 */
import { h, icon } from './dom.js';

const TOAST_MS = { ok: 4200, warn: 7000, bad: 9000 };

export function createToasts(root) {
    const host = h('div', { class: 'st-toasts', attrs: { role: 'region', 'aria-label': 'Notifications' } });
    root.appendChild(host);
    function toast(message, opts = {}) {
        const kind = opts.kind === 'bad' || opts.kind === 'warn' ? opts.kind : 'ok';
        let timer = null;
        const close = () => { clearTimeout(timer); if (item.parentNode) item.remove(); };
        const arm = () => { clearTimeout(timer); if (!opts.sticky) timer = setTimeout(close, opts.ms || (opts.action ? Math.max(9000, TOAST_MS[kind]) : TOAST_MS[kind])); }; // a message with an action (Undo) stays longer (WCAG 2.2.1)
        const warm = !!opts.warm && kind === 'ok'; // a milestone: the warm yellow tick (first saved design, approval)
        const item = h('div', { class: `st-toast st-toast--${kind}${warm ? ' st-toast--warm' : ''}`, attrs: { role: kind === 'bad' ? 'alert' : 'status' } },
            warm ? h('span', { class: 'st-toast__tick' }, icon('check')) : icon(kind === 'ok' ? 'circle-check' : kind === 'warn' ? 'triangle-alert' : 'circle-alert'),
            h('div', { class: 'st-toast__body', text: String(message) }),
            opts.action ? h('button', { class: 'st-toast__action', attrs: { type: 'button' }, text: opts.action.label, onClick: () => { try { opts.action.onClick(); } finally { close(); } } }) : null,
            h('button', { class: 'st-toast__close', attrs: { type: 'button', 'aria-label': 'Dismiss' }, onClick: close }, icon('x', 'lt-icon--sm')));
        item.addEventListener('mouseenter', () => clearTimeout(timer));
        item.addEventListener('mouseleave', arm);
        item.addEventListener('focusin', () => clearTimeout(timer)); // reading by keyboard stops the clock too
        item.addEventListener('focusout', arm);
        while (host.children.length >= 4) host.firstChild.remove();
        host.appendChild(item);
        arm();
        return { close };
    }
    return { toast, host };
}

export function createDialogs({ root, registry, getCtx, onOpenChange }) {
    let open = 0;
    let current = null;

    function bump(d) { open += d; if (onOpenChange) onOpenChange(open > 0); }

    function shell(title, size) {
        const body = h('div', { class: 'lt-dialog__body st-dialog__body' });
        const closeBtn = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Close' } }, icon('x'));
        const head = h('header', { class: 'lt-dialog__head' }, h('h2', { class: 'lt-dialog__title', text: title }), closeBtn);
        const box = h('div', { class: `lt-dialog__box lt-dialog__box--${size === 'sm' ? 'sm' : size === 'lg' ? 'wide' : 'md'} st-dialog__box` }, head, body);
        const wrap = h('div', { class: 'lt-dialog__wrap' }, box);
        const dlg = h('dialog', { class: 'lt-dialog st-dialog', attrs: { 'aria-label': title } }, wrap);
        return { dlg, body, box, wrap, closeBtn, head };
    }

    function present(dlg, onClose, { dismissible = true } = {}) {
        root.appendChild(dlg);
        let done = false;
        const finish = (result) => { if (done) return; done = true; bump(-1); if (dlg.open) dlg.close(); dlg.remove(); onClose(result); };
        dlg.addEventListener('cancel', (e) => { e.preventDefault(); if (dismissible) finish(false); });
        dlg.addEventListener('mousedown', (e) => { if (dismissible && (e.target === dlg || e.target.classList.contains('lt-dialog__wrap'))) finish(false); });
        bump(1);
        dlg.showModal();
        return finish;
    }

    function openDialog(id, props = {}) {
        const spec = registry.dialog(id);
        if (!spec) { getCtx().toast('That panel is not available yet.', { kind: 'warn' }); return null; }
        if (current) current.close();
        const ctx = getCtx();
        const s = shell(typeof spec.title === 'function' ? spec.title(props, ctx) : spec.title || '', spec.size);
        let handle = null;
        // Mount before the dialog opens, so an `autofocus` control inside it takes the first focus.
        try { handle = spec.mount(s.body, props, ctx) || null; } catch (e) {
            console.error('[studio] dialog "' + id + '" failed to open', e);
            ctx.toast('That panel could not open. Try again.', { kind: 'bad' });
            return null;
        }
        const finish = present(s.dlg, () => { try { if (handle && handle.destroy) handle.destroy(); } catch (e) { /* a module must not trap the dialog */ } current = null; ctx.emit('dialog', { id: null, closed: id }); });
        s.closeBtn.addEventListener('click', () => finish(false));
        current = { id, close: () => finish(false) };
        ctx.emit('dialog', { id });
        return current;
    }

    function closeDialog() { if (current) current.close(); }

    function confirm({ title = 'Are you sure?', body = '', confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false } = {}) {
        return new Promise((resolve) => {
            const s = shell(title, 'sm');
            s.closeBtn.remove();
            const ok = h('button', { class: `lt-btn ${danger ? 'lt-btn--danger' : ''}`, attrs: { type: 'button' }, text: confirmLabel });
            const no = h('button', { class: 'lt-btn lt-btn--secondary', attrs: { type: 'button' }, text: cancelLabel });
            if (body) (Array.isArray(body) ? body : [body]).forEach((b) => s.body.appendChild(b instanceof Node ? b : h('p', { text: String(b) })));
            s.box.appendChild(h('footer', { class: 'lt-dialog__foot' }, no, ok));
            const finish = present(s.dlg, (r) => resolve(!!r));
            ok.addEventListener('click', () => finish(true));
            no.addEventListener('click', () => finish(false));
            (danger ? no : ok).focus();
        });
    }

    return { openDialog, closeDialog, confirm, isOpen: () => open > 0 };
}

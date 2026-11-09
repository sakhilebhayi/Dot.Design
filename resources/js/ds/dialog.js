// Dialogs use the native <dialog> element (focus trap, Escape, inert background). Openers: [data-lt-dialog-open="id"]. Closers: [data-lt-dialog-close].
// Clicking the dimmed area around the box closes it.
let opener = null;
document.addEventListener('click', (e) => {
    const open = e.target.closest('[data-lt-dialog-open]');
    if (open) {
        const d = document.getElementById(open.getAttribute('data-lt-dialog-open'));
        if (d && typeof d.showModal === 'function') {
            // Fill a shared confirm dialog from the opener: data-lt-action -> the form action, data-lt-name -> the named thing.
            const form = d.querySelector('[data-lt-dialog-form]'); if (form && open.dataset.ltAction) form.setAttribute('action', open.dataset.ltAction);
            d.querySelectorAll('[data-lt-dialog-name]').forEach((n) => { n.textContent = open.dataset.ltName || ''; });
            opener = open; d.showModal(); const f = d.querySelector('[autofocus], input:not([type=hidden]), select, textarea'); if (f) f.focus(); }
        return;
    }
    const close = e.target.closest('[data-lt-dialog-close]');
    if (close) { const d = close.closest('dialog'); if (d) d.close(); return; }
    if (e.target.classList && e.target.classList.contains('lt-dialog__wrap')) { const d = e.target.closest('dialog'); if (d) d.close(); }
});
document.addEventListener('close', (e) => { if (e.target.matches && e.target.matches('dialog.lt-dialog') && opener) { opener.focus(); opener = null; } }, true);

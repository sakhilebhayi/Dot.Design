// Menus and popovers: [data-lt-menu] > [data-lt-menu-trigger] + [data-lt-menu-panel]. Click or Enter opens, Escape and outside click close,
// arrow keys move between [role="menuitem"] elements, focus returns to the trigger.
function items(panel) { return Array.from(panel.querySelectorAll('[role="menuitem"]:not([disabled]), [role="menuitemradio"]:not([disabled])')); }

function setOpen(menu, open, focusFirst) {
    const trigger = menu.querySelector('[data-lt-menu-trigger]');
    const panel = menu.querySelector('[data-lt-menu-panel]');
    if (!trigger || !panel) return;
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', String(open));
    if (open && focusFirst) { const first = items(panel)[0]; if (first) first.focus(); }
}

function closeAll(except) {
    document.querySelectorAll('[data-lt-menu]').forEach((m) => { if (m !== except) { const p = m.querySelector('[data-lt-menu-panel]'); if (p && !p.hidden) setOpen(m, false); } });
}

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-lt-menu-trigger]');
    if (trigger) {
        const menu = trigger.closest('[data-lt-menu]');
        const panel = menu.querySelector('[data-lt-menu-panel]');
        const willOpen = panel.hidden;
        closeAll(menu);
        setOpen(menu, willOpen, e.detail === 0);
        return;
    }
    const inside = e.target.closest('[data-lt-menu-panel]');
    if (inside && e.target.closest('a, button[type="submit"], [data-lt-menu-close]')) { const m = inside.closest('[data-lt-menu]'); setOpen(m, false); return; }
    if (!inside) closeAll(null);
});

document.addEventListener('keydown', (e) => {
    const menu = e.target.closest && e.target.closest('[data-lt-menu]');
    if (e.key === 'Escape') {
        const open = Array.from(document.querySelectorAll('[data-lt-menu]')).find((m) => { const p = m.querySelector('[data-lt-menu-panel]'); return p && !p.hidden; });
        if (open) { setOpen(open, false); const t = open.querySelector('[data-lt-menu-trigger]'); if (t) t.focus(); }
        return;
    }
    if (!menu) return;
    const panel = menu.querySelector('[data-lt-menu-panel]');
    const trigger = menu.querySelector('[data-lt-menu-trigger]');
    if (e.target === trigger && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); closeAll(menu); setOpen(menu, true, true); return; }
    if (panel && !panel.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Home' || e.key === 'End')) {
        const list = items(panel); if (!list.length) return;
        e.preventDefault();
        const i = list.indexOf(document.activeElement);
        let next = e.key === 'ArrowDown' ? i + 1 : e.key === 'ArrowUp' ? i - 1 : e.key === 'Home' ? 0 : list.length - 1;
        if (next < 0) next = list.length - 1; if (next >= list.length) next = 0;
        list[next].focus();
    }
    if (e.key === 'Tab' && panel && !panel.hidden) setOpen(menu, false);
});

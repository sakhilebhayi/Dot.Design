// Tabs: [data-lt-tabs] with role="tab" buttons (aria-controls) and role="tabpanel" panels. Arrow keys move, Home/End jump.
function select(tab) {
    const list = tab.closest('[role="tablist"]');
    list.querySelectorAll('[role="tab"]').forEach((t) => {
        const on = t === tab;
        t.setAttribute('aria-selected', String(on));
        t.tabIndex = on ? 0 : -1;
        const p = document.getElementById(t.getAttribute('aria-controls'));
        if (p) p.hidden = !on;
    });
}
document.addEventListener('click', (e) => { const t = e.target.closest('[data-lt-tabs] [role="tab"]'); if (t) select(t); });
document.addEventListener('keydown', (e) => {
    const t = e.target.closest && e.target.closest('[data-lt-tabs] [role="tab"]');
    if (!t) return;
    const tabs = Array.from(t.closest('[role="tablist"]').querySelectorAll('[role="tab"]'));
    let i = tabs.indexOf(t);
    if (e.key === 'ArrowRight') i = (i + 1) % tabs.length; else if (e.key === 'ArrowLeft') i = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') i = 0; else if (e.key === 'End') i = tabs.length - 1; else return;
    e.preventDefault(); tabs[i].focus(); select(tabs[i]);
});

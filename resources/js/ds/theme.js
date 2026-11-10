// Theme: light, dark or system. The choice is stored in localStorage (key shared with the earlier shell) and set on <html data-theme>.
const KEY = 'dot-design-theme';
const root = document.documentElement;
const systemDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

const stored = () => { try { const v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : 'system'; } catch (e) { return 'system'; } };
const resolved = () => { const s = stored(); return s !== 'system' ? s : (systemDark && systemDark.matches ? 'dark' : 'light'); };

function apply(choice) {
    if (choice === 'system') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', choice);
    try { if (choice === 'system') localStorage.removeItem(KEY); else localStorage.setItem(KEY, choice); } catch (e) { /* ignore */ }
    sync();
}

function sync() {
    const choice = stored();
    document.querySelectorAll('[data-theme-set]').forEach((b) => b.setAttribute('aria-pressed', String(b.getAttribute('data-theme-set') === choice)));
    const now = resolved();
    document.querySelectorAll('[data-theme-toggle]').forEach((b) => {
        b.setAttribute('aria-pressed', String(now === 'dark'));
        b.setAttribute('aria-label', now === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
        b.querySelectorAll('[data-theme-icon]').forEach((i) => { i.hidden = i.getAttribute('data-theme-icon') !== now; });
    });
}

document.addEventListener('click', (e) => {
    const set = e.target.closest('[data-theme-set]');
    if (set) { apply(set.getAttribute('data-theme-set')); return; }
    if (e.target.closest('[data-theme-toggle]')) apply(resolved() === 'dark' ? 'light' : 'dark');
});
if (systemDark && systemDark.addEventListener) systemDark.addEventListener('change', sync);
document.addEventListener('DOMContentLoaded', sync);

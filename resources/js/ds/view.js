// Grid or list view for the designs list. The choice is stored per browser and applied to <html data-view> before paint (public/js/ds-theme.js).
const KEY = 'dot-design-view';
function sync() {
    const cur = document.documentElement.getAttribute('data-view') || 'grid';
    document.querySelectorAll('[data-lt-view]').forEach((b) => b.setAttribute('aria-pressed', String(b.getAttribute('data-lt-view') === cur)));
}
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-lt-view]'); if (!b) return;
    const v = b.getAttribute('data-lt-view');
    document.documentElement.setAttribute('data-view', v);
    try { localStorage.setItem(KEY, v); } catch (err) { /* ignore */ }
    sync();
});
document.addEventListener('DOMContentLoaded', sync);

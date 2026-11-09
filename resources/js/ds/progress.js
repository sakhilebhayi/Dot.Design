// A thin progress bar for navigations and submits: the page is working, and the control that was pressed says so (aria-busy) and cannot be pressed twice.
const root = document.documentElement;
const clear = () => { root.classList.remove('is-navigating'); document.querySelectorAll('[aria-busy="true"][data-lt-wait]').forEach((b) => { b.removeAttribute('aria-busy'); b.removeAttribute('data-lt-wait'); }); };
window.addEventListener('pageshow', clear);

document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a[href]');
    if (!a || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-lt-no-progress')) return;
    const url = new URL(a.href, location.href);
    if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search)) return;
    if (a.closest('[x-data], [wire\\:id]') && a.getAttribute('href') === '#') return;
    root.classList.add('is-navigating');
});

document.addEventListener('submit', (e) => {
    const form = e.target;
    // Livewire and other handlers call preventDefault: nothing navigates, so nothing to show.
    setTimeout(() => {
        if (e.defaultPrevented || form.hasAttribute('data-lt-no-progress') || form.target === '_blank') return;
        root.classList.add('is-navigating');
        const btn = e.submitter || form.querySelector('button[type="submit"]');
        if (btn) { btn.setAttribute('aria-busy', 'true'); btn.setAttribute('data-lt-wait', ''); }
    }, 0);
});

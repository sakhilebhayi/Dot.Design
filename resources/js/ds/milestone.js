// First-time moments. The message is rendered by the server; this remembers that it was shown (so it appears once) and lets "Got it" close it
// without a page load. If the request fails nothing is lost: the form post still works and the message may simply show once more.
const node = document.querySelector('[data-milestone]');
if (node) {
    const token = document.querySelector('meta[name="csrf-token"]');
    const remember = () => fetch(node.dataset.url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' } }).then((r) => r.ok).catch(() => false);
    let told = false;
    const tell = () => { if (told) return Promise.resolve(true); told = true; return remember(); };

    // shown long enough to be read: tell the server, so a reload does not repeat it
    let timer = null;
    const watch = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) { if (timer === null) timer = setTimeout(() => { tell(); watch.disconnect(); }, 2500); } else if (timer !== null) { clearTimeout(timer); timer = null; }
    }, { threshold: 0.6 });
    if (watch) watch.observe(node); else setTimeout(tell, 2500);

    const form = node.querySelector('form');
    if (form) form.addEventListener('submit', (e) => { e.preventDefault(); node.classList.add('is-leaving'); tell().then(() => { setTimeout(() => node.remove(), 200); }); });
}

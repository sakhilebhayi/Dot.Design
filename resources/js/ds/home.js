// Home (/dashboard): two small enhancements over a page that already works without script.
//  1. Choosing a design swaps the panel in place (the row is still a plain link to /dashboard?design=ID, which is what runs
//     when this fails, when a modifier key is held, or when script is off).
//  2. Name and Status headers sort the rows that are on the page.
const desk = document.querySelector('[data-home-desk]');
if (desk) {
    const ledger = desk.querySelector('[data-home-ledger]');
    const body = ledger.tBodies[0];
    const panel = desk.querySelector('[data-home-panel]');
    const status = desk.querySelector('[data-home-status]');
    const narrow = window.matchMedia('(max-width: 64rem)');
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)');
    let busy = 0;

    const select = async (link) => {
        const url = new URL(link.href, location.href);
        const ticket = ++busy;
        try {
            const res = await fetch(url.pathname + url.search, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const next = doc.querySelector('[data-home-panel]');
            if (!next || ticket !== busy) throw new Error('no panel');
            panel.classList.add('is-swapped');
            panel.replaceChildren(...Array.from(next.childNodes).map((node) => document.importNode(node, true)));
            body.querySelectorAll('tr').forEach((tr) => tr.removeAttribute('aria-current'));
            link.closest('tr').setAttribute('aria-current', 'true');
            history.replaceState(null, '', url.pathname + url.search);
            const title = panel.querySelector('#sel-title');
            if (status && title) status.textContent = `Showing ${title.textContent}.`;
            if (narrow.matches && title) {
                panel.scrollIntoView({ block: 'start', behavior: calm.matches ? 'auto' : 'smooth' });
                title.focus({ preventScroll: true });
            }
        } catch {
            location.href = link.href;
        }
    };

    body.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-design]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        select(link);
    });

    ledger.querySelectorAll('th button[data-sort]').forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.sort;
            const th = button.closest('th');
            const dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
            const rows = Array.from(body.rows);
            rows.sort((a, b) => (dir === 'ascending' ? 1 : -1) * (a.dataset[key] || '').localeCompare(b.dataset[key] || '', undefined, { numeric: true, sensitivity: 'base' }));
            rows.forEach((row) => body.appendChild(row));
            ledger.querySelectorAll('th[aria-sort]').forEach((h) => h.setAttribute('aria-sort', 'none'));
            th.setAttribute('aria-sort', dir);
        });
    });
}

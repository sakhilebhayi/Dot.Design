// The header search (R-52). dot-ui prints the pill and owns the keyboard, the loading line and the debounce
// (public/dot-ui/js/dot-search-results.js); this file only says where to ask. The address is printed on the pill by the layout
// (data-ds-search), the answer is groups of rows from WorkspaceSearch, already limited to what the person can open.
// Without script the pill is a plain GET form to the same address, which shows the full results page.
function mount() {
    const root = document.querySelector('.dh-search .dsr[data-dsr][data-ds-search]');
    const results = window.DotSearchResults;
    if (!root || !results || root.hasAttribute('data-ds-search-bound')) return;
    root.setAttribute('data-ds-search-bound', '');
    const url = root.getAttribute('data-ds-search');

    results.attach(root, {
        minChars: 2,
        debounce: 220,
        empty: 'Nothing here matches. Press Enter to search everywhere.',
        search: async (q, { signal }) => {
            const res = await fetch(`${url}?q=${encodeURIComponent(q)}`, {
                signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(`search ${res.status}`);
            const body = await res.json();
            return Array.isArray(body.groups) ? body.groups : [];
        },
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
else mount();
window.addEventListener('load', mount); // a late dot-ui script

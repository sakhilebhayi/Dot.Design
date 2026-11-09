// LEGACY BRIDGE (deleted with legacy.css when the old editor goes). The old editor writes Material Symbols names as text, some through Alpine x-text.
// This copies the text into data-i, which legacy-icons.css turns into an inline-SVG mask icon, and keeps it in sync when Alpine changes the text.
function mark(el) { const n = el.textContent.trim(); if (n && el.getAttribute('data-i') !== n) el.setAttribute('data-i', n); }
function scan(root) { if (root.nodeType !== 1) return; if (root.matches('.material-symbols-rounded')) mark(root); root.querySelectorAll('.material-symbols-rounded').forEach(mark); }
function init() {
    scan(document.body);
    new MutationObserver((list) => {
        for (const m of list) {
            if (m.type === 'childList') m.addedNodes.forEach((n) => { if (n.nodeType === 1) scan(n); });
            const host = m.target.nodeType === 3 ? m.target.parentElement : m.target;
            const icon = host && host.closest ? host.closest('.material-symbols-rounded') : null;
            if (icon) mark(icon);
        }
    }).observe(document.body, { childList: true, characterData: true, subtree: true });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

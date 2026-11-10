/*
 * Arrow keys inside a composite widget (a tab list, a radio group, a toolbar): Left and Right (and Up and Down) move focus
 * to the neighbouring item, Home and End to the first and last. `activate` also presses the item it lands on, which is what
 * radio groups and tabs with automatic activation do. Pure DOM, no dependencies, safe to call on any container.
 */
export function nextIndex(key, index, count, { vertical = false, both = true } = {}) {
    if (count < 1) return -1;
    const fwd = key === 'ArrowRight' || (both || vertical) && key === 'ArrowDown';
    const back = key === 'ArrowLeft' || (both || vertical) && key === 'ArrowUp';
    if (key === 'Home') return 0;
    if (key === 'End') return count - 1;
    if (vertical && !both) { if (key === 'ArrowDown') return (index + 1) % count; if (key === 'ArrowUp') return (index - 1 + count) % count; return -1; }
    if (fwd) return (index + 1) % count;
    if (back) return (index - 1 + count) % count;
    return -1;
}

export function arrowNav(container, selector, { activate = false, vertical = false, both = true } = {}) {
    container.addEventListener('keydown', (e) => {
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
        const items = Array.from(container.querySelectorAll(selector)).filter((b) => !b.disabled && !b.hidden && b.getAttribute('aria-disabled') !== 'true');
        const at = items.indexOf(document.activeElement);
        if (at < 0) return;
        const to = nextIndex(e.key, at, items.length, { vertical, both });
        if (to < 0 || to === at) return;
        e.preventDefault();
        items[to].focus();
        if (activate) items[to].click();
    });
}

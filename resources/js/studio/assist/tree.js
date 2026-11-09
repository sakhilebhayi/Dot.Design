/* Small read helpers over a page's element tree (pure, no engine import). */

export function walk(elements, visit, parent = null) {
    for (const el of elements || []) {
        visit(el, parent);
        if (el.type === 'group' && Array.isArray(el.children)) walk(el.children, visit, el);
    }
}

export function find(elements, id) {
    let hit = null;
    walk(elements, (el) => { if (el.id === id) hit = el; });
    return hit;
}

/** Immutable update of one element by id (anywhere in the tree). Returns the same array when nothing matched. */
export function updateElement(elements, id, fn) {
    let changed = false;
    const next = elements.map((el) => {
        if (el.id === id) { changed = true; return fn(el); }
        if (el.type === 'group' && Array.isArray(el.children)) {
            const kids = updateElement(el.children, id, fn);
            if (kids !== el.children) { changed = true; return { ...el, children: kids }; }
        }
        return el;
    });
    return changed ? next : elements;
}

/** Everything inside the selected elements plus the selected elements themselves. */
export function selectionScope(elements, selection) {
    const scope = new Set();
    const sel = new Set(selection || []);
    walk(elements, (el, parent) => {
        if (sel.has(el.id) || (parent && scope.has(parent.id))) scope.add(el.id);
    });
    return scope;
}

export const solidFill = (el) => (typeof el.fill === 'string' && /^#[0-9a-f]{6}/i.test(el.fill) ? el.fill.slice(0, 7) : null);

export function pageBackground(page) {
    const c = page && page.background && page.background.color;
    return typeof c === 'string' && /^#[0-9a-f]{6}/i.test(c) ? c.slice(0, 7) : '#ffffff';
}

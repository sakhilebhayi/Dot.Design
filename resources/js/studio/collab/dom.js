/*
 * A very small element builder for the collaboration modules. Text is always set as text, never as HTML, so
 * nothing a person types (a comment, a version name, a guest's name) can become markup.
 */

/** h('button', {class: 'x', onclick: fn, 'aria-label': 'y'}, 'Label', childNode) */
export function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    if (attrs) {
        for (const [key, value] of Object.entries(attrs)) {
            if (value === undefined || value === null || value === false) continue;
            if (key === 'class') el.className = value;
            else if (key === 'text') el.textContent = value;
            else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
            else if (key === 'value') el.value = value;
            else if (key === 'checked' || key === 'disabled' || key === 'hidden' || key === 'required' || key === 'readOnly') { if (value) el[key] = true; }
            else if (value === true) el.setAttribute(key, '');
            else el.setAttribute(key, String(value));
        }
    }
    append(el, kids);
    return el;
}

export function append(el, kids) {
    for (const kid of kids.flat(Infinity)) {
        if (kid === null || kid === undefined || kid === false) continue;
        el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
    }
    return el;
}

export function clear(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
    return el;
}

let counter = 0;
/** A unique id for label/for and aria wiring. */
export function uid(prefix = 'cb') {
    counter += 1;
    return `${prefix}-${counter}`;
}

/** An icon from the studio's icon set: ctx supplies icon(name) when the shell has one, otherwise nothing is drawn. */
export function icon(ctx, name, size) {
    const make = ctx && typeof ctx.icon === 'function' ? ctx.icon : null;
    if (!make) return null;
    try {
        const node = make(name, size);
        return node && node.nodeType ? node : null;
    } catch (e) {
        return null;
    }
}

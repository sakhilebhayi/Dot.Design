/*
 * Keyboard shortcut parsing and matching (pure). `mod` is Cmd on Mac and Ctrl
 * elsewhere. A spec looks like 'mod+shift+e', 'shift+1', '?', '+', 'Escape'.
 */
export function parseKeys(spec) {
    const parts = String(spec).split('+');
    // a literal plus key is written 'mod++' or '+'
    let key = parts.pop();
    if (key === '' && parts.length) key = '+';
    const want = { mod: false, shift: false, alt: false, key: key.toLowerCase() };
    for (const p of parts) { const q = p.toLowerCase(); if (q in want && q !== 'key') want[q] = true; }
    return want;
}

export function matches(e, spec, isMac = false) {
    const w = typeof spec === 'string' ? parseKeys(spec) : spec;
    const mod = isMac ? e.metaKey : e.ctrlKey;
    if (!!mod !== w.mod) return false;
    if (!!e.altKey !== w.alt) return false;
    const key = String(e.key || '').toLowerCase();
    // Shift is part of the character for symbols such as ? and +, so it is only compared for letters and digits.
    const symbol = !/^[a-z0-9]$/.test(w.key) && w.key !== 'space' && w.key !== 'esc';
    if (!symbol && !!e.shiftKey !== w.shift) return false;
    if (symbol && w.shift && !e.shiftKey) return false;
    if (w.key === 'space') return key === ' ';
    if (w.key === 'esc') return key === 'escape';
    return key === w.key;
}

/** The human label of a spec for the shortcut sheet: 'mod+shift+e' -> 'Cmd Shift E' or 'Ctrl Shift E'. */
export function labelFor(spec, isMac = false) {
    const w = parseKeys(spec);
    const out = [];
    if (w.mod) out.push(isMac ? 'Cmd' : 'Ctrl');
    if (w.alt) out.push(isMac ? 'Option' : 'Alt');
    if (w.shift) out.push('Shift');
    const k = w.key;
    out.push(k === ' ' || k === 'space' ? 'Space' : k === 'escape' || k === 'esc' ? 'Esc' : k.length === 1 ? k.toUpperCase() : k.charAt(0).toUpperCase() + k.slice(1));
    return out;
}

export function isEditableTarget(t) {
    if (!t || !t.tagName) return false;
    const tag = t.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable === true;
}

export const isMacPlatform = () => typeof navigator !== 'undefined' && /mac|iphone|ipad/i.test(navigator.platform || navigator.userAgent || '');

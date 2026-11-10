/*
 * Icon registry. Icons are looked up by key through an injected registry so the
 * library (resources/design/icons.json, drawn in this repo) can grow without
 * touching the renderer. Until it lands a built-in set of 12 keeps tests and the
 * editor working.
 *
 * Accepted JSON shapes (all tolerated):
 *   { "icons": { "star": {...} } }  or  { "star": {...} }
 *   entry: a path string | { viewBox: "0 0 24 24" | [0,0,24,24], paths: [d | {d, fill?}] } | { d }
 * Icons are drawn as 1-colour strokes (round caps/joins) unless a path entry has fill:true.
 */

export const BUILTIN_ICONS = {
    'star': 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z',
    'heart': 'M12 20.5C5 16 3 12 3 8.8 3 6.2 5 4.5 7.3 4.5c1.9 0 3.6 1 4.7 2.7 1.1-1.7 2.8-2.7 4.7-2.7C19 4.5 21 6.2 21 8.8c0 3.2-2 7.2-9 11.7z',
    'check': 'M4 12.5l5 5L20 6.5',
    'x': 'M5 5l14 14M19 5L5 19',
    'plus': 'M12 4v16M4 12h16',
    'arrow-right': 'M4 12h16M14 6l6 6-6 6',
    'arrow-up': 'M12 20V4M6 10l6-6 6 6',
    'circle': 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
    'mail': 'M3 6h18v12H3zM3 7l9 7 9-7',
    'map-pin': 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 7.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
    'home': 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    'sparkle': 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M18 6l-2.5 2.5M8.5 15.5L6 18',
};

function parseViewBox(v) {
    if (Array.isArray(v) && v.length === 4 && v.every(Number.isFinite)) return v.slice();
    if (typeof v === 'string') {
        const a = v.trim().split(/[\s,]+/).map(Number);
        if (a.length === 4 && a.every(Number.isFinite) && a[2] > 0 && a[3] > 0) return a;
    }
    return [0, 0, 24, 24];
}

function normaliseIcon(entry) {
    if (typeof entry === 'string') return { viewBox: [0, 0, 24, 24], paths: [{ d: entry, fill: false }] };
    if (!entry || typeof entry !== 'object') return null;
    const raw = Array.isArray(entry.paths) ? entry.paths : typeof entry.d === 'string' ? [entry.d] : [];
    const paths = raw
        .map((p) => (typeof p === 'string' ? { d: p, fill: false } : p && typeof p.d === 'string' ? { d: p.d, fill: !!p.fill } : null))
        .filter(Boolean);
    if (!paths.length) return null;
    return { viewBox: parseViewBox(entry.viewBox), paths };
}

export function createIconRegistry(json) {
    const map = new Map();
    const source = json && typeof json === 'object' ? (json.icons && typeof json.icons === 'object' ? json.icons : json) : {};
    for (const [key, entry] of Object.entries({ ...BUILTIN_ICONS, ...source })) {
        if (!/^[a-z0-9-]{1,40}$/.test(key)) continue;
        const icon = normaliseIcon(entry);
        if (icon) map.set(key, icon);
    }
    return {
        get: (key) => map.get(key) || null,
        has: (key) => map.has(key),
        keys: () => Array.from(map.keys()),
    };
}

export const builtinRegistry = createIconRegistry();

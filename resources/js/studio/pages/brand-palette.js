/*
 * The colour list of a brand kit, as the editor edits it: roles, extras, reordering, and "use my logo colours".
 * Pure functions over plain arrays; each returns a new list and never mutates its input.
 * A kit colour is { key, name, hex, role } with role primary | secondary | accent | neutral | extra. The four named roles
 * appear at most once. The server builds the final key from the role (or the name for extras); the key given here is only a handle.
 */
import { normaliseHex } from './brand-contrast.js';

export const SINGLE_ROLES = ['primary', 'secondary', 'accent', 'neutral'];
export const ROLE_LABELS = { primary: 'Main colour', secondary: 'Second colour', accent: 'Highlight colour', neutral: 'Neutral (text and backgrounds)', extra: 'Extra colour' };
export const MAX_COLORS = 16;

/** The starting palette of a new kit. These are starting values to replace, not anyone's brand. */
export function starterColors() {
    return [
        { key: 'primary', name: 'Main colour', hex: '#2b4c7e', role: 'primary' },
        { key: 'secondary', name: 'Second colour', hex: '#567ebb', role: 'secondary' },
        { key: 'accent', name: 'Highlight colour', hex: '#e8a33d', role: 'accent' },
        { key: 'neutral', name: 'Neutral (text and backgrounds)', hex: '#1f2933', role: 'neutral' },
    ];
}

const clone = (colors) => colors.map((c) => ({ ...c }));

function uniqueKey(colors, base) {
    const clean = (base || 'colour').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 24) || 'colour';
    let key = clean;
    for (let n = 2; colors.some((c) => c.key === key); n += 1) key = `${clean}-${n}`;
    return key;
}

/** Which of the four named roles the list does not use yet. */
export function freeRoles(colors) {
    return SINGLE_ROLES.filter((r) => !colors.some((c) => c.role === r));
}

/** Add an extra colour (never a duplicate of one already there). Returns {colors, added, reason}. */
export function addExtra(colors, hex, name) {
    const h = normaliseHex(hex);
    if (!h) return { colors, added: false, reason: 'That is not a colour code. Use something like #1a2b3c.' };
    if (colors.length >= MAX_COLORS) return { colors, added: false, reason: `A kit holds up to ${MAX_COLORS} colours.` };
    if (colors.some((c) => normaliseHex(c.hex) === h)) return { colors, added: false, reason: 'That colour is already in your kit.' };
    const next = clone(colors);
    next.push({ key: uniqueKey(next, name || 'extra'), name: name || `Extra colour ${next.filter((c) => c.role === 'extra').length + 1}`, hex: h, role: 'extra' });
    return { colors: next, added: true };
}

export function setHex(colors, index, hex) {
    const h = normaliseHex(hex);
    if (!h || !colors[index]) return colors;
    const next = clone(colors);
    next[index].hex = h;
    return next;
}

export function rename(colors, index, name) {
    if (!colors[index]) return colors;
    const next = clone(colors);
    next[index].name = String(name).slice(0, 40);
    return next;
}

export function remove(colors, index) {
    if (!colors[index]) return colors;
    const next = clone(colors);
    next.splice(index, 1);
    return next;
}

/** Move a colour from one place to another (drag and drop, or the up and down buttons). */
export function reorder(colors, from, to) {
    if (from === to || from < 0 || to < 0 || from >= colors.length || to >= colors.length) return colors;
    const next = clone(colors);
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    return next;
}

/** Give a colour a named role. The colour that held that role becomes an extra, so a role is never doubled. */
export function assignRole(colors, index, role) {
    if (!colors[index]) return colors;
    const next = clone(colors);
    if (SINGLE_ROLES.includes(role)) {
        for (const c of next) if (c.role === role) { c.role = 'extra'; c.name = c.name || 'Extra colour'; }
    }
    next[index].role = role;
    if (SINGLE_ROLES.includes(role)) next[index].name = ROLE_LABELS[role];
    return next;
}

/**
 * The colours read from a logo: what the person may accept. `response` is the palette endpoint's answer:
 * { colors: [{hex, share, is_neutral}], roles: {primary, secondary, accent, neutral} }.
 * Each candidate says whether it is already in the kit and which role the picture suggests for it.
 */
export function candidatesFrom(response, colors) {
    const list = (response && Array.isArray(response.colors) ? response.colors : []);
    const suggested = (response && response.roles) || {};
    return list.map((c) => {
        const hex = normaliseHex(c.hex);
        const role = SINGLE_ROLES.find((r) => suggested[r] && normaliseHex(suggested[r]) === hex) || null;
        return { hex, share: Number(c.share) || 0, neutral: !!c.is_neutral, suggestedRole: role, inKit: colors.some((k) => normaliseHex(k.hex) === hex) };
    }).filter((c) => c.hex);
}

/**
 * Accept one candidate. With a named role the candidate takes that role's place (the old colour of the role is kept as an
 * extra unless it is the very same colour). With 'extra' it is added. Returns {colors, changed, reason}.
 */
export function acceptCandidate(colors, candidate, role) {
    const hex = normaliseHex(candidate && candidate.hex);
    if (!hex) return { colors, changed: false, reason: 'That is not a colour code.' };

    if (role === 'extra' || !SINGLE_ROLES.includes(role)) {
        const added = addExtra(colors, hex, 'From your logo');
        return { colors: added.colors, changed: added.added, reason: added.reason };
    }

    const at = colors.findIndex((c) => c.role === role);
    if (at === -1) {
        if (colors.length >= MAX_COLORS) return { colors, changed: false, reason: `A kit holds up to ${MAX_COLORS} colours.` };
        const next = clone(colors);
        next.push({ key: role, name: ROLE_LABELS[role], hex, role });
        return { colors: next, changed: true };
    }
    if (normaliseHex(colors[at].hex) === hex) return { colors, changed: false, reason: 'That is already your ' + ROLE_LABELS[role].toLowerCase() + '.' };
    const next = clone(colors);
    next[at].hex = hex;
    return { colors: next, changed: true };
}

/** Accept the picture's own suggestion for every role it has a colour for. Returns {colors, changed}. */
export function acceptSuggested(colors, response) {
    let next = colors;
    let changed = 0;
    for (const role of SINGLE_ROLES) {
        const hex = response && response.roles && response.roles[role];
        if (!hex) continue;
        const r = acceptCandidate(next, { hex }, role);
        if (r.changed) { next = r.colors; changed += 1; }
    }
    return { colors: next, changed };
}

/** The choices for a contrast check: every colour of the kit plus white and near black, without repeats. */
export function pairChoices(colors) {
    const out = [];
    const seen = new Set();
    const push = (name, hex) => {
        const h = normaliseHex(hex);
        if (!h || seen.has(h)) return;
        seen.add(h);
        out.push({ name, hex: h });
    };
    for (const c of colors) push(c.name || c.role, c.hex);
    push('White', '#ffffff');
    push('Near black', '#111111');
    return out;
}

/*
 * The kit as the editor holds it, before it is saved: how it starts, what counts as unsaved, the request bodies a save sends,
 * and the picture changes to send after it. Pure; the editor page owns the DOM.
 */
import { starterColors } from './brand-palette.js';

export const FONT_SLOTS = ['heading', 'body', 'accent'];
export const MAX_TYPE_STYLES = 12;
export const MAX_WORDS = 12;
export const SINGLE_ASSET_ROLES = ['logo', 'logo-light', 'mark'];

export function defaultTypeStyles() {
    return [
        { key: 'display', label: 'Display', font: 'heading', size: 72, weight: 700, line_height: 1.05, letter_spacing: -0.02, case: 'none' },
        { key: 'heading', label: 'Heading', font: 'heading', size: 40, weight: 700, line_height: 1.15, letter_spacing: 0, case: 'none' },
        { key: 'body', label: 'Body text', font: 'body', size: 18, weight: 400, line_height: 1.45, letter_spacing: 0, case: 'none' },
        { key: 'caption', label: 'Small print', font: 'body', size: 12, weight: 400, line_height: 1.35, letter_spacing: 0.02, case: 'none' },
    ];
}

export function emptyKit() {
    return {
        name: '',
        colors: starterColors(),
        fonts: { heading: 'inter', body: 'inter', accent: 'inter' },
        type_styles: defaultTypeStyles(),
        guidelines: '',
        voice: { tone: '', keywords: [], avoid: [] },
        tagline: '',
        website: '',
        contacts: {},
    };
}

const text = (v) => (typeof v === 'string' ? v : '');

/** What the editor edits, taken from the server's kit (a copy: the server object is never edited). */
export function fromServer(kit) {
    if (!kit) return emptyKit();
    const voice = kit.voice || {};
    const contacts = kit.contacts && !Array.isArray(kit.contacts) ? kit.contacts : {};
    return {
        name: text(kit.name),
        colors: (kit.colors || []).map((c) => ({ key: c.key, name: c.name, hex: c.hex, role: c.role })),
        fonts: { heading: 'inter', body: 'inter', accent: 'inter', ...(kit.fonts || {}) },
        type_styles: (kit.type_styles || []).map((s) => ({ ...s })),
        guidelines: text(kit.guidelines),
        voice: { tone: text(voice.tone), keywords: [...(voice.keywords || [])], avoid: [...(voice.avoid || [])] },
        tagline: text(kit.tagline),
        website: text(kit.website),
        contacts: { ...contacts },
    };
}

/** The body of POST /design/brand/kits and PUT .../{kit}. Empty text goes as null; empty contacts are left out. */
export function toPayload(state) {
    const contacts = {};
    for (const [k, v] of Object.entries(state.contacts || {})) if (typeof v === 'string' && v.trim() !== '') contacts[k] = v.trim();
    const clean = (v) => (typeof v === 'string' && v.trim() !== '' ? v.trim() : null);
    return {
        name: state.name.trim(),
        colors: state.colors.map((c) => ({ key: c.key, name: c.name, hex: c.hex, role: c.role })),
        fonts: { ...state.fonts },
        type_styles: state.type_styles.map((s) => ({ ...s })),
        guidelines: clean(state.guidelines),
        voice: { tone: clean(state.voice.tone), keywords: [...state.voice.keywords], avoid: [...state.voice.avoid] },
        tagline: clean(state.tagline),
        website: clean(state.website),
        contacts,
    };
}

/** Stable text of the saved-or-savable parts, for the unsaved-changes check. */
export function fingerprint(state, assets) {
    return JSON.stringify([toPayload(state), (assets || []).map((a) => [a.role, a.design_asset_id, a.label || '']).sort()]);
}

export function isDirty(savedFingerprint, state, assets) {
    return savedFingerprint !== fingerprint(state, assets);
}

/**
 * The picture changes a save must send. `initial` and `desired` are lists of
 * { id (kit picture id, initial only), role, design_asset_id, label }.
 * A single-slot role (logo, logo for dark, mark) that is replaced needs only the attach: the server swaps it.
 */
export function assetOps(initial, desired) {
    const key = (a) => `${a.role}:${a.design_asset_id}`;
    const have = new Map(initial.map((a) => [key(a), a]));
    const want = new Map(desired.map((a) => [key(a), a]));

    const attach = desired.filter((a) => {
        const was = have.get(key(a));
        return !was || (was.label || '') !== (a.label || '');
    });
    const replacedSingles = new Set(attach.filter((a) => SINGLE_ASSET_ROLES.includes(a.role)).map((a) => a.role));
    const detach = initial.filter((a) => !want.has(key(a)) && !replacedSingles.has(a.role));

    return { attach, detach };
}

/** Add a word to a short list (keywords, words to avoid): trimmed, no repeats, 30 characters, 12 words. Returns {list, added, reason}. */
export function addWord(list, word) {
    const w = String(word || '').trim().replace(/\s+/g, ' ');
    if (w === '') return { list, added: false, reason: null };
    if (w.length > 30) return { list, added: false, reason: 'Keep each word or phrase to 30 characters.' };
    if (list.some((x) => x.toLowerCase() === w.toLowerCase())) return { list, added: false, reason: 'That one is already in the list.' };
    if (list.length >= MAX_WORDS) return { list, added: false, reason: `Add up to ${MAX_WORDS} words or short phrases.` };
    return { list: [...list, w], added: true };
}

/** Tone chips: the tone text is the chosen words joined by commas. These helpers read and write that one string. */
export function toneWords(tone) {
    return String(tone || '').split(',').map((s) => s.trim()).filter(Boolean);
}

export function toggleTone(tone, word) {
    const words = toneWords(tone);
    const at = words.findIndex((w) => w.toLowerCase() === word.toLowerCase());
    if (at >= 0) words.splice(at, 1); else words.push(word);
    return words.join(', ').slice(0, 160);
}

/** Pairings that work together, by font key. Heading first. All keys are from config/design_fonts.php. */
export const PAIRINGS = [
    { id: 'classic', label: 'Classic', heading: 'playfair-display', body: 'inter', accent: 'playfair-display' },
    { id: 'modern', label: 'Modern', heading: 'space-grotesk', body: 'inter', accent: 'jetbrains-mono' },
    { id: 'friendly', label: 'Friendly', heading: 'poppins', body: 'dm-sans', accent: 'caveat' },
    { id: 'bold', label: 'Bold', heading: 'anton', body: 'montserrat', accent: 'bebas-neue' },
    { id: 'editorial', label: 'Editorial', heading: 'fraunces', body: 'lora', accent: 'inter' },
    { id: 'poster', label: 'Poster', heading: 'archivo-black', body: 'dm-sans', accent: 'permanent-marker' },
    { id: 'elegant', label: 'Elegant', heading: 'cormorant-garamond', body: 'montserrat', accent: 'cormorant-garamond' },
    { id: 'warm', label: 'Warm', heading: 'merriweather', body: 'poppins', accent: 'pacifico' },
];

/** Keep only pairings whose three fonts are all available; the manifest decides, not this list. */
export function availablePairings(available) {
    const have = new Set(available);
    return PAIRINGS.filter((p) => have.has(p.heading) && have.has(p.body) && have.has(p.accent));
}

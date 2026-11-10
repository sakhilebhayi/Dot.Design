/*
 * The assistant panel's behaviour, without any DOM: what happens when a person asks for a fix, an improvement or
 * some copy, and when they press Apply. The panel (panel.js) only draws what this exposes. Everything goes through
 * ctx.api (never fetch), the engine is touched in exactly one place (applyProposal, one undo step), and nothing is
 * applied until Apply is pressed.
 */
import { quickFixes } from './heuristics.js';
import { applyProposal, previewProposal } from './ops.js';
import { createMachine } from './proposal-state.js';

export const IMPROVE_PRESETS = [
    { key: 'professional', label: 'More professional', instruction: 'Make this look more professional' },
    { key: 'headline', label: 'Make the headline stand out', instruction: 'Make the headline stand out' },
    { key: 'layout', label: 'Improve the layout', instruction: 'Improve the layout' },
    { key: 'colours', label: 'More attractive colours', instruction: 'Use more attractive colours' },
    { key: 'instagram', label: 'Suitable for Instagram', instruction: 'Make this suitable for Instagram' },
    { key: 'premium', label: 'More premium', instruction: 'Make this look more premium' },
    { key: 'readable', label: 'Easier to read', instruction: 'Make this easier to read' },
];

export const COPY_KINDS = [
    { key: 'headline', label: 'Headline' },
    { key: 'promo', label: 'Promotion line' },
    { key: 'product_description', label: 'Product description' },
    { key: 'cta', label: 'Button or call to action' },
    { key: 'caption', label: 'Social media caption' },
    { key: 'campaign_concept', label: 'Campaign idea' },
];

const OFF_MESSAGE = 'The assistant is not switched on for this workspace. Automatic fixes, templates and sizes still work.';

export function createController(ctx, options = {}) {
    const machine = options.machine || createMachine();
    const copy = { phase: 'idle', suggestions: [], message: '', dropped: 0, token: 0 };
    const copyListeners = new Set();
    const statusState = { loaded: false, enabled: !!(ctx.config.flags && ctx.config.flags.ai), message: '', usage: null };
    const statusListeners = new Set();
    const projectUrl = (suffix) => `${(ctx.config.urls && ctx.config.urls.project) || `/design/projects/${ctx.config.project.id}`}${suffix}`;

    const studio = () => ctx.studio;
    const activePage = () => { const s = studio().getState(); return s.doc.pages.find((p) => p.id === s.activePageId) || s.doc.pages[0]; };
    const selection = () => (studio().getState().selection || []).slice();

    // ------------------------------------------------------------- status

    async function loadStatus() {
        const res = await ctx.api.get('/design/assistant/status');
        if (res.ok && res.data) {
            statusState.enabled = !!res.data.enabled && !!(ctx.config.flags && ctx.config.flags.ai !== false);
            statusState.message = res.data.message || '';
            statusState.usage = res.data.usage || null;
            statusState.loaded = true;
            if (res.data.usage) machine.send({ type: 'usage', usage: res.data.usage });
        } else {
            statusState.loaded = true;
            statusState.message = 'We could not check the assistant just now.';
        }
        statusListeners.forEach((fn) => fn(statusState));
        return statusState;
    }

    /** The meter counts calls to the model, so it is re-read after each one (the server is the only source of the number). */
    async function refreshUsage() {
        const res = await ctx.api.get('/design/assistant/status');
        if (res.ok && res.data && res.data.usage) machine.send({ type: 'usage', usage: res.data.usage });
    }

    // -------------------------------------------------------- quick fixes

    function fixes() {
        const page = activePage();
        const brand = (ctx.config.brand && ctx.config.brand.colors) || [];
        return quickFixes(page, {
            palette: brand.map((c) => c.hex),
            selection: selection(),
            measure: options.measure || null,
        });
    }

    async function runAutomatic(fix) {
        if (!machine.send({ type: 'ask', source: 'auto', label: fix.label }) || machine.state.phase !== 'working') return;
        const token = machine.state.token;
        const page = activePage();
        const res = await ctx.api.post(projectUrl('/assistant/proposals'), { page_id: page.id, label: fix.label, ops: fix.ops, selection: selection() });
        return settle(token, res);
    }

    // ------------------------------------------------------------ improve

    async function ask(instruction) {
        const text = String(instruction || '').trim();
        if (text.length < 3) return;
        if (!statusState.enabled) {
            machine.send({ type: 'ask', source: 'ai', label: text });
            machine.send({ type: 'unavailable', token: machine.state.token, message: statusState.message || OFF_MESSAGE });
            return;
        }
        machine.send({ type: 'ask', source: 'ai', label: text });
        if (machine.state.phase !== 'working') return;
        const token = machine.state.token;
        const page = activePage();
        const res = await ctx.api.post(projectUrl('/assistant/improve'), { instruction: text, page_id: page.id, selection: selection() });
        return settle(token, res);
    }

    function settle(token, res) {
        const d = res.data || {};

        if (res.ok && d.status === 'ok' && d.proposal) {
            machine.send({ type: 'proposal', token, proposal: d.proposal });
        } else if (d.status === 'unavailable') {
            statusState.enabled = false;
            statusState.message = d.message || OFF_MESSAGE;
            statusListeners.forEach((fn) => fn(statusState));
            machine.send({ type: 'unavailable', token, message: statusState.message });
        } else if (res.status === 429 && d.status === 'limit') {
            machine.send({ type: 'limit', token, message: d.message, usage: d.usage });
        } else if (res.status === 429) {
            machine.send({ type: 'fail', token, message: d.message || 'You are going a bit fast. Wait a minute and try again.', retryable: true });
        } else if (res.ok && d.status === 'nothing') {
            machine.send({ type: 'nothing', token, message: d.message });
        } else if (res.status === 0 || res.status === undefined) {
            machine.send({ type: 'fail', token, message: 'We could not reach the server. Check your connection and try again. Nothing was changed.', retryable: true });
        } else {
            machine.send({ type: 'fail', token, message: d.message || 'Something went wrong. Nothing was changed.', retryable: d.retryable !== false });
        }

        if (machine.state.source === 'ai') refreshUsage();
        return machine.state;
    }

    // ------------------------------------------------------------- apply

    function preview() {
        const p = machine.state.proposal;
        if (!p || p.type !== 'patch') return null;
        const res = previewProposal(studio().getState(), p);
        return res.ok ? res.page : null;
    }

    /** Apply = the only way the design changes. Checks the plan against the page as it is now, records the decision on the server (which snapshots first), then applies as one undo step. */
    async function apply() {
        const p = machine.state.proposal;
        machine.send({ type: 'apply' });
        if (machine.state.phase !== 'applying' || !p) return;

        if (p.type !== 'patch') {
            machine.send({ type: 'apply-failed', message: 'Sizes are changed with the Resize tool.' });
            return;
        }

        const dry = previewProposal(studio().getState(), p);
        if (!dry.ok) {
            machine.send({ type: 'apply-failed', message: dry.message });
            return;
        }

        const res = await ctx.api.post(`/design/ai-proposals/${p.id}/apply`, {});
        if (!res.ok) {
            const used = res.status === 409;
            machine.send({ type: 'apply-failed', final: used, message: used ? 'This suggestion was already used or dismissed.' : (res.message || (res.data && res.data.message) || 'We could not record that change, so nothing was changed. Try again.') });
            return;
        }

        const done = applyProposal(studio(), p);
        if (!done.ok) {
            machine.send({ type: 'apply-failed', final: true, message: `${done.message} Nothing was changed.` });
            return;
        }

        machine.send({ type: 'applied', notice: 'Applied. Undo brings the old version back.' });
        if (options.onApplied) options.onApplied(p);
    }

    function dismiss() {
        const p = machine.state.proposal;
        const phase = machine.state.phase;
        machine.send({ type: 'dismiss' });
        if (p && p.id && phase === 'review') ctx.api.post(`/design/ai-proposals/${p.id}/dismiss`, {});
    }

    function openResize(formatKey) {
        try {
            ctx.openDialog('resize', { format_key: formatKey });
            return true;
        } catch (e) {
            ctx.toast('Open Resize from the top bar and choose that size.', { kind: 'warn' });
            return false;
        }
    }

    // --------------------------------------------------------------- copy

    async function suggestCopy(form) {
        if (!statusState.enabled) {
            copy.phase = 'unavailable';
            copy.message = statusState.message || OFF_MESSAGE;
            return emitCopy();
        }
        if (copy.phase === 'working') return;
        copy.phase = 'working';
        copy.suggestions = [];
        copy.message = '';
        copy.token += 1;
        const token = copy.token;
        emitCopy();

        const res = await ctx.api.post('/design/assistant/copy', { ...form, project_id: ctx.config.project.id });
        if (token !== copy.token) return;
        const d = res.data || {};

        if (res.ok && d.status === 'ok') {
            copy.phase = 'ready';
            copy.suggestions = d.suggestions || [];
            copy.dropped = d.dropped || 0;
        } else if (d.status === 'unavailable') {
            statusState.enabled = false;
            copy.phase = 'unavailable';
            copy.message = d.message || OFF_MESSAGE;
        } else if (res.ok && d.status === 'nothing') {
            copy.phase = 'nothing';
            copy.message = d.message;
        } else if (res.status === 429) {
            copy.phase = 'limit';
            copy.message = d.message || 'You are going a bit fast. Wait a minute and try again.';
            if (d.usage) machine.send({ type: 'usage', usage: d.usage });
        } else {
            copy.phase = 'error';
            copy.message = res.status ? (d.message || 'Something went wrong. Nothing was changed.') : 'We could not reach the server. Check your connection and try again.';
        }
        emitCopy();
        refreshUsage();
        return copy;
    }

    function selectedText() {
        const sel = selection();
        if (sel.length !== 1) return null;
        const found = findIn(activePage().elements, sel[0]);
        return found && found.type === 'text' && !found.locked ? found : null;
    }

    /** Put a suggestion on the page: replaces the selected text box, or adds a new text box. One undo step either way. */
    function insertCopy(text, mode) {
        const target = mode === 'replace' ? selectedText() : null;
        if (mode === 'replace' && !target) return { ok: false, message: 'Select one text box first.' };

        const res = target
            ? studio().exec('text.edit', { id: target.id, text })
            : studio().exec('element.add', newTextBox(activePage(), text, ctx), {});
        return res.ok ? { ok: true } : { ok: false, message: (res.error && res.error.message) || 'The editor could not add that text.' };
    }

    function emitCopy() { copyListeners.forEach((fn) => fn(copy)); return copy; }

    return {
        machine, copy, status: statusState,
        loadStatus, fixes, runAutomatic, ask, apply, dismiss, preview, openResize,
        suggestCopy, insertCopy, selectedText, activePage, selection,
        onStatus(fn) { statusListeners.add(fn); return () => statusListeners.delete(fn); },
        onCopy(fn) { copyListeners.add(fn); return () => copyListeners.delete(fn); },
        resetCopy() { copy.phase = 'idle'; copy.suggestions = []; copy.message = ''; copy.token += 1; emitCopy(); },
    };
}

function findIn(list, id) {
    for (const el of list || []) {
        if (el.id === id) return el;
        if (el.type === 'group') { const hit = findIn(el.children, id); if (hit) return hit; }
    }
    return null;
}

function newTextBox(page, text, ctx) {
    const size = Math.max(16, Math.round(Math.min(page.width, page.height) * 0.05));
    const bg = (page.background && /^#[0-9a-f]{6}/i.test(page.background.color || '')) ? page.background.color : '#ffffff';
    const r = parseInt(bg.slice(1, 3), 16); const g = parseInt(bg.slice(3, 5), 16); const b = parseInt(bg.slice(5, 7), 16);
    const dark = (0.299 * r + 0.587 * g + 0.114 * b) < 128;
    const brandFont = ctx.config.brand && ctx.config.brand.fonts && (ctx.config.brand.fonts.body || ctx.config.brand.fonts.heading);
    const width = Math.round(page.width * 0.7);
    return {
        type: 'text', text, name: 'Suggested text', x: Math.round((page.width - width) / 2), y: Math.round(page.height * 0.4), width, height: size * 2,
        font: brandFont || 'inter', fontSize: size, fontWeight: 600, color: dark ? '#ffffff' : '#111111', align: 'center', autoFit: 'grow', select: true,
    };
}

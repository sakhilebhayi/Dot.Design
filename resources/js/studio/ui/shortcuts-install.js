/*
 * Keyboard shortcuts beyond the engine's own (undo, duplicate, group, nudge and
 * the rest live in the engine). Everything registered with registerShortcut is
 * routed from one document listener that ignores typing in fields.
 */
import { h } from './dom.js';
import { insert } from './insert.js';
import { isEditableTarget, isMacPlatform, labelFor, matches } from './shortcuts.js';
import { textPreset } from './stage.js';

const mac = isMacPlatform();

export const ENGINE_KEYS = [
    ['Editing', [['Undo', 'mod+z'], ['Redo', 'mod+shift+z'], ['Duplicate', 'mod+d'], ['Delete', 'Delete'], ['Select everything', 'mod+a'], ['Group', 'mod+g'], ['Ungroup', 'mod+shift+g'], ['Copy, cut, paste', 'mod+c'], ['Paste in place', 'mod+shift+v']]],
    ['Moving', [['Nudge 1 pixel', 'Arrow keys'], ['Nudge 10 pixels', 'shift+Arrow keys'], ['Select the next or previous element', 'Tab'], ['Edit text, or open a group', 'Enter'], ['Leave text, group or selection', 'Escape']]],
    ['Order', [['Move forward or back', ']'], ['Bring to front', 'mod+]'], ['Send to back', 'mod+[']]],
    ['Dragging', [['Keep to one direction', 'hold shift'], ['Turn snapping off', 'hold alt'], ['Resize from the centre', 'hold alt'], ['Keep the shape', 'hold shift'], ['Turn in 15 degree steps', 'hold shift'], ['Pan', 'hold Space and drag']]],
];

export function installShortcuts(ctx, { preview, openSheet }) {
    const st = ctx.studio;
    const reg = (keys, label, run, extra) => ctx.registerShortcut({ keys, label, run, ...extra });
    const addShape = (shape) => () => { if (!ctx.readOnly) insert(ctx, { kind: 'shape', shape }); };

    reg('?', 'Show keyboard shortcuts', openSheet, { group: 'View' });
    reg('shift+1', 'Fit the page to the screen', () => st.viewport.fit(), { group: 'View' });
    reg('shift+0', 'Zoom to 100%', () => st.viewport.setZoom(1), { group: 'View' });
    reg('+', 'Zoom in', () => st.viewport.zoomIn(), { group: 'View' });
    reg('=', 'Zoom in', () => st.viewport.zoomIn(), { group: 'View', hidden: true });
    reg('-', 'Zoom out', () => st.viewport.zoomOut(), { group: 'View' });
    reg('p', 'Preview', () => preview.open(), { group: 'View' });
    reg('pagedown', 'Next page', () => { const s = st.getState(); const i = s.doc.pages.findIndex((p) => p.id === s.activePageId); if (s.doc.pages[i + 1]) st.setActivePage(s.doc.pages[i + 1].id); }, { group: 'Pages' });
    reg('pageup', 'Previous page', () => { const s = st.getState(); const i = s.doc.pages.findIndex((p) => p.id === s.activePageId); if (s.doc.pages[i - 1]) st.setActivePage(s.doc.pages[i - 1].id); }, { group: 'Pages' });
    if (!ctx.readOnly) {
        reg('mod+s', 'Save now', async () => { const s = await st.save.flush(); if (s === 'saved' || s === 'idle') ctx.toast('Everything is saved.'); }, { group: 'File', inInputs: true });
        reg('t', 'Add a heading', () => insert(ctx, { kind: 'text', props: textPreset(ctx, 'heading') }), { group: 'Add' });
        reg('r', 'Add a rectangle', addShape('rect'), { group: 'Add' });
        reg('o', 'Add a circle', addShape('ellipse'), { group: 'Add' });
        reg('l', 'Add a line', addShape('line'), { group: 'Add' });
        reg('alt+m', 'Switch between Simple and Pro', () => ctx.setMode(ctx.mode === 'pro' ? 'simple' : 'pro'), { group: 'View' });
        reg('shift+r', 'Show or hide rulers (Pro)', () => { if (ctx.mode === 'pro') ctx.saveSetting({ rulers: !ctx.settings.rulers }); }, { group: 'View' });
        reg('shift+g', 'Show or hide the grid (Pro)', () => { if (ctx.mode === 'pro') ctx.saveSetting({ grid: !ctx.settings.grid }); }, { group: 'View' });
        reg('shift+s', 'Turn snapping on or off', () => ctx.saveSetting({ snap: ctx.settings.snap === false }), { group: 'View' });
    }

    document.addEventListener('keydown', (e) => {
        if (e.defaultPrevented || e.isComposing) return;
        const editable = isEditableTarget(e.target) || !!st.getState().editingId;
        const modalOpen = !!document.querySelector('dialog[open]') || preview.isOpen();
        for (const s of ctx.registry.shortcuts()) {
            if (!matches(e, s.keys, mac)) continue;
            if (editable && !s.inInputs) continue;
            if (modalOpen && s.keys !== '?' && !s.global) continue;
            e.preventDefault();
            try { s.run(ctx); } catch (err) { console.error('[studio] shortcut failed', err); }
            return;
        }
    });
}

export function shortcutSheet(ctx) {
    return {
        title: 'Keyboard shortcuts', size: 'lg',
        mount(el) {
            const groups = new Map();
            for (const s of ctx.registry.shortcuts()) { if (s.hidden) continue; const g = s.group || 'More'; if (!groups.has(g)) groups.set(g, []); groups.get(g).push(s); }
            const caps = (spec) => {
                const parts = /(^|\+)(mod|shift|alt)(\+|$)/.test(spec) && !/hold/.test(spec) ? labelFor(spec, mac) : String(spec).split('+');
                return h('span', { class: 'st-keys' }, ...parts.map((p) => h('kbd', { class: 'lt-kbd', text: p })));
            };
            const section = (title, rows) => h('section', { class: 'st-keysec' }, h('h3', { class: 'st-group__title', text: title }), h('dl', { class: 'st-keylist' }, ...rows.flatMap(([label, spec]) => [h('dt', { text: label }), h('dd', {}, caps(spec))])));
            el.append(h('div', { class: 'st-keysheet' },
                ...ENGINE_KEYS.map(([t, rows]) => section(t, rows)),
                ...Array.from(groups).map(([t, list]) => section(t, list.map((s) => [s.label || s.keys, s.keys])))));
            return null;
        },
    };
}

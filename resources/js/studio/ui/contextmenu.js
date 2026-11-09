/*
 * Right click and long press menu. Core actions first, then whatever modules
 * registered with registerContextAction whose `when` passes.
 */
import { labelFor, isMacPlatform } from './shortcuts.js';
import { openMenu } from './popover.js';

const mac = isMacPlatform();
const hint = (k) => labelFor(k, mac).join(' ');

export function contextItems(ctx) {
    const st = ctx.studio;
    const sel = ctx.selection();
    const items = [];
    const run = (name, args) => () => ctx.run(name, { ids: sel.ids, ...(args || {}) });
    if (!sel.count) {
        items.push({ label: 'Paste', hint: hint('mod+v'), onClick: () => st.clipboard.paste() });
        items.push({ label: 'Select everything', hint: hint('mod+a'), onClick: () => st.selectAll() });
        items.push({ sep: true }, { label: 'Fit to screen', hint: hint('shift+1'), onClick: () => st.viewport.fit() });
    } else {
        if (sel.kind === 'text') items.push({ label: 'Edit text', hint: 'Enter', onClick: () => st.beginTextEdit(sel.el.id, { selectAll: true }) }, { sep: true });
        if (sel.kind === 'image') items.push({ label: sel.emptyPicture ? 'Add a picture' : 'Replace picture', onClick: () => ctx.pickPicture(sel.el.id) }, { sep: true });
        items.push({ label: 'Cut', hint: hint('mod+x'), onClick: () => st.clipboard.cut() }, { label: 'Copy', hint: hint('mod+c'), onClick: () => st.clipboard.copy() }, { label: 'Paste', hint: hint('mod+v'), onClick: () => st.clipboard.paste() });
        items.push({ label: 'Duplicate', hint: hint('mod+d'), onClick: run('element.duplicate') }, { label: 'Delete', hint: 'Del', danger: true, onClick: run('element.remove') });
        items.push({ sep: true }, { heading: 'Order' },
            { label: 'Bring to front', hint: hint('mod+]'), onClick: run('element.reorder', { to: 'front' }) },
            { label: 'Move forward', hint: ']', onClick: run('element.reorder', { to: 'forward' }) },
            { label: 'Move back', hint: '[', onClick: run('element.reorder', { to: 'back' }) },
            { label: 'Send to back', hint: hint('mod+['), onClick: run('element.reorder', { to: 'back-most' }) });
        items.push({ sep: true });
        if (sel.canGroup) items.push({ label: 'Group', hint: hint('mod+g'), onClick: run('element.group') });
        if (sel.canUngroup) items.push({ label: 'Ungroup', hint: hint('mod+shift+g'), onClick: run('element.ungroup') });
        items.push({ label: sel.locked ? 'Unlock' : 'Lock in place', onClick: run('element.lock', { value: !sel.locked }) }, { label: sel.hidden ? 'Show' : 'Hide', onClick: run('element.hide', { value: !sel.hidden }) });
    }
    const extra = ctx.registry.contextActions(sel, ctx);
    if (extra.length) { items.push({ sep: true }); for (const a of extra) items.push({ label: a.label, onClick: () => a.run(ctx) }); }
    return items;
}

export function openContextMenu(ctx, at) {
    if (ctx.readOnly) return null;
    return openMenu(null, contextItems(ctx), { at, label: 'Actions', width: 232 });
}

export function installContextMenu(ctx) {
    ctx.studio.on('contextmenu', ({ x, y }) => openContextMenu(ctx, { x, y }));
}

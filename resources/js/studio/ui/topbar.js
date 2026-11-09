/*
 * The top bar: back to designs, inline rename, the honest save chip, undo and
 * redo, zoom, the Simple/Pro switch, preview, and the slots modules register
 * into (resize, share, export, review, assistant...).
 */
import { h, icon, isCompact } from './dom.js';
import { openMenu, openPopover } from './popover.js';
import { segmented } from './fields.js';

export const SAVE_TEXT = {
    idle: { text: 'No changes yet', kind: 'idle', icon: 'circle-check' },
    pending: { text: 'Unsaved changes', kind: 'pending', icon: 'circle' },
    saving: { text: 'Saving', kind: 'saving', icon: 'loader' },
    saved: { text: 'Saved', kind: 'ok', icon: 'circle-check' },
    failed: { text: 'Not saved', kind: 'bad', icon: 'circle-alert' },
    conflict: { text: 'Changed elsewhere', kind: 'bad', icon: 'triangle-alert' },
    offline: { text: 'Offline, kept on this device', kind: 'warn', icon: 'triangle-alert' },
};

export function createTopbar(ctx, shell, { onPreview, openShortcuts }) {
    const studio = ctx.studio;
    const top = shell.top;
    const project = ctx.config.project;

    // ---- back + name ----------------------------------------------------------------------------
    const back = h('a', { class: 'lt-icon-btn st-top__back', href: ctx.config.urls['design.projects.index'] || '/dashboard', attrs: { 'aria-label': 'Back to your designs', 'data-tip': 'Your designs', 'data-tip-side': 'bottom' } }, icon('arrow-left'));
    back.addEventListener('click', async (e) => {
        if (!studio.save.hasUnsaved()) return;
        e.preventDefault();
        const state = await studio.save.flush();
        if (state === 'saved' || state === 'idle') { location.href = back.href; return; }
        const ok = await ctx.confirm({ title: 'Leave without saving?', body: 'Your latest changes are not saved to the server yet. They are kept on this device, and you can recover them next time you open this design.', confirmLabel: 'Leave anyway', cancelLabel: 'Stay here', danger: true });
        if (ok) { ctx.allowUnload(); location.href = back.href; }
    });
    const name = h('input', { class: 'st-name', attrs: { type: 'text', 'aria-label': 'Design name', maxlength: 255, spellcheck: 'false', autocomplete: 'off' } });
    name.value = project.name;
    const nameSizer = h('span', { class: 'st-name__size', attrs: { 'aria-hidden': 'true' } });
    const nameWrap = h('div', { class: 'st-name__wrap' }, nameSizer, name);
    const sizeName = () => { nameSizer.textContent = name.value || ' '; };
    sizeName();
    name.readOnly = ctx.readOnly;
    name.addEventListener('input', sizeName);
    name.addEventListener('keydown', (e) => { if (e.key === 'Enter') name.blur(); if (e.key === 'Escape') { name.value = project.name; sizeName(); name.blur(); } e.stopPropagation(); });
    name.addEventListener('blur', async () => {
        const next = name.value.trim();
        if (ctx.readOnly || !next || next === project.name) { name.value = project.name; sizeName(); return; }
        const r = await ctx.api.put(ctx.url('design.projects.update'), { name: next });
        if (r.ok) { project.name = next; document.title = next + ' | Dot.Design'; ctx.toast('Renamed.'); } else { name.value = project.name; sizeName(); ctx.toast(r.message || 'The name could not be changed.', { kind: 'bad' }); }
    });
    const nameBox = h('div', { class: 'st-top__name' }, nameWrap, ctx.readOnly ? h('span', { class: 'lt-tag', text: 'View only' }) : null);

    // ---- save chip --------------------------------------------------------------------------------
    const chipIcon = h('span', { class: 'st-save__icon' });
    const chipText = h('span', { class: 'st-save__text' });
    const chip = h('button', { class: 'st-save', attrs: { type: 'button', role: 'status', 'aria-live': 'polite' } }, chipIcon, chipText);
    let savedAt = null;
    function renderSave() {
        const state = studio.save.state;
        const d = SAVE_TEXT[state] || SAVE_TEXT.idle;
        chip.dataset.state = state;
        chip.dataset.kind = d.kind;
        chipIcon.replaceChildren(icon(d.icon, state === 'saving' ? 'lt-spin' : ''));
        let text = d.text;
        if (state === 'failed') text = 'Not saved. Retry';
        else if (state === 'conflict') text = 'Changed elsewhere. Resolve';
        chipText.textContent = text;
        const info = studio.save.info || {};
        let tip = info.message || '';
        if (state === 'saved' && savedAt) tip = 'Saved at ' + savedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        if (state === 'failed') tip = (tip ? tip + ' ' : '') + 'Your work is kept on this device. Press to try again.';
        chip.title = tip;
        chip.disabled = state !== 'failed' && state !== 'conflict' && state !== 'offline';
        chip.setAttribute('aria-disabled', String(chip.disabled));
    }
    chip.addEventListener('click', () => {
        const state = studio.save.state;
        if (state === 'failed' || state === 'offline') studio.save.retry();
        else if (state === 'conflict') ctx.emit('conflict-open');
    });
    ctx.on('save-state', ({ state }) => { if (state === 'saved') savedAt = new Date(); renderSave(); });
    renderSave();

    // ---- undo / redo ------------------------------------------------------------------------------
    const undo = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Undo', 'data-tip': 'Undo', 'data-tip-side': 'bottom' }, onClick: () => { studio.undo(); } }, icon('undo'));
    const redo = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Redo', 'data-tip': 'Redo', 'data-tip-side': 'bottom' }, onClick: () => { studio.redo(); } }, icon('redo'));
    function renderHistory() {
        const hi = studio.history();
        undo.disabled = !hi.canUndo; redo.disabled = !hi.canRedo;
        const u = hi.undoLabel ? 'Undo ' + String(hi.undoLabel).toLowerCase() : 'Undo';
        const r = hi.redoLabel ? 'Redo ' + String(hi.redoLabel).toLowerCase() : 'Redo';
        undo.setAttribute('aria-label', u); undo.dataset.tip = u; redo.setAttribute('aria-label', r); redo.dataset.tip = r;
    }
    // A drag commits its undo step without a store change, so no 'history' event follows: refresh after any gesture ends too.
    for (const ev of ['history', 'change', 'selection', 'save-state']) ctx.on(ev, renderHistory);
    window.addEventListener('pointerup', () => setTimeout(renderHistory, 0));
    window.addEventListener('keyup', renderHistory);
    renderHistory();
    const history = h('div', { class: 'st-top__group st-top__history' }, undo, redo);
    history.hidden = ctx.readOnly;

    // ---- zoom -------------------------------------------------------------------------------------
    const zoomLabel = h('span', { class: 'lt-num', text: '100%' });
    const zoomBtn = h('button', { class: 'st-zoom__btn', attrs: { type: 'button', 'aria-haspopup': 'menu', 'aria-label': 'Zoom' } }, zoomLabel, icon('chevron-down', 'lt-icon--sm'));
    const zOut = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Zoom out', 'data-tip': 'Zoom out', 'data-tip-side': 'bottom' }, onClick: () => studio.viewport.zoomOut() }, icon('minus'));
    const zIn = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Zoom in', 'data-tip': 'Zoom in', 'data-tip-side': 'bottom' }, onClick: () => studio.viewport.zoomIn() }, icon('plus'));
    zoomBtn.addEventListener('click', () => openMenu(zoomBtn, [
        { label: 'Fit to screen', hint: 'Shift 1', onClick: () => studio.viewport.fit() },
        { label: 'Fill the screen', onClick: () => studio.viewport.fill() },
        { sep: true },
        ...[0.25, 0.5, 1, 2].map((z) => ({ label: Math.round(z * 100) + '%', hint: z === 1 ? 'Shift 0' : '', onClick: () => studio.viewport.setZoom(z) })),
    ], { label: 'Zoom' }));
    const fitBtn = h('button', { class: 'lt-icon-btn st-top__fit', attrs: { type: 'button', 'aria-label': 'Fit the page to the screen', 'data-tip': 'Fit to screen', 'data-tip-side': 'bottom' }, onClick: () => studio.viewport.fit() }, icon('fullscreen'));
    const zoom = h('div', { class: 'st-top__group st-zoom' }, zOut, zoomBtn, zIn, fitBtn);
    const renderZoom = () => { zoomLabel.textContent = Math.round(studio.viewport.zoom * 100) + '%'; };
    ctx.on('viewport', renderZoom); renderZoom();

    // ---- mode + preview ---------------------------------------------------------------------------
    const modeSeg = segmented({ label: 'Editing mode', value: ctx.mode, small: true, items: [{ value: 'simple', label: 'Simple mode: plain controls for what you selected', text: 'Simple' }, { value: 'pro', label: 'Pro mode: every control', text: 'Pro' }], onChange: (v) => ctx.setMode(v) });
    ctx.on('mode', (m) => modeSeg.set(m));
    const modeBox = h('div', { class: 'st-top__mode' }, modeSeg.el);
    modeBox.hidden = ctx.readOnly;
    const preview = h('button', { class: 'st-top__btn', attrs: { type: 'button', 'aria-label': 'Preview the design' }, onClick: onPreview }, icon('eye'), h('span', { class: 'st-top__btnlabel', text: 'Preview' }));
    const keys = h('button', { class: 'lt-icon-btn st-top__keys', attrs: { type: 'button', 'aria-label': 'Keyboard shortcuts', 'data-tip': 'Shortcuts ?', 'data-tip-side': 'bottom' }, onClick: openShortcuts }, icon('keyboard'));

    // ---- module slots -----------------------------------------------------------------------------
    const slots = h('div', { class: 'st-top__slots' });
    const primarySlots = h('div', { class: 'st-top__slots st-top__slots--primary' });
    const more = h('button', { class: 'lt-icon-btn st-top__more', attrs: { type: 'button', 'aria-haspopup': 'menu', 'aria-label': 'More' } }, icon('more'));

    function actionButton(a) {
        if (typeof a.render === 'function') { const n = a.render(ctx); if (n) return n; }
        const label = typeof a.label === 'function' ? a.label(ctx) : a.label;
        const btn = h('button', { class: [a.primary ? 'lt-btn lt-btn--sm' : 'st-top__btn', a.primary && 'st-top__primary'], attrs: { type: 'button', 'aria-label': label, 'aria-haspopup': Array.isArray(a.menu) ? 'menu' : null }, data: { action: a.id } }, a.icon ? icon(a.icon) : null, h('span', { class: 'st-top__btnlabel', text: label }));
        btn.addEventListener('click', () => {
            if (Array.isArray(a.menu)) openMenu(btn, a.menu.map((m) => ({ ...m, onClick: () => m.onClick && m.onClick(ctx) })), { label });
            else a.onClick(ctx);
        });
        return btn;
    }
    function visibleActions() { return ctx.registry.topbarActions(ctx.mode).filter((a) => { try { return !a.visible || a.visible(ctx); } catch (e) { return false; } }); }
    function renderSlots() {
        slots.replaceChildren(); primarySlots.replaceChildren();
        for (const a of visibleActions()) (a.primary ? primarySlots : slots).appendChild(actionButton(a));
    }
    ctx.registry.onChange((k) => { if (k === 'actions') renderSlots(); });
    for (const ev of ['mode', 'page']) ctx.on(ev, renderSlots);

    more.addEventListener('click', () => {
        const items = [];
        if (!ctx.readOnly) items.push({ heading: 'Mode' }, { label: 'Simple', checked: ctx.mode === 'simple', onClick: () => ctx.setMode('simple') }, { label: 'Pro', checked: ctx.mode === 'pro', onClick: () => ctx.setMode('pro') }, { sep: true });
        items.push({ label: 'Preview', icon: 'eye', onClick: onPreview }, { label: 'Fit to screen', icon: 'fullscreen', onClick: () => studio.viewport.fit() });
        const acts = visibleActions();
        if (acts.length) items.push({ sep: true });
        for (const a of acts) {
            const label = typeof a.label === 'function' ? a.label(ctx) : a.label;
            if (Array.isArray(a.menu)) { items.push({ heading: label }); for (const m of a.menu) items.push({ label: m.label, onClick: () => m.onClick && m.onClick(ctx) }); } else items.push({ label, icon: a.icon, onClick: () => a.onClick && a.onClick(ctx) });
        }
        items.push({ sep: true }, { label: 'Keyboard shortcuts', icon: 'keyboard', onClick: openShortcuts });
        openMenu(more, items, { label: 'More', align: 'end' });
    });

    top.append(back, nameBox, chip, h('div', { class: 'st-top__spacer' }), history, zoom, modeBox, slots, preview, primarySlots, keys, more);
    renderSlots();

    return { renderSlots, renderSave, nameInput: name, openPopover };
}

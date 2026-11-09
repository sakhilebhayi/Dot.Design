/*
 * The top bar: back to designs, inline rename, the honest save chip, undo and
 * redo, zoom, the Simple/Pro switch, preview, and the slots modules register
 * into (resize, share, export, review, assistant...).
 */
import { h, icon, isCompact } from './dom.js';
import { openMenu, openPopover } from './popover.js';
import { isMacPlatform, labelFor } from './shortcuts.js';

/** 'Preview' + 'P' -> 'Preview (P)': the shortcut shows in the tooltip so people learn it without a manual. */
export function tipWithKeys(label, spec) {
    if (!spec) return label;
    const mac = isMacPlatform();
    const k = labelFor(spec, mac).map((x) => (mac ? ({ Cmd: '\u2318', Shift: '\u21e7', Option: '\u2325' }[x] || x) : x));
    return label + ' (' + k.join(mac ? '' : '+') + ')';
}

/**
 * How each top bar action is shown. The bar has one primary (Download, filled), one secondary (Share), a small group of
 * icon buttons with tooltips, and everything else lives under More. An action nobody classified goes under More so a new
 * module never adds clutter to the bar.
 */
export const ACTION_TIER = { export: 'primary', share: 'secondary', resize: 'icon', review: 'icon' };
export const ACTION_KEYS = { export: 'mod+shift+e' };

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
    let freshTimer = null;
    ctx.on('save-state', ({ state }) => {
        if (state === 'saved') {
            savedAt = new Date();
            // the warm yellow tick for a moment after each confirmed save, then the chip goes calm again
            chip.classList.add('is-fresh'); clearTimeout(freshTimer); freshTimer = setTimeout(() => chip.classList.remove('is-fresh'), 2200);
        } else chip.classList.remove('is-fresh');
        renderSave();
    });
    renderSave();

    // ---- undo / redo ------------------------------------------------------------------------------
    const undo = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Undo', 'data-tip': 'Undo', 'data-tip-side': 'bottom' }, onClick: () => { studio.undo(); } }, icon('undo'));
    const redo = h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Redo', 'data-tip': 'Redo', 'data-tip-side': 'bottom' }, onClick: () => { studio.redo(); } }, icon('redo'));
    function renderHistory() {
        const hi = studio.history();
        undo.disabled = !hi.canUndo; redo.disabled = !hi.canRedo;
        const u = hi.undoLabel ? 'Undo ' + String(hi.undoLabel).toLowerCase() : 'Undo';
        const r = hi.redoLabel ? 'Redo ' + String(hi.redoLabel).toLowerCase() : 'Redo';
        undo.setAttribute('aria-label', u); undo.dataset.tip = tipWithKeys(u, 'mod+z'); redo.setAttribute('aria-label', r); redo.dataset.tip = tipWithKeys(r, 'mod+shift+z');
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
    const fitBtn = h('button', { class: 'lt-icon-btn st-top__fit', attrs: { type: 'button', 'aria-label': 'Fit the page to the screen', 'data-tip': tipWithKeys('Fit to screen', 'shift+1'), 'data-tip-side': 'bottom' }, onClick: () => studio.viewport.fit() }, icon('fullscreen'));
    const zoom = h('div', { class: 'st-top__group st-zoom' }, zOut, zoomBtn, zIn, fitBtn);
    const renderZoom = () => { zoomLabel.textContent = Math.round(studio.viewport.zoom * 100) + '%'; };
    ctx.on('viewport', renderZoom); renderZoom();

    // ---- mode + preview ---------------------------------------------------------------------------
    // The mode switch: the chosen side is filled with a check, so there is no doubt which mode is on.
    const MODES = [
        ['simple', 'Simple', 'Simple: only what the selected item needs, in plain words'],
        ['pro', 'Pro', 'Pro: every setting, layers, rulers, guides and exact sizes (' + tipWithKeys('', 'alt+m').trim().replace(/^\(|\)$/g, '') + ')'],
    ];
    const modeBtns = MODES.map(([value, text, tip]) => h('button', { class: 'st-mode__btn', attrs: { type: 'button', role: 'radio', 'aria-label': tip, 'data-tip': tip, 'data-tip-side': 'bottom' }, data: { value }, onClick: () => { if (ctx.mode !== value) ctx.setMode(value); } }, icon('check', 'st-mode__check'), h('span', { text })));
    const modeSeg = h('div', { class: 'st-mode', attrs: { role: 'radiogroup', 'aria-label': 'Editing mode' } }, ...modeBtns);
    modeSeg.addEventListener('keydown', (e) => {
        const i = modeBtns.indexOf(document.activeElement);
        if (i < 0 || !['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
        e.preventDefault(); const n = modeBtns[(i + 1) % modeBtns.length]; n.focus(); n.click();
    });
    const paintMode = (m) => modeBtns.forEach((b) => { const on = b.dataset.value === m; b.setAttribute('aria-checked', String(on)); b.classList.toggle('is-on', on); b.tabIndex = on ? 0 : -1; });
    paintMode(ctx.mode);
    ctx.on('mode', paintMode);
    const modeBox = h('div', { class: 'st-top__mode' }, modeSeg);
    modeBox.hidden = ctx.readOnly;
    const preview = h('button', { class: 'lt-icon-btn st-top__icon', attrs: { type: 'button', 'aria-label': 'Preview the design', 'data-tip': tipWithKeys('Preview', 'p'), 'data-tip-side': 'bottom' }, onClick: onPreview }, icon('eye'));
    const keys = h('button', { class: 'lt-icon-btn st-top__keys', attrs: { type: 'button', 'aria-label': 'Keyboard shortcuts', 'data-tip': tipWithKeys('Shortcuts', '?'), 'data-tip-side': 'bottom' }, onClick: openShortcuts }, icon('keyboard'));

    // ---- module slots -----------------------------------------------------------------------------
    // Priority: Download (filled), Share (outlined), then icon buttons with tooltips, then More. Modules only say what an
    // action is; where it sits is decided here (ACTION_TIER) so the bar stays the same shape whatever is registered.
    const iconSlots = h('div', { class: 'st-top__group st-top__icons' }, preview);
    const shareSlot = h('div', { class: 'st-top__slot' });
    const primarySlot = h('div', { class: 'st-top__slot' });
    const more = h('button', { class: 'lt-icon-btn st-top__more st-top__icon', attrs: { type: 'button', 'aria-haspopup': 'menu', 'aria-label': 'More', 'data-tip': 'More', 'data-tip-side': 'bottom' } }, icon('more'));
    const labelOf = (a) => (typeof a.label === 'function' ? a.label(ctx) : a.label);
    const tierOf = (a) => ACTION_TIER[a.id] || 'menu';

    function actionButton(a) {
        if (typeof a.render === 'function') { const n = a.render(ctx); if (n) return n; }
        const label = labelOf(a);
        const tier = tierOf(a);
        const tip = tipWithKeys(label, ACTION_KEYS[a.id]);
        const common = { type: 'button', 'aria-haspopup': Array.isArray(a.menu) ? 'menu' : null };
        let btn;
        if (tier === 'icon') btn = h('button', { class: 'lt-icon-btn st-top__icon', attrs: { ...common, 'aria-label': label, 'data-tip': tip, 'data-tip-side': 'bottom' }, data: { action: a.id } }, icon(a.icon || 'circle-dot'));
        else btn = h('button', { class: ['lt-btn', 'lt-btn--sm', tier === 'secondary' && 'lt-btn--secondary', 'st-top__cta', tier === 'primary' && 'st-top__primary'], attrs: { ...common, 'aria-label': label, ...(ACTION_KEYS[a.id] ? { title: tip } : {}) }, data: { action: a.id } }, a.icon ? icon(a.icon) : null, h('span', { class: 'st-top__btnlabel', text: label }));
        btn.addEventListener('click', () => {
            if (Array.isArray(a.menu)) openMenu(btn, a.menu.map((m) => ({ ...m, onClick: () => m.onClick && m.onClick(ctx) })), { label });
            else a.onClick(ctx);
        });
        return btn;
    }
    function visibleActions() { return ctx.registry.topbarActions(ctx.mode).filter((a) => { try { return !a.visible || a.visible(ctx); } catch (e) { return false; } }); }
    function renderSlots() {
        const acts = visibleActions();
        iconSlots.replaceChildren(preview, ...acts.filter((a) => tierOf(a) === 'icon').map(actionButton));
        shareSlot.replaceChildren(...acts.filter((a) => tierOf(a) === 'secondary').map(actionButton));
        primarySlot.replaceChildren(...acts.filter((a) => tierOf(a) === 'primary').map(actionButton));
    }
    ctx.registry.onChange((k) => { if (k === 'actions') renderSlots(); });
    for (const ev of ['mode', 'page']) ctx.on(ev, renderSlots);

    // More: what does not fit the bar, plus every action again on a phone (where the bar shows only the essentials).
    more.addEventListener('click', () => {
        const items = [];
        const compact = isCompact();
        const acts = visibleActions();
        if (!ctx.readOnly) items.push({ heading: 'Mode' }, { label: 'Simple', checked: ctx.mode === 'simple', onClick: () => ctx.setMode('simple') }, { label: 'Pro', checked: ctx.mode === 'pro', onClick: () => ctx.setMode('pro') }, { sep: true });
        items.push({ label: 'Preview', icon: 'eye', hint: 'P', onClick: onPreview }, { label: 'Fit to screen', icon: 'fullscreen', hint: 'Shift 1', onClick: () => studio.viewport.fit() });
        const listed = acts.filter((a) => compact || tierOf(a) === 'menu' || a.id === 'resize' || a.id === 'review');
        if (listed.length) items.push({ sep: true });
        for (const a of listed) {
            const label = labelOf(a);
            if (Array.isArray(a.menu)) { items.push({ heading: label }); for (const m of a.menu) items.push({ label: m.label, onClick: () => m.onClick && m.onClick(ctx) }); } else items.push({ label, icon: a.icon, onClick: () => a.onClick && a.onClick(ctx) });
        }
        if (compact) for (const a of acts.filter((x) => tierOf(x) === 'primary' || tierOf(x) === 'secondary')) { if (!listed.includes(a)) items.push({ label: labelOf(a), icon: a.icon, onClick: () => a.onClick && a.onClick(ctx) }); }
        items.push({ sep: true }, { label: 'Keyboard shortcuts', icon: 'keyboard', hint: '?', onClick: openShortcuts });
        openMenu(more, items, { label: 'More', align: 'end' });
    });

    top.append(back, nameBox, chip, h('div', { class: 'st-top__spacer' }), history, zoom, modeBox, h('span', { class: 'st-top__divider', attrs: { 'aria-hidden': 'true' } }), iconSlots, keys, more, shareSlot, primarySlot);
    renderSlots();

    return { renderSlots, renderSave, nameInput: name, openPopover };
}

/*
 * Version history (R-45 collab): the "History" left tab, the read-only snapshot viewer dialog and Restore.
 *
 *  - The list comes from GET versions (never carries pages). Named versions ("Save this version"), approved versions and
 *    restore points are kept for good; automatic saves are pruned by the server (newest 50). That is shown, not controlled.
 *  - Viewing a snapshot renders its pages with the engine's read-only viewer (the same renderer as the editor).
 *  - Restore: confirms in plain words, flushes any unsaved edits first (so they land in the safety copy), restores, drops
 *    the local crash journal and reloads the editor from the server. doc.replacePages was NOT used: it keeps each page's old
 *    revision and ids, while a restore bumps revisions and can recreate pages, so the next save would conflict.
 *    A shell can supply ctx.reloadDocument() to do this without a full page load; otherwise the page reloads.
 */
import '../../../../css/studio-collab.css';
import { createViewer } from '../../engine/viewer.js';
import { h, clear } from '../../collab/dom.js';
import { busyWhile, button, empty, field, input, notice, skeleton, tag } from '../../collab/ui.js';
import { can, collabUrls } from '../../collab/urls.js';
import { explain } from '../../collab/errors.js';
import { dateTime, formatBytes, timeAgo } from '../../collab/format.js';
import { FILTERS, applyFilter, describeVersion, initialList, initialRestore, listReducer, restoreReducer, summariseDiff } from '../../collab/versions-state.js';

export function register(ctx) {
    if (!can(ctx.config, 'view')) return;

    ctx.registerDialog('versions.view', {
        title: 'Earlier version',
        size: 'lg',
        mount(el, props, c) { return mountSnapshot(el, props || {}, c || ctx); },
    });

    ctx.registerLeftTab({
        id: 'versions',
        label: 'History',
        icon: 'history',
        order: 70,
        modes: ['simple', 'pro'],
        mount(el, c) { return mountPanel(el, c || ctx); },
    });
}

function mountPanel(el, ctx) {
    const urls = collabUrls(ctx.config);
    const canSave = can(ctx.config, 'edit');
    let list = initialList();
    let destroyed = false;
    const root = h('div', { class: 'cb-panel' });
    el.append(root);

    const dispatch = (action) => { list = listReducer(list, action); render(); };

    async function load(more = false) {
        if (more) dispatch({ type: 'more' }); else dispatch({ type: 'load' });
        const cursor = more ? list.cursor : null;
        const res = await ctx.api.get(cursor ? `${urls.versions}?cursor=${encodeURIComponent(cursor)}` : urls.versions);
        if (destroyed) return;
        if (!res.ok) { dispatch({ type: 'failed', error: explain(res, { what: 'load the history' }) }); return; }
        const items = ((res.data && res.data.versions) || []).map((v) => ({ ...v, view: describeVersion(v) }));
        dispatch(more ? { type: 'moreLoaded', items, cursor: res.data.next_cursor } : { type: 'loaded', items, cursor: res.data.next_cursor });
    }

    const saveForm = canSave ? buildSaveForm() : null;
    function buildSaveForm() {
        const name = input({ type: 'text', maxlength: 80, placeholder: 'For example: Sent to printer' });
        const f = field('Name this version', name, { hint: 'Keeps a copy of the design as it is now. Named versions are never removed automatically.' });
        const submit = button({ label: 'Save this version', variant: 'primary', size: 'sm', type: 'submit' });
        const form = h('form', { class: 'cb-saveform', novalidate: true }, f, h('div', { class: 'cb-actions' }, submit));
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const label = name.value.trim();
            if (!label) { f.setError('Give this version a name so you can find it later.'); name.focus(); return; }
            f.setError('');
            // Unsaved edits are saved first, so the version holds what is on screen.
            try { if (ctx.studio && ctx.studio.save && ctx.studio.save.flush) await ctx.studio.save.flush(); } catch (err) { /* the server copy is still saved below */ }
            const res = await busyWhile(submit, ctx.api.post(urls.versions, { label }), 'Saving...');
            if (destroyed) return;
            if (res.ok && res.data && res.data.version) {
                name.value = '';
                ctx.toast('Version saved.', { kind: 'ok' });
                dispatch({ type: 'created', item: { ...res.data.version, view: describeVersion(res.data.version) } });
            } else { const x = explain(res, { what: 'save this version' }); f.setError(x.text); }
        });
        return form;
    }

    function render() {
        if (destroyed) return;
        const top = h('div', { class: 'cb-panel__top' }, h('h2', { class: 'cb-panel__title', text: 'Version history' }));
        const filters = h('div', { class: 'cb-chips', role: 'group', 'aria-label': 'Show versions' },
            FILTERS.map((f) => h('button', { type: 'button', class: 'cb-chip', 'aria-pressed': String(list.filter === f.key), onclick: () => { list = { ...list, filter: f.key }; render(); }, text: f.label })));
        const body = h('div', { class: 'cb-panel__list', 'aria-live': 'polite' });
        if (list.status === 'loading' || list.status === 'idle') body.append(skeleton(4));
        else if (list.status === 'error') body.append(notice(list.error.text, list.error.kind, { action: { label: 'Try again', onClick: () => load() } }));
        else {
            const items = applyFilter(list.items, list.filter);
            if (!items.length) body.append(empty(list.items.length ? 'Nothing matches that filter' : 'No earlier versions yet', list.items.length ? 'Try All.' : 'The design is saved automatically as you work, and earlier copies appear here.'));
            else {
                const ul = h('ul', { class: 'cb-versions' });
                for (const v of items) ul.append(versionRow(v));
                body.append(ul);
                if (list.cursor) body.append(button({ label: list.status === 'more' ? 'Loading...' : 'Show older versions', variant: 'secondary', size: 'sm', disabled: list.status === 'more', onClick: () => load(true) }));
            }
        }
        root.replaceChildren(...[top, saveForm, filters, body].filter(Boolean));
    }

    function versionRow(v) {
        const d = v.view;
        return h('li', { class: 'cb-version' },
            h('div', { class: 'cb-version__main' },
                h('span', { class: 'cb-version__title' }, d.title, d.approved ? [' ', tag('Approved', 'ok')] : null),
                h('span', { class: 'cb-version__meta' }, `${d.kind}. ${d.author}, `, h('time', { datetime: d.createdAt, title: dateTime(d.createdAt), text: timeAgo(d.createdAt) }), d.bytes ? `. ${formatBytes(d.bytes)}` : '', d.protectedFromPruning ? '. Kept for good' : '')),
            button({ label: 'View', size: 'sm', variant: 'secondary', ariaLabel: `View the version ${d.title}, ${timeAgo(d.createdAt)}`, onClick: () => ctx.openDialog('versions.view', { versionId: v.id }) }));
    }

    render();
    load();
    return { destroy() { destroyed = true; clear(el); } };
}

// ---------------------------------------------------------------------- snapshot viewer + restore
export function mountSnapshot(el, props, ctx) {
    const urls = collabUrls(ctx.config);
    const canRestore = can(ctx.config, 'edit');
    let destroyed = false;
    let viewer = null;
    let restore = initialRestore();
    const root = h('div', { class: 'cb-snap' });
    el.append(root);

    root.append(skeleton(4));

    (async () => {
        const [vres, dres] = await Promise.all([ctx.api.get(urls.version(props.versionId)), ctx.api.get(urls.diff(props.versionId))]);
        if (destroyed) return;
        clear(root);
        if (!vres.ok || !vres.data || !vres.data.version) { const x = explain(vres, { what: 'open this version' }); root.append(notice(x.text, x.kind)); return; }
        build(vres.data.version, dres.ok && dres.data ? dres.data.diff : null);
    })();

    function build(version, diff) {
        const d = describeVersion(version);
        const stage = h('div', { class: 'cb-snap__stage', role: 'img', 'aria-label': `Read-only preview of ${d.title}` });
        const pager = h('div', { class: 'cb-snap__pager' });
        const summary = diff ? summariseDiff(diff) : 'The comparison with the design now is not available.';
        const msg = h('div', { class: 'cb-formerr' });
        const restoreBtn = canRestore ? button({ label: 'Restore this version', variant: 'primary', onClick: () => askRestore() }) : null;

        root.append(
            h('div', { class: 'cb-snap__head' },
                h('div', {}, h('h3', { class: 'cb-h', text: d.title }), h('p', { class: 'cb-hint' }, `${d.kind}. ${d.author}, `, h('time', { datetime: d.createdAt, text: dateTime(d.createdAt) }), '.')),
                d.approved ? tag('Approved', 'ok') : null),
            stage, pager,
            h('p', { class: 'cb-snap__diff', text: summary }),
            msg,
            h('div', { class: 'cb-actions' }, restoreBtn, h('span', { class: 'cb-hint', text: 'Viewing does not change your design.' })));

        // The read-only viewer is the editor's own renderer, so this is exactly what the version looked like.
        const pages = (version.pages || []).map((p) => ({
            ...p,
            width: p.width || ctx.config.project.width,
            height: p.height || ctx.config.project.height,
            background: p.background || { type: 'solid', color: p.background_color || '#ffffff' },
        }));
        if (!pages.length) { stage.replaceWith(notice('This version has no pages.', 'warn')); return; }
        try {
            const assets = Array.isArray(ctx.config.assets) ? ctx.config.assets : Object.values(ctx.config.assets || {});
            viewer = createViewer({ root: stage, pages, assets, fonts: ctx.config.fonts, icons: ctx.config.icons });
            const label = h('span', { class: 'cb-snap__page', 'aria-live': 'polite' });
            const prev = button({ label: 'Previous page', size: 'sm', variant: 'secondary', onClick: () => viewer.prev() });
            const next = button({ label: 'Next page', size: 'sm', variant: 'secondary', onClick: () => viewer.next() });
            const sync = () => { label.textContent = `Page ${viewer.index + 1} of ${viewer.count}`; prev.disabled = viewer.index === 0; next.disabled = viewer.index >= viewer.count - 1; };
            viewer.on('page', sync); sync();
            pager.append(prev, label, next, button({ label: 'Fit', size: 'sm', variant: 'ghost', onClick: () => viewer.fit() }));
            if (pages.length === 1) pager.hidden = true;
        } catch (err) {
            stage.replaceWith(notice('This version could not be drawn here. You can still restore it.', 'warn'));
        }

        function paintRestore() {
            clear(msg);
            if (restore.error) msg.append(notice(restore.error.text, restore.error.kind));
        }

        async function askRestore() {
            restore = restoreReducer(restore, { type: 'ask', versionId: version.id });
            if (restore.step !== 'confirm') return;
            const ok = await ctx.confirm({
                title: 'Restore this version?',
                body: 'Your design goes back to how it looked in this version. Right now it is saved first as a restore point, so nothing is lost. To undo a restore, restore that safety copy from the history.',
                confirmLabel: 'Restore',
            });
            if (!ok) { restore = restoreReducer(restore, { type: 'cancel' }); return; }
            restore = restoreReducer(restore, { type: 'start' });
            restoreBtn.setBusy(true, 'Restoring...');
            // Anything unsaved is saved first so the safety copy includes it.
            try { if (ctx.studio && ctx.studio.save && ctx.studio.save.flush) await ctx.studio.save.flush(); } catch (err) { /* the restore still protects the saved state */ }
            const res = await ctx.api.post(urls.restore(version.id), {});
            if (destroyed) return;
            if (res.ok) {
                restore = restoreReducer(restore, { type: 'done', preRestoreId: res.data && res.data.pre_restore_version });
                ctx.toast('Restored. Reloading your design...', { kind: 'ok' });
                try { const rec = ctx.studio && ctx.studio.recovery; if (rec && rec.list) { const ids = rec.list().map((r) => r.pageId); if (ids.length) rec.discard(ids); } } catch (err) { /* a stale journal is flagged by the engine anyway */ }
                if (typeof ctx.reloadDocument === 'function') ctx.reloadDocument(); else window.location.reload();
            } else {
                restore = restoreReducer(restore, { type: 'failed', error: explain(res, { what: 'restore this version' }) });
                restoreBtn.setBusy(false);
                paintRestore();
            }
        }
    }

    return { destroy() { destroyed = true; if (viewer) { viewer.destroy(); viewer = null; } clear(el); } };
}

/*
 * Uploads tab: the person's pictures. Upload by picking, dropping or pasting,
 * with progress and plain failure messages; search and filter; click or drag to
 * place; use as a background; delete with a usage warning. The same library is
 * available in every design.
 */
import { pickFile } from '../actions.js';
import { h, icon } from '../dom.js';
import { draggable } from '../insert.js';
import { openMenu } from '../popover.js';
import { ACCEPT } from '../uploads.js';
import { emptyState, errorNote, searchBox, skeletonGrid } from './common.js';

const fmtBytes = (n) => (n >= 1048576 ? (n / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');

export function mountUploads(el, ctx) {
    const state = { q: '', folder: '', tag: '', cursor: null, items: [], loading: false, error: null, usage: null, token: 0, folders: [] };
    const banner = h('div', { class: 'st-notebox st-notebox--accent', hidden: true, attrs: { role: 'status' } });
    const jobsBox = h('div', { class: 'st-jobs', attrs: { 'aria-live': 'polite' } });
    const grid = h('div', { class: 'st-pics' });
    const status = h('div', { class: 'st-status' });
    const more = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--block', attrs: { type: 'button' }, text: 'Show more pictures', hidden: true, onClick: () => load(true) });
    const usage = h('div', { class: 'st-usage', hidden: true });
    const folderSel = h('select', { class: 'lt-select st-select', attrs: { 'aria-label': 'Folder' } }, h('option', { value: '', text: 'All folders' }));
    folderSel.addEventListener('change', () => { state.folder = folderSel.value; load(false); });
    const search = searchBox({ label: 'Search pictures', placeholder: 'Search your pictures', onInput: (q) => { state.q = q; load(false); } });
    const drop = h('div', { class: 'st-dropzone' }, icon('upload'), h('div', {}, h('strong', { text: 'Drop pictures here' }), h('span', { class: 'st-note', text: 'or choose them. JPG, PNG or WebP, up to 8 MB each.' })));
    const choose = h('button', { class: 'lt-btn lt-btn--block', attrs: { type: 'button' }, onClick: async () => { const files = await pickFile(ACCEPT, true); if (files.length) addFiles(files); } }, icon('upload'), 'Upload pictures');
    if (ctx.readOnly) { choose.hidden = true; drop.hidden = true; }
    el.append(h('div', { class: 'st-tabhead' }, choose, drop, banner, jobsBox, search.el, folderSel), usage, grid, status, more);

    for (const t of ['dragenter', 'dragover']) drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('is-over'); });
    for (const t of ['dragleave', 'drop']) drop.addEventListener(t, () => drop.classList.remove('is-over'));
    drop.addEventListener('drop', (e) => { e.preventDefault(); const f = Array.from(e.dataTransfer.files || []); if (f.length) addFiles(f); });
    drop.addEventListener('click', () => choose.click());

    async function addFiles(files) {
        // The banner says "Pick a picture for this spot": the first picture that arrives fills that spot, the rest go to the library.
        let target = ctx.uploadTarget || (ctx.selection().emptyPicture ? ctx.selection().el.id : null);
        for (const f of files.slice(0, 10)) {
            const r = await ctx.uploads.upload(f);
            if (!r.ok) { ctx.toast(r.message, { kind: 'bad' }); continue; }
            if (target) { ctx.uploads.usePicture(r.asset, { targetId: target }); target = null; }
        }
    }

    function paintJobs() {
        const jobs = Array.from(ctx.uploads.jobs.values());
        jobsBox.replaceChildren(...jobs.map((j) => h('div', { class: ['st-job', 'is-' + j.status] },
            h('div', { class: 'st-job__top' }, h('span', { class: 'st-job__name', text: j.name }), j.status === 'uploading' ? h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Cancel upload of ' + j.name }, onClick: () => j.cancel && j.cancel() }, icon('x', 'lt-icon--sm')) : j.status === 'failed' ? h('button', { class: 'lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Dismiss' }, onClick: () => ctx.uploads.dismiss(j.id) }, icon('x', 'lt-icon--sm')) : icon('circle-check', 'st-job__ok')),
            j.status === 'failed' ? h('p', { class: 'lt-error', text: j.message }) : h('div', { class: 'lt-progress', attrs: { role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': Math.round(j.progress * 100), 'aria-label': 'Uploading ' + j.name } }, h('span', { vars: { p: j.progress } })))));
    }
    ctx.on('uploads', paintJobs);
    ctx.on('asset-added', ({ asset }) => { if (!state.items.some((a) => a.id === asset.id)) { state.items.unshift({ ...asset, owned: true }); paint(); } });

    function tile(a) {
        const img = h('img', { class: 'st-pic__img', attrs: { src: a.preview_url || a.url, alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' } });
        const b = h('button', { class: 'st-pic', attrs: { type: 'button', 'aria-label': `${ctx.uploadTarget || ctx.selection().emptyPicture ? 'Use' : 'Add'} ${a.name}` }, onClick: () => { if (!ctx.readOnly) ctx.uploads.usePicture(a); } }, img, a.placeholder ? h('span', { class: 'lt-tag st-pic__tag', text: 'Placeholder' }) : null);
        const menu = h('button', { class: 'st-pic__menu', attrs: { type: 'button', 'aria-label': 'Options for ' + a.name, 'aria-haspopup': 'menu' } }, icon('more', 'lt-icon--sm'));
        menu.addEventListener('click', (e) => { e.stopPropagation(); openMenu(menu, [
            { label: 'Add to the page', icon: 'plus', onClick: () => ctx.uploads.usePicture(a, { targetId: null }), disabled: ctx.readOnly },
            { label: 'Use as the background', icon: 'image', onClick: () => ctx.run('page.update', { id: ctx.selection().page.id, changes: { background: { type: 'image', color: '#ffffff', gradient: null, asset_id: a.id, fit: 'cover' } } }), disabled: ctx.readOnly },
            { sep: true },
            { label: 'Delete from library', icon: 'trash', danger: true, disabled: ctx.readOnly || a.owned === false, onClick: () => remove(a) },
        ], { label: 'Picture options', align: 'end' }); });
        if (!ctx.readOnly) draggable(b, () => ({ kind: 'asset', asset: a, name: a.name }));
        return h('div', { class: 'st-picwrap', attrs: { title: `${a.name}${a.width ? ` (${a.width} x ${a.height})` : ''}` } }, b, ctx.readOnly ? null : menu);
    }

    async function remove(a) {
        const ok = await ctx.confirm({ title: `Delete ${a.name}?`, body: ['This removes the picture from your library, so no design can use it.', 'If it is on a page, you will need to take it off first. This cannot be undone.'], confirmLabel: 'Delete picture', danger: true });
        if (!ok) return;
        const r = await ctx.api.del(ctx.url('design.assets.destroy', { asset: a.id }));
        if (r.ok) { state.items = state.items.filter((x) => x.id !== a.id); ctx.removeAsset(a.id); paint(); ctx.toast('Picture deleted.'); if (r.data && r.data.usage) state.usage = r.data.usage; paintUsage(); } else ctx.toast(r.status === 409 ? r.message : (r.message || 'The picture could not be deleted.'), { kind: 'bad' });
    }

    function paintUsage() {
        const u = state.usage;
        usage.hidden = !u || !u.quota;
        if (!u || !u.quota) return;
        const pct = Math.min(1, u.used / u.quota);
        usage.replaceChildren(h('div', { class: 'st-note', text: `${fmtBytes(u.used)} of ${fmtBytes(u.quota)} used` }), h('div', { class: 'lt-progress', attrs: { role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': Math.round(pct * 100), 'aria-label': 'Picture storage used' } }, h('span', { vars: { p: pct } })));
    }

    function paint() {
        grid.replaceChildren(...state.items.map(tile));
        more.hidden = !state.cursor;
        status.replaceChildren();
        if (state.error) status.append(errorNote(state.error, () => load(false)));
        else if (state.loading && !state.items.length) status.append(skeletonGrid(6, '1 / 1'));
        else if (!state.items.length) status.append(state.q || state.folder ? emptyState('No pictures match', 'Try another search or folder.') : emptyState('No pictures yet', 'Upload a logo or photo and it will be here in every design. You can also drag a picture onto the page, or paste one.', ctx.readOnly ? null : h('button', { class: 'lt-btn lt-btn--sm', attrs: { type: 'button' }, text: 'Upload pictures', onClick: () => choose.click() })));
        paintTarget();
    }

    function paintTarget() {
        const sel = ctx.selection();
        const target = ctx.uploadTarget || (sel.emptyPicture ? sel.el.id : null);
        banner.hidden = !target;
        if (target) banner.replaceChildren(icon('image-plus'), h('div', { class: 'st-notebox__body' }, h('strong', { text: 'Pick a picture for this spot.' }), ctx.uploadTarget ? h('button', { class: 'lt-btn lt-btn--ghost lt-btn--sm', attrs: { type: 'button' }, text: 'Cancel', onClick: () => { ctx.uploadTarget = null; ctx.emit('upload-target', { id: null }); } }) : null));
    }
    ctx.on('upload-target', paintTarget); ctx.on('selection', paintTarget);

    async function load(append) {
        const token = ++state.token;
        state.loading = true; state.error = null;
        if (!append) { state.cursor = null; state.items = []; }
        paint();
        const r = await ctx.api.get(ctx.url('design.assets.index'), { per_page: 40, cursor: append ? state.cursor : null, q: state.q, folder: state.folder });
        if (token !== state.token) return;
        state.loading = false;
        if (!r.ok) { state.error = r.message || 'Your pictures could not be loaded.'; paint(); return; }
        ctx.addAssets(r.data.assets);
        state.items = append ? state.items.concat(r.data.assets) : r.data.assets;
        state.cursor = r.data.next_cursor; state.usage = r.data.usage;
        paint(); paintUsage();
    }
    (async () => { const r = await ctx.api.get(ctx.url('design.assets.folders')); if (r.ok && (r.data.folders || []).length) { for (const f of r.data.folders) folderSel.appendChild(h('option', { value: f.name, text: `${f.name} (${f.count})` })); } else folderSel.hidden = true; })();
    load(false);
    return { show() { paintTarget(); } };
}

/*
 * Assets library page. Grid or list, search and filters, folders and tags, drag and drop upload with a per-file queue, bulk actions
 * and a details drawer. Every figure and limit comes from the server (usage, quota, upload limits); a refused upload shows the
 * server's exact reason. Files are private, so there is no "copy link": a picture opens only for people who can see where it lives.
 */
import { api, append, clear, confirmDialog, csrfToken, debounce, el, firstError, formatBytes, icon, readBoot, toast } from './brand-core.js';
import { createQueue, xhrTransport } from './assets-queue.js';
import { openDetail } from './assets-detail.js';

const boot = readBoot('assets-boot');
const root = document.getElementById('assets-page');
const $ = (sel) => root.querySelector(sel);

const VIEW_KEY = 'dot-assets-view';
const state = {
    filters: { q: '', type: '', used: '', folder: '', tag: '', sort: 'newest' },
    mode: 'grid',
    items: [],
    next: null,
    total: 0,
    loaded: false,
    error: null,
    selected: new Set(),
    meta: { folders: [], tags: [], usage: boot.usage, unfiled: 0, total: 0 },
};

// ---- helpers ----------------------------------------------------------------------------------------------------------

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
const hasFilters = () => Object.entries(state.filters).some(([k, v]) => k !== 'sort' && v !== '');

function params(extra = {}) {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(state.filters)) if (v !== '' && !(k === 'sort' && v === 'newest')) p.set(k, v);
    for (const [k, v] of Object.entries(extra)) p.set(k, v);
    return p;
}

function syncUrl() {
    const p = params();
    history.replaceState(null, '', location.pathname + (p.toString() ? '?' + p.toString() : ''));
}

function readUrl() {
    const p = new URLSearchParams(location.search);
    for (const k of Object.keys(state.filters)) if (p.has(k)) state.filters[k] = p.get(k);
    try { const m = localStorage.getItem(VIEW_KEY); if (m === 'list' || m === 'grid') state.mode = m; } catch (e) { /* storage blocked */ }
    for (const [k, v] of Object.entries(state.filters)) { const input = $(`[data-f="${k}"]`); if (input) input.value = v; }
}

// ---- loading ----------------------------------------------------------------------------------------------------------

async function loadMeta() {
    const res = await api('GET', boot.urls.meta);
    if (res.ok) { state.meta = res.data; renderSide(); renderQuota(); }
}

async function loadFirst() {
    state.error = null;
    const res = await api('GET', `${boot.urls.library}?${params({ per_page: 36 }).toString()}`);
    state.loaded = true;
    if (!res.ok) { state.error = firstError(res.errors) || res.message; state.items = []; state.next = null; renderResults(); return; }
    state.items = res.data.assets;
    state.next = res.data.next_cursor;
    state.total = res.data.total;
    // anything selected that is no longer listed (a filter changed, a file was deleted) is dropped from the selection
    const ids = new Set(state.items.map((a) => a.id));
    for (const id of [...state.selected]) if (!ids.has(id)) state.selected.delete(id);
    renderResults(); renderBulk(); renderActive();
}

async function loadMore() {
    if (!state.next) return;
    const btn = $('[data-act="more"]');
    btn.setAttribute('aria-busy', 'true');
    const res = await api('GET', `${boot.urls.library}?${params({ per_page: 36, cursor: state.next }).toString()}`);
    btn.removeAttribute('aria-busy');
    if (!res.ok) { toast(res.message, 'bad'); return; }
    state.items = state.items.concat(res.data.assets);
    state.next = res.data.next_cursor;
    renderResults();
}

const refresh = () => Promise.all([loadFirst(), loadMeta()]);

// ---- side: quota, folders, tags --------------------------------------------------------------------------------------------

function renderQuota() {
    const u = state.meta.usage || boot.usage;
    const pct = u.quota > 0 ? Math.min(100, (u.used / u.quota) * 100) : 0;
    const bar = $('[data-quota-bar]');
    if (bar) { bar.setAttribute('aria-valuenow', String(Math.round(pct))); const span = bar.querySelector('span'); if (span) span.style.setProperty('--p', String(pct / 100)); }
    $('[data-quota-text]').textContent = `${formatBytes(u.used)} of ${formatBytes(u.quota)} used`;
    const sub = root.querySelector('.lt-pagehead__sub');
    if (sub) sub.textContent = `Pictures you and your workspace have added. ${formatBytes(u.used)} of ${formatBytes(u.quota)} used.`;
}

function folderRow({ label, value, count, own, name }) {
    const current = state.filters.folder === value;
    const btn = el('button', { type: 'button', class: 'as-folder__btn', 'aria-current': current ? 'true' : null, onclick: () => setFilter('folder', current ? '' : value) }, [
        icon(value === '' || value === '_none' ? 'images' : 'folder', 'sm'), el('span', { text: label }), el('span', { class: 'as-count', text: String(count) }),
    ]);
    const row = el('li', { class: 'as-folder', 'data-folder': value }, btn);
    if (name) {
        // a picture (or the selection) dropped on a folder moves there
        row.addEventListener('dragover', (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('application/x-asset-ids')) { e.preventDefault(); row.classList.add('is-over'); } });
        row.addEventListener('dragleave', () => row.classList.remove('is-over'));
        row.addEventListener('drop', async (e) => {
            row.classList.remove('is-over');
            const raw = e.dataTransfer.getData('application/x-asset-ids');
            if (!raw) return;
            e.preventDefault();
            await bulk({ ids: JSON.parse(raw), action: 'move', folder: value === '_none' ? null : name });
        });
    }
    if (own) {
        row.appendChild(el('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': `Rename folder ${label}`, 'data-tip': 'Rename', onclick: () => renameFolder(label) }, icon('pencil', 'sm')));
        row.appendChild(el('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': `Delete folder ${label}`, 'data-tip': 'Delete', onclick: () => deleteFolder(label) }, icon('trash', 'sm')));
    }
    return row;
}

function renderSide() {
    const list = $('[data-folders]');
    clear(list);
    list.appendChild(folderRow({ label: 'All pictures', value: '', count: state.meta.total }));
    list.appendChild(folderRow({ label: 'No folder', value: '_none', count: state.meta.unfiled, name: '_none' }));
    for (const f of state.meta.folders) list.appendChild(folderRow({ label: f.name, value: f.name, count: f.count, own: f.own, name: f.name }));

    const tags = $('[data-tags]');
    clear(tags);
    if (!state.meta.tags.length) tags.appendChild(el('p', { class: 'lt-meta', text: 'No tags yet. Add tags to a picture to find it quickly.' }));
    for (const t of state.meta.tags) {
        tags.appendChild(el('button', { type: 'button', class: 'as-tag', 'aria-pressed': state.filters.tag === t.name ? 'true' : 'false', onclick: () => setFilter('tag', state.filters.tag === t.name ? '' : t.name) }, [t.name, el('span', { class: 'as-count', text: String(t.count) })]));
    }
}

function nameDialog({ title, label, value = '', confirmLabel, hint }) {
    return new Promise((resolve) => {
        const dialog = el('dialog', { class: 'lt-dialog', 'aria-labelledby': 'nd-title' });
        const input = el('input', { class: 'lt-input', value, maxlength: 80, 'aria-label': label, autocomplete: 'off' });
        const err = el('p', { class: 'lt-error', hidden: true });
        const finish = (v) => { dialog.close(); dialog.remove(); resolve(v); };
        const submit = () => { const v = input.value.trim(); if (!v) { err.textContent = 'Type a name first.'; err.hidden = false; return; } finish(v); };
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
        append(dialog, el('div', { class: 'lt-dialog__wrap' }, el('div', { class: 'lt-dialog__box lt-dialog__box--sm' }, [
            el('div', { class: 'lt-dialog__head' }, el('h2', { class: 'lt-dialog__title', id: 'nd-title', text: title })),
            el('div', { class: 'lt-dialog__body' }, el('div', { class: 'lt-field' }, [el('label', { class: 'lt-label', text: label }), input, hint ? el('p', { class: 'lt-hint', text: hint }) : null, err])),
            el('div', { class: 'lt-dialog__foot' }, [el('button', { type: 'button', class: 'lt-btn lt-btn--secondary', onclick: () => finish(null), text: 'Cancel' }), el('button', { type: 'button', class: 'lt-btn', onclick: submit, text: confirmLabel })]),
        ])));
        dialog.addEventListener('cancel', (e) => { e.preventDefault(); finish(null); });
        document.body.appendChild(dialog);
        dialog.showModal();
        input.focus(); input.select();
    });
}

async function createFolder() {
    const name = await nameDialog({ title: 'New folder', label: 'Folder name', confirmLabel: 'Create folder', hint: 'Letters, numbers, spaces and - _ . & / are fine.' });
    if (!name) return;
    const res = await api('POST', boot.urls.folders, { name });
    if (!res.ok) { toast(firstError(res.errors) || res.message, 'bad'); return; }
    state.meta = res.data; renderSide();
    toast(`Folder ${name} created. Drop pictures on it, or move pictures into it.`, 'ok');
}

async function renameFolder(from) {
    const to = await nameDialog({ title: `Rename ${from}`, label: 'New name', value: from, confirmLabel: 'Rename' });
    if (!to || to === from) return;
    const res = await api('PUT', boot.urls.folders, { from, to });
    if (!res.ok) { toast(firstError(res.errors) || res.message, 'bad'); return; }
    if (state.filters.folder === from) state.filters.folder = to;
    state.meta = res.data; renderSide(); toast(`Renamed to ${to}.`, 'ok'); loadFirst();
}

async function deleteFolder(name) {
    const ok = await confirmDialog({ title: `Delete the folder ${name}?`, body: 'The pictures in it are not deleted. They move to "No folder".', confirmLabel: 'Delete folder', danger: true });
    if (!ok) return;
    const res = await api('DELETE', boot.urls.folders, { name });
    if (!res.ok) { toast(res.message, 'bad'); return; }
    if (state.filters.folder === name) state.filters.folder = '';
    state.meta = res.data; renderSide(); toast(res.data.moved ? `Folder deleted. ${plural(res.data.moved, 'picture moved', 'pictures moved')} to No folder.` : 'Folder deleted.', 'ok'); syncUrl(); loadFirst();
}

// ---- filters ----------------------------------------------------------------------------------------------------------------

function setFilter(key, value) {
    state.filters[key] = value;
    const input = $(`[data-f="${key}"]`);
    if (input) input.value = value;
    syncUrl(); renderSide(); loadFirst();
}

function renderActive() {
    const box = $('[data-active-filters]');
    clear(box);
    const labels = { q: (v) => `Name has "${v}"`, type: (v) => `Type: ${{ png: 'PNG', jpeg: 'JPEG', webp: 'WebP' }[v]}`, used: (v) => (v === 'used' ? 'Used in a design' : 'Not used yet'), folder: (v) => (v === '_none' ? 'No folder' : `Folder: ${v}`), tag: (v) => `Tag: ${v}` };
    const active = Object.keys(labels).filter((k) => state.filters[k] !== '');
    box.hidden = active.length === 0;
    if (!active.length) return;
    box.appendChild(el('span', { role: 'status', text: `${plural(state.total, 'picture', 'pictures')} match` }));
    for (const k of active) box.appendChild(el('button', { type: 'button', class: 'lt-chip is-on', 'aria-label': `Remove filter ${labels[k](state.filters[k])}`, onclick: () => setFilter(k, '') }, [labels[k](state.filters[k]), icon('x', 'sm')]));
    box.appendChild(el('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => { for (const k of active) { state.filters[k] = ''; const i = $(`[data-f="${k}"]`); if (i) i.value = ''; } syncUrl(); renderSide(); loadFirst(); }, text: 'Clear all' }));
    if (state.filters.tag) {
        box.appendChild(el('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: renameTag, text: 'Rename this tag' }));
        box.appendChild(el('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: deleteTag, text: 'Delete this tag' }));
    }
}

async function renameTag() {
    const from = state.filters.tag;
    const to = await nameDialog({ title: `Rename the tag ${from}`, label: 'New tag', value: from, confirmLabel: 'Rename', hint: 'Changes it on your own pictures that use it.' });
    if (!to || to === from) return;
    const res = await api('PUT', boot.urls.tags, { from, to });
    if (!res.ok) { toast(firstError(res.errors) || res.message, 'bad'); return; }
    state.filters.tag = to; state.meta = res.data; toast(`Renamed on ${plural(res.data.changed, 'picture', 'pictures')}.`, 'ok'); syncUrl(); renderSide(); loadFirst();
}

async function deleteTag() {
    const name = state.filters.tag;
    const ok = await confirmDialog({ title: `Delete the tag ${name}?`, body: 'The tag is removed from your pictures. The pictures stay.', confirmLabel: 'Delete tag', danger: true });
    if (!ok) return;
    const res = await api('DELETE', boot.urls.tags, { name });
    if (!res.ok) { toast(res.message, 'bad'); return; }
    state.filters.tag = ''; state.meta = res.data; toast(`Removed from ${plural(res.data.changed, 'picture', 'pictures')}.`, 'ok'); syncUrl(); renderSide(); loadFirst();
}

// ---- results ----------------------------------------------------------------------------------------------------------------

function tile(a) {
    const selected = state.selected.has(a.id);
    const box = el('input', { type: 'checkbox', checked: selected, 'aria-label': `Select ${a.name}`, onchange: () => { if (box.checked) state.selected.add(a.id); else state.selected.delete(a.id); renderBulk(); node.classList.toggle('is-selected', box.checked); root.querySelector('.as-grid').classList.toggle('as-selecting', state.selected.size > 0); } });
    const node = el('div', { class: 'as-tile' + (selected ? ' is-selected' : ''), role: 'listitem', draggable: 'true', 'data-id': a.id }, [
        el('button', { type: 'button', class: 'as-tile__open', 'aria-label': `Open details of ${a.name}`, onclick: () => openDetail({ asset: a, boot, state, onChanged: refresh, onDeleted: refresh }) }),
        el('div', { class: 'as-tile__check' }, box),
        el('div', { class: 'as-tile__pic' }, el('img', { src: a.url, alt: a.name, loading: 'lazy', width: a.width || 128, height: a.height || 128 })),
        el('div', { class: 'as-tile__meta' }, [
            el('span', { class: 'as-tile__name', text: a.name, title: a.name }),
            el('span', { class: 'as-tile__sub' }, [`${a.width} x ${a.height}`, a.used_in ? el('span', { class: 'as-used', text: `In ${plural(a.used_in, 'design', 'designs')}` }) : el('span', { class: 'as-unused', text: 'Not used yet' })]),
        ]),
        el('div', { class: 'as-tile__extra' }, [el('span', { text: a.type }), el('span', { text: formatBytes(a.size) }), el('span', { text: a.folder || 'No folder' })]),
    ]);
    node.addEventListener('dragstart', (e) => {
        const ids = state.selected.has(a.id) ? [...state.selected] : [a.id];
        e.dataTransfer.setData('application/x-asset-ids', JSON.stringify(ids));
        e.dataTransfer.effectAllowed = 'move';
    });
    return node;
}

function renderResults() {
    const host = $('[data-results]');
    clear(host);
    root.querySelector('[data-as]').setAttribute('data-as-mode', state.mode);
    $('[data-more]').hidden = !state.next;

    if (state.error) {
        append(host, el('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, [icon('alert'), el('div', {}, [el('p', { text: state.error }), el('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: refresh, text: 'Try again' })])]));
        return;
    }
    if (!state.items.length) {
        if (hasFilters()) {
            append(host, emptyState('No pictures match', 'Nothing in your library fits those filters.', [el('button', { type: 'button', class: 'lt-btn lt-btn--secondary', onclick: () => { for (const k of Object.keys(state.filters)) if (k !== 'sort') { state.filters[k] = ''; const i = $(`[data-f="${k}"]`); if (i) i.value = ''; } syncUrl(); renderSide(); loadFirst(); }, text: 'Clear filters' })]));
        } else {
            append(host, emptyState('No pictures yet', 'Upload logos, photos and artwork once, then place them in any design. PNG, JPEG or WebP, up to ' + Math.round(boot.limits.max_kb / 1024) + ' MB each. You can also drop files anywhere on this page.', [el('button', { type: 'button', class: 'lt-btn', onclick: pickFiles }, [icon('upload'), 'Upload pictures'])]));
        }
        return;
    }
    const grid = el('div', { class: 'as-grid' + (state.selected.size ? ' as-selecting' : ''), role: 'list', 'aria-label': 'Pictures' }, state.items.map(tile));
    append(host, [el('p', { class: 'lt-meta as-count-line', text: `Showing ${state.items.length} of ${state.total}` }), grid]);
}

function emptyState(title, text, actions) {
    return el('div', { class: 'lt-empty' }, [
        el('span', { class: 'lt-empty__mark lt-crop lt-crop--quiet', style: { '--crop-len': '7px', '--crop-gap': '5px', display: 'inline-grid', 'place-items': 'center', width: '2.75rem', height: '2.75rem', color: 'var(--lt-ink-3)' } }, icon('image', 'lg')),
        el('h2', { class: 'lt-empty__title', text: title }),
        el('p', { class: 'lt-empty__text', text }),
        el('div', { class: 'lt-empty__actions' }, actions),
    ]);
}

// ---- selection and bulk ----------------------------------------------------------------------------------------------------

async function bulk(body) {
    const res = await api('POST', boot.urls.bulk, body);
    if (!res.ok) { toast(firstError(res.errors) || res.message, 'bad'); return null; }
    state.meta = res.data; renderSide(); renderQuota();
    const failed = res.data.results.filter((r) => !r.ok);
    const verb = { move: 'Moved', tag_add: 'Tagged', tag_remove: 'Untagged', delete: 'Deleted' }[body.action];
    if (res.data.done) toast(`${verb} ${plural(res.data.done, 'picture', 'pictures')}.`, 'ok');
    if (failed.length) {
        await confirmDialog({ title: `${plural(failed.length, 'picture was', 'pictures were')} not changed`, body: el('ul', { class: 'br-stack br-stack--tight' }, failed.slice(0, 8).map((f) => el('li', {}, [el('b', { text: (f.name || 'A picture') + ': ' }), f.message]))), confirmLabel: 'OK', hideConfirm: true, cancelLabel: 'Close' });
    }
    for (const r of res.data.results) if (r.ok && body.action === 'delete') state.selected.delete(r.id);
    await loadFirst();
    return res.data;
}

function renderBulk() {
    const bar = $('[data-bulk]');
    clear(bar);
    const n = state.selected.size;
    bar.hidden = n === 0;
    if (!n) return;
    const folderSel = el('select', { class: 'lt-select', 'aria-label': 'Move to folder' }, [el('option', { value: '', text: 'Move to folder' }), el('option', { value: '_none', text: 'No folder' }), ...state.meta.folders.map((f) => el('option', { value: f.name, text: f.name })), el('option', { value: '_new', text: 'New folder' })]);
    folderSel.addEventListener('change', async () => {
        let v = folderSel.value; folderSel.value = '';
        if (!v) return;
        if (v === '_new') { v = await nameDialog({ title: 'Move to a new folder', label: 'Folder name', confirmLabel: 'Create and move' }); if (!v) return; }
        await bulk({ ids: [...state.selected], action: 'move', folder: v === '_none' ? null : v });
    });
    const tagInput = el('input', { class: 'lt-input', placeholder: 'Tag', maxlength: 30, 'aria-label': 'Tag to add' });
    const addTag = async () => { const t = tagInput.value.trim(); if (!t) { tagInput.focus(); return; } const r = await bulk({ ids: [...state.selected], action: 'tag_add', tag: t }); if (r) tagInput.value = ''; };
    tagInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } });
    append(bar, [
        el('span', { class: 'as-bulk__count', role: 'status', text: `${n} selected` }),
        folderSel,
        tagInput, el('button', { type: 'button', class: 'lt-btn lt-btn--sm', onclick: addTag, text: 'Add tag' }),
        el('button', { type: 'button', class: 'lt-btn lt-btn--sm lt-btn--danger', onclick: bulkDelete }, [icon('trash', 'sm'), 'Delete']),
        el('button', { type: 'button', class: 'lt-btn lt-btn--sm', onclick: () => { state.selected.clear(); renderResults(); renderBulk(); }, text: 'Clear selection' }),
    ]);
}

async function bulkDelete() {
    const ids = [...state.selected];
    const ok = await confirmDialog({ title: `Delete ${plural(ids.length, 'picture', 'pictures')}?`, body: 'This cannot be undone. A picture that is placed on a design is not deleted: you will be told which ones and why.', confirmLabel: 'Delete', danger: true });
    if (ok) await bulk({ ids, action: 'delete' });
}

// ---- upload -----------------------------------------------------------------------------------------------------------------

const queue = createQueue({
    transport: (item, opts) => xhrTransport({ url: boot.urls.upload, csrf: csrfToken })(item, opts),
    maxKb: boot.limits.max_kb,
    concurrency: 2,
    onChange: renderQueue,
});
let finishedToast = 0;

const settled = debounce(() => {
    const c = queue.counts();
    if (queue.busy()) return;
    refresh();
    if (c.done && finishedToast !== c.done) { toast(`Uploaded ${plural(c.done, 'picture', 'pictures')}.`, 'ok'); finishedToast = c.done; }
}, 400);

function currentUploadTarget() {
    const f = state.filters.folder;
    return { folder: f && f !== '_none' ? f : null, tags: state.filters.tag ? [state.filters.tag] : [] };
}

function addFiles(files) {
    const list = Array.from(files).filter((f) => f && f.name !== undefined);
    if (!list.length) return;
    finishedToast = 0;
    queue.add(list, currentUploadTarget());
}

function pickFiles() { $('[data-file-input]').click(); }

const STATUS_TEXT = { queued: 'Waiting', uploading: 'Uploading', done: 'Added to your library', cancelled: 'Cancelled' };

function renderQueue(items) {
    const box = $('[data-queue]');
    clear(box);
    box.hidden = items.length === 0;
    if (!items.length) return;
    const c = queue.counts();
    const active = c.queued + c.uploading;
    append(box, el('div', { class: 'as-queue__head' }, [
        el('h2', { class: 'lt-h3', text: active ? `Uploading ${plural(active, 'picture', 'pictures')}` : 'Uploads' }),
        el('div', { class: 'br-row' }, [active ? null : el('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => queue.clearFinished(), text: 'Clear list' })]),
    ]));
    for (const i of items) {
        const text = i.status === 'failed' ? i.error : (i.status === 'done' && i.duplicate ? 'Already in your library' : (i.status === 'uploading' ? `Uploading ${Math.round(i.progress * 100)}%` : STATUS_TEXT[i.status]));
        append(box, el('div', { class: 'as-up', 'data-status': i.status }, [
            el('p', { class: 'as-up__name', text: `${i.name} (${formatBytes(i.size)})` }),
            el('div', { class: 'as-up__actions' }, [
                i.status === 'failed' && i.retryable ? el('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => queue.retry(i.id), text: 'Retry' }) : null,
                i.status === 'queued' || i.status === 'uploading' ? el('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => queue.cancel(i.id), text: 'Cancel' }) : null,
                ['failed', 'done', 'cancelled'].includes(i.status) ? el('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': `Remove ${i.name} from the list`, onclick: () => queue.remove(i.id) }, icon('x', 'sm')) : null,
            ]),
            el('p', { class: 'as-up__status', role: i.status === 'failed' ? 'alert' : null, text }),
            i.status === 'uploading' || i.status === 'queued' ? el('div', { class: 'lt-progress', role: 'progressbar', 'aria-label': `Upload of ${i.name}`, 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(i.progress * 100)) }, el('span', { style: { '--p': String(i.progress) } })) : null,
        ]));
    }
    if (!active) settled();
}

// drop anywhere
let dragDepth = 0;
const dropZone = $('[data-drop]');
const hasFiles = (e) => e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files');
document.addEventListener('dragenter', (e) => { if (hasFiles(e)) { dragDepth += 1; dropZone.classList.add('is-over'); } });
document.addEventListener('dragleave', (e) => { if (hasFiles(e)) { dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) dropZone.classList.remove('is-over'); } });
document.addEventListener('dragover', (e) => { if (hasFiles(e)) e.preventDefault(); });
document.addEventListener('drop', (e) => { if (!hasFiles(e)) return; e.preventDefault(); dragDepth = 0; dropZone.classList.remove('is-over'); addFiles(e.dataTransfer.files); });

$('[data-file-input]').addEventListener('change', (e) => { addFiles(e.target.files); e.target.value = ''; });

// ---- wiring -----------------------------------------------------------------------------------------------------------------

root.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]');
    if (!act) return;
    const name = act.getAttribute('data-act');
    if (name === 'upload') pickFiles();
    else if (name === 'new-folder') createFolder();
    else if (name === 'more') loadMore();
});

const searchDebounced = debounce((v) => setFilter('q', v), 300);
root.querySelectorAll('[data-f]').forEach((input) => {
    const key = input.getAttribute('data-f');
    if (key === 'q') input.addEventListener('input', () => searchDebounced(input.value.trim()));
    else input.addEventListener('change', () => setFilter(key, input.value));
});

root.querySelectorAll('[data-as-view]').forEach((b) => b.addEventListener('click', () => {
    state.mode = b.getAttribute('data-as-view');
    try { localStorage.setItem(VIEW_KEY, state.mode); } catch (err) { /* ignore */ }
    root.querySelectorAll('[data-as-view]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    renderResults();
}));

readUrl();
root.querySelectorAll('[data-as-view]').forEach((x) => x.setAttribute('aria-pressed', String(x.getAttribute('data-as-view') === state.mode)));
renderSide();
refresh();

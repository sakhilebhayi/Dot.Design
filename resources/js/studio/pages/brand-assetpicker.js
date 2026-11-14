/*
 * Choosing or uploading a picture for the kit: one dialog over the asset library, and the upload call the kit editor's
 * "Upload" buttons share. Uploads go to the same endpoint as the assets library and the editor, so every limit (size, type,
 * dimensions, quota) is the server's, and a refusal is shown with the server's exact words.
 */
import { api, append, clear, el, firstError, icon } from './brand-core.js';

export const ACCEPT = 'image/png,image/jpeg,image/webp';
const TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/** Check a file in the browser before sending it, with the same words the server uses. Returns a message or null. */
export function precheck(file, maxKb) {
    if (!TYPES.includes(file.type)) return 'Only JPG, PNG or WebP images can be uploaded. SVG and other formats are not accepted.';
    if (file.size > maxKb * 1024) return `The file is larger than the ${Math.round(maxKb / 1024)} MB upload limit.`;
    return null;
}

/** POST one picture. Resolves { ok, asset, duplicate, message }. */
export async function uploadAsset(url, file, { folder = 'Brand', maxKb = 8192 } = {}) {
    const early = precheck(file, maxKb);
    if (early) return { ok: false, message: early };
    const body = new FormData();
    body.append('file', file);
    if (folder) body.append('folder', folder);
    const res = await api('POST', url, body);
    if (!res.ok) return { ok: false, message: firstError(res.errors) || res.message };
    return { ok: true, asset: res.data.asset, duplicate: !!res.data.duplicate };
}

/**
 * pickAsset({ boot, title }) -> Promise<asset | null>. Lists the library (search, newest first), lets the person upload a new
 * picture right there, and resolves with the chosen asset (the editor-array shape: id, name, url, width, height).
 */
export function pickAsset({ boot, title = 'Choose a picture' }) {
    return new Promise((resolve) => {
        const dialog = el('dialog', { class: 'lt-dialog', 'aria-labelledby': 'ap-title' });
        let settled = false;
        const finish = (value) => { if (settled) return; settled = true; dialog.close(); dialog.remove(); resolve(value); };

        const status = el('p', { class: 'lt-small', role: 'status', 'aria-live': 'polite' });
        const grid = el('div', { class: 'br-imgs', role: 'list' });
        const search = el('input', { type: 'search', class: 'dsr-filter', placeholder: 'Search your pictures', 'aria-label': 'Search your pictures', maxlength: 100 });
        const file = el('input', { type: 'file', accept: ACCEPT, hidden: true, 'aria-label': 'Upload a picture' });
        let timer = null;

        async function load() {
            status.textContent = 'Loading your pictures';
            const q = search.value.trim();
            const res = await api('GET', `${boot.urls.assetLibrary}?per_page=48${q ? '&q=' + encodeURIComponent(q) : ''}`);
            clear(grid);
            if (!res.ok) { status.textContent = res.message; return; }
            const list = res.data.assets || [];
            status.textContent = list.length ? '' : (q ? 'No pictures match that search.' : 'You have not uploaded any pictures yet. Upload one to start.');
            for (const a of list) {
                grid.appendChild(el('div', { role: 'listitem' }, el('button', { type: 'button', class: 'br-img', onclick: () => finish(a), 'aria-label': `Use ${a.name}` }, [
                    el('span', { class: 'br-img__pic' }, el('img', { src: a.preview_url || a.url, alt: '', loading: 'lazy', width: 128, height: 128 })),
                    el('span', { class: 'lt-small', text: a.name, style: { 'overflow-wrap': 'anywhere' } }),
                ])));
            }
        }

        file.addEventListener('change', async () => {
            const f = file.files[0];
            file.value = '';
            if (!f) return;
            status.textContent = 'Uploading';
            const r = await uploadAsset(boot.urls.assetUpload, f, { maxKb: boot.limits.max_kb });
            if (!r.ok) { status.textContent = r.message; return; }
            finish(r.asset);
        });
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250); });

        append(dialog, el('div', { class: 'lt-dialog__wrap' }, el('div', { class: 'lt-dialog__box lt-dialog__box--wide' }, [
            el('div', { class: 'lt-dialog__head' }, [
                el('h2', { class: 'lt-dialog__title', id: 'ap-title', text: title }),
                el('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': 'Close', onclick: () => finish(null) }, icon('x')),
            ]),
            el('div', { class: 'lt-dialog__body' }, el('div', { class: 'br-stack' }, [
                el('div', { class: 'br-row' }, [
                    el('div', { style: { flex: '1 1 14rem' } }, search),
                    el('button', { type: 'button', class: 'lt-btn lt-btn--secondary', onclick: () => file.click() }, [icon('upload'), 'Upload new']),
                    file,
                ]),
                status,
                grid,
                el('p', { class: 'lt-meta', text: 'PNG, JPEG or WebP. Upload only pictures you have the right to use.' }),
            ])),
        ])));
        dialog.addEventListener('cancel', (e) => { e.preventDefault(); finish(null); });
        dialog.addEventListener('click', (e) => { if (e.target.classList && e.target.classList.contains('lt-dialog__wrap')) finish(null); });
        document.body.appendChild(dialog);
        dialog.showModal();
        load();
        search.focus();
    });
}

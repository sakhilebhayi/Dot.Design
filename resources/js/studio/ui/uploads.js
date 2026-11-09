/*
 * Picture uploads: validation, progress (XMLHttpRequest, because fetch cannot
 * report upload progress), the master asset index and putting pictures on the
 * page. The server already re-encodes images and enforces the quota; this layer
 * only turns every outcome into a plain sentence.
 */
import { find } from '../doc/tree.js';
import { addPicture, setPicture } from './actions.js';
import { activePage } from './describe.js';
import { messageFor } from './api.js';

export const ACCEPT = 'image/jpeg,image/png,image/webp';
export const MAX_BYTES = 8 * 1024 * 1024;

export function checkFile(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return `${file.name} is not a JPG, PNG or WebP picture. SVG and other formats are not accepted.`;
    if (file.size > MAX_BYTES) return `${file.name} is larger than 8 MB. Resize it and try again.`;
    if (file.size === 0) return `${file.name} is empty.`;
    return null;
}

export function createUploads(ctx) {
    const jobs = new Map();
    let seq = 0;
    const csrf = () => { const m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };

    function upload(file, { folder, tags } = {}) {
        const id = ++seq;
        const job = { id, name: file.name, size: file.size, progress: 0, status: 'uploading', message: '' };
        jobs.set(id, job);
        ctx.emit('uploads', { jobs });
        const bad = checkFile(file);
        if (bad) { job.status = 'failed'; job.message = bad; ctx.emit('uploads', { jobs }); return Promise.resolve({ ok: false, message: bad, job }); }
        return new Promise((resolve) => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('project_id', String(ctx.config.project.id));
            if (folder) fd.append('folder', folder);
            (tags || []).forEach((t) => fd.append('tags[]', t));
            const xhr = new XMLHttpRequest();
            xhr.open('POST', ctx.url('design.assets.store'));
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable) { job.progress = e.loaded / e.total; ctx.emit('uploads', { jobs }); } });
            const finish = (ok, message, asset, duplicate) => {
                job.status = ok ? 'done' : 'failed'; job.message = message || ''; job.progress = ok ? 1 : job.progress;
                ctx.emit('uploads', { jobs });
                setTimeout(() => { if (jobs.get(id) && jobs.get(id).status === 'done') { jobs.delete(id); ctx.emit('uploads', { jobs }); } }, 4000);
                resolve({ ok, message, asset, duplicate, job });
            };
            xhr.onload = () => {
                let data = null; try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                if (xhr.status >= 200 && xhr.status < 300 && data && data.asset) {
                    ctx.addAssets([{ ...data.asset, owned: true }]);
                    ctx.emit('asset-added', { asset: data.asset });
                    finish(true, '', data.asset, !!data.duplicate);
                } else {
                    const status = xhr.status === 401 || xhr.status === 419 ? xhr.status : xhr.status;
                    if (status === 401 || status === 419) ctx._hooks.onSession({ status });
                    const quota = data && data.reason === 'quota';
                    finish(false, quota ? (data.message || 'You are out of picture storage. Delete pictures you no longer use.') : (data && data.message) || messageFor(xhr.status, data), null, false);
                }
            };
            xhr.onerror = () => finish(false, messageFor(0), null, false);
            xhr.onabort = () => finish(false, 'The upload was cancelled.', null, false);
            job.cancel = () => xhr.abort();
            xhr.send(fd);
        });
    }

    function dismiss(id) { jobs.delete(id); ctx.emit('uploads', { jobs }); }

    /** Put a chosen picture on the page: into the target slot when there is one, otherwise as a new picture. */
    function usePicture(asset, { point, targetId } = {}) {
        const st = ctx.studio;
        const page = activePage(st.getState());
        let id = targetId ?? ctx.uploadTarget ?? null;
        if (id === null) {
            const sel = ctx.selection();
            if (sel.emptyPicture) id = sel.el.id;
        }
        if (id) {
            const hit = find(page.elements, id);
            if (hit && hit.element.type === 'image') {
                ctx.addAssets([asset]);
                const r = setPicture(ctx, id, asset.id);
                ctx.uploadTarget = null; ctx.emit('upload-target', { id: null });
                st.select([id]);
                return r;
            }
        }
        ctx.uploadTarget = null;
        return ctx.insert({ kind: 'asset', asset }, { point });
    }

    async function placeFiles(files, { point } = {}) {
        const list = Array.from(files).filter((f) => f && f.type !== undefined);
        if (!list.length) return;
        let placed = 0; let first = true;
        for (const file of list.slice(0, 10)) {
            const r = await upload(file);
            if (!r.ok) { ctx.toast(r.message, { kind: 'bad' }); continue; }
            usePicture(r.asset, { point: first ? point : undefined });
            first = false; placed += 1;
        }
        if (placed) ctx.toast(placed === 1 ? 'Picture added.' : `${placed} pictures added.`);
    }

    /** Make sure the pictures a set of pages uses are in the asset index (the engine drops a picture it cannot find). */
    async function ensureAssets(pages) {
        const want = new Set();
        const walk = (list) => { for (const e of list || []) { if (e.type === 'image' && e.asset_id != null) want.add(Number(e.asset_id)); if (e.children) walk(e.children); } };
        for (const p of pages) { walk(p.elements); if (p.background && p.background.asset_id != null) want.add(Number(p.background.asset_id)); }
        const missing = Array.from(want).filter((id) => !ctx.assets.has(id));
        if (!missing.length) return;
        for (let page = 0, cursor = null; page < 5; page += 1) {
            const r = await ctx.api.get(ctx.url('design.assets.index'), { per_page: 100, cursor }, { headers: { Accept: 'application/json' } });
            if (!r.ok) return;
            ctx.addAssets(r.data.assets || []);
            cursor = r.data.next_cursor;
            if (!cursor || missing.every((id) => ctx.assets.has(id))) return;
        }
    }

    return { upload, jobs, dismiss, usePicture, placeFiles, ensureAssets };
}

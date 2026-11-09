/*
 * The upload queue of the assets library, as a small state machine with no DOM. Every file is one item:
 *
 *   queued -> uploading -> done
 *                       -> failed  (with a reason: size, type, dimensions, decode, quota, rate, network, server) -> queued again on retry
 *   queued | uploading -> cancelled
 *
 * Files are checked in the browser first (type and size, with the same words the server uses), then sent a few at a time. The
 * transport is injected, so the rules can be tested without a network: transport(item, { onProgress, signal }) resolves
 * { ok: true, asset, duplicate } or { ok: false, message, reason, status }.
 */

export const ACCEPTED_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/** Reasons a person can fix by changing the file: retrying the same file cannot work. */
export const PERMANENT_REASONS = ['type', 'size', 'dimensions', 'decode', 'empty', 'memory', 'invalid'];

export function describeFailure({ status, reason, message }) {
    if (status === 429) return { reason: 'rate', message: message || 'Too many uploads in a short time. Wait a minute, then retry.' };
    if (status === 413) return { reason: 'size', message: message || 'The file is larger than the server accepts.' };
    if (status === 0) return { reason: 'network', message: message || 'The connection dropped before the upload finished. Check your connection, then retry.' };
    if (status >= 500) return { reason: 'server', message: 'The server could not store the file. Nothing was lost. Try again in a moment.' };
    return { reason: reason || 'invalid', message: message || 'This picture could not be added.' };
}

/** Browser-side check, in the server's words. Returns null when the file may be sent. */
export function precheck(file, { maxKb }) {
    if (!ACCEPTED_TYPES.includes(file.type)) return { reason: 'type', message: 'Only JPG, PNG or WebP images can be uploaded. SVG and other formats are not accepted.' };
    if (file.size === 0) return { reason: 'size', message: 'That file is empty.' };
    if (file.size > maxKb * 1024) return { reason: 'size', message: `The file is larger than the ${Math.round(maxKb / 1024)} MB upload limit.` };
    return null;
}

export function createQueue({ transport, maxKb = 8192, concurrency = 2, onChange = () => {}, onDone = () => {} } = {}) {
    let nextId = 1;
    const items = [];
    const controllers = new Map();
    let running = 0;

    const emit = () => onChange(items.map((i) => ({ ...i })));
    const find = (id) => items.find((i) => i.id === id);

    function counts() {
        const c = { queued: 0, uploading: 0, done: 0, failed: 0, cancelled: 0 };
        for (const i of items) c[i.status] += 1;
        return c;
    }

    async function run(item) {
        item.status = 'uploading';
        item.progress = 0;
        item.error = null;
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : { signal: undefined, abort() {} };
        controllers.set(item.id, controller);
        running += 1;
        emit();
        let result;
        try {
            result = await transport(item, { signal: controller.signal, onProgress: (p) => { if (item.status === 'uploading') { item.progress = Math.max(0, Math.min(1, p)); emit(); } } });
        } catch (e) {
            result = { ok: false, status: 0, message: null };
        }
        running -= 1;
        controllers.delete(item.id);
        if (item.status === 'cancelled') { pump(); return; }
        if (result && result.ok) {
            item.status = 'done';
            item.progress = 1;
            item.asset = result.asset;
            item.duplicate = !!result.duplicate;
            emit();
            onDone(item);
        } else {
            const f = describeFailure(result || { status: 0 });
            item.status = 'failed';
            item.reason = f.reason;
            item.error = f.message;
            item.retryable = !PERMANENT_REASONS.includes(f.reason);
            emit();
        }
        pump();
    }

    function pump() {
        for (const item of items) {
            if (running >= concurrency) break;
            if (item.status === 'queued') run(item);
        }
    }

    return {
        /** Add files. Returns the new items (some may already be failed from the browser-side check). */
        add(files, extra = {}) {
            const added = [];
            for (const file of files) {
                const bad = precheck(file, { maxKb });
                const item = { id: nextId++, file, name: file.name || 'picture', size: file.size, status: bad ? 'failed' : 'queued', progress: 0, reason: bad ? bad.reason : null, error: bad ? bad.message : null, retryable: false, asset: null, duplicate: false, ...extra };
                items.push(item);
                added.push(item);
            }
            emit();
            pump();
            return added.map((i) => ({ ...i }));
        },
        retry(id) {
            const item = find(id);
            if (!item || item.status !== 'failed' || !item.retryable) return false;
            item.status = 'queued'; item.error = null; item.reason = null;
            emit(); pump();
            return true;
        },
        cancel(id) {
            const item = find(id);
            if (!item || (item.status !== 'queued' && item.status !== 'uploading')) return false;
            const was = item.status;
            item.status = 'cancelled';
            if (was === 'uploading') { const c = controllers.get(id); if (c) c.abort(); }
            emit(); pump();
            return true;
        },
        /** Take finished, failed and cancelled items off the list. */
        clearFinished() {
            for (let i = items.length - 1; i >= 0; i -= 1) if (['done', 'failed', 'cancelled'].includes(items[i].status)) items.splice(i, 1);
            emit();
        },
        remove(id) {
            const at = items.findIndex((i) => i.id === id);
            if (at < 0 || ['queued', 'uploading'].includes(items[at].status)) return false;
            items.splice(at, 1); emit();
            return true;
        },
        counts,
        items: () => items.map((i) => ({ ...i })),
        busy: () => items.some((i) => i.status === 'queued' || i.status === 'uploading'),
    };
}

/** The real transport: one XMLHttpRequest per file (fetch cannot report upload progress). */
export function xhrTransport({ url, csrf, folder = null, tags = [] }) {
    return (item, { signal, onProgress }) => new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        const body = new FormData();
        body.append('file', item.file);
        const f = item.folder !== undefined ? item.folder : folder;
        if (f) body.append('folder', f);
        for (const t of item.tags || tags) body.append('tags[]', t);
        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
        xhr.onerror = () => resolve({ ok: false, status: 0 });
        xhr.onabort = () => resolve({ ok: false, status: 0, message: 'Cancelled.' });
        xhr.onload = () => {
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            if (xhr.status >= 200 && xhr.status < 300 && data && data.asset) { resolve({ ok: true, asset: data.asset, duplicate: !!data.duplicate, usage: data.usage }); return; }
            const first = data && data.errors ? Object.values(data.errors).flat()[0] : null;
            resolve({ ok: false, status: xhr.status, reason: data && data.reason, message: (data && data.reason && data.message) || first || (data && data.message) || null });
        };
        if (signal) signal.addEventListener('abort', () => xhr.abort());
        xhr.send(body);
    });
}

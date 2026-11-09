// A small fetch wrapper for the page scripts: JSON in and out, CSRF added, and it never throws on an HTTP error.
// Every result is {ok, status, data, message, errors}; a lost connection is {ok:false, status:0, offline:true}.
const FALLBACK = {
    401: 'You were signed out. Sign in again, then try once more.',
    403: 'You do not have permission to do that.',
    404: 'We could not find that. It may have been removed.',
    413: 'That is too large to send.',
    419: 'This page timed out. Reload it, then try once more.',
    422: 'Something in that needs another look.',
    429: 'Too many requests. Wait a minute, then try again.',
};

export function messageFor(status, data) {
    if (data && typeof data.message === 'string' && data.message.trim() !== '') return data.message;
    if (FALLBACK[status]) return FALLBACK[status];
    if (status >= 500) return 'Something went wrong on our side. Try again in a moment.';
    return 'That did not work. Try again.';
}

export function createApi({ fetchImpl, csrf } = {}) {
    const doFetch = fetchImpl || (typeof fetch !== 'undefined' ? fetch.bind(globalThis) : null);
    const token = csrf !== undefined ? csrf : (typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]') || {}).content : '');

    async function request(method, url, body, { signal } = {}) {
        if (!doFetch) return { ok: false, status: 0, offline: true, data: null, message: 'This browser cannot send requests.', errors: {} };
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (token) headers['X-CSRF-TOKEN'] = token;
        const init = { method, headers, credentials: 'same-origin', signal };
        if (body !== undefined && method !== 'GET') { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
        let res;
        try {
            res = await doFetch(url, init);
        } catch (e) {
            if (e && e.name === 'AbortError') return { ok: false, status: 0, aborted: true, data: null, message: '', errors: {} };
            return { ok: false, status: 0, offline: true, data: null, message: 'We could not reach the server. Check your connection and try again.', errors: {} };
        }
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        const errors = data && typeof data.errors === 'object' && data.errors ? Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)])) : {};
        return { ok: res.ok, status: res.status, data, message: res.ok ? '' : messageFor(res.status, data), errors };
    }

    return {
        get: (url, opts) => request('GET', url, undefined, opts),
        post: (url, body, opts) => request('POST', url, body ?? {}, opts),
    };
}

/** Upload one file with progress. Resolves {ok, status, data, message, errors}; abort() cancels. */
export function uploadFile(url, file, { onProgress, csrf, XHR } = {}) {
    const Ctor = XHR || (typeof XMLHttpRequest !== 'undefined' ? XMLHttpRequest : null);
    const token = csrf !== undefined ? csrf : (typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]') || {}).content : '');
    let xhr = null;
    const promise = new Promise((resolve) => {
        if (!Ctor) { resolve({ ok: false, status: 0, data: null, message: 'This browser cannot upload files.', errors: {} }); return; }
        xhr = new Ctor();
        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        if (token) xhr.setRequestHeader('X-CSRF-TOKEN', token);
        if (xhr.upload) xhr.upload.onprogress = (e) => { if (onProgress && e.lengthComputable) onProgress(e.loaded / e.total); };
        xhr.onload = () => {
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            const ok = xhr.status >= 200 && xhr.status < 300;
            const errors = data && data.errors ? Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)])) : {};
            const fileError = errors.file;
            resolve({ ok, status: xhr.status, data, message: ok ? '' : (fileError || messageFor(xhr.status, data)), errors });
        };
        xhr.onerror = () => resolve({ ok: false, status: 0, offline: true, data: null, message: 'The upload was interrupted. Check your connection and try again.', errors: {} });
        xhr.onabort = () => resolve({ ok: false, status: 0, aborted: true, data: null, message: '', errors: {} });
        const form = new FormData();
        form.append('file', file);
        xhr.send(form);
    });
    return { promise, abort: () => { if (xhr) xhr.abort(); } };
}

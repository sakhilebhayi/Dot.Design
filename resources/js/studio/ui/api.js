/*
 * ctx.api: fetch wrapper for the studio JSON API. Adds the CSRF token and JSON
 * headers, never throws on an HTTP error, and turns every outcome into
 *   { ok, status, data, message, errors }
 * with a plain-language `message` for each failure. A network failure is status 0.
 * An expired session (401, 419, or a login redirect) also calls onSession so the
 * shell can run the sign-in flow without losing the document.
 */
export function messageFor(status, data) {
    const body = data && typeof data === 'object' ? data : {};
    const first = body.errors && typeof body.errors === 'object' ? Object.values(body.errors).flat()[0] : null;
    const given = typeof body.message === 'string' && body.message && !/^(server error|unauthenticated\.?|csrf token mismatch\.?)$/i.test(body.message) ? body.message : null;
    if (status === 0) return 'Could not reach the server. Check your connection and try again.';
    if (status === 401 || status === 419) return 'Your session has expired. Sign in again to keep working.';
    if (status === 403) return given || 'You do not have permission to do that.';
    if (status === 404) return given || 'That could not be found. It may have been removed.';
    if (status === 409) return given || 'This changed somewhere else. Reload to see the latest.';
    if (status === 413) return given || 'That is too large to send.';
    if (status === 422) return given || (typeof first === 'string' ? first : 'Some of that is not valid. Check it and try again.');
    if (status === 429) return 'Too many requests. Wait a moment and try again.';
    if (status >= 500) return 'Something went wrong on our side. Your work on this device is safe. Try again in a moment.';
    return given || `The request failed (${status}).`;
}

export function createApi({ csrf, fetchImpl, onSession, headers = {} } = {}) {
    const doFetch = fetchImpl || ((...a) => fetch(...a));

    async function request(method, url, body, opts = {}) {
        const init = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...headers, ...(opts.headers || {}) } };
        if (csrf && method !== 'GET' && method !== 'HEAD') init.headers['X-CSRF-TOKEN'] = typeof csrf === 'function' ? csrf() : csrf;
        if (opts.signal) init.signal = opts.signal;
        if (body !== undefined && body !== null && method !== 'GET') {
            if ((typeof FormData !== 'undefined' && body instanceof FormData) || (typeof Blob !== 'undefined' && body instanceof Blob) || body instanceof ArrayBuffer) init.body = body;
            else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
        }
        let res;
        try { res = await doFetch(url, init); } catch (e) {
            if (e && e.name === 'AbortError') return { ok: false, status: 0, aborted: true, data: null, message: 'Cancelled.', errors: {} };
            return { ok: false, status: 0, data: null, message: messageFor(0), errors: {} };
        }
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        let status = res.status;
        // A lapsed session answers a fetch with the login page after a redirect.
        if (res.redirected && status === 200 && data === null) status = 401;
        const ok = status >= 200 && status < 300;
        const out = { ok, status, data, message: ok ? ((data && data.message) || '') : messageFor(status, data), errors: (data && data.errors && typeof data.errors === 'object') ? data.errors : {} };
        if (status === 401 || status === 419) { out.sessionExpired = true; if (onSession) onSession(out); }
        return out;
    }

    const qs = (url, params) => {
        if (!params) return url;
        const p = new URLSearchParams();
        for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') p.set(k, String(v));
        const s = p.toString();
        return s ? url + (url.includes('?') ? '&' : '?') + s : url;
    };

    return {
        request,
        get: (url, params, opts) => request('GET', qs(url, params), undefined, opts),
        post: (url, body, opts) => request('POST', url, body === undefined ? {} : body, opts),
        put: (url, body, opts) => request('PUT', url, body === undefined ? {} : body, opts),
        patch: (url, body, opts) => request('PATCH', url, body === undefined ? {} : body, opts),
        del: (url, body, opts) => request('DELETE', url, body, opts),
    };
}

/** Replace {name} placeholders in a route template. Unknown names stay as they are. */
export function fillUrl(template, params = {}) {
    return String(template).replace(/\{(\w+)\??\}/g, (m, k) => (params[k] !== undefined && params[k] !== null ? encodeURIComponent(params[k]) : m));
}

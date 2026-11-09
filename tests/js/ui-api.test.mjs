import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createApi, fillUrl, messageFor } from '../../resources/js/studio/ui/api.js';

function fakeFetch(responses) {
    const calls = [];
    const fn = async (url, init) => {
        calls.push({ url, init });
        const r = responses.shift();
        if (r instanceof Error) throw r;
        return { status: r.status, redirected: !!r.redirected, json: async () => { if (r.body === undefined) throw new Error('no json'); return r.body; } };
    };
    fn.calls = calls;
    return fn;
}

test('adds the CSRF token and JSON headers to writes, not to reads', async () => {
    const f = fakeFetch([{ status: 200, body: {} }, { status: 200, body: {} }]);
    const api = createApi({ csrf: () => 'tok123', fetchImpl: f });
    await api.get('/a');
    await api.put('/b', { x: 1 });
    assert.equal(f.calls[0].init.headers['X-CSRF-TOKEN'], undefined);
    assert.equal(f.calls[0].init.headers.Accept, 'application/json');
    assert.equal(f.calls[1].init.headers['X-CSRF-TOKEN'], 'tok123');
    assert.equal(f.calls[1].init.headers['Content-Type'], 'application/json');
    assert.equal(f.calls[1].init.body, '{"x":1}');
    assert.equal(f.calls[1].init.credentials, 'same-origin');
});

test('query parameters are encoded and empty ones dropped', async () => {
    const f = fakeFetch([{ status: 200, body: {} }]);
    await createApi({ fetchImpl: f }).get('/x?a=1', { q: 'a b', page: 2, empty: '', none: null });
    assert.equal(f.calls[0].url, '/x?a=1&q=a+b&page=2');
});

test('never throws: a network failure is status 0 with a plain message', async () => {
    const r = await createApi({ fetchImpl: fakeFetch([new TypeError('Failed to fetch')]) }).post('/x', {});
    assert.equal(r.ok, false);
    assert.equal(r.status, 0);
    assert.match(r.message, /could not reach the server/i);
});

test('HTTP errors are normalised with a plain-language message', async () => {
    const cases = [
        [403, undefined, /permission/],
        [404, undefined, /could not be found/],
        [409, { message: 'This page was changed somewhere else.' }, /changed somewhere else/],
        [413, undefined, /too large/],
        [422, { message: 'The given data was invalid.', errors: { name: ['Give it a name.'] } }, /invalid|name/i],
        [429, undefined, /too many requests/i],
        [500, { message: 'Server Error' }, /our side/],
    ];
    for (const [status, body, re] of cases) {
        const r = await createApi({ fetchImpl: fakeFetch([{ status, body }]) }).get('/x');
        assert.equal(r.ok, false, String(status));
        assert.match(r.message, re, String(status));
    }
});

test('422 field errors are passed through and the first one is the message when none is given', async () => {
    const r = await createApi({ fetchImpl: fakeFetch([{ status: 422, body: { errors: { 'details.phone': ['The phone number is not valid.'] } } }]) }).post('/x', {});
    assert.deepEqual(r.errors, { 'details.phone': ['The phone number is not valid.'] });
    assert.equal(r.message, 'The phone number is not valid.');
});

test('an expired session (401, 419 or a login redirect) calls onSession and flags the result', async () => {
    for (const res of [{ status: 401, body: { message: 'Unauthenticated.' } }, { status: 419, body: {} }, { status: 200, redirected: true }]) {
        let called = 0;
        const r = await createApi({ fetchImpl: fakeFetch([res]), onSession: () => { called += 1; } }).put('/x', {});
        assert.equal(r.sessionExpired, true);
        assert.equal(r.ok, false);
        assert.equal(called, 1);
        assert.match(r.message, /session has expired/i);
    }
});

test('success keeps the body as data and a server message', async () => {
    const r = await createApi({ fetchImpl: fakeFetch([{ status: 201, body: { id: 5, message: 'Created.' } }]) }).post('/x', {});
    assert.equal(r.ok, true);
    assert.equal(r.data.id, 5);
    assert.equal(r.message, 'Created.');
});

test('a blob body is sent raw with the caller content type (thumbnail upload)', async () => {
    const f = fakeFetch([{ status: 200, body: {} }]);
    const blob = new Blob(['x'], { type: 'image/png' });
    await createApi({ csrf: 't', fetchImpl: f }).put('/thumb', blob, { headers: { 'Content-Type': 'image/png' } });
    assert.equal(f.calls[0].init.body, blob);
    assert.equal(f.calls[0].init.headers['Content-Type'], 'image/png');
});

test('fillUrl fills named placeholders and leaves unknown ones', () => {
    assert.equal(fillUrl('/design/projects/1/pages/{page}', { page: 'a b' }), '/design/projects/1/pages/a%20b');
    assert.equal(fillUrl('/x/{a}/{b}', { a: 1 }), '/x/1/{b}');
    assert.equal(messageFor(404, { message: 'Nope.' }), 'Nope.');
});

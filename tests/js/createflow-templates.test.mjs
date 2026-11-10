import assert from 'node:assert/strict';
import test from 'node:test';
import { createPreviewCache, fillKey } from '../../resources/js/studio/pages/lib/preview-cache.js';
import { buildPreviewSvg, createRenderQueue } from '../../resources/js/studio/pages/lib/preview.js';
import { activeFilters, buildApiQuery, buildPageQuery, clearAll, defaultState, parseQuery, sameQuery, withFilter, withTab } from '../../resources/js/studio/pages/lib/template-query.js';
import { createApi, messageFor } from '../../resources/js/studio/pages/lib/api.js';

test('query: parse, build the API query and the page address', () => {
    const s = parseQuery('?category=social&style=bold&industry=food&q=%20pie%20&sort=newest&bogus=1&kind=BAD KIND');
    assert.equal(s.tab, 'social');
    assert.deepEqual(s.filters, { style: 'bold', industry: 'food' });
    assert.equal(s.q, 'pie');
    assert.equal(s.sort, 'newest');
    assert.equal(buildApiQuery(s, { page: 2, perPage: 12 }), 'category=social&industry=food&style=bold&q=pie&sort=newest&per_page=12&page=2&include=first_page');
    assert.equal(buildPageQuery(s), '?category=social&industry=food&style=bold&q=pie&sort=newest');
    assert.equal(buildPageQuery(defaultState()), '', 'defaults give a clean address');
    assert.equal(buildApiQuery(defaultState(), { includePreview: false }), 'sort=popular&per_page=24&page=1');
});

test('query: the workspace tab asks for team or own templates, not a category', () => {
    const s = parseQuery('?source=user&category=social');
    assert.equal(s.tab, 'workspace');
    assert.equal(s.workspace, 'user');
    assert.match(buildApiQuery(s), /source=user/);
    assert.doesNotMatch(buildApiQuery(s), /category/);
    const team = withTab(defaultState(), 'workspace');
    assert.match(buildApiQuery(team), /source=team/);
    assert.equal(withTab(s, 'nonsense').tab, 'all');
});

test('query: filters can be added, removed and cleared; unsafe values never reach the address', () => {
    let s = withFilter(defaultState(), 'style', 'elegant');
    s = withFilter(s, 'q', 'bakery');
    assert.deepEqual(activeFilters(s), [{ key: 'style', value: 'elegant' }, { key: 'q', value: 'bakery' }]);
    s = withFilter(s, 'style', '');
    assert.deepEqual(activeFilters(s), [{ key: 'q', value: 'bakery' }]);
    const t = clearAll(withTab(s, 'marketing'));
    assert.equal(t.tab, 'marketing');
    assert.deepEqual(activeFilters(t), []);
    assert.deepEqual(parseQuery('?style=bold%26evil%3D1').filters, {});
    assert.equal(sameQuery(parseQuery('?style=bold'), parseQuery('?style=bold&page=3')), true);
    assert.equal(sameQuery(parseQuery('?style=bold'), parseQuery('?style=clean')), false);
});

test('preview cache: least recently used goes first, and concurrent asks share one render', async () => {
    const c = createPreviewCache(2);
    c.set('a', 1); c.set('b', 2); c.get('a'); c.set('c', 3);
    assert.equal(c.has('b'), false);
    assert.equal(c.has('a'), true);
    assert.equal(c.size, 2);

    let renders = 0;
    const factory = async () => { renders += 1; await new Promise((r) => setTimeout(r, 5)); return 'svg'; };
    const [x, y] = await Promise.all([c.resolve('k', factory), c.resolve('k', factory)]);
    assert.equal(x, 'svg'); assert.equal(y, 'svg');
    assert.equal(renders, 1);
    await c.resolve('k', factory);
    assert.equal(renders, 1, 'second ask is a hit');
    assert.equal(c.stats.hits, 1);
});

test('preview cache: a failed render is not cached and can be retried', async () => {
    const c = createPreviewCache(4);
    await assert.rejects(c.resolve('z', async () => { throw new Error('boom'); }));
    assert.equal(await c.resolve('z', async () => 'ok'), 'ok');
});

test('fill key: equal fills share a key, different text does not, empty is the plain key', () => {
    assert.equal(fillKey(null), '');
    assert.equal(fillKey({ headline: '' }), '');
    assert.equal(fillKey({ a: 'x', b: 'y' }), fillKey({ b: 'y', a: 'x' }));
    assert.notEqual(fillKey({ a: 'x' }), fillKey({ a: 'z' }));
});

test('preview svg is drawn from the template with the engine renderer, at its size, with no placeholders', () => {
    const template = {
        slug: 't', width: 400, height: 300,
        pages: [
            { background: { type: 'solid', color: '#ffeeaa' }, elements: [
                { id: 'a1', type: 'shape', kind: 'rect', x: 10, y: 10, width: 100, height: 50, fill: '#112233' },
                { id: 'a2', type: 'text', x: 20, y: 80, width: 300, height: 60, text: 'Hello <b>there</b>', fontSize: 32, color: '#000000' },
                { id: 'a3', type: 'image', x: 200, y: 100, width: 100, height: 100, asset_id: null },
            ] },
            { elements: [] },
        ],
    };
    const svg = buildPreviewSvg(template, 0);
    assert.match(svg, /^<svg /);
    assert.match(svg, /viewBox="0 0 400 300"/);
    assert.match(svg, /#112233/i);
    assert.match(svg, /Hello &lt;b&gt;there&lt;\/b&gt;/, 'text is escaped, never markup');
    assert.doesNotMatch(svg, /Add a picture/, 'empty picture slots are not drawn as placeholders in a preview');
    assert.doesNotMatch(svg, /<script/i);
    assert.match(buildPreviewSvg(template, 1), /viewBox="0 0 400 300"/, 'a second page uses the template size');
    assert.match(buildPreviewSvg(template, 9), /viewBox="0 0 400 300"/, 'an out-of-range page falls back to the first');
});

test('render queue runs jobs one at a time, in order, and survives a failing job', async () => {
    const q = createRenderQueue({ yieldTo: async () => {} });
    const order = [];
    let running = 0; let peak = 0;
    const job = (name, fail) => async () => { running += 1; peak = Math.max(peak, running); await new Promise((r) => setTimeout(r, 3)); running -= 1; order.push(name); if (fail) throw new Error('x'); return name; };
    const results = await Promise.allSettled([q(job('a')), q(job('b', true)), q(job('c'))]);
    assert.deepEqual(order, ['a', 'b', 'c']);
    assert.equal(peak, 1);
    assert.equal(results[1].status, 'rejected');
    assert.equal(results[2].value, 'c');
});

test('api: errors become plain messages and never throw', async () => {
    const respond = (status, body) => async () => ({ ok: status < 400, status, json: async () => body });
    const api = (fetchImpl) => createApi({ fetchImpl, csrf: 'tok' });
    const ok = await api(respond(200, { a: 1 })).get('/x');
    assert.deepEqual([ok.ok, ok.data.a], [true, 1]);
    const bad = await api(respond(422, { message: 'Check this.', errors: { 'details.phone': ['Bad phone.'] } })).post('/x', {});
    assert.equal(bad.ok, false);
    assert.equal(bad.errors['details.phone'], 'Bad phone.');
    assert.equal((await api(respond(429, null)).post('/x')).message, 'Too many requests. Wait a minute, then try again.');
    assert.equal((await api(respond(500, null)).get('/x')).message, 'Something went wrong on our side. Try again in a moment.');
    const off = await api(async () => { throw new TypeError('network'); }).get('/x');
    assert.equal(off.offline, true);
    assert.match(off.message, /could not reach the server/);
    const aborted = await api(async () => { const e = new Error('a'); e.name = 'AbortError'; throw e; }).get('/x');
    assert.equal(aborted.aborted, true);
    assert.equal(messageFor(404, {}), 'We could not find that. It may have been removed.');
    let seen;
    await api(async (url, init) => { seen = init; return { ok: true, status: 200, json: async () => ({}) }; }).post('/x', { b: 2 });
    assert.equal(seen.headers['X-CSRF-TOKEN'], 'tok');
    assert.equal(seen.headers.Accept, 'application/json');
    assert.equal(seen.body, '{"b":2}');
});

import { composePrompt, interpretCompose } from '../../resources/js/studio/pages/lib/compose.js';

globalThis.window ||= { location: { origin: 'https://design.test' } };

test('compose: the three answers of the contract and every failure become one plain outcome', () => {
    const ok = (data) => ({ ok: true, status: 200, data, message: '' });
    assert.deepEqual(interpretCompose(ok({ status: 'ok', project_url: '/design/projects/9' })), { kind: 'open', url: '/design/projects/9' });
    assert.deepEqual(interpretCompose(ok({ status: 'questions', questions: [{ id: 'a', text: 'Which day?' }, { id: 'b', text: '  ' }, { text: 'Price?' }] })), { kind: 'questions', questions: [{ id: 'a', text: 'Which day?' }, { id: 'Price?', text: 'Price?' }] });
    assert.deepEqual(interpretCompose(ok({ status: 'unavailable', message: 'The assistant is switched off.' })), { kind: 'unavailable', message: 'The assistant is switched off.' });
    assert.equal(interpretCompose(ok({ status: 'unavailable' })).message, 'The assistant is not available right now.');
    assert.deepEqual(interpretCompose({ ok: false, status: 0, aborted: true, data: null, message: '' }), { kind: 'aborted' });
    assert.deepEqual(interpretCompose({ ok: false, status: 500, data: null, message: 'Something went wrong on our side. Try again in a moment.' }), { kind: 'error', message: 'Something went wrong on our side. Try again in a moment.' });
    assert.equal(interpretCompose(ok({ status: 'ok' })).kind, 'error', 'ok without an address is not followed');
    assert.equal(interpretCompose(ok({ status: 'questions', questions: [] })).kind, 'error');
});

test('compose: only addresses on this site are followed', () => {
    const ok = (url) => ({ ok: true, status: 200, data: { status: 'ok', project_url: url }, message: '' });
    assert.equal(interpretCompose(ok('https://design.test/design/projects/4?x=1')).url, '/design/projects/4?x=1');
    assert.equal(interpretCompose(ok('https://evil.example/phish')).kind, 'error');
    assert.equal(interpretCompose(ok('//evil.example/phish')).kind, 'error');
    assert.equal(interpretCompose(ok('javascript:alert(1)')).kind, 'error');
});

test('compose: the prompt carries the answers to follow-up questions', () => {
    assert.equal(composePrompt('  A poster for pies '), 'A poster for pies');
    assert.equal(composePrompt('A poster', [{ text: 'Which day?', value: ' Saturday ' }, { text: 'Price?', value: '' }]), 'A poster\n\nWhich day? Saturday');
});

test('compose: a fake api round trip, as the page uses it', async () => {
    const calls = [];
    const api = createApi({ csrf: 't', fetchImpl: async (url, init) => { calls.push([url, JSON.parse(init.body)]); return { ok: true, status: 200, json: async () => ({ status: 'questions', questions: [{ id: 'd', text: 'Which day?' }] }) }; } });
    const first = interpretCompose(await api.post('/design/assistant/compose', { prompt: composePrompt('Poster for pies') }));
    assert.equal(first.kind, 'questions');
    assert.deepEqual(calls[0], ['/design/assistant/compose', { prompt: 'Poster for pies' }]);
});

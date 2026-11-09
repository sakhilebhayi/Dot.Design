import test from 'node:test';
import assert from 'node:assert/strict';
import { createController } from '../../resources/js/studio/assist/controller.js';
import { byId, ids, mkCore, rect, text } from './helpers.mjs';

function world({ ai = true, routes = {}, elements } = {}) {
    const core = mkCore(elements || [
        rect({ id: 'bg', width: 1000, height: 800, fill: '#ffffff' }),
        text({ id: 'head', x: 4, y: 100, width: 800, height: 100, fontSize: 40, color: '#cccccc', fontWeight: 400 }),
        text({ id: 'body', x: 80, y: 300, width: 800, height: 60, fontSize: 20, color: '#111111' }),
    ]);
    const calls = [];
    const toasts = [];
    const dialogs = [];
    const api = {
        async get(url) { calls.push(['get', url]); return respond('get ' + url); },
        async post(url, body) { calls.push(['post', url, body]); return respond('post ' + url.replace(/ai-proposals\/\d+/, 'ai-proposals/:n'), body); },
    };
    const respond = (key, body) => {
        const r = routes[key];
        const v = typeof r === 'function' ? r(body) : r;
        return v || { ok: false, status: 404, data: { message: 'no route ' + key } };
    };
    const ctx = {
        studio: core, api, toast: (m, o) => toasts.push([m, o]),
        openDialog: (id, props) => dialogs.push([id, props]),
        config: { project: { id: 7 }, flags: { ai }, brand: { colors: [{ key: 'primary', hex: '#00a651' }], fonts: { body: 'dm-sans' } }, urls: {} },
    };
    return { core, ctx, calls, toasts, dialogs, c: createController(ctx) };
}

const ok = (data, status = 200) => ({ ok: status < 400, status, data });
const patchProposal = (extra = {}) => ({ id: 11, type: 'patch', source: 'ai', page_id: 1, ops: [{ op: 'set', id: 'head', props: { fontSize: 80, fontWeight: 800 } }], changes: [], summary: 'Bigger headline.', ...extra });

test('quick fixes are computed from the live page and exclude what has nothing to do', () => {
    const { c } = world();
    const { available, unavailable } = c.fixes();
    const keys = available.map((f) => f.key);
    assert.ok(keys.includes('contrast') && keys.includes('margins'));
    assert.ok(unavailable.some((u) => u.key === 'overflow'), 'no text measurer in a headless run: stated, not guessed');
});

test('an automatic fix is validated by the server and shown for review; the design does not change until Apply', async () => {
    let posted;
    const w = world({ routes: { 'post /design/projects/7/assistant/proposals': (b) => { posted = b; return ok({ status: 'ok', proposal: patchProposal({ id: 12, source: 'automatic', ops: b.ops }) }, 201); } } });
    const fix = w.c.fixes().available.find((f) => f.key === 'contrast');

    await w.c.runAutomatic(fix);

    assert.equal(w.c.machine.state.phase, 'review');
    assert.equal(w.c.machine.state.source, 'auto');
    assert.deepEqual(posted.ops, fix.ops);
    assert.equal(posted.page_id, 1);
    assert.equal(byId(w.core, 'head').color, '#cccccc', 'review changes nothing');
    assert.equal(w.core.canUndo(), false);
});

test('Apply records the decision first, then changes the design as ONE undo step', async () => {
    const w = world({ routes: {
        'post /design/projects/7/assistant/improve': ok({ status: 'ok', proposal: patchProposal() }, 201),
        'post /design/ai-proposals/:n/apply': ok({ status: 'applied', version_id: 5 }),
    } });
    await w.c.ask('Make the headline stand out');
    assert.equal(w.c.machine.state.phase, 'review');
    assert.ok(w.c.preview(), 'an after-preview exists on a scratch copy');
    assert.equal(byId(w.core, 'head').fontSize, 40);

    await w.c.apply();

    assert.equal(w.c.machine.state.phase, 'idle');
    assert.match(w.c.machine.state.notice, /Undo/);
    assert.equal(byId(w.core, 'head').fontSize, 80);
    assert.deepEqual(w.calls.map((x) => x[1]).filter((u) => u.includes('apply')), ['/design/ai-proposals/11/apply']);
    w.core.undo();
    assert.equal(byId(w.core, 'head').fontSize, 40);
    assert.equal(w.core.canUndo(), false);
});

test('nothing is applied when the server refuses to record it', async () => {
    const w = world({ routes: {
        'post /design/projects/7/assistant/improve': ok({ status: 'ok', proposal: patchProposal() }, 201),
        'post /design/ai-proposals/:n/apply': ok({ message: 'Not allowed.' }, 403),
    } });
    await w.c.ask('Make the headline stand out');
    await w.c.apply();

    assert.equal(w.c.machine.state.phase, 'review', 'still on screen so it can be tried again or dismissed');
    assert.equal(byId(w.core, 'head').fontSize, 40);
    assert.equal(w.core.canUndo(), false);
});

test('a design that changed under the proposal is caught before the server is asked', async () => {
    const w = world({ routes: { 'post /design/projects/7/assistant/improve': ok({ status: 'ok', proposal: patchProposal() }, 201) } });
    await w.c.ask('Make the headline stand out');
    w.core.exec('element.remove', { ids: ['head'] });

    await w.c.apply();

    assert.equal(w.c.machine.state.phase, 'review');
    assert.match(w.c.machine.state.message, /changed/);
    assert.ok(!w.calls.some((x) => x[1].includes('/apply')), 'the server is not told it was applied');
});

test('dismiss tells the server and clears the panel; a late answer after dismiss is ignored', async () => {
    let release;
    const slow = new Promise((r) => { release = r; });
    const w = world({ routes: { 'post /design/projects/7/assistant/improve': () => slow } });
    w.ctx.api.post = async (url, body) => { w.calls.push(['post', url, body]); return url.includes('improve') ? slow : ok({}); };

    const pending = w.c.ask('Improve the layout');
    assert.equal(w.c.machine.state.phase, 'working');
    w.c.dismiss();
    assert.equal(w.c.machine.state.phase, 'idle');
    release(ok({ status: 'ok', proposal: patchProposal() }, 201));
    await pending;
    assert.equal(w.c.machine.state.phase, 'idle', 'the late answer never reappears');
});

test('dismissing a shown proposal notifies the server', async () => {
    const w = world({ routes: {
        'post /design/projects/7/assistant/improve': ok({ status: 'ok', proposal: patchProposal() }, 201),
        'post /design/ai-proposals/:n/dismiss': ok({ status: 'dismissed' }),
    } });
    await w.c.ask('Improve the layout');
    w.c.dismiss();
    assert.equal(w.c.machine.state.phase, 'idle');
    assert.ok(w.calls.some((x) => x[1] === '/design/ai-proposals/11/dismiss'));
});

test('every server outcome lands in an honest state: nothing, limit, unavailable, throttled, failure, offline', async () => {
    const cases = [
        [ok({ status: 'nothing', message: 'No safe change.' }), 'nothing'],
        [ok({ status: 'limit', message: 'Capped.', usage: { user: { used: 60, limit: 60 } } }, 429), 'limit'],
        [ok({ status: 'throttled', message: 'Slow down.' }, 429), 'error'],
        [ok({ status: 'unavailable', message: 'Off.' }), 'unavailable'],
        [ok({ status: 'failed', message: 'Busy.', retryable: true }, 503), 'error'],
        [{ ok: false, status: 0, data: null, message: 'Failed to fetch' }, 'error'],
    ];
    for (const [response, phase] of cases) {
        const w = world({ routes: { 'post /design/projects/7/assistant/improve': response } });
        await w.c.ask('Improve the layout');
        assert.equal(w.c.machine.state.phase, phase, JSON.stringify(response.data));
        assert.ok(w.c.machine.state.message, 'always a plain message');
        assert.ok(!w.c.machine.state.proposal);
    }
});

test('with AI off the free box says so without calling the server, and quick fixes still work', async () => {
    const w = world({ ai: false });
    await w.c.ask('Improve the layout');
    assert.equal(w.c.machine.state.phase, 'unavailable');
    assert.equal(w.calls.length, 0);
    assert.ok(w.c.fixes().available.length > 0);
});

test('a suggested resize is not applied as a patch and opens the resize tool with the format', async () => {
    const w = world({ routes: { 'post /design/projects/7/assistant/improve': ok({ status: 'ok', proposal: { id: 3, type: 'suggest_resize', format_key: 'instagram-post-square', summary: 'Resize this design to Instagram post (square).' } }, 201) } });
    await w.c.ask('Make this suitable for Instagram');
    assert.equal(w.c.machine.state.proposal.type, 'suggest_resize');
    assert.equal(w.c.preview(), null);
    w.c.openResize('instagram-post-square');
    assert.deepEqual(w.dialogs, [['resize', { format_key: 'instagram-post-square' }]]);
});

test('a missing resize tool is reported, not hidden', () => {
    const w = world();
    w.ctx.openDialog = () => { throw new Error('no such dialog'); };
    assert.equal(w.c.openResize('instagram-story'), false);
    assert.equal(w.toasts[0][1].kind, 'warn');
});

test('the status call turns AI off when the server says so, and carries the usage meter', async () => {
    const w = world({ routes: { 'get /design/assistant/status': ok({ enabled: false, message: 'Off for this workspace.', usage: null }) } });
    const status = await w.c.loadStatus();
    assert.equal(status.enabled, false);
    assert.equal(status.message, 'Off for this workspace.');

    const on = world({ routes: { 'get /design/assistant/status': ok({ enabled: true, usage: { user: { used: 12, limit: 60 }, workspace: { used: 40, limit: 300 } } }) } });
    await on.c.loadStatus();
    assert.equal(on.c.status.enabled, true);
    assert.equal(on.c.machine.state.usage.user.used, 12);
});

test('copy: suggestions come back and are inserted as a new text box or replace the selected one, each one undo step', async () => {
    const w = world({ routes: { 'post /design/assistant/copy': ok({ status: 'ok', suggestions: [{ id: 1, text: 'Warm up with a hot pie' }, { id: 2, text: 'Winter belongs to pies' }], dropped: 1 }) } });
    await w.c.suggestCopy({ kind: 'headline', product: 'pies' });
    assert.equal(w.c.copy.phase, 'ready');
    assert.equal(w.c.copy.suggestions.length, 2);
    assert.equal(w.calls.filter((x) => x[0] === 'post').at(-1)[2].project_id, 7);

    const before = ids(w.core).length;
    assert.equal(w.c.insertCopy('Warm up with a hot pie', 'add').ok, true);
    assert.equal(ids(w.core).length, before + 1);
    const added = byId(w.core, ids(w.core).at(-1));
    assert.equal(added.text, 'Warm up with a hot pie');
    assert.equal(added.font, 'dm-sans', 'the brand body font is used');
    assert.equal(w.core.undo(), true);
    assert.equal(ids(w.core).length, before);

    assert.equal(w.c.insertCopy('x', 'replace').ok, false, 'replace needs a selected text box');
    w.core.select(['body']);
    assert.equal(w.c.insertCopy('Winter belongs to pies', 'replace').ok, true);
    assert.equal(byId(w.core, 'body').text, 'Winter belongs to pies');
    w.core.undo();
    assert.equal(byId(w.core, 'body').text, 'Hello');
});

test('the usage meter is re-read from the server after every model call, and not after automatic fixes', async () => {
    let used = 0;
    const w = world({ routes: {
        'get /design/assistant/status': () => ok({ enabled: true, usage: { user: { used, limit: 60 }, workspace: { used, limit: 300 } } }),
        'post /design/projects/7/assistant/improve': () => { used += 1; return ok({ status: 'ok', proposal: patchProposal() }, 201); },
        'post /design/projects/7/assistant/proposals': (b) => ok({ status: 'ok', proposal: patchProposal({ source: 'automatic', ops: b.ops }) }, 201),
    } });
    await w.c.loadStatus();
    await w.c.ask('Improve the layout');
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(w.c.machine.state.usage.user.used, 1);

    w.c.dismiss();
    const gets = w.calls.filter((x) => x[0] === 'get').length;
    await w.c.runAutomatic(w.c.fixes().available[0]);
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(w.calls.filter((x) => x[0] === 'get').length, gets, 'automatic fixes make no model call, so nothing to re-read');
});

test('copy: off, nothing safe, limit and offline are each reported', async () => {
    for (const [response, phase] of [
        [ok({ status: 'nothing', message: 'None were safe.' }), 'nothing'],
        [ok({ status: 'unavailable', message: 'Off.' }), 'unavailable'],
        [ok({ status: 'limit', message: 'Capped.' }, 429), 'limit'],
        [{ ok: false, status: 0, data: null }, 'error'],
    ]) {
        const w = world({ routes: { 'post /design/assistant/copy': response } });
        await w.c.suggestCopy({ kind: 'headline' });
        assert.equal(w.c.copy.phase, phase);
        assert.ok(w.c.copy.message);
    }
    const off = world({ ai: false });
    await off.c.suggestCopy({ kind: 'headline' });
    assert.equal(off.c.copy.phase, 'unavailable');
    assert.equal(off.calls.length, 0);
});

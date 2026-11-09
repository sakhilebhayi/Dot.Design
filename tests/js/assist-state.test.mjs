import test from 'node:test';
import assert from 'node:assert/strict';
import { createMachine, initial, reduce } from '../../resources/js/studio/assist/proposal-state.js';

const proposal = { id: 5, ops: [{ op: 'set', id: 'a', props: { fontSize: 50 } }] };

function working(m = createMachine(), source = 'ai') {
    m.send({ type: 'ask', source, label: 'Make it pop' });
    return m;
}

test('happy path: ask, review, apply, applied; the proposal is gone afterwards', () => {
    const m = working();
    assert.equal(m.state.phase, 'working');
    m.send({ type: 'proposal', token: m.state.token, proposal });
    assert.equal(m.state.phase, 'review');
    m.send({ type: 'apply' });
    assert.equal(m.state.phase, 'applying');
    m.send({ type: 'applied', notice: 'Applied. You can undo it.' });
    assert.equal(m.state.phase, 'idle');
    assert.equal(m.state.proposal, null);
    assert.match(m.state.notice, /undo/);
});

test('apply is impossible unless a proposal is on screen', () => {
    const m = createMachine();
    m.send({ type: 'apply' });
    assert.equal(m.state.phase, 'idle');
    working(m);
    m.send({ type: 'apply' });
    assert.equal(m.state.phase, 'working', 'still loading: nothing to apply');
    m.send({ type: 'applied' });
    assert.equal(m.state.phase, 'working');
});

test('a response for a dismissed or superseded request is ignored', () => {
    const m = working();
    const stale = m.state.token;
    m.send({ type: 'dismiss' });
    assert.equal(m.state.phase, 'idle');
    m.send({ type: 'proposal', token: stale, proposal });
    assert.equal(m.state.phase, 'idle', 'late answer after dismiss never appears');

    working(m);
    const fresh = m.state.token;
    assert.notEqual(fresh, stale);
    m.send({ type: 'proposal', token: stale, proposal: { id: 1 } });
    assert.equal(m.state.phase, 'working');
    m.send({ type: 'proposal', token: fresh, proposal });
    assert.equal(m.state.proposal.id, 5);
});

test('a second ask while working or applying is ignored', () => {
    const m = working();
    const t = m.state.token;
    m.send({ type: 'ask', source: 'auto' });
    assert.equal(m.state.token, t);
    m.send({ type: 'proposal', token: t, proposal });
    m.send({ type: 'apply' });
    m.send({ type: 'ask', source: 'auto' });
    assert.equal(m.state.phase, 'applying');
    m.send({ type: 'dismiss' });
    assert.equal(m.state.phase, 'applying', 'cannot dismiss mid-apply');
});

test('failures: retryable error, unavailable, limit, nothing, each with its message', () => {
    let m = working();
    m.send({ type: 'fail', token: m.state.token, message: 'Busy', retryable: true });
    assert.deepEqual([m.state.phase, m.state.message, m.state.retryable], ['error', 'Busy', true]);
    m.send({ type: 'dismiss' });
    assert.equal(m.state.phase, 'idle');

    m = working();
    m.send({ type: 'unavailable', token: m.state.token, message: 'Off' });
    assert.equal(m.state.phase, 'unavailable');

    m = working();
    m.send({ type: 'limit', token: m.state.token, message: 'Capped', usage: { user: { used: 60, limit: 60 } } });
    assert.equal(m.state.phase, 'limit');
    assert.equal(m.state.usage.user.used, 60);

    m = working();
    m.send({ type: 'nothing', token: m.state.token, message: 'No safe change' });
    assert.equal(m.state.phase, 'nothing');
});

test('a failed apply returns to review with the proposal intact so it can be retried or dismissed', () => {
    const m = working();
    m.send({ type: 'proposal', token: m.state.token, proposal });
    m.send({ type: 'apply' });
    m.send({ type: 'apply-failed', message: 'The design changed.' });
    assert.equal(m.state.phase, 'review');
    assert.equal(m.state.proposal, proposal);
    assert.equal(m.state.message, 'The design changed.');
    m.send({ type: 'apply' });
    assert.equal(m.state.phase, 'applying');
});

test('a failed apply after the decision was recorded is final: the proposal is gone and cannot be retried', () => {
    const m = working();
    m.send({ type: 'proposal', token: m.state.token, proposal });
    m.send({ type: 'apply' });
    m.send({ type: 'apply-failed', message: 'The design changed. Ask again.', final: true });
    assert.deepEqual([m.state.phase, m.state.proposal, m.state.retryable], ['error', null, false]);
    m.send({ type: 'apply' });
    assert.equal(m.state.phase, 'error');
});

test('usage survives across proposals; subscribers hear every change but not no-ops', () => {
    const m = createMachine();
    const seen = [];
    const off = m.subscribe((s, e) => seen.push(e.type));
    m.send({ type: 'usage', usage: { user: { used: 3, limit: 60 } } });
    working(m);
    m.send({ type: 'dismiss' });
    assert.equal(m.state.usage.user.used, 3);
    m.send({ type: 'apply' });
    off();
    m.send({ type: 'usage', usage: null });
    assert.deepEqual(seen, ['usage', 'ask', 'dismiss']);
});

test('reduce is pure and unknown events change nothing', () => {
    const s = initial();
    assert.equal(reduce(s, { type: 'bogus' }), s);
    assert.notEqual(reduce(s, { type: 'ask', source: 'ai' }), s);
    assert.equal(s.phase, 'idle');
});

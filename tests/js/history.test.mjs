import assert from 'node:assert/strict';
import { test } from 'node:test';
import { load } from './load.mjs';

const h = load('history').history;

test('a fresh history has nothing to undo or redo', () => {
    const history = h.create();
    assert.equal(h.canUndo(history), false);
    assert.equal(h.canRedo(history), false);
    assert.equal(h.undo(history, []), null);
    assert.equal(h.redo(history, []), null);
});

test('undo returns the recorded state and makes the current one redoable', () => {
    const history = h.create();
    h.record(history, [{ id: 'a' }]);
    const back = h.undo(history, [{ id: 'a' }, { id: 'b' }]);
    assert.deepEqual(back, [{ id: 'a' }]);
    assert.equal(h.canRedo(history), true);
    assert.deepEqual(h.redo(history, [{ id: 'a' }]), [{ id: 'a' }, { id: 'b' }]);
    assert.equal(h.canRedo(history), false);
    assert.equal(h.canUndo(history), true);
});

test('recording a new change throws the redo stack away', () => {
    const history = h.create();
    h.record(history, ['s0']);
    h.record(history, ['s1']);
    h.undo(history, ['s2']);
    assert.equal(h.canRedo(history), true);
    h.record(history, ['s1']);
    assert.equal(h.canRedo(history), false);
});

test('multiple undos walk back through every recorded state in order', () => {
    const history = h.create();
    h.record(history, ['s0']);
    h.record(history, ['s1']);
    h.record(history, ['s2']);
    assert.deepEqual(h.undo(history, ['s3']), ['s2']);
    assert.deepEqual(h.undo(history, ['s2']), ['s1']);
    assert.deepEqual(h.undo(history, ['s1']), ['s0']);
    assert.equal(h.undo(history, ['s0']), null);
});

test('the stack is capped at 100 entries and drops the oldest', () => {
    const history = h.create();
    for (let i = 0; i < 150; i += 1) h.record(history, [i]);
    assert.equal(history.past.length, 100);
    // newest 100 survive: undoing 100 times ends at state 50
    let last = null;
    for (let i = 0; i < 100; i += 1) last = h.undo(history, ['x']);
    assert.deepEqual(last, [50]);
    assert.equal(h.canUndo(history), false);
});

test('the cap is configurable', () => {
    const history = h.create(3);
    for (let i = 0; i < 10; i += 1) h.record(history, [i]);
    assert.equal(history.past.length, 3);
});

test('snapshots are copies: later mutation of the live array does not rewrite history', () => {
    const history = h.create();
    const live = [{ id: 'a', x: 1 }];
    h.record(history, live);
    live[0].x = 999;
    const back = h.undo(history, live);
    assert.equal(back[0].x, 1);
    back[0].x = 5;
    assert.equal(h.redo(history, back)[0].x, 999);
});

test('edits with the same key inside the window coalesce into one undo step', () => {
    const history = h.create();
    h.record(history, ['before typing'], { key: 'text:a', now: 0 });
    h.record(history, ['after 1 key'], { key: 'text:a', now: 200 });
    h.record(history, ['after 2 keys'], { key: 'text:a', now: 450 });
    assert.equal(history.past.length, 1);
    assert.deepEqual(h.undo(history, ['after 3 keys']), ['before typing']);
});

test('a pause, or a different key, starts a new undo step', () => {
    const history = h.create();
    h.record(history, ['s0'], { key: 'k', now: 0 });
    h.record(history, ['s1'], { key: 'k', now: 5000 });
    h.record(history, ['s2'], { key: 'other', now: 5100 });
    assert.equal(history.past.length, 3);
});

test('records without a key never coalesce', () => {
    const history = h.create();
    h.record(history, ['s0'], { now: 0 });
    h.record(history, ['s1'], { now: 10 });
    assert.equal(history.past.length, 2);
});

test('clear empties both stacks', () => {
    const history = h.create();
    h.record(history, ['s0']);
    h.undo(history, ['s1']);
    h.clear(history);
    assert.equal(h.canUndo(history), false);
    assert.equal(h.canRedo(history), false);
});

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { authorLabel, countThreads, filterThreads, remaining, remove, upsert, validateBody } from '../../resources/js/studio/collab/comments-state.js';

const t = (id, over = {}) => ({ id, page_id: 1, resolved: false, replies: [], ...over });

test('comment text is validated: empty, too long, and trimmed', () => {
    assert.equal(validateBody('   ').ok, false);
    assert.equal(validateBody('a'.repeat(2001)).ok, false);
    assert.match(validateBody('a'.repeat(2005)).message, /5 characters too long/);
    assert.deepEqual(validateBody('  hi  \r\nthere '), { ok: true, body: 'hi  \nthere' });
    assert.equal(remaining('abc'), 1997);
});

test('filters by open, resolved, page', () => {
    const list = [t(1), t(2, { resolved: true }), t(3, { page_id: 2 })];
    assert.deepEqual(filterThreads(list, { status: 'open', scope: 'all', pageId: 1 }).map((x) => x.id), [1, 3]);
    assert.deepEqual(filterThreads(list, { status: 'resolved', scope: 'all', pageId: 1 }).map((x) => x.id), [2]);
    assert.deepEqual(filterThreads(list, { status: 'all', scope: 'page', pageId: 1 }).map((x) => x.id), [1, 2]);
    assert.deepEqual(filterThreads(list, { status: 'open', scope: 'page', pageId: 2 }).map((x) => x.id), [3]);
});

test('counts for the tab', () => {
    const c = countThreads([t(1), t(2, { resolved: true }), t(3, { page_id: 2 })], 1);
    assert.deepEqual(c, { open: 2, resolved: 1, all: 3, openOnPage: 1 });
});

test('guests are labelled as guests and members by name', () => {
    assert.deepEqual(authorLabel({ is_guest: true, guest_name: 'Client Carl' }), { name: 'Client Carl', guest: true });
    assert.deepEqual(authorLabel({ is_guest: false, author: { name: 'Ann' } }), { name: 'Ann', guest: false });
    assert.equal(authorLabel({ is_guest: false, author: null, guest_name: null }).guest, false);
});

test('upsert and remove keep thread order and replies', () => {
    let list = [t(1), t(3)];
    list = upsert(list, t(2));
    assert.deepEqual(list.map((x) => x.id), [1, 2, 3]);
    list = upsert(list, { id: 10, parent_id: 2, body: 'reply' });
    assert.equal(list[1].replies.length, 1);
    list = upsert(list, { id: 10, parent_id: 2, body: 'edited reply' });
    assert.equal(list[1].replies[0].body, 'edited reply');
    list = upsert(list, { ...t(1), resolved: true });
    assert.equal(list[0].resolved, true);
    list = remove(list, 10);
    assert.equal(list[1].replies.length, 0);
    list = remove(list, 3);
    assert.deepEqual(list.map((x) => x.id), [1, 2]);
});

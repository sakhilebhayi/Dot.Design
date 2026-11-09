import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyFilter, describeVersion, initialList, initialRestore, listReducer, restoreReducer, summariseDiff } from '../../resources/js/studio/collab/versions-state.js';
import { EXPIRY_CHOICES, allowsSentence, createLinkVault, describeShare, expiryDays } from '../../resources/js/studio/collab/share-state.js';
import { collabUrls, can } from '../../resources/js/studio/collab/urls.js';
import { explain } from '../../resources/js/studio/collab/errors.js';
import { expiresIn, formatBytes, timeAgo } from '../../resources/js/studio/collab/format.js';

const v = (over) => ({ id: 1, kind: 'auto', label: null, bytes: 2048, created_at: '2026-11-09T10:00:00Z', author: { id: 1, name: 'Ann' }, ...over });

test('version list state machine: load, loaded, more, failure, created', () => {
    let s = initialList();
    assert.equal(s.status, 'idle');
    s = listReducer(s, { type: 'load' });
    assert.equal(s.status, 'loading');
    s = listReducer(s, { type: 'loaded', items: [{ id: 3 }, { id: 2 }], cursor: 'abc' });
    assert.equal(s.status, 'ready');
    assert.equal(s.cursor, 'abc');
    s = listReducer(s, { type: 'more' });
    assert.equal(s.status, 'more');
    s = listReducer(s, { type: 'moreLoaded', items: [{ id: 2 }, { id: 1 }], cursor: null });
    assert.deepEqual(s.items.map((i) => i.id), [3, 2, 1], 'no duplicates');
    assert.equal(s.cursor, null);
    s = listReducer(s, { type: 'more' });
    assert.equal(s.status, 'ready', 'no cursor, nothing more to load');
    s = listReducer(s, { type: 'created', item: { id: 9 } });
    assert.equal(s.items[0].id, 9);
    s = listReducer(s, { type: 'failed', error: { text: 'x' } });
    assert.equal(s.status, 'error');
    s = listReducer(s, { type: 'load' });
    assert.equal(s.status, 'loading');
    assert.equal(s.error, null);
});

test('versions are described in plain words and only automatic ones can be pruned', () => {
    assert.equal(describeVersion(v()).title, 'Automatic save');
    assert.equal(describeVersion(v()).protectedFromPruning, false);
    const named = describeVersion(v({ kind: 'manual', label: 'Sent to printer' }));
    assert.equal(named.title, 'Sent to printer');
    assert.equal(named.protectedFromPruning, true);
    const approved = describeVersion(v({ kind: 'approved', label: 'Sent for review' }));
    assert.equal(approved.approved, true);
    assert.equal(approved.protectedFromPruning, true);
    assert.equal(describeVersion(v({ kind: 'restore', label: null })).kind, 'Before a restore');
    assert.equal(describeVersion(v({ author: null })).author, 'Someone who left the workspace');
});

test('filters separate kept versions from automatic ones', () => {
    const items = [v({ id: 1 }), v({ id: 2, kind: 'manual' }), v({ id: 3, kind: 'approved' })];
    assert.deepEqual(applyFilter(items, 'kept').map((i) => i.id), [2, 3]);
    assert.deepEqual(applyFilter(items, 'auto').map((i) => i.id), [1]);
    assert.equal(applyFilter(items, 'all').length, 3);
});

test('restore flow cannot start twice, returns to confirm on failure and ends in reloading', () => {
    let s = initialRestore();
    s = restoreReducer(s, { type: 'start' });
    assert.equal(s.step, 'idle', 'cannot start without asking');
    s = restoreReducer(s, { type: 'ask', versionId: 5 });
    assert.equal(s.step, 'confirm');
    s = restoreReducer(s, { type: 'start' });
    assert.equal(s.step, 'restoring');
    assert.equal(restoreReducer(s, { type: 'ask', versionId: 6 }).step, 'restoring', 'ask is ignored mid restore');
    assert.equal(restoreReducer(s, { type: 'cancel' }).step, 'restoring', 'cannot cancel mid restore');
    const failed = restoreReducer(s, { type: 'failed', error: { text: 'nope' } });
    assert.equal(failed.step, 'confirm');
    assert.equal(failed.error.text, 'nope');
    const done = restoreReducer(s, { type: 'done', preRestoreId: 12 });
    assert.equal(done.step, 'reloading');
    assert.equal(done.preRestoreId, 12);
});

test('diff counts become one sentence', () => {
    assert.equal(summariseDiff({ pages: { added: 0, removed: 0, changed: 0 }, elements: { added: 0, removed: 0, changed: 0 } }), 'Same as the design now.');
    assert.equal(summariseDiff({ pages: { added: 1, removed: 0, changed: 1 }, elements: { added: 3, removed: 1, changed: 2 } }), '3 elements added, 1 element removed, 2 elements changed, 1 page added since this version.');
    assert.equal(summariseDiff(null), '');
});

test('share expiry choices map to days and an unknown choice means never', () => {
    assert.equal(expiryDays('never'), null);
    assert.equal(expiryDays('7'), 7);
    assert.equal(expiryDays('bogus'), null);
    assert.ok(EXPIRY_CHOICES.every((c) => c.days === null || (c.days >= 1 && c.days <= 365)));
});

test('what a link allows is stated honestly for each combination', () => {
    assert.match(allowsSentence('view', false), /cannot comment/);
    assert.match(allowsSentence('view', false), /cannot download/);
    assert.match(allowsSentence('comment', true), /leave comments/);
    assert.match(allowsSentence('comment', true), /can download/);
    assert.match(allowsSentence('view', false), /without signing in/);
});

test('a new link is held in memory, handed out until hidden, and then gone for good', () => {
    const vault = createLinkVault();
    assert.equal(vault.has, false);
    vault.keep('https://x.test/s/abc', 4);
    assert.equal(vault.has, true);
    assert.equal(vault.peek(), 'https://x.test/s/abc');
    assert.equal(vault.shareId, 4);
    vault.hide();
    assert.equal(vault.has, false);
    assert.equal(vault.peek(), null);
});

test('the link list never carries an address and states status in words', () => {
    const now = Date.parse('2026-11-09T12:00:00Z');
    const active = describeShare({ permission: 'comment', status: 'active', download_allowed: true, view_count: 1, expires_at: null }, now);
    assert.deepEqual([active.state, active.permission, active.views, active.downloads], ['active', 'View and comment', '1 view', 'Downloads allowed']);
    assert.equal(describeShare({ permission: 'view', revoked_at: '2026-11-08T00:00:00Z', view_count: 0 }, now).state, 'revoked');
    assert.equal(describeShare({ permission: 'view', expires_at: '2026-11-01T00:00:00Z', view_count: 0 }, now).state, 'expired');
    assert.equal(expiresIn(null), 'Never expires');
    assert.equal(expiresIn('2026-11-16T12:00:00Z', now), 'Expires in 7 days');
    assert.equal(expiresIn('2026-11-01T12:00:00Z', now), 'Expired');
});

test('urls come from the project id and permission names accept both spellings', () => {
    const u = collabUrls({ project: { id: 42 } });
    assert.equal(u.comments, '/design/projects/42/comments');
    assert.equal(u.restore(9), '/design/projects/42/versions/9/restore');
    assert.equal(u.review(3, 'request-changes'), '/design/projects/42/reviews/3/request-changes');
    assert.equal(collabUrls({ project: { id: 1 }, urls: { comments: '/x' } }).comments, '/x');
    assert.equal(can({ permissions: { share_link: true } }, 'share'), true);
    assert.equal(can({ permissions: { can: { request_review: true } } }, 'review'), true);
    assert.equal(can({ permissions: { share: false } }, 'share'), false);
    assert.equal(can({}, 'share'), false);
});

test('server failures become sentences a person can act on', () => {
    assert.match(explain({ status: 0 }, { what: 'send your comment' }).text, /Check your connection/);
    assert.match(explain({ status: 429 }).text, /Wait a minute/);
    assert.match(explain({ status: 403, message: 'This action is unauthorized.' }, { what: 'share this' }).text, /permission to share this/);
    assert.equal(explain({ status: 422, errors: { body: ['A comment cannot be empty.'] } }).text, 'A comment cannot be empty.');
    assert.equal(explain({ status: 409, message: 'This project already has the maximum number of active links. Revoke one first.' }).text, 'This project already has the maximum number of active links. Revoke one first.');
    assert.match(explain({ status: 500 }, { what: 'save' }).text, /problem on our side/);
    assert.match(explain({ status: 404 }).text, /no longer available/);
});

test('time and size formats', () => {
    const now = Date.parse('2026-11-09T12:00:00Z');
    assert.equal(timeAgo('2026-11-09T11:59:40Z', now), 'just now');
    assert.equal(timeAgo('2026-11-09T11:30:00Z', now), '30 minutes ago');
    assert.equal(timeAgo('2026-11-09T09:00:00Z', now), '3 hours ago');
    assert.equal(timeAgo('2026-11-08T09:00:00Z', now), 'yesterday');
    assert.equal(formatBytes(512), '512 B');
    assert.equal(formatBytes(1536), '1.5 KB');
});

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRecovery } from '../../resources/js/studio/engine/recovery.js';

class MemStorage {
    constructor() { this.m = new Map(); this.quota = Infinity; }
    get length() { return this.m.size; }
    key(i) { return [...this.m.keys()][i] ?? null; }
    getItem(k) { return this.m.has(k) ? this.m.get(k) : null; }
    setItem(k, v) { if ([...this.m.values()].reduce((s, x) => s + x.length, 0) + v.length > this.quota) throw new Error('QuotaExceededError'); this.m.set(k, String(v)); }
    removeItem(k) { this.m.delete(k); }
}
const page = (id, rev, extra = {}) => ({ id, name: null, width: 100, height: 100, background: { type: 'solid', color: '#fff' }, duration_ms: 5000, transition: 'none', guides: [], elements: [], revision: rev, ...extra });
const el = (n) => ({ id: 'e' + n, type: 'shape', kind: 'rect' });

test('write journals a page under project/page/revision and list returns it', () => {
    const s = new MemStorage();
    const r = createRecovery({ storage: s, projectKey: 'p7', now: () => 1000 });
    assert.equal(r.write(page(3, 2, { elements: [el(1)] })), true);
    assert.deepEqual([...s.m.keys()], ['dotdesign:studio:journal:p7:3:2']);
    const list = r.list();
    assert.equal(list.length, 1);
    assert.equal(list[0].pageId, 3);
    assert.equal(list[0].revision, 2);
    assert.equal(list[0].page.elements[0].id, 'e1');
    assert.equal('revision' in list[0].page, false);
});

test('writing a new revision replaces the old key for that page only', () => {
    const s = new MemStorage();
    const r = createRecovery({ storage: s, projectKey: 'p' });
    r.write(page(1, 1)); r.write(page(2, 1)); r.write(page(1, 2));
    assert.deepEqual([...s.m.keys()].sort(), ['dotdesign:studio:journal:p:1:2', 'dotdesign:studio:journal:p:2:1']);
});

test('clear removes one page, clearAll removes the project only', () => {
    const s = new MemStorage();
    const a = createRecovery({ storage: s, projectKey: 'a' });
    const b = createRecovery({ storage: s, projectKey: 'b' });
    a.write(page(1, 1)); a.write(page(2, 1)); b.write(page(1, 1));
    a.clear(1);
    assert.equal(a.list().length, 1);
    a.clearAll();
    assert.equal(a.list().length, 0);
    assert.equal(b.list().length, 1);
});

test('storage that throws never breaks the editor', () => {
    const boom = { get length() { throw new Error('denied'); }, key() { throw new Error('denied'); }, getItem() { throw new Error('denied'); }, setItem() { throw new Error('QuotaExceededError'); }, removeItem() { throw new Error('denied'); } };
    const r = createRecovery({ storage: boom });
    assert.doesNotThrow(() => r.write(page(1, 1)));
    assert.equal(r.write(page(1, 1)), false);
    assert.deepEqual(r.list(), []);
    assert.deepEqual(r.recoverable([page(1, 1)]), []);
    assert.doesNotThrow(() => r.clear(1));
    assert.doesNotThrow(() => r.clearAll());
});

test('no storage at all: available is false and every call is safe', () => {
    const r = createRecovery({ storage: null });
    assert.equal(r.available, false);
    assert.equal(r.write(page(1, 1)), false);
    assert.deepEqual(r.list(), []);
    assert.doesNotThrow(() => r.clear(1));
});

test('quota errors are swallowed and report false', () => {
    const s = new MemStorage();
    s.quota = 50;
    const r = createRecovery({ storage: s });
    assert.equal(r.write(page(1, 1, { elements: [el(1), el(2), el(3)] })), false);
});

test('oversized pages are not journaled and the warning is raised once', () => {
    const warnings = [];
    const r = createRecovery({ storage: new MemStorage(), maxPageBytes: 200, onWarn: (m) => warnings.push(m) });
    const big = page(1, 1, { elements: Array.from({ length: 50 }, (_, i) => el(i)) });
    assert.equal(r.write(big), false);
    assert.equal(r.write(big), false);
    assert.equal(warnings.length, 1);
});

test('the journal is size bounded: the oldest entries are evicted first', () => {
    const s = new MemStorage();
    let t = 0;
    const r = createRecovery({ storage: s, now: () => (t += 1), maxEntries: 3 });
    for (let i = 1; i <= 6; i += 1) r.write(page(i, 1));
    const left = r.list().map((e) => e.pageId).sort();
    assert.ok(left.length <= 3);
    assert.ok(left.includes(6));
    assert.equal(left.includes(1), false);
    const small = createRecovery({ storage: new MemStorage(), maxTotalBytes: 900, now: () => (t += 1) });
    for (let i = 1; i <= 8; i += 1) small.write(page(i, 1));
    assert.ok(small.list().length < 8);
});

test('recoverable returns journals that differ from the server copy, flagged stale when the server moved on', () => {
    const s = new MemStorage();
    const r = createRecovery({ storage: s, projectKey: 'p', now: () => 5000 });
    r.write(page(1, 3, { elements: [el(1)] }));
    r.write(page(2, 1, { elements: [el(2)] }));
    const server = [page(1, 3), page(2, 4)];
    const list = r.recoverable(server).sort((a, b) => a.pageId - b.pageId);
    assert.deepEqual(list.map((x) => [x.pageId, x.revision, x.serverRevision, x.stale]), [[1, 3, 3, false], [2, 1, 4, true]]);
});

test('journals identical to the server copy are discarded (the save did land)', () => {
    const s = new MemStorage();
    const r = createRecovery({ storage: s, projectKey: 'p' });
    r.write(page(1, 3, { elements: [el(1)] }));
    assert.deepEqual(r.recoverable([page(1, 4, { elements: [el(1)] })]), []);
    assert.equal(s.length, 0);
});

test('journals for pages that no longer exist, or older than 30 days, are pruned', () => {
    const s = new MemStorage();
    let now = 0;
    const r = createRecovery({ storage: s, projectKey: 'p', now: () => now });
    r.write(page(9, 1, { elements: [el(1)] }));
    r.write(page(1, 1, { elements: [el(1)] }));
    assert.deepEqual(r.recoverable([page(1, 1)]).map((x) => x.pageId), [1]);
    assert.equal(s.length, 1);
    now = 31 * 24 * 3600 * 1000;
    assert.deepEqual(r.recoverable([page(1, 1)]), []);
    assert.equal(s.length, 0);
});

test('corrupt entries are ignored', () => {
    const s = new MemStorage();
    s.setItem('dotdesign:studio:journal:p:1:1', '{not json');
    s.setItem('dotdesign:studio:journal:p:2:1', JSON.stringify({ v: 2, project: 'p', page: {} }));
    s.setItem('other:key', 'x');
    const r = createRecovery({ storage: s, projectKey: 'p' });
    assert.deepEqual(r.list(), []);
    assert.deepEqual(r.recoverable([page(1, 1)]), []);
});

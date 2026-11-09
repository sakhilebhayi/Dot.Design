import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createQueue, describeFailure, precheck } from '../../resources/js/studio/pages/assets-queue.js';

const file = (name, type = 'image/png', size = 1000) => ({ name, type, size });
const tick = () => new Promise((r) => setTimeout(r, 0));

/** A transport whose uploads finish only when the test says so. */
function manualTransport() {
    const calls = [];
    const transport = (item, opts) => new Promise((resolve) => { calls.push({ item, opts, resolve }); });
    return { transport, calls };
}

test('precheck: wrong type and over-size files fail before any upload, in the server words', () => {
    assert.equal(precheck(file('a.png'), { maxKb: 8192 }), null);
    assert.equal(precheck(file('a.gif', 'image/gif'), { maxKb: 8192 }).reason, 'type');
    assert.match(precheck(file('a.pdf', 'application/pdf'), { maxKb: 8192 }).message, /JPG, PNG or WebP/);
    const big = precheck(file('big.png', 'image/png', 9 * 1024 * 1024), { maxKb: 8192 });
    assert.equal(big.reason, 'size');
    assert.match(big.message, /8 MB upload limit/);
    assert.equal(precheck(file('empty.png', 'image/png', 0), { maxKb: 8192 }).reason, 'size');
});

test('files that fail the check never reach the transport and are not retryable', async () => {
    const { transport, calls } = manualTransport();
    const q = createQueue({ transport });
    q.add([file('a.gif', 'image/gif'), file('ok.png')]);
    await tick();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].item.name, 'ok.png');
    const [gif] = q.items();
    assert.equal(gif.status, 'failed');
    assert.equal(gif.retryable, false);
    assert.equal(q.retry(gif.id), false);
});

test('at most `concurrency` uploads run at once, the rest wait their turn', async () => {
    const { transport, calls } = manualTransport();
    const q = createQueue({ transport, concurrency: 2 });
    q.add([file('1.png'), file('2.png'), file('3.png')]);
    await tick();
    assert.equal(calls.length, 2);
    assert.deepEqual(q.items().map((i) => i.status), ['uploading', 'uploading', 'queued']);
    calls[0].resolve({ ok: true, asset: { id: 1 } });
    await tick();
    assert.equal(calls.length, 3);
    assert.equal(q.counts().done, 1);
});

test('progress is clamped to 0..1 and reported while uploading', async () => {
    const { transport, calls } = manualTransport();
    const seen = [];
    const q = createQueue({ transport, onChange: (items) => seen.push(items[0].progress) });
    q.add([file('1.png')]);
    await tick();
    calls[0].opts.onProgress(0.5);
    calls[0].opts.onProgress(7);
    assert.equal(q.items()[0].progress, 1);
    assert.ok(seen.includes(0.5));
});

test('a server refusal keeps its exact reason and words; quota is retryable, dimensions is not', async () => {
    const { transport, calls } = manualTransport();
    const q = createQueue({ transport });
    q.add([file('q.png'), file('d.png')]);
    await tick();
    calls[0].resolve({ ok: false, status: 422, reason: 'quota', message: 'This would go over your storage quota (190.0 MB used of 200.0 MB). Delete some assets and try again.' });
    calls[1].resolve({ ok: false, status: 422, reason: 'dimensions', message: 'That picture is larger than 8000 pixels on a side.' });
    await tick();
    const [quota, dimension] = q.items();
    assert.equal(quota.reason, 'quota');
    assert.match(quota.error, /190\.0 MB used of 200\.0 MB/);
    assert.equal(quota.retryable, true);
    assert.equal(dimension.reason, 'dimensions');
    assert.equal(dimension.retryable, false);
});

test('retry puts a failed, retryable item back in the queue and it can then succeed', async () => {
    const { transport, calls } = manualTransport();
    const q = createQueue({ transport });
    q.add([file('n.png')]);
    await tick();
    calls[0].resolve({ ok: false, status: 0 });
    await tick();
    const failed = q.items()[0];
    assert.equal(failed.reason, 'network');
    assert.equal(q.retry(failed.id), true);
    await tick();
    assert.equal(calls.length, 2);
    calls[1].resolve({ ok: true, asset: { id: 9 }, duplicate: true });
    await tick();
    const done = q.items()[0];
    assert.equal(done.status, 'done');
    assert.equal(done.duplicate, true);
    assert.equal(done.asset.id, 9);
});

test('a transport that throws becomes a network failure, never an unhandled error', async () => {
    const q = createQueue({ transport: () => { throw new Error('boom'); } });
    q.add([file('x.png')]);
    await tick();
    const [item] = q.items();
    assert.equal(item.status, 'failed');
    assert.equal(item.reason, 'network');
});

test('cancel stops a queued file and an uploading one; its late result is ignored', async () => {
    const { transport, calls } = manualTransport();
    const q = createQueue({ transport, concurrency: 1 });
    q.add([file('1.png'), file('2.png')]);
    await tick();
    const [first, second] = q.items();
    assert.equal(q.cancel(second.id), true, 'queued');
    assert.equal(q.cancel(first.id), true, 'uploading');
    calls[0].resolve({ ok: true, asset: { id: 1 } });
    await tick();
    assert.deepEqual(q.items().map((i) => i.status), ['cancelled', 'cancelled']);
    assert.equal(calls.length, 1, 'the cancelled queued file was never sent');
    assert.equal(q.cancel(first.id), false);
});

test('onDone fires once per success with the item and its asset', async () => {
    const { transport, calls } = manualTransport();
    const done = [];
    const q = createQueue({ transport, onDone: (i) => done.push(i.asset.id) });
    q.add([file('1.png'), file('2.png')]);
    await tick();
    calls[1].resolve({ ok: true, asset: { id: 22 } });
    calls[0].resolve({ ok: true, asset: { id: 11 } });
    await tick();
    assert.deepEqual(done.sort(), [11, 22]);
    assert.equal(q.busy(), false);
});

test('clearFinished removes done, failed and cancelled only; remove refuses an active item', async () => {
    const { transport, calls } = manualTransport();
    const q = createQueue({ transport, concurrency: 1 });
    q.add([file('1.png'), file('bad.gif', 'image/gif'), file('3.png')]);
    await tick();
    assert.equal(q.remove(q.items()[0].id), false, 'uploading');
    calls[0].resolve({ ok: true, asset: { id: 1 } });
    await tick();
    q.clearFinished();
    assert.deepEqual(q.items().map((i) => i.name), ['3.png']);
});

test('failure wording by status', () => {
    assert.equal(describeFailure({ status: 429 }).reason, 'rate');
    assert.equal(describeFailure({ status: 413 }).reason, 'size');
    assert.equal(describeFailure({ status: 503 }).reason, 'server');
    assert.equal(describeFailure({ status: 0 }).reason, 'network');
    assert.equal(describeFailure({ status: 422, reason: 'type', message: 'x' }).message, 'x');
});

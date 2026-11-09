import test from 'node:test';
import assert from 'node:assert/strict';
import { applyOps, applyProposal, previewProposal } from '../../resources/js/studio/assist/ops.js';
import { byId, ids, mkCore, rect, text } from './helpers.mjs';

const setup = () => mkCore([
    rect({ id: 'bg', width: 1000, height: 800, fill: '#1b1b1b' }),
    text({ id: 'head', x: 80, y: 100, width: 800, height: 100, fontSize: 40, color: '#ffffff', fontWeight: 400 }),
    text({ id: 'body', x: 80, y: 300, width: 800, height: 60, fontSize: 20, color: '#ffffff' }),
]);

const proposal = (ops, extra = {}) => ({ id: 1, page_id: 1, type: 'patch', ops, ...extra });

test('set / add / reorder apply together as one undo step, and one undo restores the page exactly', () => {
    const core = setup();
    const before = core.getState().doc.pages;
    const startDepth = core.history.canUndo();

    const res = applyProposal(core, proposal([
        { op: 'set', id: 'head', props: { fontSize: 72, fontWeight: 800 } },
        { op: 'set', id: 'body', props: { color: '#ffb81c' } },
        { op: 'add', element: { type: 'shape', kind: 'rect', x: 40, y: 280, width: 900, height: 100, fill: '#000000', opacity: 0.6 }, behind_id: 'body' },
        { op: 'reorder', id: 'head', to: 'back-most' },
    ]));

    assert.equal(res.ok, true);
    assert.equal(byId(core, 'head').fontSize, 72);
    assert.equal(byId(core, 'head').fontWeight, 800);
    assert.equal(byId(core, 'body').color, '#ffb81c');
    const order = ids(core);
    assert.equal(order[0], 'head', 'reordered to the back');
    assert.ok(order.indexOf(order.find((i) => i.startsWith('ai_'))) === order.indexOf('body') - 1, 'the backing shape sits just behind the body text');
    assert.equal(startDepth, false);

    assert.equal(core.undo(), true, 'a single undo');
    assert.deepEqual(core.getState().doc.pages, before, 'the page is exactly as it was');
    assert.equal(core.canUndo(), false, 'nothing else was left on the undo stack: the whole proposal was ONE step');
    assert.equal(core.redo(), true);
    assert.equal(byId(core, 'head').fontSize, 72);
});

test('nothing is applied when any operation names an element that is gone', () => {
    const core = setup();
    const before = core.getState().doc.pages;

    const res = applyProposal(core, proposal([
        { op: 'set', id: 'head', props: { fontSize: 72 } },
        { op: 'set', id: 'deleted-meanwhile', props: { fontSize: 20 } },
    ]));

    assert.equal(res.ok, false);
    assert.equal(res.code, 'stale');
    assert.deepEqual(res.missing, ['deleted-meanwhile']);
    assert.deepEqual(core.getState().doc.pages, before);
    assert.equal(core.canUndo(), false);
});

test('unknown operations, properties and a missing page are refused without touching the design', () => {
    const core = setup();
    const before = core.getState().doc.pages;

    for (const ops of [[{ op: 'delete', id: 'head' }], [{ op: 'set', id: 'head', props: { text: 'Hacked' } }], [{ op: 'set', id: 'head', props: { children: [] } }], [{ op: 'reorder', id: 'head', to: 'sideways' }], [{ op: 'add', element: { type: 'image', x: 0, y: 0, width: 1, height: 1 } }]]) {
        assert.equal(applyProposal(core, proposal(ops)).ok, false, JSON.stringify(ops));
    }
    assert.equal(applyProposal(core, proposal([{ op: 'set', id: 'head', props: { fontSize: 50 } }], { page_id: 999 })).ok, false);
    assert.equal(applyProposal(core, proposal([])).ok, false);
    assert.deepEqual(core.getState().doc.pages, before);
    assert.equal(core.canUndo(), false);
});

test('preview works on a copy: the engine state is never touched', () => {
    const core = setup();
    const before = core.getState();
    const res = previewProposal(before, proposal([{ op: 'set', id: 'head', props: { fontSize: 90 } }]));
    assert.equal(res.ok, true);
    assert.equal(res.page.elements.find((e) => e.id === 'head').fontSize, 90);
    assert.equal(core.getState(), before);
    assert.equal(before.doc.pages[0].elements[1].fontSize, 40);
});

test('the selection and active page survive an apply', () => {
    const core = setup();
    core.select(['body']);
    assert.equal(applyProposal(core, proposal([{ op: 'set', id: 'head', props: { fontSize: 60 } }])).ok, true);
    assert.deepEqual(core.getState().selection, ['body']);
    core.undo();
    assert.deepEqual(core.getState().selection, ['body']);
});

test('values the engine would not accept are normalised, not stored raw', () => {
    const core = setup();
    applyProposal(core, proposal([{ op: 'set', id: 'head', props: { fontSize: 99999, opacity: 7, color: 'not-a-colour' } }]));
    const head = byId(core, 'head');
    assert.ok(head.fontSize <= 2000);
    assert.ok(head.opacity <= 1);
    assert.match(head.color, /^#[0-9a-f]{3,8}$/i);
});

test('applyOps is pure and generates collision-free ids for added elements', () => {
    const page = { id: 1, elements: [{ id: 'a', type: 'text' }, { id: 'ai_taken', type: 'shape' }] };
    const copy = JSON.stringify(page);
    const add = { op: 'add', element: { type: 'text', text: 'New', x: 0, y: 0, width: 100, height: 20, fontSize: 12, font: 'inter', color: '#111111' } };
    const res = applyOps(page, [add, add]);
    assert.equal(res.ok, true);
    assert.equal(JSON.stringify(page), copy, 'input untouched');
    const added = res.page.elements.filter((e) => e.id !== 'a' && e.id !== 'ai_taken');
    assert.equal(added.length, 2);
    assert.notEqual(added[0].id, added[1].id);
});

test('set reaches elements inside groups', () => {
    const page = { id: 1, elements: [{ id: 'g', type: 'group', children: [{ id: 'kid', type: 'text', fontSize: 10 }] }] };
    const res = applyOps(page, [{ op: 'set', id: 'kid', props: { fontSize: 30 } }]);
    assert.equal(res.page.elements[0].children[0].fontSize, 30);
    assert.equal(applyOps(page, [{ op: 'reorder', id: 'kid', to: 'front' }]).ok, false, 'children cannot be reordered from outside their group');
});

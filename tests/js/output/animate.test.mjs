import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { evaluate, evaluateElement, ease, elementTimeline, IN_PRESETS, OUT_PRESETS, LOOP_PRESETS, EASINGS, proposeDefaults, staggerPatches, readingOrder, pageDuration, isIdentity, contentEnd } from '../../../resources/js/studio/output/animate.js';
import { applyAnimationToSvg, animatedMatrix, revealLines } from '../../../resources/js/studio/output/animate-apply.js';
import { renderPageToSvgString } from '../../../resources/js/studio/engine/render.js';
import { el, mkPage, text, rect, stub } from '../helpers.mjs';

const schema = JSON.parse(fs.readFileSync(new URL('../../../resources/design/document-schema.json', import.meta.url), 'utf8'));
const near = (a, b, eps = 1e-6, msg = '') => assert.ok(Math.abs(a - b) <= eps, `${msg} expected ${b}, got ${a}`);

const page = (elements, extra = {}) => mkPage(elements, { width: 1000, height: 800, duration_ms: 5000, ...extra });
const withAnim = (e, animation) => ({ ...e, animation });

test('preset lists equal the schema enums (nothing missing, nothing invented)', () => {
    assert.deepEqual(IN_PRESETS, schema.enums.anim_preset_in);
    assert.deepEqual(OUT_PRESETS, schema.enums.anim_preset_out);
    assert.deepEqual(LOOP_PRESETS, schema.enums.anim_preset_loop);
    assert.deepEqual(EASINGS, schema.enums.anim_easing);
});

test('easings: endpoints, monotone ease-out, spring overshoots', () => {
    for (const name of EASINGS) { assert.equal(ease(name, 0), 0); assert.equal(ease(name, 1), 1); assert.equal(ease(name, -1), 0); assert.equal(ease(name, 2), 1); }
    near(ease('linear', 0.25), 0.25);
    near(ease('ease-out', 0.5), 0.875);
    near(ease('ease-in-out', 0.5), 0.5);
    assert.ok(ease('ease-in-out', 0.25) < 0.25);
    let prev = 0; for (let i = 1; i <= 20; i += 1) { const v = ease('ease-out', i / 20); assert.ok(v >= prev); prev = v; }
    assert.ok(Array.from({ length: 50 }, (_, i) => ease('spring', i / 50)).some((v) => v > 1.001));
});

test('every IN preset starts hidden/moved and ends at rest', () => {
    for (const preset of IN_PRESETS.filter((p) => p !== 'none')) {
        const e = withAnim(text({ id: 'a', x: 100, y: 100, width: 300, height: 60 }), { in: { preset, delay_ms: 200, duration_ms: 1000, easing: 'linear' } });
        const pg = page([e]);
        const before = evaluate(pg, 0).get('a');
        assert.ok(before && !isIdentity(before), `${preset} should not be at rest before it starts`);
        const end = evaluate(pg, 1200).get('a');
        assert.ok(isIdentity(end), `${preset} should be at rest when it has finished: ${JSON.stringify(end)}`);
        // determinism: same input, same output, no hidden state
        assert.deepEqual(evaluate(pg, 700).get('a'), evaluate(pg, 700).get('a'));
        assert.deepEqual(evaluate(pg, 0).get('a'), before);
    }
});

test('specific frames of rise, fade, zoom-in, wipe-left, type, blur-in, pop, slide', () => {
    const mk = (preset, extra = {}) => page([withAnim({ ...text({ id: 'a', x: 100, y: 100, width: 300, height: 60 }), ...extra }, { in: { preset, delay_ms: 0, duration_ms: 1000, easing: 'linear' } })]);
    let s = evaluate(mk('fade'), 250).get('a'); near(s.opacity, 0.25);
    s = evaluate(mk('rise'), 500).get('a'); near(s.opacity, 0.5); near(s.translateY, 0.5 * 0.06 * 800);
    s = evaluate(mk('drop'), 500).get('a'); near(s.translateY, -0.5 * 0.06 * 800);
    s = evaluate(mk('zoom-in'), 500).get('a'); near(s.scale, 0.8); near(s.opacity, 0.5);
    s = evaluate(mk('wipe-left'), 400).get('a'); assert.deepEqual(s.clip, { x0: 0, y0: 0, x1: 0.4, y1: 1 });
    s = evaluate(mk('wipe-up'), 400).get('a'); assert.deepEqual(s.clip, { x0: 0, y0: 0.6, x1: 1, y1: 1 });
    s = evaluate(mk('type'), 300).get('a'); near(s.textProgress, 0.3);
    s = evaluate(mk('type', { type: 'shape', kind: 'rect' }), 300).get('a'); assert.equal(s.textProgress, undefined); assert.ok(s.clip, 'type on a non-text element wipes instead');
    s = evaluate(mk('blur-in'), 500).get('a'); near(s.blur, 0.5 * 0.02 * 800); near(s.opacity, 0.5);
    s = evaluate(mk('pop'), 500).get('a'); assert.ok(s.scale > 0.9 && s.scale < 1.2); near(evaluate(mk('pop'), 0).get('a').scale, 0);
    s = evaluate(mk('slide-left'), 0).get('a'); near(s.translateX, -(100 + 300 + 8)); // starts fully off the left edge
    s = evaluate(mk('slide-right'), 0).get('a'); near(s.translateX, 1000 - 100 + 8);
    s = evaluate(mk('slide-left'), 500).get('a'); near(s.translateX, -0.5 * 408);
});

test('delays: an element waits in its "from" state, then animates', () => {
    const pg = page([withAnim(text({ id: 'a', x: 0, y: 0, width: 100, height: 30 }), { in: { preset: 'fade', delay_ms: 1000, duration_ms: 500, easing: 'linear' } })]);
    near(evaluate(pg, 0).get('a').opacity, 0);
    near(evaluate(pg, 999).get('a').opacity, 0);
    near(evaluate(pg, 1250).get('a').opacity, 0.5);
    near(evaluate(pg, 1500).get('a').opacity, 1);
});

test('out presets end gone/moved at the page end; delay_ms means "finishes this long before the end"', () => {
    for (const preset of OUT_PRESETS.filter((p) => p !== 'none')) {
        const pg = page([withAnim(text({ id: 'a', x: 100, y: 100, width: 300, height: 60 }), { out: { preset, delay_ms: 0, duration_ms: 500, easing: 'linear' } })], { duration_ms: 4000 });
        assert.ok(isIdentity(evaluate(pg, 3400).get('a')), `${preset} rests before its exit starts`);
        const end = evaluate(pg, 4000).get('a');
        assert.ok(!isIdentity(end), `${preset} must be away at the end`);
        if (['fade', 'sink', 'zoom-out'].includes(preset)) near(end.opacity, 0);
    }
    const hold = page([withAnim(text({ id: 'a', x: 0, y: 0, width: 100, height: 30 }), { out: { preset: 'fade', delay_ms: 1000, duration_ms: 500, easing: 'linear' } })], { duration_ms: 4000 });
    near(evaluate(hold, 2500).get('a').opacity, 1);
    near(evaluate(hold, 2750).get('a').opacity, 0.5);
    near(evaluate(hold, 3000).get('a').opacity, 0);
    near(evaluate(hold, 4000).get('a').opacity, 0); // stays gone until the page ends
});

test('the exit never starts before the entrance has ended', () => {
    const e = withAnim(text({ id: 'a', x: 0, y: 0, width: 100, height: 30 }), { in: { preset: 'fade', delay_ms: 0, duration_ms: 3000, easing: 'linear' }, out: { preset: 'fade', delay_ms: 0, duration_ms: 3000, easing: 'linear' } });
    const tl = elementTimeline(e, 4000);
    assert.equal(tl.out.start, 3000);
});

test('loops: pulse, float, spin, wiggle are periodic, start after the entrance and stop at the exit', () => {
    for (const preset of LOOP_PRESETS.filter((p) => p !== 'none')) {
        const e = withAnim(text({ id: 'a', x: 0, y: 0, width: 100, height: 30 }), { in: { preset: 'fade', delay_ms: 0, duration_ms: 500, easing: 'linear' }, loop: { preset, duration_ms: 1000 } });
        const pg = page([e], { duration_ms: 5000 });
        const a = evaluate(pg, 1250).get('a');
        const b = evaluate(pg, 2250).get('a');
        assert.deepEqual(a, b, `${preset} must repeat every period`);
        const rest = evaluate(pg, 1500).get('a'); // phase 0 of the loop
        assert.ok(rest.rotate === 0 || preset === 'spin' || preset === 'wiggle');
        if (preset === 'pulse') { near(evaluate(pg, 1750).get('a').scale, 1.05); }
        if (preset === 'float') assert.ok(evaluate(pg, 1750).get('a').translateY !== 0);
        if (preset === 'spin') near(evaluate(pg, 2000).get('a').rotate, 180);
    }
    const looped = page([withAnim(text({ id: 'a', x: 0, y: 0, width: 100, height: 30 }), { loop: { preset: 'pulse', duration_ms: 1000 }, out: { preset: 'fade', delay_ms: 0, duration_ms: 500, easing: 'linear' } })], { duration_ms: 3000 });
    assert.equal(evaluate(looped, 2750).get('a').scale, 1, 'no loop motion once the exit has started');
});

test('only animated, visible, top-level elements appear; none and empty animation are ignored', () => {
    const a = withAnim(text({ id: 'a' }), { in: { preset: 'fade', delay_ms: 0, duration_ms: 500 } });
    const none = withAnim(text({ id: 'n' }), { in: { preset: 'none' } });
    const hidden = { ...withAnim(text({ id: 'h' }), { in: { preset: 'fade' } }), hidden: true };
    const plain = text({ id: 'p' });
    const m = evaluate(page([a, none, hidden, plain]), 100);
    assert.deepEqual(Array.from(m.keys()), ['a']);
    assert.equal(evaluateElement(plain, 0, page([plain])), null);
});

test('page duration defaults and drives the exit', () => {
    assert.equal(pageDuration({}), 5000);
    assert.equal(pageDuration({ duration_ms: 8000 }), 8000);
    assert.ok(contentEnd(page([withAnim(text({ id: 'a' }), { in: { preset: 'fade', delay_ms: 1000, duration_ms: 600 } })])) === 1600);
});

test('reading order, stagger and smart defaults', () => {
    const head = text({ id: 'head', role: 'headline', x: 100, y: 100, width: 600, height: 80 });
    const sub = text({ id: 'sub', role: 'subhead', x: 100, y: 200, width: 600, height: 40 });
    const logo = el('image', { id: 'logo', role: 'logo', x: 800, y: 40, width: 100, height: 100 });
    const photo = el('image', { id: 'photo', role: 'photo', x: 100, y: 400, width: 800, height: 300 });
    const bg = rect({ id: 'bg', role: 'background', x: 0, y: 0, width: 1000, height: 800 });
    const decor = rect({ id: 'decor', role: 'decor', x: 10, y: 10, width: 30, height: 30 });
    const pg = page([bg, photo, sub, decor, logo, head]);
    assert.deepEqual(readingOrder(pg).map((e) => e.id), ['decor', 'logo', 'head', 'sub', 'photo']);
    const { patches } = proposeDefaults(pg, { stepMs: 100 });
    const by = Object.fromEntries(patches.map((p) => [p.id, p.animation.in]));
    assert.equal(by.head.preset, 'rise');
    assert.equal(by.sub.preset, 'fade');
    assert.equal(by.logo.preset, 'pop');
    assert.equal(by.photo.preset, 'zoom-in');
    assert.equal(by.bg, undefined);
    assert.equal(by.decor, undefined);
    assert.ok(by.logo.delay_ms < by.head.delay_ms && by.head.delay_ms < by.sub.delay_ms && by.sub.delay_ms < by.photo.delay_ms);
    // existing animations are respected
    const mine = withAnim(head, { in: { preset: 'drop', delay_ms: 0, duration_ms: 400, easing: 'linear' } });
    assert.equal(proposeDefaults(page([mine])).patches.length, 0);
    // stagger keeps presets, rewrites delays in reading order
    const st = staggerPatches(page([withAnim(sub, { in: { preset: 'fade', delay_ms: 0, duration_ms: 400, easing: 'linear' } }), withAnim(head, { in: { preset: 'rise', delay_ms: 0, duration_ms: 400, easing: 'linear' } })]), { stepMs: 250 });
    assert.deepEqual(st.map((p) => p.animation.in.preset).sort(), ['fade', 'rise']);
    const order = Object.fromEntries(st.map((p) => [p.id, p.animation.in.delay_ms]));
    assert.equal(order.head, 0); assert.equal(order.sub, 250);
});

test('revealLines reveals characters across lines in order', () => {
    assert.deepEqual(revealLines(['Hello', 'World'], 0), [{ visible: '', hidden: 'Hello' }, { visible: '', hidden: 'World' }]);
    assert.deepEqual(revealLines(['Hello', 'World'], 0.5), [{ visible: 'Hello', hidden: '' }, { visible: '', hidden: 'World' }]);
    assert.deepEqual(revealLines(['Hello', 'World'], 0.8), [{ visible: 'Hello', hidden: '' }, { visible: 'Wor', hidden: 'ld' }]);
    assert.deepEqual(revealLines(['A😀B'], 2 / 3), [{ visible: 'A😀', hidden: 'B' }]);
});

test('animated matrix: identity at rest, translate, scale about centre, rotation about centre', () => {
    const e = rect({ id: 'r', x: 100, y: 50, width: 200, height: 100 });
    const rest = { opacity: 1, translateX: 0, translateY: 0, scale: 1, rotate: 0 };
    const nz = (m) => m.map((v) => v + 0);
    assert.deepEqual(nz(animatedMatrix(e, rest)), [1, 0, 0, 1, 100, 50]);
    assert.deepEqual(nz(animatedMatrix(e, { ...rest, translateX: 10, translateY: -5 })), [1, 0, 0, 1, 110, 45]);
    const s = animatedMatrix(e, { ...rest, scale: 0.5 }); // centre (200,100) stays fixed
    near(s[0], 0.5); near(s[3], 0.5); near(s[4], 150, 1e-9); near(s[5], 75, 1e-9); // top-left moves toward the fixed centre (200,100)
    const r = animatedMatrix(e, { ...rest, rotate: 90 }); // centre stays at (200,100): local (100,50) -> (200,100)
    near(r[0] * 100 + r[2] * 50 + r[4], 200, 1e-9); near(r[1] * 100 + r[3] * 50 + r[5], 100, 1e-9);
});

test('applyAnimationToSvg changes only animated frames and adds clip, blur and typed text to the real renderer output', () => {
    const t1 = withAnim(text({ id: 'tt', text: 'Hello world', x: 10, y: 10, width: 300, height: 40, fontSize: 20, align: 'center' }), { in: { preset: 'type', delay_ms: 0, duration_ms: 1000, easing: 'linear' } });
    const t2 = withAnim(rect({ id: 'rr', x: 50, y: 100, width: 100, height: 60, opacity: 0.8 }), { in: { preset: 'wipe-left', delay_ms: 0, duration_ms: 1000, easing: 'linear' } });
    const t3 = withAnim(rect({ id: 'bb', x: 200, y: 100, width: 50, height: 50 }), { in: { preset: 'blur-in', delay_ms: 0, duration_ms: 1000, easing: 'linear' } });
    const still = rect({ id: 'ss', x: 300, y: 300, width: 20, height: 20 });
    const pg = page([t1, t2, t3, still]);
    const base = renderPageToSvgString(pg, { measure: stub, forExport: true });
    const at = (t) => applyAnimationToSvg(base, pg, evaluate(pg, t));
    const mid = at(500);
    assert.ok(mid.includes('clip-path="url(#dd-anim-clip-rr)"') && mid.includes('<clipPath id="dd-anim-clip-rr"><rect x="0" y="0" width="50" height="60"/>'));
    assert.ok(/<filter id="dd-anim-blur-bb"/.test(mid));
    assert.ok(mid.includes('opacity="0.8"') && mid.includes('data-id="ss"'));
    // the still element's frame is byte-identical
    const frame = (svg, id) => new RegExp(`<g transform="[^"]*"[^>]*data-id="${id}"[^>]*>`).exec(svg)[0];
    assert.equal(frame(mid, 'ss'), frame(base, 'ss'));
    // typed text: half of 11 chars (round 5.5 -> 6) visible, the rest hidden but still laid out
    assert.ok(/>Hello <tspan fill-opacity="0">world<\/tspan><\/tspan>/.test(mid), mid.match(/<text.*<\/text>/)[0]);
    // at the end everything is back to the base frames (no clip, no filter, no hidden text)
    const done = at(1000);
    assert.ok(!done.includes('anim-clip') && !done.includes('anim-blur') && !done.includes('fill-opacity="0"'));
    // output is still well-formed enough for an XML parser: balanced g tags
    for (const s of [mid, done]) assert.equal((s.match(/<g[\s>]/g) || []).length, (s.match(/<\/g>/g) || []).length);
});

test('a frame at t gives the same string every time (export determinism)', () => {
    const e = withAnim(text({ id: 'tt', x: 10, y: 10, width: 300, height: 40 }), { in: { preset: 'rise', delay_ms: 100, duration_ms: 900, easing: 'spring' }, loop: { preset: 'float', duration_ms: 1000 } });
    const pg = page([e]);
    const base = renderPageToSvgString(pg, { measure: stub, forExport: true });
    assert.equal(applyAnimationToSvg(base, pg, evaluate(pg, 777)), applyAnimationToSvg(base, pg, evaluate(pg, 777)));
    assert.notEqual(applyAnimationToSvg(base, pg, evaluate(pg, 777)), applyAnimationToSvg(base, pg, evaluate(pg, 1777)));
});

import { moveSegment, snap, clampN } from '../../../resources/js/studio/output/anim-edit.js';

test('timeline editing: moving the entrance changes its delay, resizing changes its length, both snap and clamp', () => {
    const a = { in: { preset: 'fade', delay_ms: 300, duration_ms: 700, easing: 'ease-out' } };
    assert.equal(moveSegment(a, 'in', 260, 'move', 5000).in.delay_ms, 550);
    assert.equal(moveSegment(a, 'in', -1000, 'move', 5000).in.delay_ms, 0);
    assert.equal(moveSegment(a, 'in', 240, 'resize', 5000).in.duration_ms, 950);
    assert.equal(moveSegment(a, 'in', -2000, 'resize', 5000).in.duration_ms, 100);
    assert.equal(a.in.delay_ms, 300, 'the input is not modified');
    assert.equal(moveSegment(a, 'out', 100, 'move', 5000), null);
    assert.equal(moveSegment(a, 'loop', 100, 'move', 5000), null);
});

test('timeline editing: the exit is positioned from the end of the page, so moving it right leaves less time after it', () => {
    const a = { out: { preset: 'fade', delay_ms: 1000, duration_ms: 500, easing: 'linear' } };
    assert.equal(moveSegment(a, 'out', 400, 'move', 5000).out.delay_ms, 600);
    assert.equal(moveSegment(a, 'out', -300, 'move', 5000).out.delay_ms, 1300);
    const longer = moveSegment(a, 'out', 250, 'resize', 5000).out; // right edge later: longer, finishing later, same start
    assert.equal(longer.duration_ms, 750); assert.equal(longer.delay_ms, 750);
    const before = elementTimeline({ animation: a }, 5000).out;
    const after = elementTimeline({ animation: { out: longer } }, 5000).out;
    assert.equal(after.start, before.start);
    assert.equal(after.end, before.end + 250);
    assert.equal(snap(123), 100); assert.equal(snap(126), 150); assert.equal(clampN(5, 1, 3), 3);
});

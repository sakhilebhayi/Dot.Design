// Animation (R-45 output): the Animate tab opens straight into the timeline (one track per element, bars you can drag, a playhead,
// scrub, play and repeat), with the presets of the selected element right under it. "Animate this design" is one button above the
// timeline. An Animation section in the inspector offers the same presets for the selected element. The evaluator is a pure
// function of time (output/animate.js); playback drives the live canvas and always hands it back exactly as it was.
// All figures are written with a dot (fmtSeconds): the browser's own number input would print "5,0" in many locales.
import '../../../../css/studio-output.css';
import { h, clear, icon } from '../../output/ui-kit.js';
import { IN_PRESETS, OUT_PRESETS, LOOP_PRESETS, EASINGS, elementTimeline, pageDuration, proposeDefaults, staggerPatches, readingOrder, contentEnd, DEFAULT_IN_MS } from '../../output/animate.js';
import { IN_LABELS, OUT_LABELS, LOOP_LABELS, EASING_LABELS, TRANSITION_LABELS, elementLabel, describeProposal } from '../../output/anim-labels.js';
import { createPlayback } from '../../output/playback.js';
import { moveSegment, secs, clampN, snap } from '../../output/anim-edit.js';
import { fmtSeconds } from '../../output/number-format.js';
import { select as mcSelect, numberField, switchControl, slider } from './_controls.js';

const idsOf = (selection) => (Array.isArray(selection) ? selection : selection && selection.ids) || [];

function topLevel(studio, id) {
    const page = studio.activePage();
    return (page.elements || []).find((e) => e.id === id) || null;
}

/** Writes an animation object (or null) for one element; one undo step per burst thanks to the key. */
function setAnimation(studio, id, anim, key) {
    const clean = anim && ((anim.in && anim.in.preset !== 'none') || (anim.out && anim.out.preset !== 'none') || (anim.loop && anim.loop.preset !== 'none')) ? pruned(anim) : null;
    return studio.exec('element.update', { ids: [id], changes: { animation: clean } }, { key: key || `anim:${id}` });
}

function pruned(anim) {
    const out = {};
    for (const k of ['in', 'out', 'loop']) if (anim[k] && anim[k].preset && anim[k].preset !== 'none') out[k] = anim[k];
    return out;
}

/** Ruler labels: whole seconds, thinned so two labels never come closer than `minGap` pixels. Pure: tested. */
export function rulerTicks(durationMs, widthPx, minGap = 34) {
    const total = Math.max(0.5, durationMs / 1000);
    const whole = Math.floor(total);
    const fit = Math.max(1, Math.floor(widthPx / minGap));
    const step = Math.max(1, Math.ceil(whole / fit));
    const ticks = [];
    for (let s = 0; s <= whole; s += step) ticks.push({ at: (s * 1000) / durationMs, label: `${s}s`, major: true });
    // a half-second page ends between labels: no label for it, the lane end is the end
    return ticks;
}

export function register(ctx) {
    const studio = ctx.studio;
    const playback = createPlayback(studio);
    studio.on('page', () => playback.stop());
    let afterEdit = 0;
    studio.on('change', (e) => {
        if (e && e.system) return;
        if (!playback.state.active) return;
        cancelAnimationFrame(afterEdit);
        afterEdit = requestAnimationFrame(() => requestAnimationFrame(() => playback.refresh()));
    });

    ctx.registerLeftTab({ id: 'animate', label: 'Animate', icon: 'play', order: 60, modes: ['simple', 'pro'], mount: (el, c) => mountPanel(el, c || ctx, playback) });
    ctx.registerInspectorSection({
        id: 'animation', label: 'Animation', order: 70, modes: ['simple', 'pro'],
        when: (selection) => idsOf(selection).length === 1,
        mount: (el, c) => mountInspector(el, c || ctx, playback),
    });
    return { playback };
}

// ---- the presets of one element (used by the tab and by the inspector) --------------------------------------------------

function presetFields(studio, el, playback, { compact = false } = {}) {
    const id = el.id;
    const a = el.animation || {};
    const D = pageDuration(studio.activePage());
    const upd = (patch) => { const next = { ...(el.animation || {}), ...patch }; setAnimation(studio, id, next); };
    const inA = a.in && a.in.preset !== 'none' ? a.in : null;
    const outA = a.out && a.out.preset !== 'none' ? a.out : null;
    const loopA = a.loop && a.loop.preset !== 'none' ? a.loop : null;
    const opts = (list, labels) => list.map((k) => ({ value: k, label: labels[k] }));
    const sel = (label, list, labels, value, onChange, fk) => mcSelect({ label, options: opts(list, labels), value, onChange, dataFk: fk, size: 'sm' });
    const num = (label, ms, min, max, onChange, fk) => numberField({ value: ms / 1000, min: min / 1000, max: max / 1000, step: 0.1, decimals: 1, unit: 's', label, dataFk: fk, onChange: (v) => onChange(Math.round(v * 1000)) });
    const field = (label, control) => h('div', { class: 'out-field' }, h('span', { class: 'lt-label' }, label), control);

    const wrap = h('div', { class: `out-presets${compact ? ' out-presets--compact' : ''}` });
    wrap.append(h('fieldset', {}, h('legend', {}, 'Comes in'),
        sel('Entrance', IN_PRESETS, IN_LABELS, inA ? inA.preset : 'none', (v) => upd({ in: v === 'none' ? { preset: 'none' } : { delay_ms: 0, duration_ms: DEFAULT_IN_MS, easing: 'ease-out', ...(a.in || {}), preset: v } }), 'a-in'),
        inA ? h('div', { class: 'out-inspector__pair' },
            field('Starts after', num('Entrance delay in seconds', inA.delay_ms || 0, 0, 60000, (ms) => upd({ in: { ...inA, delay_ms: ms } }), 'a-in-delay')),
            field('Takes', num('Entrance length in seconds', inA.duration_ms || DEFAULT_IN_MS, 100, 10000, (ms) => upd({ in: { ...inA, duration_ms: ms } }), 'a-in-len'))) : null,
        inA ? sel('Entrance feel', EASINGS, EASING_LABELS, inA.easing || 'ease-out', (v) => upd({ in: { ...inA, easing: v } }), 'a-in-ease') : null));
    wrap.append(h('fieldset', {}, h('legend', {}, 'Goes out'),
        sel('Exit', OUT_PRESETS, OUT_LABELS, outA ? outA.preset : 'none', (v) => upd({ out: v === 'none' ? { preset: 'none' } : { delay_ms: 0, duration_ms: DEFAULT_IN_MS, easing: 'ease-in-out', ...(a.out || {}), preset: v } }), 'a-out'),
        outA ? h('div', { class: 'out-inspector__pair' },
            field('Gone before the end', num('Seconds before the end of the page when the exit has finished', outA.delay_ms || 0, 0, 60000, (ms) => upd({ out: { ...outA, delay_ms: ms } }), 'a-out-delay')),
            field('Takes', num('Exit length in seconds', outA.duration_ms || DEFAULT_IN_MS, 100, 10000, (ms) => upd({ out: { ...outA, duration_ms: ms } }), 'a-out-len'))) : null));
    wrap.append(h('fieldset', {}, h('legend', {}, 'Keeps moving'),
        sel('Movement while it is on the page', LOOP_PRESETS, LOOP_LABELS, loopA ? loopA.preset : 'none', (v) => upd({ loop: v === 'none' ? { preset: 'none' } : { duration_ms: 2000, ...(a.loop || {}), preset: v } }), 'a-loop'),
        loopA ? field('One round takes', num('Seconds for one round of the movement', loopA.duration_ms || 2000, 300, 10000, (ms) => upd({ loop: { ...loopA, duration_ms: ms } }), 'a-loop-len')) : null));
    void D;
    return wrap;
}

// ---- the Animate tab ----------------------------------------------------------------------------------------------

export function mountPanel(root, ctx, playback) {
    const studio = ctx.studio;
    const state = { proposal: null, drag: null };
    const wrap = h('div', { class: 'out-anim' });
    root.appendChild(wrap);
    let destroyed = false;
    let pending = 0;

    const page = () => studio.activePage();
    const schedule = () => { if (pending || state.drag) return; pending = requestAnimationFrame(() => { pending = 0; if (!destroyed && !state.drag) render(); }); };
    const offs = [studio.on('change', schedule), studio.on('selection', schedule), studio.on('page', () => { state.proposal = null; schedule(); }), playback.subscribe(updateTransport)];

    // ---- actions
    function animateDesign() {
        const pg = page();
        const { patches, skipped } = proposeDefaults(pg);
        if (!patches.length) { ctx.toast(skipped ? 'Every element that can move already has an animation.' : 'There is nothing on this page to animate yet.', { kind: 'warn' }); return; }
        const byId = new Map((pg.elements || []).map((e) => [e.id, e]));
        state.proposal = { patches, lines: patches.map((p) => describeProposal(byId.get(p.id), p.animation)) };
        render();
    }
    function applyPatches(patches, message) {
        const per = {};
        for (const p of patches) per[p.id] = { animation: p.animation };
        const res = studio.exec('element.update', { ids: patches.map((p) => p.id), changes: {}, perElement: per });
        if (!res.ok) { ctx.toast(res.error.message, { kind: 'bad' }); return; }
        ctx.toast(message, { kind: 'ok', action: { label: 'Undo', onClick: () => studio.undo() } });
        state.proposal = null;
        playback.stop();
        playback.seek(0);
        playback.play();
    }
    function stagger() {
        const patches = staggerPatches(page(), { stepMs: 150 });
        if (!patches.length) { ctx.toast('Add an entrance to some elements first, then they can follow each other.', { kind: 'warn' }); return; }
        applyPatches(patches, 'Elements now come in one after another, in reading order.');
    }
    function setPage(changes) {
        const res = studio.exec('page.update', { id: page().id, changes }, { key: `page-anim:${Object.keys(changes)[0]}` });
        if (!res.ok) ctx.toast(res.error.message, { kind: 'bad' });
    }

    // ---- transport (updated in place while playing so the DOM is not rebuilt 60 times a second)
    const timeEl = h('span', { class: 'out-transport__time', 'aria-live': 'off' });
    const statusEl = h('span', { class: 'out-status-chip', role: 'status' });
    const scrub = slider({ value: 0, min: 0, max: 5000, step: 50, label: 'Position in the animation', format: (v) => `${fmtSeconds(v)} s`, onInput: (v) => playback.seek(v) });
    const playBtn = h('button', { type: 'button', class: 'lt-btn lt-btn--sm', dataset: { fk: 'play' }, onclick: () => { playback.toggle(); } });
    const stopBtn = h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => playback.stop() }, 'Back to editing');
    const playhead = h('div', { class: 'out-playhead', hidden: true });
    function updateTransport(s) {
        const d = s.duration;
        timeEl.textContent = `${fmtSeconds(s.t)} / ${fmtSeconds(d)} s`;
        scrub.input.max = String(d);
        if (document.activeElement !== scrub.input) scrub.value = Math.round(s.t);
        clear(playBtn);
        playBtn.appendChild(icon(s.playing ? 'pause' : 'play'));
        playBtn.appendChild(document.createTextNode(s.playing ? 'Pause' : 'Play'));
        playBtn.setAttribute('aria-label', s.playing ? 'Pause the animation' : 'Play the animation');
        stopBtn.hidden = !s.active;
        playhead.hidden = !s.active;
        playhead.style.left = `${(s.t / d) * 100}%`;
        const label = s.playing ? 'Playing on the design' : s.active ? `Paused at ${fmtSeconds(s.t)} s` : 'Not playing';
        if (statusEl.textContent !== label) statusEl.textContent = label;
        statusEl.dataset.state = s.playing ? 'playing' : s.active ? 'paused' : 'idle';
    }

    // ---- tracks
    function trackRow(el, D, selected) {
        const tl = elementTimeline(el, D);
        const lane = h('div', { class: 'out-lane', dataset: { id: el.id, selected: selected ? '1' : '0' }, onpointerdown: (e) => { if (e.target === lane) { const r = lane.getBoundingClientRect(); playback.seek(snap(((e.clientX - r.left) / r.width) * D, 50)); studio.select([el.id]); } } });
        const mkSeg = (kind, startMs, endMs, label) => {
            const seg = h('button', { type: 'button', class: 'out-seg', 'data-kind': kind, 'aria-label': label, title: label, style: { left: `${(startMs / D) * 100}%`, width: `${Math.max(0.5, ((endMs - startMs) / D) * 100)}%` } });
            if (kind !== 'loop') {
                seg.addEventListener('pointermove', (e) => { if (e.buttons) return; seg.style.cursor = e.clientX > seg.getBoundingClientRect().right - 9 ? 'ew-resize' : 'grab'; });
                seg.addEventListener('pointerdown', (e) => beginDrag(e, el, kind, seg, lane, D));
                seg.addEventListener('keydown', (e) => keyNudge(e, el, kind, D));
                seg.addEventListener('focus', () => { if (!studio.getState().selection.includes(el.id)) studio.select([el.id]); });
            } else seg.tabIndex = -1;
            return seg;
        };
        if (tl.in) lane.appendChild(mkSeg('in', tl.in.start, tl.in.end, `${elementLabel(el)}, ${IN_LABELS[tl.in.preset] || 'entrance'}, starts at ${secs(tl.in.start)} seconds and lasts ${secs(tl.in.duration)}. Use the arrow keys to move it.`));
        if (tl.loop && tl.loop.end > tl.loop.start) lane.appendChild(mkSeg('loop', tl.loop.start, tl.loop.end, `${elementLabel(el)}, ${LOOP_LABELS[tl.loop.preset]}`));
        if (tl.out) lane.appendChild(mkSeg('out', tl.out.start, tl.out.end, `${elementLabel(el)}, ${OUT_LABELS[tl.out.preset] || 'exit'}, starts at ${secs(tl.out.start)} seconds and lasts ${secs(tl.out.duration)}. Use the arrow keys to move it.`));
        if (!tl.in && !tl.out && !tl.loop) {
            lane.dataset.empty = '1';
            lane.appendChild(h('button', { type: 'button', class: 'out-lane__add', 'aria-label': `Add an entrance to ${elementLabel(el)}`, title: 'Add an entrance', onclick: () => { studio.select([el.id]); setAnimation(studio, el.id, { in: { preset: 'fade', delay_ms: 0, duration_ms: DEFAULT_IN_MS, easing: 'ease-out' } }, `anim-add:${el.id}`); } }, icon('plus')));
        }
        const name = h('button', { type: 'button', class: 'out-lane__name', dataset: { id: el.id, selected: selected ? '1' : '0' }, title: elementLabel(el), 'aria-pressed': selected ? 'true' : 'false', onclick: () => studio.select([el.id]) }, h('span', {}, elementLabel(el)));
        return { name, lane };
    }

    function keyNudge(e, el, kind, D) {
        const step = e.shiftKey ? 250 : 50;
        let dir = 0;
        if (e.key === 'ArrowLeft') dir = -1; else if (e.key === 'ArrowRight') dir = 1; else return;
        e.preventDefault();
        const a = (topLevel(studio, el.id) || el).animation || {};
        const next = moveSegment(a, kind, dir * step, 'move', D);
        if (next) setAnimation(studio, el.id, next, `anim-key:${el.id}`);
    }

    function beginDrag(e, el, kind, seg, lane, D) {
        if (e.button !== 0) return;
        const r = seg.getBoundingClientRect();
        const mode = e.clientX > r.right - 9 ? 'resize' : 'move';
        const laneW = lane.getBoundingClientRect().width;
        const startX = e.clientX;
        const orig = JSON.parse(JSON.stringify((topLevel(studio, el.id) || el).animation || {}));
        seg.setPointerCapture(e.pointerId);
        e.preventDefault();
        studio.select([el.id]);
        state.drag = { id: el.id };
        const move = (ev) => {
            const dms = snap(((ev.clientX - startX) / laneW) * D, 50);
            const next = moveSegment(orig, kind, dms, mode, D);
            if (!next) return;
            setAnimation(studio, el.id, next, `anim-drag:${el.id}`);
            // the tracks are not rebuilt while dragging (that would drop the pointer capture); move the bar itself
            const tl = elementTimeline({ animation: next }, D)[kind];
            if (tl) { seg.style.left = `${(tl.start / D) * 100}%`; seg.style.width = `${Math.max(0.5, ((tl.end - tl.start) / D) * 100)}%`; }
        };
        const up = () => {
            seg.removeEventListener('pointermove', move); seg.removeEventListener('pointerup', up); seg.removeEventListener('pointercancel', up);
            state.drag = null;
            schedule();
        };
        seg.addEventListener('pointermove', move); seg.addEventListener('pointerup', up); seg.addEventListener('pointercancel', up);
    }

    function render() {
        const active = document.activeElement;
        const focusId = active && active.dataset ? active.dataset.fk : null;
        clear(wrap);
        const pg = page();
        const D = pageDuration(pg);
        const els = readingOrder(pg);
        const sel = new Set(studio.getState().selection);
        const selected = els.find((e) => sel.has(e.id)) || null;
        const animatedCount = els.filter((e) => { const t = elementTimeline(e, D); return t.in || t.out || t.loop; }).length;

        // title, the one button, and what the page does now
        wrap.appendChild(h('div', { class: 'out-anim__head' },
            h('button', { type: 'button', class: 'lt-btn', dataset: { fk: 'animate-design' }, onclick: animateDesign }, icon('wand'), 'Animate this design'),
            h('p', { class: 'out-muted' }, 'Adds a gentle entrance to each element. You see it first, then decide. Or drag the bars below to time things yourself.')));
        if (state.proposal) {
            wrap.appendChild(h('div', { class: 'out-propose', role: 'group', 'aria-label': 'Suggested animation' },
                h('strong', {}, `Add animation to ${state.proposal.patches.length} elements?`),
                h('ul', {}, state.proposal.lines.slice(0, 8).map((l) => h('li', {}, l)), state.proposal.lines.length > 8 ? h('li', {}, `and ${state.proposal.lines.length - 8} more`) : null),
                h('div', { class: 'out-row' },
                    h('button', { type: 'button', class: 'lt-btn lt-btn--sm', onclick: () => applyPatches(state.proposal.patches, `Animation added to ${state.proposal.patches.length} elements.`) }, 'Add these'),
                    h('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => { state.proposal = null; render(); } }, 'Not now'))));
        }

        // transport
        const repeat = switchControl({ label: 'Repeat', checked: playback.state.loop, onChange: (on) => playback.setLoop(on), className: 'out-repeat' });
        wrap.appendChild(h('div', { class: 'out-transport' },
            h('div', { class: 'out-transport__buttons' }, playBtn, stopBtn, timeEl),
            scrub,
            h('div', { class: 'out-transport__meta' }, statusEl, repeat)));
        updateTransport(playback.state);

        // timeline: names on the left, lanes on the right, one ruler on top, one playhead over the lanes
        const rows = els.map((el) => trackRow(el, D, sel.has(el.id)));
        const ruler = h('div', { class: 'out-ruler' });
        const lanes = h('div', { class: 'out-tl__lanes' }, ruler, ...rows.map((r) => r.lane), playhead);
        const names = h('div', { class: 'out-tl__names' }, h('div', { class: 'out-tl__corner' }), ...rows.map((r) => r.name));
        const timeline = h('div', { class: 'out-tl', role: 'group', 'aria-label': 'Timeline: one row per element' }, names, lanes);
        wrap.appendChild(h('div', { class: 'out-section' },
            h('div', { class: 'out-section__row' }, h('h3', {}, 'Timeline'), h('span', { class: 'out-muted' }, animatedCount ? `${animatedCount} of ${els.length} elements move` : 'Nothing moves yet')),
            els.length ? timeline : h('p', { class: 'out-muted' }, 'There is nothing on this page to animate yet.'),
            els.length ? h('div', { class: 'out-row' }, h('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: stagger }, 'One after another'), h('span', { class: 'out-muted' }, 'in reading order')) : null));
        // ruler labels need the real lane width, so they are placed once the timeline is in the document
        requestAnimationFrame(() => {
            if (destroyed || !ruler.isConnected) return;
            const w = ruler.getBoundingClientRect().width || 200;
            for (const t of rulerTicks(D, w)) ruler.appendChild(h('span', { style: { left: `${t.at * 100}%` } }, t.label));
        });

        // presets of the selected element, right under the timeline
        if (selected) {
            wrap.appendChild(h('div', { class: 'out-section out-selected' },
                h('div', { class: 'out-section__row' }, h('h3', {}, `How ${elementLabel(selected)} moves`),
                    h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => { playback.stop(); playback.play(); } }, icon('play'), 'Preview')),
                presetFields(studio, selected, playback, { compact: true })));
        } else if (els.length) {
            wrap.appendChild(h('p', { class: 'out-muted out-hint' }, 'Pick a row, or click an element on the design, to choose how it comes in, goes out or keeps moving.'));
        }

        // page settings
        const lenInput = numberField({ value: D / 1000, min: 0.5, max: 60, step: 0.5, decimals: 1, unit: 's', label: 'Page length in seconds', dataFk: 'page-len', onChange: (v) => setPage({ duration_ms: Math.round(clampN(v, 0.5, 60) * 1000) }) });
        const trans = mcSelect({ label: 'When the next page comes in', value: pg.transition || 'none', options: ['none', 'fade', 'slide', 'zoom'].map((k) => ({ value: k, label: TRANSITION_LABELS[k] })), size: 'sm', onChange: (v) => setPage({ transition: v }), dataFk: 'page-trans' });
        wrap.appendChild(h('div', { class: 'out-section' }, h('h3', {}, 'This page'),
            h('div', { class: 'out-field out-field--row' }, h('span', { class: 'lt-label' }, 'Lasts'), lenInput),
            h('div', { class: 'out-field out-field--row' }, h('span', { class: 'lt-label' }, 'Then the next page'), trans),
            h('p', { class: 'out-muted' }, 'Page changes show up when you download a GIF or video of several pages.'),
            contentEnd(pg) > D ? h('p', { class: 'out-note out-note--warn', role: 'status' }, 'Some animations finish after the page ends. Make the page longer or shorten them.') : null));
        if (focusId) { const t = wrap.querySelector(`[data-fk="${focusId}"]`); if (t) t.focus(); }
    }

    render();
    return {
        destroy() { destroyed = true; cancelAnimationFrame(pending); offs.forEach((f) => f && f()); playback.stop(); clear(root); },
    };
}

// ---- inspector ------------------------------------------------------------------------------------------------------

export function mountInspector(root, ctx, playback) {
    const studio = ctx.studio;
    const wrap = h('div', { class: 'out-inspector' });
    root.appendChild(wrap);

    const sel = () => studio.getState().selection[0];

    function render() {
        const active = document.activeElement;
        const fk = active && active.dataset ? active.dataset.fk : null;
        clear(wrap);
        const id = sel();
        const el = id && topLevel(studio, id);
        if (!el) {
            wrap.appendChild(h('p', { class: 'out-muted' }, 'This element sits inside a group. Select the group to animate it as one piece.'));
            return;
        }
        wrap.appendChild(presetFields(studio, el, playback));
        wrap.appendChild(h('div', { class: 'out-row' },
            h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => { playback.stop(); playback.play(); } }, icon('play'), 'Preview'),
            el.type === 'group' ? h('span', { class: 'out-muted' }, 'Moves as one piece.') : null));
        if (fk) { const t = wrap.querySelector(`[data-fk="${fk}"]`); if (t) t.focus(); }
    }
    render();
    const offs = [studio.on('change', () => render()), studio.on('selection', () => render())];
    return { update: render, destroy() { offs.forEach((f) => f && f()); clear(root); } };
}

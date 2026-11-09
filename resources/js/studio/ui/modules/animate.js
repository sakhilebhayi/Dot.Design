// Animation (R-45 output): an Animate tab (play, scrub, page length, element tracks, stagger, "Animate this design") and an
// Animation section in the inspector for the selected element. The evaluator is a pure function of time (output/animate.js);
// playback drives the live canvas and always hands it back exactly as it was.
import '../../../../css/studio-output.css';
import { h, clear, icon, chip } from '../../output/ui-kit.js';
import { IN_PRESETS, OUT_PRESETS, LOOP_PRESETS, EASINGS, elementTimeline, pageDuration, proposeDefaults, staggerPatches, readingOrder, contentEnd, DEFAULT_IN_MS } from '../../output/animate.js';
import { IN_LABELS, OUT_LABELS, LOOP_LABELS, EASING_LABELS, TRANSITION_LABELS, elementLabel, describeProposal } from '../../output/anim-labels.js';
import { createPlayback } from '../../output/playback.js';
import { moveSegment, secs, clampN, snap } from '../../output/anim-edit.js';
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
    const scrub = h('input', { class: 'out-scrub', type: 'range', min: '0', max: '5000', step: '50', value: '0', 'aria-label': 'Position in the animation', oninput: (e) => playback.seek(Number(e.target.value)) });
    const playBtn = h('button', { type: 'button', class: 'lt-btn lt-btn--sm', onclick: () => { playback.toggle(); } });
    const stopBtn = h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => playback.stop() }, 'Back to editing');
    const playhead = h('div', { class: 'out-playhead', hidden: true });
    function updateTransport(s) {
        const d = s.duration;
        timeEl.textContent = `${secs(s.t)} / ${secs(d)} s`;
        scrub.max = String(d);
        if (document.activeElement !== scrub) scrub.value = String(Math.round(s.t));
        clear(playBtn);
        playBtn.appendChild(icon(s.playing ? 'pause' : 'play'));
        playBtn.appendChild(document.createTextNode(s.playing ? 'Pause' : 'Play'));
        playBtn.setAttribute('aria-label', s.playing ? 'Pause the animation' : 'Play the animation');
        stopBtn.hidden = !s.active;
        playhead.hidden = !s.active;
        playhead.style.left = `${(s.t / d) * 100}%`;
    }

    // ---- tracks
    function trackRow(el, pg, D, selected) {
        const tl = elementTimeline(el, D);
        const lane = h('div', { class: 'out-track__lane', dataset: { id: el.id }, onpointerdown: (e) => { if (e.target === lane) { const r = lane.getBoundingClientRect(); playback.seek(snap(((e.clientX - r.left) / r.width) * D, 50)); studio.select([el.id]); } } });
        const mkSeg = (kind, startMs, endMs, label) => {
            const seg = h('button', { type: 'button', class: 'out-seg', 'data-kind': kind, 'aria-label': label, title: label, style: { left: `${(startMs / D) * 100}%`, width: `${Math.max(0.5, ((endMs - startMs) / D) * 100)}%` } });
            if (kind !== 'loop') {
                seg.addEventListener('pointermove', (e) => { if (e.buttons) return; seg.style.cursor = e.clientX > seg.getBoundingClientRect().right - 9 ? 'ew-resize' : 'grab'; });
                seg.addEventListener('pointerdown', (e) => beginDrag(e, el, kind, seg, lane, D));
                seg.addEventListener('keydown', (e) => keyNudge(e, el, kind, D));
            } else seg.tabIndex = -1;
            return seg;
        };
        if (tl.in) lane.appendChild(mkSeg('in', tl.in.start, tl.in.end, `${elementLabel(el)}, ${IN_LABELS[tl.in.preset] || 'entrance'}, starts at ${secs(tl.in.start)} seconds and lasts ${secs(tl.in.duration)}. Use the arrow keys to move it.`));
        if (tl.loop && tl.loop.end > tl.loop.start) lane.appendChild(mkSeg('loop', tl.loop.start, tl.loop.end, `${elementLabel(el)}, ${LOOP_LABELS[tl.loop.preset]}`));
        if (tl.out) lane.appendChild(mkSeg('out', tl.out.start, tl.out.end, `${elementLabel(el)}, ${OUT_LABELS[tl.out.preset] || 'exit'}, starts at ${secs(tl.out.start)} seconds and lasts ${secs(tl.out.duration)}. Use the arrow keys to move it.`));
        if (!tl.in && !tl.out && !tl.loop) {
            lane.appendChild(h('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', style: { position: 'absolute', inset: '0 auto 0 0', minHeight: '0' }, 'aria-label': `Add an entrance to ${elementLabel(el)}`, onclick: () => setAnimation(studio, el.id, { in: { preset: 'fade', delay_ms: 0, duration_ms: DEFAULT_IN_MS, easing: 'ease-out' } }, `anim-add:${el.id}`) }, icon('plus'), 'Add'));
        }
        return h('div', { class: 'out-track', dataset: { selected: selected ? '1' : '0' } },
            h('button', { type: 'button', class: 'out-track__name', title: elementLabel(el), onclick: () => studio.select([el.id]) }, h('span', {}, elementLabel(el))),
            lane);
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
        wrap.appendChild(h('h2', {}, 'Animate'));
        wrap.appendChild(h('div', { class: 'out-stack' },
            h('button', { type: 'button', class: 'lt-btn', dataset: { fk: 'animate-design' }, onclick: animateDesign }, icon('wand'), 'Animate this design'),
            h('p', { class: 'out-muted' }, 'Adds a gentle entrance to each element. You see it first, then decide.')));
        if (state.proposal) {
            wrap.appendChild(h('div', { class: 'out-propose', role: 'group', 'aria-label': 'Suggested animation' },
                h('strong', {}, `Add animation to ${state.proposal.patches.length} elements?`),
                h('ul', {}, state.proposal.lines.slice(0, 8).map((l) => h('li', {}, l)), state.proposal.lines.length > 8 ? h('li', {}, `and ${state.proposal.lines.length - 8} more`) : null),
                h('div', { class: 'out-row' },
                    h('button', { type: 'button', class: 'lt-btn lt-btn--sm', onclick: () => applyPatches(state.proposal.patches, `Animation added to ${state.proposal.patches.length} elements.`) }, 'Add these'),
                    h('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => { state.proposal = null; render(); } }, 'Not now'))));
        }
        // transport
        wrap.appendChild(h('div', { class: 'out-transport' },
            h('div', { class: 'out-transport__buttons' }, playBtn, stopBtn, chip({ label: 'Repeat', pressed: playback.state.loop, onClick: (e) => { playback.setLoop(!playback.state.loop); e.currentTarget.setAttribute('aria-pressed', playback.state.loop ? 'true' : 'false'); }, title: 'Play again when it reaches the end' }), timeEl),
            scrub));
        updateTransport(playback.state);
        // page settings
        const lenInput = h('input', { class: 'out-input', type: 'number', min: '0.5', max: '60', step: '0.5', value: secs(D), 'aria-label': 'Page length in seconds', onchange: (e) => { const v = clampN(Number(e.target.value), 0.5, 60); setPage({ duration_ms: Math.round(v * 1000) }); } });
        const trans = h('select', { class: 'out-select', 'aria-label': 'When the next page comes in', onchange: (e) => setPage({ transition: e.target.value }) }, ['none', 'fade', 'slide', 'zoom'].map((k) => h('option', { value: k, selected: (pg.transition || 'none') === k }, TRANSITION_LABELS[k])));
        wrap.appendChild(h('div', { class: 'out-stack' },
            h('div', { class: 'out-row' }, h('label', { class: 'lt-label', for: 'out-len' }, 'This page lasts'), (lenInput.id = 'out-len', lenInput), h('span', { class: 'out-muted' }, 'seconds')),
            h('div', { class: 'out-row' }, h('label', { class: 'lt-label', for: 'out-trans' }, 'Then the next page'), (trans.id = 'out-trans', trans)),
            h('p', { class: 'out-muted' }, 'Page changes show up when you download a GIF or video of several pages.'),
            contentEnd(pg) > D ? h('p', { class: 'out-note out-note--warn', role: 'status' }, 'Some animations finish after the page ends. Make the page longer or shorten them.') : null));
        // tracks
        const secsN = Math.max(1, Math.round(D / 1000));
        const ruler = h('div', { class: 'out-ruler', style: { '--secs': String(Math.max(1, D / 1000)) } }, Array.from({ length: Math.min(secsN, 12) + 1 }, (_, i) => { const step = Math.max(1, Math.ceil(secsN / 10)); return i % step === 0 || i === secsN ? h('span', { style: { left: `${((i * 1000) / D) * 100}%` } }, `${i}s`) : null; }));
        const sel = new Set(studio.getState().selection);
        const tracks = h('div', { class: 'out-tracks' },
            h('div', { class: 'out-tracks__head' }, h('span', {}), ruler),
            els.map((el) => trackRow(el, pg, D, sel.has(el.id))));
        // the playhead sits over the lanes column
        const laneHost = h('div', { style: { position: 'relative' } }, tracks);
        const body = h('div', { class: 'out-stack' }, laneHost, h('div', { class: 'out-row' }, h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: stagger }, 'Stagger all'), h('span', { class: 'out-muted' }, 'One after another, in reading order')));
        if (ctx.mode === 'simple') wrap.appendChild(h('details', { class: 'out-adv' }, h('summary', {}, 'Fine tune timing', icon('chevron')), body));
        else wrap.appendChild(h('div', { class: 'out-stack' }, h('h3', { style: { margin: 0, fontSize: 'var(--lt-text-md)' } }, 'Timing'), body));
        // playhead overlay aligned to the lane column (7rem + gap)
        const head = h('div', { style: { position: 'absolute', top: '1.25rem', bottom: '0', left: 'calc(7rem + var(--lt-space-2))', right: '0', pointerEvents: 'none' } }, playhead);
        laneHost.appendChild(head);
        updateTransport(playback.state);
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

    function select(label, options, labels, value, onChange, fk) {
        return h('select', { class: 'out-select', 'aria-label': label, dataset: { fk }, onchange: (e) => onChange(e.target.value) }, options.map((k) => h('option', { value: k, selected: value === k }, labels[k])));
    }
    function num(label, ms, min, max, onChange) {
        return h('input', { class: 'out-input', type: 'number', step: '0.1', min: String(min / 1000), max: String(max / 1000), value: secs(ms), 'aria-label': label, onchange: (e) => onChange(Math.round(clampN(Number(e.target.value), min / 1000, max / 1000) * 1000)) });
    }

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
        const a = el.animation || {};
        const D = pageDuration(studio.activePage());
        const upd = (patch) => { const next = { ...(el.animation || {}), ...patch }; setAnimation(studio, id, next); };
        const inA = a.in && a.in.preset !== 'none' ? a.in : null;
        const outA = a.out && a.out.preset !== 'none' ? a.out : null;
        const loopA = a.loop && a.loop.preset !== 'none' ? a.loop : null;

        wrap.appendChild(h('fieldset', {}, h('legend', {}, 'Comes in'),
            select('Entrance', IN_PRESETS, IN_LABELS, inA ? inA.preset : 'none', (v) => upd({ in: v === 'none' ? { preset: 'none' } : { delay_ms: 0, duration_ms: DEFAULT_IN_MS, easing: 'ease-out', ...(a.in || {}), preset: v } }), 'a-in'),
            inA ? h('div', { class: 'out-inspector__pair' },
                h('div', { class: 'lt-field' }, h('span', { class: 'lt-label' }, 'Starts after (s)'), num('Entrance delay in seconds', inA.delay_ms || 0, 0, 60000, (ms) => upd({ in: { ...inA, delay_ms: ms } }))),
                h('div', { class: 'lt-field' }, h('span', { class: 'lt-label' }, 'Takes (s)'), num('Entrance length in seconds', inA.duration_ms || DEFAULT_IN_MS, 100, 10000, (ms) => upd({ in: { ...inA, duration_ms: ms } })))) : null,
            inA ? select('Entrance feel', EASINGS, EASING_LABELS, inA.easing || 'ease-out', (v) => upd({ in: { ...inA, easing: v } }), 'a-in-ease') : null));
        wrap.appendChild(h('fieldset', {}, h('legend', {}, 'Goes out'),
            select('Exit', OUT_PRESETS, OUT_LABELS, outA ? outA.preset : 'none', (v) => upd({ out: v === 'none' ? { preset: 'none' } : { delay_ms: 0, duration_ms: DEFAULT_IN_MS, easing: 'ease-in-out', ...(a.out || {}), preset: v } }), 'a-out'),
            outA ? h('div', { class: 'out-inspector__pair' },
                h('div', { class: 'lt-field' }, h('span', { class: 'lt-label' }, 'Gone before the end (s)'), num('Seconds before the end of the page when the exit has finished', outA.delay_ms || 0, 0, 60000, (ms) => upd({ out: { ...outA, delay_ms: ms } }))),
                h('div', { class: 'lt-field' }, h('span', { class: 'lt-label' }, 'Takes (s)'), num('Exit length in seconds', outA.duration_ms || DEFAULT_IN_MS, 100, 10000, (ms) => upd({ out: { ...outA, duration_ms: ms } })))) : null));
        wrap.appendChild(h('fieldset', {}, h('legend', {}, 'Keeps moving'),
            select('Movement while it is on the page', LOOP_PRESETS, LOOP_LABELS, loopA ? loopA.preset : 'none', (v) => upd({ loop: v === 'none' ? { preset: 'none' } : { duration_ms: 2000, ...(a.loop || {}), preset: v } }), 'a-loop'),
            loopA ? h('div', { class: 'lt-field' }, h('span', { class: 'lt-label' }, 'One round takes (s)'), num('Seconds for one round of the movement', loopA.duration_ms || 2000, 300, 10000, (ms) => upd({ loop: { ...loopA, duration_ms: ms } }))) : null));
        wrap.appendChild(h('div', { class: 'out-row' },
            h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => { playback.stop(); playback.play(); } }, icon('play'), 'Preview'),
            el.type === 'group' ? h('span', { class: 'out-muted' }, 'Moves as one piece.') : null));
        void D;
        if (fk) { const t = wrap.querySelector(`[data-fk="${fk}"]`); if (t) t.focus(); }
    }
    render();
    const offs = [studio.on('change', () => render()), studio.on('selection', () => render())];
    return { update: render, destroy() { offs.forEach((f) => f && f()); clear(root); } };
}

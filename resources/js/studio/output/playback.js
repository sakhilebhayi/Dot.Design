/*
 * Playback controller for the animation preview. It drives the LIVE canvas through createDomPlayer (the editor's own
 * renderer stays untouched) and keeps the editor honest while a preview is on: input is paused, any edit puts the
 * preview back in step, leaving the page or pressing "Back to editing" restores every element exactly.
 * Timing is wall clock (performance.now), so a slow frame skips ahead instead of slowing the animation down.
 * `editor.prefers-reduced-motion` affects the editor's own UI motion only: playing a design is something the user asks for.
 */
import { evaluate, pageDuration } from './animate.js';
import { createDomPlayer } from './animate-apply.js';

export function createPlayback(studio, { raf = (fn) => requestAnimationFrame(fn), now = () => performance.now() } = {}) {
    let player = null;
    let active = false;
    let playing = false;
    let t = 0;
    let loop = true;
    let base = 0;
    let handle = 0;
    const subs = new Set();
    const stats = { frames: 0, sumMs: 0, maxMs: 0, intervals: [], last: 0, over33: 0 };

    const emit = () => { for (const fn of Array.from(subs)) { try { fn(api.state); } catch (e) { /* a subscriber must not stop playback */ } } };
    const svg = () => (studio.root ? studio.root.querySelector('svg.studio-page') : null);
    const page = () => studio.activePage();
    const duration = () => pageDuration(page());

    function activate() {
        if (active) return true;
        const root = svg();
        if (!root) return false;
        player = createDomPlayer(root);
        active = true;
        studio.setInputEnabled(false);
        return true;
    }

    function applyAt(ms) {
        if (!active) return;
        const t0 = now();
        const pg = page();
        player.apply(pg, evaluate(pg, ms));
        const dt = now() - t0;
        stats.frames += 1; stats.sumMs += dt; if (dt > stats.maxMs) stats.maxMs = dt; if (dt > 33) stats.over33 += 1;
    }

    function tick() {
        if (!playing) return;
        const nowMs = now();
        if (stats.last) stats.intervals.push(nowMs - stats.last);
        stats.last = nowMs;
        t = nowMs - base;
        const d = duration();
        if (t >= d) {
            if (loop) { base = nowMs; t = 0; } else { t = d; playing = false; }
        }
        applyAt(t);
        emit();
        if (playing) handle = raf(tick);
    }

    const api = {
        subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
        get state() { return { active, playing, t, duration: duration(), loop }; },
        get stats() {
            const iv = stats.intervals.slice().sort((a, b) => a - b);
            const avg = iv.length ? iv.reduce((s, v) => s + v, 0) / iv.length : 0;
            return { frames: stats.frames, avgWorkMs: stats.frames ? stats.sumMs / stats.frames : 0, maxWorkMs: stats.maxMs, framesOver33ms: stats.over33, avgIntervalMs: avg, p95IntervalMs: iv.length ? iv[Math.floor(iv.length * 0.95)] : 0, fps: avg ? 1000 / avg : 0 };
        },
        resetStats() { stats.frames = 0; stats.sumMs = 0; stats.maxMs = 0; stats.intervals = []; stats.last = 0; stats.over33 = 0; },
        setLoop(v) { loop = !!v; emit(); },
        play() {
            if (!activate()) return false;
            if (t >= duration()) t = 0;
            playing = true;
            base = now() - t;
            stats.last = 0;
            handle = raf(tick);
            emit();
            return true;
        },
        pause() { playing = false; emit(); },
        toggle() { return playing ? (api.pause(), false) : api.play(); },
        seek(ms) {
            if (!activate()) return;
            playing = false;
            t = Math.max(0, Math.min(duration(), ms));
            applyAt(t);
            emit();
        },
        /** Back to editing: every animated element is put back exactly as the renderer drew it. */
        stop() {
            playing = false;
            if (active) { player.restore(page()); active = false; studio.setInputEnabled(true); }
            t = 0;
            emit();
        },
        /** Re-apply at the current time (after an edit changed what the page animates). */
        refresh() { if (active) applyAt(t); },
        destroy() { api.stop(); subs.clear(); },
    };
    void handle;
    return api;
}

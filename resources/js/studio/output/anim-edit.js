// Pure helpers for editing animation timing from the timeline (drag and arrow keys). No DOM.
import { DEFAULT_IN_MS } from './animate.js';

export const secs = (ms) => (ms / 1000).toFixed(1);
export const clampN = (v, a, b) => Math.min(b, Math.max(a, v));
export const snap = (ms, step = 50) => Math.round(ms / step) * step;

/** Pure: the new animation object after moving/resizing the segment of `kind` by dms. Returns null when nothing applies. */
export function moveSegment(anim, kind, dms, mode, D) {
    const a = JSON.parse(JSON.stringify(anim || {}));
    if (kind === 'in' && a.in) {
        if (mode === 'resize') a.in.duration_ms = clampN(snap((a.in.duration_ms || DEFAULT_IN_MS) + dms), 100, 10000);
        else a.in.delay_ms = clampN(snap((a.in.delay_ms || 0) + dms), 0, 60000);
        return a;
    }
    if (kind === 'out' && a.out) {
        const dur = a.out.duration_ms || DEFAULT_IN_MS;
        const hold = a.out.delay_ms || 0;
        if (mode === 'resize') { // the right edge moves: the exit starts where it did and ends later or earlier
            const newDur = clampN(snap(dur + dms), 100, 10000);
            a.out.duration_ms = newDur;
            a.out.delay_ms = clampN(snap(hold - (newDur - dur)), 0, 60000);
        } else a.out.delay_ms = clampN(snap(hold - dms), 0, 60000); // moving right means finishing later, so less time left before the end
        return a;
    }
    return null;
}


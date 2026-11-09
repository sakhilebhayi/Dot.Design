/*
 * SVG path data validator and re-serialiser for shape.kind === 'path'.
 *
 * Only M L H V C S Q T Z (absolute or relative) are allowed: no arcs, no
 * anything else. The parser tokenises strictly; the renderer never emits the
 * author's raw string, only the canonical string rebuilt here, so a hostile
 * `d` can never reach the DOM.
 */

const ARGS = { M: 2, L: 2, H: 1, V: 1, C: 6, S: 4, Q: 4, T: 2, Z: 0 };
const NUMBER = /^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/;
const MAX_ABS = 1e6;
export const MAX_PATH_LENGTH = 20000;

function fmt(n) {
    return String(Math.round(n * 1000) / 1000);
}

export function parsePath(d) {
    if (typeof d !== 'string') return { ok: false, error: 'Path data must be a string.' };
    if (d.length > MAX_PATH_LENGTH) return { ok: false, error: 'Path data is too long.' };
    const s = d.trim();
    if (s === '') return { ok: false, error: 'Path data is empty.' };

    const commands = [];
    let i = 0;
    let current = null; // { cmd, args }
    let need = 0;
    let first = true;

    function flush() {
        if (current && current.args.length !== need) throw new Error('Wrong number of numbers for command ' + current.cmd + '.');
        if (current) commands.push(current);
        current = null;
    }

    try {
        while (i < s.length) {
            const ch = s[i];
            if (ch === ' ' || ch === ',' || ch === '\t' || ch === '\n' || ch === '\r') { i += 1; continue; }
            const upper = ch.toUpperCase();
            if (/[A-Za-z]/.test(ch)) {
                if (!(upper in ARGS)) throw new Error('Command "' + ch + '" is not allowed.');
                flush();
                if (first && upper !== 'M') throw new Error('Path must start with M.');
                first = false;
                current = { cmd: ch, args: [] };
                need = ARGS[upper];
                i += 1;
                if (need === 0) flush();
                continue;
            }
            const m = NUMBER.exec(s.slice(i));
            if (!m) throw new Error('Unexpected character "' + ch + '".');
            if (first) throw new Error('Path must start with M.');
            const n = Number(m[0]);
            if (!Number.isFinite(n) || Math.abs(n) > MAX_ABS) throw new Error('Number out of range.');
            i += m[0].length;
            if (!current) throw new Error('Numbers without a command.');
            if (current.args.length === need) {
                // implicit repeat of the command (M repeats as L)
                const repeat = current.cmd === 'M' ? 'L' : current.cmd === 'm' ? 'l' : current.cmd;
                flush();
                current = { cmd: repeat, args: [] };
            }
            current.args.push(n);
        }
        if (current && current.args.length === 0 && need !== 0) throw new Error('Command ' + current.cmd + ' has no numbers.');
        flush();
    } catch (e) {
        return { ok: false, error: e.message };
    }
    if (!commands.length) return { ok: false, error: 'Path data is empty.' };
    const out = commands.map((c) => (c.args.length ? c.cmd + ' ' + c.args.map(fmt).join(' ') : c.cmd)).join(' ');
    return { ok: true, d: out, commands };
}

export function isValidPath(d) {
    return parsePath(d).ok;
}

/**
 * Scale path data by (sx, sy) so a path shape keeps its look when its box is
 * resized. Horizontal values scale by sx, vertical by sy; relative commands
 * scale the same way (the transform is linear). Returns the canonical string,
 * or the input when it does not parse.
 */
export function scalePath(d, sx, sy) {
    const p = parsePath(d);
    if (!p.ok) return d;
    return p.commands.map((c) => {
        if (!c.args.length) return c.cmd;
        const a = c.args;
        const u = c.cmd.toUpperCase();
        let out;
        if (u === 'H') out = [a[0] * sx];
        else if (u === 'V') out = [a[0] * sy];
        else out = a.map((v, i) => v * (i % 2 === 0 ? sx : sy));
        return c.cmd + ' ' + out.map(fmt).join(' ');
    }).join(' ');
}

/*
 * Animated GIF encoder, from scratch: one global palette by median cut (up to 256 colours),
 * exact-colour nearest mapping with a lookup cache, optional ordered dithering, frame delays in
 * centiseconds, NETSCAPE2.0 loop count, changed-area frames (each frame after the first stores only the
 * rectangle that differs from the previous one), variable-width LZW with clear codes.
 *
 *   encodeGif({width, height, frames: [{data: Uint8ClampedArray RGBA, delayMs}], loop: 0, dither?}) -> Uint8Array
 *   buildPalette(frames, max) -> Uint8Array [r,g,b,...]
 *   lzwEncode(indices, minCodeSize) -> Uint8Array (raw codes, not yet in blocks)
 */
import { ascii, createWriter } from './bytes.js';

// ---- median cut ------------------------------------------------------------

/** Sample pixels of all frames into a histogram of 15-bit colours: {keys, counts}. */
function histogram(frames, maxSamples = 400000) {
    const counts = new Map();
    const totalPx = frames.reduce((s, f) => s + f.data.length / 4, 0);
    const step = Math.max(1, Math.floor(totalPx / maxSamples));
    let seen = 0;
    for (const f of frames) {
        const d = f.data;
        for (let i = 0; i < d.length; i += 4) {
            if (seen % step === 0) {
                const key = ((d[i] >> 3) << 10) | ((d[i + 1] >> 3) << 5) | (d[i + 2] >> 3);
                const c = counts.get(key);
                if (c) { c.n += 1; c.sr += d[i]; c.sg += d[i + 1]; c.sb += d[i + 2]; } else counts.set(key, { n: 1, sr: d[i], sg: d[i + 1], sb: d[i + 2] });
            }
            seen += 1;
        }
    }
    return counts;
}

export function buildPalette(frames, max = 256) {
    const hist = histogram(frames);
    // r, g, b are the cell (5 bit) coordinates used for splitting; sr, sg, sb keep the true sums so colours stay exact
    const entries = Array.from(hist, ([key, c]) => ({ r: (key >> 10) & 31, g: (key >> 5) & 31, b: key & 31, n: c.n, sr: c.sr, sg: c.sg, sb: c.sb }));
    if (entries.length === 0) return new Uint8Array([0, 0, 0, 255, 255, 255]);
    let boxes = [makeBox(entries)];
    while (boxes.length < max) {
        // split the box with the largest population x range, as long as it has more than one colour
        let best = -1;
        let bestScore = 0;
        boxes.forEach((b, i) => { if (b.entries.length > 1) { const s = b.range * Math.sqrt(b.pop); if (s > bestScore) { bestScore = s; best = i; } } });
        if (best < 0) break;
        const [a, b] = splitBox(boxes[best]);
        boxes.splice(best, 1, a, b);
    }
    const out = new Uint8Array(boxes.length * 3);
    boxes.forEach((b, i) => {
        let r = 0; let g = 0; let bl = 0;
        for (const e of b.entries) { r += e.sr; g += e.sg; bl += e.sb; }
        out[i * 3] = Math.round(r / b.pop);
        out[i * 3 + 1] = Math.round(g / b.pop);
        out[i * 3 + 2] = Math.round(bl / b.pop);
    });
    return out;
}

function makeBox(entries) {
    let min = [31, 31, 31];
    let maxv = [0, 0, 0];
    let pop = 0;
    for (const e of entries) {
        const v = [e.r, e.g, e.b];
        for (let c = 0; c < 3; c += 1) { if (v[c] < min[c]) min[c] = v[c]; if (v[c] > maxv[c]) maxv[c] = v[c]; }
        pop += e.n;
    }
    const ranges = [maxv[0] - min[0], maxv[1] - min[1], maxv[2] - min[2]];
    const axis = ranges[0] >= ranges[1] && ranges[0] >= ranges[2] ? 0 : ranges[1] >= ranges[2] ? 1 : 2;
    return { entries, pop, axis, range: ranges[axis] };
}

function splitBox(box) {
    const key = ['r', 'g', 'b'][box.axis];
    const sorted = box.entries.slice().sort((x, y) => x[key] - y[key]);
    let acc = 0;
    let cut = 0;
    for (let i = 0; i < sorted.length - 1; i += 1) { acc += sorted[i].n; cut = i + 1; if (acc >= box.pop / 2) break; }
    cut = Math.max(1, Math.min(sorted.length - 1, cut));
    return [makeBox(sorted.slice(0, cut)), makeBox(sorted.slice(cut))];
}

/** Maps RGB to a palette index through a 15-bit cache. */
export function createMapper(palette) {
    const n = palette.length / 3;
    const exact = new Map();
    function nearest(r, g, b) {
        let best = 0;
        let bd = Infinity;
        for (let i = 0; i < n; i += 1) {
            const dr = palette[i * 3] - r;
            const dg = palette[i * 3 + 1] - g;
            const db = palette[i * 3 + 2] - b;
            const d = dr * dr * 3 + dg * dg * 4 + db * db * 2;
            if (d < bd) { bd = d; best = i; if (d === 0) break; }
        }
        return best;
    }
    return {
        index(r, g, b) {
            const key = (r << 16) | (g << 8) | b;
            let v = exact.get(key);
            if (v === undefined) {
                v = nearest(r, g, b);
                if (exact.size > 200000) exact.clear();
                exact.set(key, v);
            }
            return v;
        },
        nearest,
    };
}

const BAYER = [0, 8, 2, 10, 12, 4, 14, 6, 3, 11, 1, 9, 15, 7, 13, 5];

export function quantiseFrame(data, width, height, mapper, dither = false) {
    const out = new Uint8Array(width * height);
    let p = 0;
    for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1, p += 1) {
            const i = p * 4;
            let r = data[i]; let g = data[i + 1]; let b = data[i + 2];
            const a = data[i + 3];
            if (a < 255) { // composite on white: GIF frames here are opaque
                const k = a / 255;
                r = Math.round(r * k + 255 * (1 - k)); g = Math.round(g * k + 255 * (1 - k)); b = Math.round(b * k + 255 * (1 - k));
            }
            if (dither) {
                const t = (BAYER[(y & 3) * 4 + (x & 3)] - 7.5) * 1.6;
                r = Math.max(0, Math.min(255, r + t)); g = Math.max(0, Math.min(255, g + t)); b = Math.max(0, Math.min(255, b + t));
            }
            out[p] = mapper.index(r | 0, g | 0, b | 0);
        }
    }
    return out;
}

// ---- LZW ---------------------------------------------------------------------

/** Variable-width LZW as GIF wants it. Returns the packed bytes (not split into sub-blocks). */
export function lzwEncode(indices, minCodeSize) {
    const clear = 1 << minCodeSize;
    const eoi = clear + 1;
    const HSIZE = 8209; // prime > 4096*2
    const hashKeys = new Int32Array(HSIZE);
    const hashVals = new Int32Array(HSIZE);
    const out = createWriter(Math.max(256, indices.length >> 2));
    let bitBuf = 0;
    let bitCnt = 0;
    let codeSize = minCodeSize + 1;
    let next = eoi + 1;

    const emit = (code) => {
        bitBuf |= code << bitCnt;
        bitCnt += codeSize;
        while (bitCnt >= 8) { out.u8(bitBuf & 0xff); bitBuf >>>= 8; bitCnt -= 8; }
    };
    const resetTable = () => { hashKeys.fill(-1); next = eoi + 1; codeSize = minCodeSize + 1; };

    resetTable();
    emit(clear);
    if (indices.length === 0) { emit(eoi); if (bitCnt > 0) out.u8(bitBuf & 0xff); return out.result(); }
    let prefix = indices[0];
    for (let i = 1; i < indices.length; i += 1) {
        const c = indices[i];
        const key = (prefix << 8) | c;
        let h = (key * 2654435761 >>> 0) % HSIZE;
        let found = -1;
        while (hashKeys[h] !== -1) {
            if (hashKeys[h] === key) { found = hashVals[h]; break; }
            h += 1; if (h === HSIZE) h = 0;
        }
        if (found >= 0) { prefix = found; continue; }
        emit(prefix);
        if (next < 4096) {
            hashKeys[h] = key; hashVals[h] = next;
            if (next === (1 << codeSize) && codeSize < 12) codeSize += 1;
            next += 1;
        } else {
            emit(clear);
            resetTable();
        }
        prefix = c;
    }
    emit(prefix);
    emit(eoi);
    if (bitCnt > 0) out.u8(bitBuf & 0xff);
    return out.result();
}

function writeSubBlocks(w, bytes) {
    for (let i = 0; i < bytes.length; i += 255) {
        const n = Math.min(255, bytes.length - i);
        w.u8(n);
        w.bytes(bytes.subarray(i, i + n));
    }
    w.u8(0);
}

function changedRect(prev, cur, width, height) {
    let minX = width; let minY = height; let maxX = -1; let maxY = -1;
    for (let y = 0; y < height; y += 1) {
        const row = y * width;
        for (let x = 0; x < width; x += 1) {
            if (prev[row + x] !== cur[row + x]) {
                if (x < minX) minX = x; if (x > maxX) maxX = x;
                if (y < minY) minY = y; maxY = y;
            }
        }
    }
    if (maxX < 0) return null;
    return { x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1 };
}

/**
 * encodeGif: width/height up to 65535. frames[i].delayMs is rounded to centiseconds (min 2 cs, browsers floor anything lower).
 * `onFrame(i, n)` is called after each frame (progress), `shouldCancel()` may throw by returning true.
 */
export function encodeGif({ width, height, frames, loop = 0, dither = false, maxColours = 256, onFrame, shouldCancel }) {
    if (!(width > 0 && height > 0 && width < 65536 && height < 65536)) throw new Error('GIF size is out of range.');
    if (!frames.length) throw new Error('A GIF needs at least one frame.');
    const palette = buildPalette(frames, maxColours);
    const colours = palette.length / 3;
    let bits = 1;
    while ((1 << bits) < colours) bits += 1;
    const tableSize = 1 << bits;
    const mapper = createMapper(palette);
    const w = createWriter(1 << 16);
    w.bytes(ascii('GIF89a'));
    w.u16(width); w.u16(height);
    w.u8(0x80 | (7 << 4) | (bits - 1)); // global colour table, 8 bits colour resolution
    w.u8(0); w.u8(0);
    for (let i = 0; i < tableSize; i += 1) { w.u8(palette[i * 3] || 0); w.u8(palette[i * 3 + 1] || 0); w.u8(palette[i * 3 + 2] || 0); }
    if (frames.length > 1) { // NETSCAPE2.0 looping extension; loop 0 = forever
        w.u8(0x21); w.u8(0xff); w.u8(11); w.bytes(ascii('NETSCAPE2.0')); w.u8(3); w.u8(1); w.u16(loop); w.u8(0);
    }
    const minCode = Math.max(2, bits);
    let prev = null;
    frames.forEach((f, i) => {
        if (shouldCancel && shouldCancel()) throw new Error('cancelled');
        const idx = quantiseFrame(f.data, width, height, mapper, dither);
        let rect = { x: 0, y: 0, w: width, h: height };
        let pixels = idx;
        if (prev) {
            const r = changedRect(prev, idx, width, height) || { x: 0, y: 0, w: 1, h: 1 };
            rect = r;
            if (r.w !== width || r.h !== height) {
                pixels = new Uint8Array(r.w * r.h);
                for (let y = 0; y < r.h; y += 1) pixels.set(idx.subarray((r.y + y) * width + r.x, (r.y + y) * width + r.x + r.w), y * r.w);
            }
        }
        const delay = Math.max(2, Math.round((f.delayMs || 100) / 10));
        w.u8(0x21); w.u8(0xf9); w.u8(4); w.u8(0x04); w.u16(delay); w.u8(0); w.u8(0); // graphic control: disposal 1 (leave in place)
        w.u8(0x2c); w.u16(rect.x); w.u16(rect.y); w.u16(rect.w); w.u16(rect.h); w.u8(0);
        w.u8(minCode);
        writeSubBlocks(w, lzwEncode(pixels, minCode));
        prev = idx;
        if (onFrame) onFrame(i + 1, frames.length);
    });
    w.u8(0x3b);
    return w.result();
}

/** Rough size before encoding: encode the first two frames for real and extrapolate. */
export function estimateGifBytes({ width, height, frames, dither }) {
    const sample = frames.slice(0, Math.min(2, frames.length));
    const first = encodeGif({ width, height, frames: sample.slice(0, 1), dither }).length;
    if (frames.length === 1) return first;
    const two = encodeGif({ width, height, frames: sample, dither }).length;
    const perExtra = Math.max(200, two - first);
    return Math.round(first + perExtra * (frames.length - 1));
}

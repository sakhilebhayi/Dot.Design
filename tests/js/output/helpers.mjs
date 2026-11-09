// Independent readers used to verify the writers (not the writers' own code).

/** Minimal PDF structure parser: header, xref offsets, trailer, page tree, MediaBox, image XObjects. */
export function parsePdf(bytes) {
    const latin = new TextDecoder('latin1').decode(bytes);
    if (!latin.startsWith('%PDF-1.')) throw new Error('missing header');
    if (!latin.trimEnd().endsWith('%%EOF')) throw new Error('missing EOF marker');
    const sx = latin.lastIndexOf('startxref');
    const xrefAt = parseInt(latin.slice(sx + 9).trim(), 10);
    if (latin.slice(xrefAt, xrefAt + 4) !== 'xref') throw new Error('startxref does not point at xref');
    const m = /^xref\n0 (\d+)\n/.exec(latin.slice(xrefAt));
    const size = parseInt(m[1], 10);
    const rows = latin.slice(xrefAt + m[0].length).split('\n').slice(0, size);
    const offsets = [];
    rows.forEach((row, i) => {
        if (row.length !== 19 && row.length !== 20) { if (!/^\d{10} \d{5} [nf] ?$/.test(row)) throw new Error(`bad xref row ${i}`); }
        if (!/^\d{10} \d{5} [nf] ?$/.test(row)) throw new Error(`bad xref row ${i}: ${row}`);
        offsets[i] = row.endsWith('n ') || row.endsWith('n') ? parseInt(row.slice(0, 10), 10) : null;
    });
    const trailer = /trailer\n<<(.*?)>>\nstartxref/s.exec(latin)[1];
    if (parseInt(/\/Size (\d+)/.exec(trailer)[1], 10) !== size) throw new Error('trailer Size differs from xref');
    const objects = {};
    for (let n = 1; n < size; n += 1) {
        const at = offsets[n];
        const head = `${n} 0 obj\n`;
        if (latin.slice(at, at + head.length) !== head) throw new Error(`xref offset of object ${n} is wrong`);
        const end = latin.indexOf('\nendobj\n', at);
        if (end < 0) throw new Error(`object ${n} not closed`);
        objects[n] = { at, body: latin.slice(at + head.length, end), start: at + head.length };
    }
    const ref = (s, key) => { const r = new RegExp(`/${key} (\\d+) 0 R`).exec(s); return r ? parseInt(r[1], 10) : null; };
    const root = ref(trailer, 'Root');
    if (!/\/Type \/Catalog/.test(objects[root].body)) throw new Error('Root is not a catalog');
    const pagesObj = ref(objects[root].body, 'Pages');
    const pagesBody = objects[pagesObj].body;
    const kids = Array.from(/\/Kids \[(.*?)\]/.exec(pagesBody)[1].matchAll(/(\d+) 0 R/g)).map((x) => parseInt(x[1], 10));
    const count = parseInt(/\/Count (\d+)/.exec(pagesBody)[1], 10);
    if (count !== kids.length) throw new Error('Count differs from Kids');
    const pages = kids.map((k) => {
        const b = objects[k].body;
        if (!/\/Type \/Page /.test(b)) throw new Error('kid is not a page');
        const mb = /\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/.exec(b);
        const contents = ref(b, 'Contents');
        const im = /\/Im0 (\d+) 0 R/.exec(b);
        const imgObj = objects[parseInt(im[1], 10)];
        const dims = { w: parseInt(/\/Width (\d+)/.exec(imgObj.body)[1], 10), h: parseInt(/\/Height (\d+)/.exec(imgObj.body)[1], 10) };
        const len = parseInt(/\/Length (\d+)/.exec(imgObj.body)[1], 10);
        // verify stream length against the real bytes between stream and endstream
        const s0 = imgObj.start + imgObj.body.indexOf('stream\n') + 7;
        if (latin.slice(s0 + len, s0 + len + 10) !== '\nendstream') throw new Error('image /Length is wrong');
        const jpeg = bytes.subarray(s0, s0 + len);
        return { widthPt: parseFloat(mb[1]), heightPt: parseFloat(mb[2]), contents: objects[contents].body, image: dims, jpegOk: jpeg[0] === 0xff && jpeg[1] === 0xd8 && jpeg[len - 2] === 0xff && jpeg[len - 1] === 0xd9 };
    });
    const info = ref(trailer, 'Info');
    return { size, pages, info: info ? objects[info].body : null };
}

/** Independent GIF decoder: returns {width, height, loop, frames: [{x,y,w,h,delayCs, rgba (full canvas after composing)}]}. */
export function decodeGif(bytes) {
    const sig = String.fromCharCode(...bytes.subarray(0, 6));
    if (sig !== 'GIF89a' && sig !== 'GIF87a') throw new Error('not a gif');
    let p = 6;
    const u16 = () => { const v = bytes[p] | (bytes[p + 1] << 8); p += 2; return v; };
    const width = u16(); const height = u16();
    const flags = bytes[p++]; p += 2;
    let gct = null;
    if (flags & 0x80) { const n = 1 << ((flags & 7) + 1); gct = bytes.subarray(p, p + n * 3); p += n * 3; }
    let loop = null; let delay = 10;
    const canvas = new Uint8ClampedArray(width * height * 4);
    const frames = [];
    while (p < bytes.length) {
        const b = bytes[p++];
        if (b === 0x3b) break;
        if (b === 0x21) {
            const label = bytes[p++];
            if (label === 0xf9) { p += 1; p += 1; delay = u16(); p += 1; p += 1; continue; }
            if (label === 0xff) {
                const n = bytes[p++]; const id = String.fromCharCode(...bytes.subarray(p, p + n)); p += n;
                while (bytes[p] !== 0) { const l = bytes[p++]; if (id === 'NETSCAPE2.0' && bytes[p] === 1) loop = bytes[p + 1] | (bytes[p + 2] << 8); p += l; }
                p += 1; continue;
            }
            while (bytes[p] !== 0) p += bytes[p] + 1; p += 1; continue;
        }
        if (b !== 0x2c) throw new Error(`unexpected block 0x${b.toString(16)} at ${p - 1}`);
        const x = u16(); const y = u16(); const w = u16(); const h = u16(); const f = bytes[p++];
        let table = gct; if (f & 0x80) { const n = 1 << ((f & 7) + 1); table = bytes.subarray(p, p + n * 3); p += n * 3; }
        const minCode = bytes[p++];
        const data = [];
        while (bytes[p] !== 0) { const l = bytes[p++]; for (let i = 0; i < l; i += 1) data.push(bytes[p + i]); p += l; }
        p += 1;
        const idx = lzwDecode(data, minCode, w * h);
        for (let j = 0; j < h; j += 1) for (let i = 0; i < w; i += 1) {
            const c = idx[j * w + i]; const o = ((y + j) * width + x + i) * 4;
            canvas[o] = table[c * 3]; canvas[o + 1] = table[c * 3 + 1]; canvas[o + 2] = table[c * 3 + 2]; canvas[o + 3] = 255;
        }
        frames.push({ x, y, w, h, delayCs: delay, rgba: canvas.slice() });
    }
    return { width, height, loop, frames };
}

export function lzwDecode(data, minCode, expected) {
    const clear = 1 << minCode; const eoi = clear + 1;
    let size = minCode + 1; let next = eoi + 1;
    let table = []; const reset = () => { table = []; for (let i = 0; i < clear; i += 1) table[i] = [i]; table[clear] = []; table[eoi] = []; size = minCode + 1; next = eoi + 1; };
    reset();
    const out = []; let bitBuf = 0; let bits = 0; let pos = 0; let prev = null;
    while (out.length < expected) {
        while (bits < size && pos < data.length) { bitBuf |= data[pos++] << bits; bits += 8; }
        if (bits < size) break;
        const code = bitBuf & ((1 << size) - 1); bitBuf >>>= size; bits -= size;
        if (code === clear) { reset(); prev = null; continue; }
        if (code === eoi) break;
        let entry;
        if (code < next) entry = table[code];
        else if (code === next && prev) entry = prev.concat(prev[0]);
        else throw new Error(`bad LZW code ${code} (next ${next})`);
        for (const v of entry) out.push(v);
        if (prev && next < 4096) { table[next++] = prev.concat(entry[0]); if (next === (1 << size) && size < 12) size += 1; }
        prev = entry;
    }
    return out;
}

export function pngSize(bytes) {
    const sig = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];
    for (let i = 0; i < 8; i += 1) if (bytes[i] !== sig[i]) throw new Error('not a png');
    const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    return { width: dv.getUint32(16), height: dv.getUint32(20) };
}

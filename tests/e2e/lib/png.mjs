// Minimal PNG encoder/decoder (8-bit RGB/RGBA, non-interlaced) so the specs can build fixtures and read exports back
// without trusting the code under test.
import zlib from 'node:zlib';

const crcTable = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
const crc = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
function chunk(type, data) {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const c = Buffer.alloc(4); c.writeUInt32BE(crc(td));
    return Buffer.concat([len, td, c]);
}

/** pixel(x, y) -> [r, g, b, a?] */
export function encodePng(width, height, pixel) {
    const raw = Buffer.alloc((width * 4 + 1) * height);
    for (let y = 0; y < height; y++) {
        raw[y * (width * 4 + 1)] = 0;
        for (let x = 0; x < width; x++) {
            const [r, g, b, a = 255] = pixel(x, y);
            const o = y * (width * 4 + 1) + 1 + x * 4;
            raw[o] = r; raw[o + 1] = g; raw[o + 2] = b; raw[o + 3] = a;
        }
    }
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 6;
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

/** -> {width, height, get(x, y) -> [r, g, b, a]} */
export function decodePng(buf) {
    if (buf.readUInt32BE(0) !== 0x89504e47) throw new Error('not a PNG');
    let p = 8, w = 0, h = 0, depth = 0, type = 0, interlace = 0; const idat = []; let plte = null, trns = null;
    while (p < buf.length) {
        const len = buf.readUInt32BE(p); const t = buf.toString('ascii', p + 4, p + 8); const d = buf.subarray(p + 8, p + 8 + len);
        if (t === 'IHDR') { w = d.readUInt32BE(0); h = d.readUInt32BE(4); depth = d[8]; type = d[9]; interlace = d[12]; }
        else if (t === 'IDAT') idat.push(d); else if (t === 'PLTE') plte = d; else if (t === 'tRNS') trns = d;
        p += 12 + len;
    }
    if (depth !== 8 || interlace !== 0) throw new Error(`unsupported PNG depth ${depth} interlace ${interlace}`);
    const ch = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 }[type];
    const raw = zlib.inflateSync(Buffer.concat(idat));
    const stride = w * ch; const px = Buffer.alloc(stride * h);
    for (let y = 0; y < h; y++) {
        const f = raw[y * (stride + 1)]; const line = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
        for (let x = 0; x < stride; x++) {
            const a = x >= ch ? px[y * stride + x - ch] : 0; const b = y > 0 ? px[(y - 1) * stride + x] : 0; const c = x >= ch && y > 0 ? px[(y - 1) * stride + x - ch] : 0;
            let v = line[x];
            if (f === 1) v += a; else if (f === 2) v += b; else if (f === 3) v += (a + b) >> 1;
            else if (f === 4) { const pp = a + b - c; const pa = Math.abs(pp - a), pb = Math.abs(pp - b), pc = Math.abs(pp - c); v += pa <= pb && pa <= pc ? a : pb <= pc ? b : c; }
            px[y * stride + x] = v & 255;
        }
    }
    const get = (x, y) => {
        const o = y * stride + x * ch;
        if (type === 6) return [px[o], px[o + 1], px[o + 2], px[o + 3]];
        if (type === 2) return [px[o], px[o + 1], px[o + 2], 255];
        if (type === 0) return [px[o], px[o], px[o], 255];
        if (type === 4) return [px[o], px[o], px[o], px[o + 1]];
        const i = px[o]; return [plte[i * 3], plte[i * 3 + 1], plte[i * 3 + 2], trns && i < trns.length ? trns[i] : 255];
    };
    return { width: w, height: h, get };
}

/** Colour distance (0-441). */
export const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);

// The fixture picture: left half is a strong teal, right half magenta, with a bright yellow square in the middle.
export const PHOTO = { w: 600, h: 400, left: [0, 150, 160], right: [200, 30, 150], mark: [250, 220, 0] };
export const photoPng = () => encodePng(PHOTO.w, PHOTO.h, (x, y) => (Math.abs(x - 300) < 60 && Math.abs(y - 200) < 60 ? PHOTO.mark : x < 300 ? PHOTO.left : PHOTO.right));
// The fixture logo: dark green rounded-ish block with an orange bar (two distinct brand colours).
export const LOGO = { w: 240, h: 90, bg: [20, 90, 60], bar: [240, 120, 20] };
export const logoPng = () => encodePng(LOGO.w, LOGO.h, (x, y) => (y > 60 && y < 80 && x > 20 && x < 220 ? LOGO.bar : LOGO.bg));

/** A noisy PNG (does not compress), about `mb` megabytes. Deterministic. */
export function noisePng(side, seed = 7) {
    let s = seed;
    const rnd = () => { s = (s * 1664525 + 1013904223) >>> 0; return s; };
    return encodePng(side, side, () => { const v = rnd(); return [v & 255, (v >> 8) & 255, (v >> 16) & 255]; });
}

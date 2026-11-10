// Build-time WOFF2 -> TrueType decoder (R-45 pmod, vector PDF). Node only: it uses node:zlib for the Brotli stream, so no
// dependency is added. Implements the WOFF2 spec (W3C Recommendation) including the transformed glyf/loca and hmtx tables.
//
//   woff2ToTtf(bytes, {keep}) -> Uint8Array   a plain sfnt with only the tables a PDF needs (keep = list of tags)
//
// CLI:  node scripts/fonts/woff2-to-ttf.mjs [--check]
// Writes public/fonts/design/<key>/<name>.ttfz (zlib-compressed TrueType, the file the PDF writer embeds as-is) next to every
// .woff2. --check only verifies that every .ttfz is present and up to date.
import { brotliDecompressSync, deflateSync, inflateSync } from 'node:zlib';
import { readdirSync, readFileSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const KNOWN_TAGS = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill'];
export const PDF_TABLES = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'glyf', 'loca', 'cvt ', 'fpgm', 'prep', 'gasp', 'GSUB', 'GPOS', 'GDEF'];

class Reader {
    constructor(buf, pos = 0) { this.b = buf; this.p = pos; }
    u8() { return this.b[this.p++]; }
    u16() { const v = (this.b[this.p] << 8) | this.b[this.p + 1]; this.p += 2; return v; }
    i16() { const v = this.u16(); return v > 0x7fff ? v - 0x10000 : v; }
    u32() { const v = ((this.b[this.p] << 24) | (this.b[this.p + 1] << 16) | (this.b[this.p + 2] << 8) | this.b[this.p + 3]) >>> 0; this.p += 4; return v; }
    bytes(n) { const s = this.b.subarray(this.p, this.p + n); this.p += n; return s; }
    base128() {
        let v = 0;
        for (let i = 0; i < 5; i += 1) {
            const c = this.u8();
            if (i === 0 && c === 0x80) throw new Error('woff2: leading zero in UIntBase128');
            if (v & 0xfe000000) throw new Error('woff2: UIntBase128 overflow');
            v = (v << 7) | (c & 0x7f);
            if ((c & 0x80) === 0) return v >>> 0;
        }
        throw new Error('woff2: UIntBase128 too long');
    }
    u255() {
        const c = this.u8();
        if (c === 253) return this.u16();
        if (c === 255) return this.u8() + 253;
        if (c === 254) return this.u8() + 506;
        return c;
    }
}

const tagOf = (b, p) => String.fromCharCode(b[p], b[p + 1], b[p + 2], b[p + 3]);
const be16 = (v) => [(v >> 8) & 255, v & 255];
const be32 = (v) => [(v >>> 24) & 255, (v >>> 16) & 255, (v >>> 8) & 255, v & 255];

function checksum(bytes) {
    let sum = 0;
    const n = bytes.length;
    for (let i = 0; i < n; i += 4) {
        sum = (sum + (((bytes[i] << 24) | ((bytes[i + 1] || 0) << 16) | ((bytes[i + 2] || 0) << 8) | (bytes[i + 3] || 0)) >>> 0)) >>> 0;
    }
    return sum >>> 0;
}

function withSign(flag, v) { return flag & 1 ? v : -v; }

/** Decode the transformed glyf table. Returns {glyf, loca (Uint8Array), indexFormat, xMins}. */
function reconstructGlyf(data) {
    const r = new Reader(data);
    r.u16(); // reserved / version
    const optionFlags = r.u16();
    const numGlyphs = r.u16();
    let indexFormat = r.u16();
    const sizes = [];
    for (let i = 0; i < 7; i += 1) sizes.push(r.u32());
    const [nContourSize, nPointsSize, flagSize, glyphSize, compositeSize, bboxSize, instrSize] = sizes;
    let pos = 36;
    const nContourS = new Reader(data, pos); pos += nContourSize;
    const nPointsS = new Reader(data, pos); pos += nPointsSize;
    const flagS = new Reader(data, pos); pos += flagSize;
    const glyphS = new Reader(data, pos); pos += glyphSize;
    const compS = new Reader(data, pos); pos += compositeSize;
    const bboxStart = pos; pos += bboxSize;
    const instrS = new Reader(data, pos); pos += instrSize;
    const overlapBits = optionFlags & 1 ? data.subarray(pos, pos + ((numGlyphs + 7) >> 3)) : null;
    const bitmapLen = ((numGlyphs + 31) >> 5) << 2;
    const bboxBits = data.subarray(bboxStart, bboxStart + bitmapLen);
    const bboxS = new Reader(data, bboxStart + bitmapLen);

    const records = [];
    const xMins = new Int16Array(numGlyphs);
    for (let g = 0; g < numGlyphs; g += 1) {
        const nContours = nContourS.i16();
        const hasBox = (bboxBits[g >> 3] >> (7 - (g & 7))) & 1;
        if (nContours === 0) { records.push(new Uint8Array(0)); continue; }
        const out = [];
        if (nContours > 0) {
            const endPts = [];
            let total = 0;
            for (let c = 0; c < nContours; c += 1) { total += nPointsS.u255(); endPts.push(total - 1); }
            const flags = [];
            for (let i = 0; i < total; i += 1) flags.push(flagS.u8());
            const xs = []; const ys = []; const on = [];
            let x = 0; let y = 0;
            for (let i = 0; i < total; i += 1) {
                const f = flags[i] & 0x7f;
                on.push(!(flags[i] >> 7));
                let dx; let dy;
                if (f < 10) { dx = 0; dy = withSign(f, ((f & 14) << 7) + glyphS.u8()); } else if (f < 20) { dx = withSign(f, (((f - 10) & 14) << 7) + glyphS.u8()); dy = 0; } else if (f < 84) {
                    const b0 = f - 20; const b1 = glyphS.u8();
                    dx = withSign(f, 1 + (b0 & 0x30) + (b1 >> 4));
                    dy = withSign(f >> 1, 1 + ((b0 & 0x0c) << 2) + (b1 & 0x0f));
                } else if (f < 120) {
                    const b0 = f - 84; const t0 = glyphS.u8(); const t1 = glyphS.u8();
                    dx = withSign(f, 1 + (Math.floor(b0 / 12) << 8) + t0);
                    dy = withSign(f >> 1, 1 + (((b0 % 12) >> 2) << 8) + t1);
                } else if (f < 124) {
                    const t0 = glyphS.u8(); const t1 = glyphS.u8(); const t2 = glyphS.u8();
                    dx = withSign(f, (t0 << 4) + (t1 >> 4));
                    dy = withSign(f >> 1, ((t1 & 0x0f) << 8) + t2);
                } else {
                    const t0 = glyphS.u8(); const t1 = glyphS.u8(); const t2 = glyphS.u8(); const t3 = glyphS.u8();
                    dx = withSign(f, (t0 << 8) + t1);
                    dy = withSign(f >> 1, (t2 << 8) + t3);
                }
                x += dx; y += dy; xs.push(x); ys.push(y);
            }
            const instrLen = glyphS.u255();
            const instr = instrS.bytes(instrLen);
            let box;
            if (hasBox) box = [bboxS.i16(), bboxS.i16(), bboxS.i16(), bboxS.i16()];
            else box = [Math.min(...xs), Math.min(...ys), Math.max(...xs), Math.max(...ys)];
            xMins[g] = box[0];
            out.push(...be16(nContours & 0xffff), ...box.flatMap((v) => be16(v & 0xffff)));
            for (const e of endPts) out.push(...be16(e));
            out.push(...be16(instrLen), ...instr);
            const fl = []; const xb = []; const yb = [];
            let px = 0; let py = 0;
            for (let i = 0; i < total; i += 1) {
                let f = on[i] ? 1 : 0;
                const dx = xs[i] - px; const dy = ys[i] - py; px = xs[i]; py = ys[i];
                if (dx === 0) f |= 0x10; else if (Math.abs(dx) < 256) { f |= 0x02 | (dx > 0 ? 0x10 : 0); xb.push(Math.abs(dx)); } else xb.push(...be16(dx & 0xffff));
                if (dy === 0) f |= 0x20; else if (Math.abs(dy) < 256) { f |= 0x04 | (dy > 0 ? 0x20 : 0); yb.push(Math.abs(dy)); } else yb.push(...be16(dy & 0xffff));
                fl.push(f);
            }
            if (overlapBits && (overlapBits[g >> 3] >> (7 - (g & 7))) & 1 && fl.length) fl[0] |= 0x40;
            out.push(...fl, ...xb, ...yb);
        } else {
            // composite: copy component records verbatim from the composite stream
            const box = [bboxS.i16(), bboxS.i16(), bboxS.i16(), bboxS.i16()];
            xMins[g] = box[0];
            out.push(...be16(0xffff), ...box.flatMap((v) => be16(v & 0xffff)));
            let more = true; let haveInstr = false;
            while (more) {
                const flags = compS.u16();
                const idx = compS.u16();
                out.push(...be16(flags), ...be16(idx));
                let n = flags & 0x0001 ? 4 : 2;
                if (flags & 0x0008) n += 2; else if (flags & 0x0040) n += 4; else if (flags & 0x0080) n += 8;
                out.push(...compS.bytes(n));
                more = !!(flags & 0x0020);
                if (flags & 0x0100) haveInstr = true;
            }
            if (haveInstr) { const len = glyphS.u255(); out.push(...be16(len), ...instrS.bytes(len)); }
        }
        while (out.length % 4) out.push(0);
        records.push(Uint8Array.from(out));
    }
    let total = 0;
    for (const rec of records) total += rec.length;
    if (total > 0x1fffe) indexFormat = 1;
    const glyf = new Uint8Array(total);
    const locaBytes = [];
    let off = 0;
    for (let g = 0; g < numGlyphs; g += 1) {
        locaBytes.push(indexFormat ? be32(off) : be16(off >> 1));
        glyf.set(records[g], off); off += records[g].length;
    }
    locaBytes.push(indexFormat ? be32(off) : be16(off >> 1));
    return { glyf, loca: Uint8Array.from(locaBytes.flat()), indexFormat, xMins, numGlyphs };
}

function reconstructHmtx(data, { numGlyphs, numHMetrics, xMins }) {
    const r = new Reader(data);
    const flags = r.u8();
    const adv = []; for (let i = 0; i < numHMetrics; i += 1) adv.push(r.u16());
    const lsb = [];
    if (flags & 1) for (let i = 0; i < numHMetrics; i += 1) lsb.push(xMins[i]); else for (let i = 0; i < numHMetrics; i += 1) lsb.push(r.i16());
    const tail = [];
    if (flags & 2) for (let i = numHMetrics; i < numGlyphs; i += 1) tail.push(xMins[i]); else for (let i = numHMetrics; i < numGlyphs; i += 1) tail.push(r.i16());
    const out = [];
    for (let i = 0; i < numHMetrics; i += 1) out.push(...be16(adv[i]), ...be16(lsb[i] & 0xffff));
    for (const v of tail) out.push(...be16(v & 0xffff));
    return Uint8Array.from(out);
}

export function woff2ToTtf(input, { keep = PDF_TABLES } = {}) {
    const buf = input instanceof Uint8Array ? input : new Uint8Array(input);
    const r = new Reader(buf);
    if (tagOf(buf, 0) !== 'wOF2') throw new Error('woff2: bad signature');
    r.p = 4;
    const flavor = r.u32(); r.u32(); const numTables = r.u16(); r.u16(); r.u32();
    const compressedSize = r.u32();
    r.p = 48;
    const dir = [];
    for (let i = 0; i < numTables; i += 1) {
        const flags = r.u8();
        const idx = flags & 0x3f;
        const version = flags >> 6;
        const tag = idx === 63 ? tagOf(r.bytes(4), 0) : KNOWN_TAGS[idx];
        const origLength = r.base128();
        const transformed = tag === 'glyf' || tag === 'loca' ? version === 0 : version !== 0;
        const transformLength = transformed ? r.base128() : origLength;
        dir.push({ tag, origLength, transformed, length: transformed ? transformLength : origLength });
    }
    const stream = brotliDecompressSync(Buffer.from(buf.subarray(r.p, r.p + compressedSize)));
    let pos = 0;
    for (const t of dir) { t.data = stream.subarray(pos, pos + t.length); pos += t.length; }
    const tables = new Map(dir.map((t) => [t.tag, t]));

    const maxp = tables.get('maxp').data;
    const numGlyphs = (maxp[4] << 8) | maxp[5];
    const hhea = tables.get('hhea').data;
    const numHMetrics = (hhea[34] << 8) | hhea[35];
    let glyfInfo = null;
    if (tables.get('glyf') && tables.get('glyf').transformed) {
        glyfInfo = reconstructGlyf(tables.get('glyf').data);
        tables.get('glyf').data = glyfInfo.glyf;
        tables.get('loca').data = glyfInfo.loca;
    }
    if (tables.get('hmtx') && tables.get('hmtx').transformed) {
        if (!glyfInfo) throw new Error('woff2: hmtx transform needs glyf');
        tables.get('hmtx').data = reconstructHmtx(tables.get('hmtx').data, { numGlyphs, numHMetrics, xMins: glyfInfo.xMins });
    }
    if (glyfInfo) { // keep head.indexToLocFormat in step with the rebuilt loca
        const head = Uint8Array.from(tables.get('head').data);
        head[50] = 0; head[51] = glyfInfo.indexFormat;
        tables.get('head').data = head;
    }
    const outTags = [...tables.keys()].filter((t) => keep.includes(t)).sort();
    const nt = outTags.length;
    let pow = 1; let log = 0;
    while (pow * 2 <= nt) { pow *= 2; log += 1; }
    const headerLen = 12 + nt * 16;
    let total = headerLen;
    const placed = outTags.map((tag) => { const data = tables.get(tag).data; const rec = { tag, data, offset: total }; total += (data.length + 3) & ~3; return rec; });
    const out = new Uint8Array(total);
    out.set([...be32(flavor === 0x4f54544f ? 0x4f54544f : 0x00010000), ...be16(nt), ...be16(pow * 16), ...be16(log), ...be16(nt * 16 - pow * 16)], 0);
    let headOffset = -1;
    placed.forEach((t, i) => {
        out.set(t.data, t.offset);
        const sum = checksum(out.subarray(t.offset, t.offset + ((t.data.length + 3) & ~3)));
        out.set([...[...t.tag].map((c) => c.charCodeAt(0)), ...be32(sum), ...be32(t.offset), ...be32(t.data.length)], 12 + i * 16);
        if (t.tag === 'head') headOffset = t.offset;
    });
    if (headOffset >= 0) {
        out.set([0, 0, 0, 0], headOffset + 8);
        const adj = (0xb1b0afba - checksum(out)) >>> 0;
        out.set(be32(adj), headOffset + 8);
    }
    return out;
}

// ---- CLI --------------------------------------------------------------------------------------------------------------
function walk(dir) {
    const out = [];
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        if (statSync(p).isDirectory()) out.push(...walk(p)); else if (name.endsWith('.woff2')) out.push(p);
    }
    return out;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../public/fonts/design');
    const check = process.argv.includes('--check');
    let bytes = 0; let n = 0; let stale = 0;
    for (const file of walk(root).sort()) {
        const target = file.replace(/\.woff2$/, '.ttfz');
        if (check) { if (!existsSync(target) || statSync(target).mtimeMs + 1000 < statSync(file).mtimeMs) { stale += 1; console.error(`stale: ${target}`); } continue; }
        const ttf = woff2ToTtf(readFileSync(file));
        const z = deflateSync(Buffer.from(ttf), { level: 9 });
        if (inflateSync(z).length !== ttf.length) throw new Error('round trip failed');
        writeFileSync(target, z);
        bytes += z.length; n += 1;
    }
    if (check) { if (stale) process.exit(1); console.log('every .ttfz is up to date'); } else console.log(`${n} fonts written, ${(bytes / 1024).toFixed(0)} KB of .ttfz`);
}

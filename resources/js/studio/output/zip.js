/*
 * Store-only ZIP writer (no compression, no dependencies) for batch exports.
 * PNG/JPG/PDF/GIF are already compressed, so storing is the honest, simple choice.
 * Entry names are UTF-8 (general purpose flag bit 11). Duplicate names get " (2)", " (3)".
 */
import { concat, crc32, utf8, createWriter } from './bytes.js';

const MAX_ENTRIES = 65535;
const MAX_BYTES = 0xffffffff; // classic ZIP (no ZIP64): 4 GB total, each entry too

function dosDateTime(date) {
    const d = date instanceof Date ? date : new Date();
    const year = Math.max(1980, d.getFullYear());
    const time = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1);
    const day = ((year - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();
    return { time: time & 0xffff, date: day & 0xffff };
}

export function safeEntryName(name) {
    let n = String(name || 'file').replace(/\\/g, '/').replace(/^\/+/, '');
    n = n.split('/').filter((p) => p !== '' && p !== '.' && p !== '..').join('/');
    return n || 'file';
}

export function dedupeNames(names) {
    const seen = new Map();
    return names.map((raw) => {
        const name = safeEntryName(raw);
        const key = name.toLowerCase();
        const n = (seen.get(key) || 0) + 1;
        seen.set(key, n);
        if (n === 1) return name;
        const dot = name.lastIndexOf('.');
        return dot > 0 ? `${name.slice(0, dot)} (${n})${name.slice(dot)}` : `${name} (${n})`;
    });
}

/**
 * createZip([{name, data: Uint8Array, date?}]) -> Uint8Array
 * Throws a plain Error when the archive would not fit the classic ZIP format.
 */
export function createZip(entries, options = {}) {
    if (entries.length > MAX_ENTRIES) throw new Error('A ZIP can hold at most 65,535 files.');
    const names = dedupeNames(entries.map((e) => e.name));
    const out = createWriter(1 << 16);
    const central = [];
    const stamp = dosDateTime(options.date);
    entries.forEach((entry, i) => {
        const data = entry.data;
        const nameBytes = utf8(names[i]);
        const crc = crc32(data);
        const offset = out.length;
        const when = entry.date ? dosDateTime(entry.date) : stamp;
        if (data.length > MAX_BYTES || offset > MAX_BYTES) throw new Error('This archive is larger than a ZIP file can hold (4 GB). Export fewer pages or a smaller size.');
        out.u32(0x04034b50); // local file header
        out.u16(20); // version needed
        out.u16(0x0800); // flags: UTF-8 names
        out.u16(0); // method: store
        out.u16(when.time);
        out.u16(when.date);
        out.u32(crc);
        out.u32(data.length);
        out.u32(data.length);
        out.u16(nameBytes.length);
        out.u16(0);
        out.bytes(nameBytes);
        out.bytes(data);
        central.push({ nameBytes, crc, size: data.length, offset, when });
    });
    const cdStart = out.length;
    for (const c of central) {
        out.u32(0x02014b50); // central directory header
        out.u16(20); // version made by
        out.u16(20); // version needed
        out.u16(0x0800);
        out.u16(0);
        out.u16(c.when.time);
        out.u16(c.when.date);
        out.u32(c.crc);
        out.u32(c.size);
        out.u32(c.size);
        out.u16(c.nameBytes.length);
        out.u16(0); // extra
        out.u16(0); // comment
        out.u16(0); // disk
        out.u16(0); // internal attrs
        out.u32(0); // external attrs
        out.u32(c.offset);
        out.bytes(c.nameBytes);
    }
    const cdSize = out.length - cdStart;
    out.u32(0x06054b50); // end of central directory
    out.u16(0);
    out.u16(0);
    out.u16(central.length);
    out.u16(central.length);
    out.u32(cdSize);
    out.u32(cdStart);
    out.u16(0);
    return out.result();
}

/** Minimal independent reader used by the tests and by the batch verifier: [{name, data}]. */
export function readZip(bytes) {
    const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    let eocd = -1;
    for (let i = bytes.length - 22; i >= 0; i -= 1) { if (dv.getUint32(i, true) === 0x06054b50) { eocd = i; break; } }
    if (eocd < 0) throw new Error('Not a ZIP file (no end of central directory).');
    const count = dv.getUint16(eocd + 10, true);
    let p = dv.getUint32(eocd + 16, true);
    const out = [];
    const dec = new TextDecoder();
    for (let i = 0; i < count; i += 1) {
        if (dv.getUint32(p, true) !== 0x02014b50) throw new Error('Bad central directory entry.');
        const method = dv.getUint16(p + 10, true);
        const crc = dv.getUint32(p + 16, true);
        const size = dv.getUint32(p + 24, true);
        const nameLen = dv.getUint16(p + 28, true);
        const extraLen = dv.getUint16(p + 30, true);
        const commentLen = dv.getUint16(p + 32, true);
        const local = dv.getUint32(p + 42, true);
        const name = dec.decode(bytes.subarray(p + 46, p + 46 + nameLen));
        if (dv.getUint32(local, true) !== 0x04034b50) throw new Error('Bad local header.');
        const lnLen = dv.getUint16(local + 26, true);
        const leLen = dv.getUint16(local + 28, true);
        const start = local + 30 + lnLen + leLen;
        const data = bytes.subarray(start, start + size);
        if (method !== 0) throw new Error('Only stored entries are supported.');
        if (crc32(data) !== crc) throw new Error(`CRC mismatch for ${name}.`);
        out.push({ name, data: data.slice() });
        p += 46 + nameLen + extraLen + commentLen;
    }
    return out;
}

export { concat };

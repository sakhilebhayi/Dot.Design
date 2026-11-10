/*
 * Byte helpers shared by the file writers (ZIP, PDF, GIF). Pure, no DOM.
 */

let CRC_TABLE = null;

function crcTable() {
    if (CRC_TABLE) return CRC_TABLE;
    CRC_TABLE = new Uint32Array(256);
    for (let n = 0; n < 256; n += 1) {
        let c = n;
        for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        CRC_TABLE[n] = c >>> 0;
    }
    return CRC_TABLE;
}

/** CRC-32 (IEEE) of a byte array. */
export function crc32(bytes, start = 0) {
    const t = crcTable();
    let c = (start ^ 0xffffffff) >>> 0;
    for (let i = 0; i < bytes.length; i += 1) c = t[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
}

export function utf8(str) {
    return new TextEncoder().encode(String(str));
}

/** Latin-1 / ASCII string to bytes (PDF syntax, GIF headers). Characters above 0xFF are replaced by '?'. */
export function ascii(str) {
    const s = String(str);
    const out = new Uint8Array(s.length);
    for (let i = 0; i < s.length; i += 1) {
        const c = s.charCodeAt(i);
        out[i] = c < 256 ? c : 63;
    }
    return out;
}

export function concat(parts) {
    let total = 0;
    for (const p of parts) total += p.length;
    const out = new Uint8Array(total);
    let at = 0;
    for (const p of parts) { out.set(p, at); at += p.length; }
    return out;
}

/** Growable byte writer with little-endian helpers. */
export function createWriter(initial = 1024) {
    let buf = new Uint8Array(initial);
    let len = 0;
    function need(n) {
        if (len + n <= buf.length) return;
        let size = buf.length * 2;
        while (size < len + n) size *= 2;
        const next = new Uint8Array(size);
        next.set(buf.subarray(0, len));
        buf = next;
    }
    return {
        get length() { return len; },
        u8(v) { need(1); buf[len++] = v & 0xff; },
        u16(v) { need(2); buf[len++] = v & 0xff; buf[len++] = (v >>> 8) & 0xff; },
        u32(v) { need(4); buf[len++] = v & 0xff; buf[len++] = (v >>> 8) & 0xff; buf[len++] = (v >>> 16) & 0xff; buf[len++] = (v >>> 24) & 0xff; },
        bytes(b) { need(b.length); buf.set(b, len); len += b.length; },
        text(s) { this.bytes(ascii(s)); },
        result() { return buf.slice(0, len); },
    };
}

export function toBase64(bytes) {
    let bin = '';
    for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(bin);
}

export function fromBase64(b64) {
    const bin = atob(b64);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i += 1) out[i] = bin.charCodeAt(i);
    return out;
}

export function formatBytes(n) {
    if (!Number.isFinite(n) || n < 0) return '';
    if (n < 1024) return `${Math.round(n)} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(n < 10240 ? 1 : 0)} KB`;
    if (n < 1024 * 1024 * 1024) return `${(n / 1048576).toFixed(n < 10485760 ? 1 : 0)} MB`;
    return `${(n / 1073741824).toFixed(1)} GB`;
}

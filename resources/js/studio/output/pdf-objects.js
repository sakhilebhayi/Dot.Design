/*
 * Low-level PDF object writer (PDF 1.5 syntax, classic cross-reference table so every reader and the test parser can
 * follow it). Objects are numbered when allocated and written in number order. Streams can be Flate compressed through an
 * injected `deflate(bytes) -> Promise<Uint8Array>` (zlib format, which is what /FlateDecode expects).
 *
 *   const pdf = createPdf({deflate});
 *   const ref = pdf.alloc();                     // reserve a number (forward references)
 *   pdf.put(ref, '<< /Type /Catalog ... >>');    // a dictionary or any object text
 *   const s = await pdf.stream('/Subtype /Form', bytes, {compress: true});   // returns the reference number
 *   const out = pdf.build({root, info});         // Uint8Array
 */
import { ascii, concat } from './bytes.js';

export const num = (n) => {
    if (!Number.isFinite(n)) return '0';
    const r = Math.round(n * 10000) / 10000;
    return Object.is(r, -0) ? '0' : String(r);
};

/** PDF text string: ASCII in parentheses when plain, otherwise UTF-16BE hex with a byte order mark. */
export function pdfText(s) {
    const t = String(s == null ? '' : s).replace(/[\u0000-\u001f\u007f]/g, ' ');
    if (/^[ -~]*$/.test(t)) return `(${t.replace(/([\\()])/g, '\\$1')})`;
    let hex = 'FEFF';
    for (const ch of t) {
        const cp = ch.codePointAt(0);
        if (cp > 0xffff) { const v = cp - 0x10000; hex += (0xd800 + (v >> 10)).toString(16).padStart(4, '0') + (0xdc00 + (v & 0x3ff)).toString(16).padStart(4, '0'); } else hex += cp.toString(16).padStart(4, '0');
    }
    return `<${hex.toUpperCase()}>`;
}

export const pdfName = (s) => `/${String(s).replace(/[^A-Za-z0-9_.+-]/g, (c) => `#${c.charCodeAt(0).toString(16).padStart(2, '0')}`)}`;

export function pdfDate(d) {
    const p = (n, l = 2) => String(n).padStart(l, '0');
    return `(D:${d.getUTCFullYear()}${p(d.getUTCMonth() + 1)}${p(d.getUTCDate())}${p(d.getUTCHours())}${p(d.getUTCMinutes())}${p(d.getUTCSeconds())}Z)`;
}

export function createPdf({ deflate = null, version = '1.5' } = {}) {
    const objects = [];
    return {
        alloc() { objects.push(null); return objects.length; },
        put(n, body) { objects[n - 1] = Array.isArray(body) ? body : [body]; return n; },
        add(body) { const n = this.alloc(); this.put(n, body); return n; },
        /** A stream object. `dict` is the extra dictionary entries (no << >>). */
        async stream(dict, data, { compress = true } = {}) {
            let bytes = typeof data === 'string' ? ascii(data) : data;
            let filter = '';
            if (compress && deflate && bytes.length > 64) {
                const z = await deflate(bytes);
                if (z.length < bytes.length) { bytes = z; filter = ' /Filter /FlateDecode'; }
            }
            return this.add([`<< ${dict}${filter} /Length ${bytes.length} >>\nstream\n`, bytes, '\nendstream']);
        },
        /** A stream whose bytes are already encoded (e.g. a JPEG for DCTDecode, or a zlib TrueType file). */
        rawStream(dict, bytes) { return this.add([`<< ${dict} /Length ${bytes.length} >>\nstream\n`, bytes, '\nendstream']); },
        get count() { return objects.length; },
        build({ root, info }) {
            const chunks = [];
            const offsets = [];
            let pos = 0;
            const push = (b) => { const bytes = typeof b === 'string' ? ascii(b) : b; chunks.push(bytes); pos += bytes.length; };
            push(concat([ascii(`%PDF-${version}\n%`), new Uint8Array([0xe2, 0xe3, 0xcf, 0xd3]), ascii('\n')]));
            objects.forEach((parts, i) => {
                if (!parts) throw new Error(`PDF object ${i + 1} was reserved but never written.`);
                offsets[i + 1] = pos;
                push(`${i + 1} 0 obj\n`);
                for (const part of parts) push(part);
                push('\nendobj\n');
            });
            const size = objects.length + 1;
            const xref = pos;
            let table = `xref\n0 ${size}\n0000000000 65535 f \n`;
            for (let n = 1; n < size; n += 1) table += `${String(offsets[n]).padStart(10, '0')} 00000 n \n`;
            push(table);
            push(`trailer\n<< /Size ${size} /Root ${root} 0 R${info ? ` /Info ${info} 0 R` : ''} >>\nstartxref\n${xref}\n%%EOF\n`);
            return concat(chunks);
        },
    };
}

/** Content stream text builder. Numbers are rounded to 4 decimals. */
export function createContent() {
    const out = [];
    const api = {
        raw(s) { out.push(s); return api; },
        q() { out.push('q'); return api; },
        Q() { out.push('Q'); return api; },
        cm(m) { out.push(`${m.map(num).join(' ')} cm`); return api; },
        gs(name) { out.push(`${pdfName(name)} gs`); return api; },
        rg(r, g, b) { out.push(`${num(r)} ${num(g)} ${num(b)} rg`); return api; },
        RG(r, g, b) { out.push(`${num(r)} ${num(g)} ${num(b)} RG`); return api; },
        w(v) { out.push(`${num(v)} w`); return api; },
        J(v) { out.push(`${v} J`); return api; },
        j(v) { out.push(`${v} j`); return api; },
        d(arr, phase = 0) { out.push(`[${arr.map(num).join(' ')}] ${num(phase)} d`); return api; },
        m(x, y) { out.push(`${num(x)} ${num(y)} m`); return api; },
        l(x, y) { out.push(`${num(x)} ${num(y)} l`); return api; },
        c(x1, y1, x2, y2, x3, y3) { out.push(`${num(x1)} ${num(y1)} ${num(x2)} ${num(y2)} ${num(x3)} ${num(y3)} c`); return api; },
        h() { out.push('h'); return api; },
        re(x, y, w, h) { out.push(`${num(x)} ${num(y)} ${num(w)} ${num(h)} re`); return api; },
        f(evenOdd = false) { out.push(evenOdd ? 'f*' : 'f'); return api; },
        S() { out.push('S'); return api; },
        B() { out.push('B'); return api; },
        W() { out.push('W n'); return api; },
        n() { out.push('n'); return api; },
        Do(name) { out.push(`${pdfName(name)} Do`); return api; },
        sh(name) { out.push(`${pdfName(name)} sh`); return api; },
        get length() { return out.length; },
        toString() { return out.join('\n'); },
    };
    return api;
}

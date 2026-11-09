// An independent PDF reader for the tests (it shares no code with the writer): cross-reference table, objects, Flate streams,
// page boxes, fonts with their ToUnicode maps, and text extraction from the page content. Enough of PDF to prove that a file
// the editor wrote is structurally valid and that its text is real, selectable text.
import { inflateSync } from 'node:zlib';

export function readPdf(bytes) {
    const latin = Buffer.from(bytes).toString('latin1');
    if (!latin.startsWith('%PDF-1.')) throw new Error('missing header');
    if (!latin.trimEnd().endsWith('%%EOF')) throw new Error('missing EOF marker');
    const sx = latin.lastIndexOf('startxref');
    const xrefAt = parseInt(latin.slice(sx + 9).trim(), 10);
    if (latin.slice(xrefAt, xrefAt + 4) !== 'xref') throw new Error('startxref does not point at xref');
    const m = /^xref\n0 (\d+)\n/.exec(latin.slice(xrefAt));
    const size = parseInt(m[1], 10);
    const rows = latin.slice(xrefAt + m[0].length).split('\n').slice(0, size);
    const trailer = /trailer\n<<(.*?)>>\nstartxref/s.exec(latin)[1];
    if (parseInt(/\/Size (\d+)/.exec(trailer)[1], 10) !== size) throw new Error('trailer Size differs from xref');
    const objects = new Map();
    for (let n = 1; n < size; n += 1) {
        if (!/^\d{10} \d{5} n ?$/.test(rows[n])) throw new Error(`bad xref row ${n}: ${rows[n]}`);
        const at = parseInt(rows[n].slice(0, 10), 10);
        const head = `${n} 0 obj\n`;
        if (latin.slice(at, at + head.length) !== head) throw new Error(`xref offset of object ${n} is wrong`);
        const end = latin.indexOf('\nendobj\n', at);
        if (end < 0) throw new Error(`object ${n} is not closed`);
        const body = latin.slice(at + head.length, end);
        const si = body.indexOf('\nstream\n');
        let dict = body; let data = null;
        if (si >= 0) {
            dict = body.slice(0, si);
            const len = parseInt(/\/Length (\d+)/.exec(dict)[1], 10);
            const from = at + head.length + si + 8;
            if (latin.slice(from + len, from + len + 10) !== '\nendstream') throw new Error(`object ${n}: /Length does not match the stream`);
            data = bytes.subarray(from, from + len);
            if (/\/Filter \/FlateDecode/.test(dict)) data = new Uint8Array(inflateSync(data));
        }
        objects.set(n, { dict, data });
    }
    const refOf = (s, key) => { const r = new RegExp(`/${key} (\\d+) 0 R`).exec(s); return r ? parseInt(r[1], 10) : null; };
    const rootN = refOf(trailer, 'Root');
    if (!/\/Type \/Catalog/.test(objects.get(rootN).dict)) throw new Error('Root is not a catalog');
    const pagesN = refOf(objects.get(rootN).dict, 'Pages');
    const kids = [...objects.get(pagesN).dict.matchAll(/(\d+) 0 R/g)].map((x) => parseInt(x[1], 10)).filter((k) => k !== pagesN);
    const box = (d, key) => { const r = new RegExp(`/${key} \\[([^\\]]*)\\]`).exec(d); return r ? r[1].trim().split(/\s+/).map(Number) : null; };

    function toUnicodeMap(n) {
        const map = new Map();
        const text = Buffer.from(objects.get(n).data).toString('latin1');
        for (const blk of text.matchAll(/beginbfchar([\s\S]*?)endbfchar/g)) {
            for (const mm of blk[1].matchAll(/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/g)) {
                const hex = mm[2]; let s = '';
                for (let i = 0; i < hex.length; i += 4) s += String.fromCharCode(parseInt(hex.slice(i, i + 4), 16));
                map.set(parseInt(mm[1], 16), s);
            }
        }
        return map;
    }

    const pages = kids.map((k) => {
        const page = objects.get(k);
        if (!/\/Type \/Page /.test(page.dict)) throw new Error('kid is not a page');
        const resN = refOf(page.dict, 'Resources');
        const resDict = resN ? objects.get(resN).dict : page.dict;
        const fontBlock = /\/Font <<(.*?)>>/s.exec(resDict);
        const fonts = new Map();
        if (fontBlock) {
            for (const f of fontBlock[1].matchAll(/\/(\w+) (\d+) 0 R/g)) {
                const fd = objects.get(parseInt(f[2], 10)).dict;
                const tu = refOf(fd, 'ToUnicode');
                const desc = refOf(fd, 'DescendantFonts') ? parseInt(/\/DescendantFonts \[(\d+) 0 R\]/.exec(fd)[1], 10) : null;
                fonts.set(f[1], { dict: fd, unicode: tu ? toUnicodeMap(tu) : new Map(), cid: desc ? objects.get(desc).dict : null });
            }
        }
        const content = Buffer.from(objects.get(refOf(page.dict, 'Contents')).data).toString('latin1');
        return { n: k, dict: page.dict, mediaBox: box(page.dict, 'MediaBox'), cropBox: box(page.dict, 'CropBox'), trimBox: box(page.dict, 'TrimBox'), bleedBox: box(page.dict, 'BleedBox'), content, fonts, resources: resDict };
    });

    /** Text of a page as extracted from its content stream: one string per text object (BT..ET), glyph codes mapped through ToUnicode. */
    function pageText(p) {
        const out = [];
        for (const bt of p.content.matchAll(/BT([\s\S]*?)ET/g)) {
            const body = bt[1];
            const font = /\/(\w+)\s+[\d.]+\s+Tf/.exec(body);
            const f = font ? p.fonts.get(font[1]) : null;
            if (!f) continue;
            let s = '';
            for (const arr of body.matchAll(/\[(.*?)\]\s*TJ/gs)) {
                for (const hex of arr[1].matchAll(/<([0-9A-Fa-f]+)>/g)) {
                    for (let i = 0; i < hex[1].length; i += 4) s += f.unicode.get(parseInt(hex[1].slice(i, i + 4), 16)) ?? '�';
                }
            }
            if (s && /\b0 Tr\b/.test(body)) out.push(s);
        }
        return out;
    }

    return { size, objects, pages, pageText, info: refOf(trailer, 'Info') ? objects.get(refOf(trailer, 'Info')).dict : null, trailer };
}

/*
 * Multi-page PDF writer, from scratch. Each page is ONE baseline JPEG drawn over the full page:
 * the pages are pictures, text is not selectable (the UI says so). Valid cross-reference table,
 * MediaBox in points, Info dictionary with the title.
 *
 *   buildPdf({pages: [{jpeg, pxWidth, pxHeight, widthPt, heightPt}], title, producer, date}) -> Uint8Array
 *   pageSizePt(page, unit) -> {widthPt, heightPt}
 */
import { ascii, concat, utf8 } from './bytes.js';

/** Page units are px at 72 dpi for print formats (mm or in): 1 unit = 1 pt. Screen px formats: 1 px = 0.75 pt (96 dpi CSS px). */
export function pageSizePt(page, unit = 'px') {
    const k = unit === 'mm' || unit === 'in' ? 1 : 0.75;
    return { widthPt: round2(page.width * k), heightPt: round2(page.height * k) };
}

const round2 = (n) => Math.round(n * 100) / 100;
const num = (n) => String(round2(n));

/** PDF string object: plain ASCII in parentheses, anything else as UTF-16BE hex with a BOM. */
export function pdfString(s) {
    const t = String(s == null ? '' : s).replace(/[\u0000-\u001f\u007f]/g, ' ');
    if (/^[ -~]*$/.test(t)) return `(${t.replace(/([\\()])/g, '\\$1')})`;
    let hex = 'FEFF';
    for (const ch of t) {
        const cp = ch.codePointAt(0);
        if (cp > 0xffff) {
            const v = cp - 0x10000;
            hex += (0xd800 + (v >> 10)).toString(16).padStart(4, '0') + (0xdc00 + (v & 0x3ff)).toString(16).padStart(4, '0');
        } else hex += cp.toString(16).padStart(4, '0');
    }
    return `<${hex.toUpperCase()}>`;
}

function pdfDate(d) {
    const p = (n, l = 2) => String(n).padStart(l, '0');
    return `(D:${d.getUTCFullYear()}${p(d.getUTCMonth() + 1)}${p(d.getUTCDate())}${p(d.getUTCHours())}${p(d.getUTCMinutes())}${p(d.getUTCSeconds())}Z)`;
}

export function isJpeg(bytes) {
    return bytes.length > 4 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[bytes.length - 2] === 0xff && bytes[bytes.length - 1] === 0xd9;
}

export function buildPdf({ pages, title = 'Design', producer = 'Dot.Design', date = new Date() }) {
    if (!Array.isArray(pages) || pages.length === 0) throw new Error('A PDF needs at least one page.');
    for (const p of pages) {
        if (!(p.jpeg instanceof Uint8Array) || !isJpeg(p.jpeg)) throw new Error('A PDF page picture is not a valid JPEG.');
        if (!(p.widthPt > 0) || !(p.heightPt > 0) || !(p.pxWidth > 0) || !(p.pxHeight > 0)) throw new Error('A PDF page has no size.');
    }
    const chunks = [];
    const offsets = [];
    let pos = 0;
    const push = (b) => { chunks.push(b); pos += b.length; };
    const text = (s) => push(ascii(s));
    const object = (n, bodyParts) => {
        offsets[n] = pos;
        text(`${n} 0 obj\n`);
        for (const part of bodyParts) push(typeof part === 'string' ? ascii(part) : part);
        text('\nendobj\n');
    };

    push(concat([ascii('%PDF-1.4\n%'), new Uint8Array([0xe2, 0xe3, 0xcf, 0xd3]), ascii('\n')]));

    const count = pages.length;
    const pageObj = (i) => 4 + i * 3;
    const kids = pages.map((_, i) => `${pageObj(i)} 0 R`).join(' ');

    object(1, ['<< /Type /Catalog /Pages 2 0 R >>']);
    object(2, [`<< /Type /Pages /Kids [${kids}] /Count ${count} >>`]);
    object(3, [`<< /Title ${pdfString(title)} /Producer ${pdfString(producer)} /Creator ${pdfString(producer)} /CreationDate ${pdfDate(date)} >>`]);

    pages.forEach((p, i) => {
        const pg = pageObj(i);
        const content = ascii(`q ${num(p.widthPt)} 0 0 ${num(p.heightPt)} 0 0 cm /Im0 Do Q`);
        object(pg, [`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${num(p.widthPt)} ${num(p.heightPt)}] /Resources << /XObject << /Im0 ${pg + 2} 0 R >> /ProcSet [/PDF /ImageC] >> /Contents ${pg + 1} 0 R >>`]);
        object(pg + 1, [`<< /Length ${content.length} >>\nstream\n`, content, '\nendstream']);
        object(pg + 2, [`<< /Type /XObject /Subtype /Image /Width ${p.pxWidth} /Height ${p.pxHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${p.jpeg.length} >>\nstream\n`, p.jpeg, '\nendstream']);
    });

    const size = 4 + count * 3;
    const xrefAt = pos;
    let xref = `xref\n0 ${size}\n0000000000 65535 f \n`;
    for (let n = 1; n < size; n += 1) xref += `${String(offsets[n]).padStart(10, '0')} 00000 n \n`;
    text(xref);
    text(`trailer\n<< /Size ${size} /Root 1 0 R /Info 3 0 R >>\nstartxref\n${xrefAt}\n%%EOF\n`);
    return concat(chunks);
}

export { utf8 };

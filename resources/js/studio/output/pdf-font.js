/*
 * TrueType reading and simple shaping for the vector PDF writer. No DOM, no dependencies: it runs in the browser and in node.
 *
 *   loadFont(ttfBytes) -> Font
 *   Font.shape(text, {ligatures}) -> {glyphs: [{gid, adv, text}], width (font units), missing: [char...]}
 *
 * What it does: cmap lookup (formats 4 and 12), advances (hmtx), the GSUB lookups a browser always applies (rvrn, ccmp,
 * liga, clig: single substitution, ligature, extension), and pair kerning from the GPOS `kern` feature (pair adjustment
 * formats 1 and 2, extension). What it deliberately does not do: contextual alternates (calt), mark positioning, complex
 * scripts. Text that needs those is rare in the 18 shipped Latin fonts; the PDF is then a hair different in spacing from
 * the screen, never wrong in content (the ToUnicode map always carries the real characters).
 */

const u16 = (dv, o) => dv.getUint16(o);
const i16 = (dv, o) => dv.getInt16(o);
const u32 = (dv, o) => dv.getUint32(o);
const tag = (b, o) => String.fromCharCode(b[o], b[o + 1], b[o + 2], b[o + 3]);

function coverage(dv, off) {
    const fmt = u16(dv, off);
    const map = new Map();
    if (fmt === 1) {
        const n = u16(dv, off + 2);
        for (let i = 0; i < n; i += 1) map.set(u16(dv, off + 4 + i * 2), i);
    } else if (fmt === 2) {
        const n = u16(dv, off + 2);
        for (let i = 0; i < n; i += 1) {
            const s = u16(dv, off + 4 + i * 6); const e = u16(dv, off + 6 + i * 6); const base = u16(dv, off + 8 + i * 6);
            for (let g = s; g <= e; g += 1) map.set(g, base + (g - s));
        }
    }
    return map;
}

function classDef(dv, off) {
    const fmt = u16(dv, off);
    const map = new Map();
    if (fmt === 1) {
        const start = u16(dv, off + 2); const n = u16(dv, off + 4);
        for (let i = 0; i < n; i += 1) map.set(start + i, u16(dv, off + 6 + i * 2));
    } else if (fmt === 2) {
        const n = u16(dv, off + 2);
        for (let i = 0; i < n; i += 1) {
            const s = u16(dv, off + 4 + i * 6); const e = u16(dv, off + 6 + i * 6); const c = u16(dv, off + 8 + i * 6);
            for (let g = s; g <= e; g += 1) map.set(g, c);
        }
    }
    return map;
}

const bits = (v) => { let n = 0; for (let x = v; x; x >>= 1) n += x & 1; return n; };

/** Lookup indexes for the wanted feature tags, in lookup-list order. */
function lookupsFor(dv, tableOff, wanted) {
    if (tableOff < 0) return { list: 0, indexes: [] };
    const featList = tableOff + u16(dv, tableOff + 6);
    const lookupList = tableOff + u16(dv, tableOff + 8);
    const fc = u16(dv, featList);
    const set = new Set();
    for (let i = 0; i < fc; i += 1) {
        const ft = String.fromCharCode(dv.getUint8(featList + 2 + i * 6), dv.getUint8(featList + 3 + i * 6), dv.getUint8(featList + 4 + i * 6), dv.getUint8(featList + 5 + i * 6));
        if (!wanted.includes(ft)) continue;
        const fo = featList + u16(dv, featList + 6 + i * 6);
        const n = u16(dv, fo + 2);
        for (let k = 0; k < n; k += 1) set.add(u16(dv, fo + 4 + k * 2));
    }
    return { list: lookupList, indexes: [...set].sort((a, b) => a - b) };
}

function subtablesOf(dv, lookupList, index, extType) {
    const lo = lookupList + u16(dv, lookupList + 2 + index * 2);
    let type = u16(dv, lo);
    const n = u16(dv, lo + 4);
    const out = [];
    for (let i = 0; i < n; i += 1) {
        let so = lo + u16(dv, lo + 6 + i * 2);
        let t = type;
        if (type === extType) { t = u16(dv, so + 2); so += u32(dv, so + 4); }
        out.push({ type: t, off: so });
    }
    return out;
}

export function loadFont(input) {
    const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
    const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const numTables = u16(dv, 4);
    const tables = {};
    for (let i = 0; i < numTables; i += 1) tables[tag(bytes, 12 + i * 16)] = { off: u32(dv, 12 + i * 16 + 8), len: u32(dv, 12 + i * 16 + 12) };
    for (const t of ['head', 'hhea', 'hmtx', 'maxp', 'cmap']) if (!tables[t]) throw new Error(`The font has no ${t} table.`);
    const head = tables.head.off;
    const unitsPerEm = u16(dv, head + 18);
    const bbox = [i16(dv, head + 36), i16(dv, head + 38), i16(dv, head + 40), i16(dv, head + 42)];
    const ascent = i16(dv, tables.hhea.off + 4);
    const descent = i16(dv, tables.hhea.off + 6);
    const numHMetrics = u16(dv, tables.hhea.off + 34);
    const numGlyphs = u16(dv, tables.maxp.off + 4);
    let capHeight = Math.round(ascent * 0.7);
    let strikePos = Math.round(unitsPerEm * 0.3); let strikeSize = Math.round(unitsPerEm * 0.06); let weight = 400;
    if (tables['OS/2']) {
        const o = tables['OS/2'].off;
        weight = u16(dv, o + 4);
        strikeSize = i16(dv, o + 26); strikePos = i16(dv, o + 28);
        if (u16(dv, o) >= 2 && tables['OS/2'].len >= 90) capHeight = i16(dv, o + 88);
    }
    let italicAngle = 0; let underlinePos = -Math.round(unitsPerEm * 0.1); let underlineSize = Math.round(unitsPerEm * 0.06); let fixedPitch = false;
    if (tables.post) {
        const o = tables.post.off;
        italicAngle = dv.getInt32(o + 4) / 65536; underlinePos = i16(dv, o + 8); underlineSize = i16(dv, o + 10); fixedPitch = u32(dv, o + 12) !== 0;
    }

    // ---- cmap
    const cm = tables.cmap.off;
    const nSub = u16(dv, cm + 2);
    let best = null;
    for (let i = 0; i < nSub; i += 1) {
        const plat = u16(dv, cm + 4 + i * 8); const enc = u16(dv, cm + 6 + i * 8); const off = cm + u32(dv, cm + 8 + i * 8);
        const f = u16(dv, off);
        const score = f === 12 && ((plat === 3 && enc === 10) || plat === 0) ? 3 : f === 4 && ((plat === 3 && enc === 1) || (plat === 0 && enc <= 4)) ? 2 : 0;
        if (score && (!best || score > best.score)) best = { score, off, f };
    }
    if (!best) throw new Error('The font has no usable Unicode map.');
    const cmapCache = new Map();
    function lookupCmap(cp) {
        const o = best.off;
        if (best.f === 12) {
            const n = u32(dv, o + 12);
            let lo = 0; let hi = n - 1;
            while (lo <= hi) {
                const mid = (lo + hi) >> 1; const g = o + 16 + mid * 12;
                const s = u32(dv, g); const e = u32(dv, g + 4);
                if (cp < s) hi = mid - 1; else if (cp > e) lo = mid + 1; else return (u32(dv, g + 8) + (cp - s)) & 0xffff;
            }
            return 0;
        }
        if (cp > 0xffff) return 0;
        const segX2 = u16(dv, o + 6);
        const endO = o + 14; const startO = endO + segX2 + 2; const deltaO = startO + segX2; const rangeO = deltaO + segX2;
        for (let i = 0; i < segX2; i += 2) {
            const end = u16(dv, endO + i);
            if (cp > end) continue;
            const start = u16(dv, startO + i);
            if (cp < start) return 0;
            const ro = u16(dv, rangeO + i);
            if (ro === 0) return (cp + i16(dv, deltaO + i)) & 0xffff;
            const gi = u16(dv, rangeO + i + ro + (cp - start) * 2);
            return gi === 0 ? 0 : (gi + i16(dv, deltaO + i)) & 0xffff;
        }
        return 0;
    }
    const glyphFor = (cp) => { let g = cmapCache.get(cp); if (g === undefined) { g = lookupCmap(cp); cmapCache.set(cp, g); } return g; };

    const advanceOf = (gid) => {
        const i = Math.min(gid, numHMetrics - 1);
        return u16(dv, tables.hmtx.off + i * 4);
    };

    // ---- GSUB (single + ligature)
    const gsubOff = tables.GSUB ? tables.GSUB.off : -1;
    const gsub = lookupsFor(dv, gsubOff, ['rvrn', 'ccmp', 'liga', 'clig']);
    const gsubLigFeature = lookupsFor(dv, gsubOff, ['liga', 'clig']).indexes;
    const gsubLookups = gsub.indexes.map((index) => ({ index, subs: subtablesOf(dv, gsub.list, index, 7).map((s) => {
        if (s.type === 1) {
            const fmt = u16(dv, s.off); const cov = coverage(dv, s.off + u16(dv, s.off + 2));
            return { kind: 1, fmt, off: s.off, cov };
        }
        if (s.type === 4) return { kind: 4, off: s.off, cov: coverage(dv, s.off + u16(dv, s.off + 2)) };
        return { kind: 0 };
    }) }));

    function applySingle(sub, g) {
        const idx = sub.cov.get(g);
        if (idx === undefined) return g;
        if (sub.fmt === 1) return (g + i16(dv, sub.off + 4)) & 0xffff;
        return u16(dv, sub.off + 6 + idx * 2);
    }
    function tryLigature(sub, glyphs, at) {
        const idx = sub.cov.get(glyphs[at].gid);
        if (idx === undefined) return false;
        const setOff = sub.off + u16(dv, sub.off + 6 + idx * 2);
        const n = u16(dv, setOff);
        for (let i = 0; i < n; i += 1) {
            const lo = setOff + u16(dv, setOff + 2 + i * 2);
            const lig = u16(dv, lo); const cc = u16(dv, lo + 2);
            if (at + cc > glyphs.length) continue;
            let ok = true;
            for (let k = 1; k < cc; k += 1) if (glyphs[at + k].gid !== u16(dv, lo + 4 + (k - 1) * 2)) { ok = false; break; }
            if (!ok) continue;
            glyphs.splice(at, cc, { gid: lig, text: glyphs.slice(at, at + cc).map((x) => x.text).join('') });
            return true;
        }
        return false;
    }

    // ---- GPOS kerning
    const gposOff = tables.GPOS ? tables.GPOS.off : -1;
    const gpos = lookupsFor(dv, gposOff, ['kern']);
    const kernSubs = [];
    for (const index of gpos.indexes) {
        for (const s of subtablesOf(dv, gpos.list, index, 9)) {
            if (s.type !== 2) continue;
            const fmt = u16(dv, s.off); const cov = coverage(dv, s.off + u16(dv, s.off + 2));
            const vf1 = u16(dv, s.off + 4); const vf2 = u16(dv, s.off + 6);
            const sub = { fmt, off: s.off, cov, vf1, vf2, size1: bits(vf1) * 2, size2: bits(vf2) * 2 };
            if (fmt === 2) { sub.cd1 = classDef(dv, s.off + u16(dv, s.off + 8)); sub.cd2 = classDef(dv, s.off + u16(dv, s.off + 10)); sub.n2 = u16(dv, s.off + 14); }
            kernSubs.push(sub);
        }
    }
    const xAdvanceOf = (vf, off) => { if (!(vf & 4)) return 0; let skip = 0; if (vf & 1) skip += 2; if (vf & 2) skip += 2; return i16(dv, off + skip); };
    const kernCache = new Map();
    function kern(a, b) {
        const key = a * 65536 + b;
        if (kernCache.has(key)) return kernCache.get(key);
        let v = 0;
        for (const s of kernSubs) {
            const ci = s.cov.get(a);
            if (ci === undefined) continue;
            if (s.fmt === 1) {
                const po = s.off + u16(dv, s.off + 10 + ci * 2);
                const n = u16(dv, po); const rec = 2 + s.size1 + s.size2;
                let found = false;
                for (let i = 0; i < n; i += 1) { if (u16(dv, po + 2 + i * rec) === b) { v = xAdvanceOf(s.vf1, po + 4 + i * rec); found = true; break; } }
                if (found) break;
            } else {
                const c1 = s.cd1.get(a) || 0; const c2 = s.cd2.get(b) || 0;
                const rec = s.size1 + s.size2;
                const val = xAdvanceOf(s.vf1, s.off + 16 + (c1 * s.n2 + c2) * rec);
                if (val) { v = val; break; }
            }
        }
        kernCache.set(key, v);
        return v;
    }

    function shape(text, { ligatures = true, kerning = true } = {}) {
        const glyphs = [];
        const missing = [];
        for (const ch of String(text)) {
            const cp = ch.codePointAt(0);
            const g = glyphFor(cp);
            if (g === 0 && !/\s/.test(ch) && cp !== 0x200b && cp !== 0xfeff) missing.push(ch);
            glyphs.push({ gid: g, text: ch });
        }
        for (const lk of gsubLookups) {
            const isLig = gsubLigFeature.includes(lk.index);
            if (isLig && !ligatures) continue;
            for (const sub of lk.subs) {
                if (sub.kind === 1) for (const gl of glyphs) gl.gid = applySingle(sub, gl.gid);
                else if (sub.kind === 4) for (let i = 0; i < glyphs.length; i += 1) tryLigature(sub, glyphs, i);
            }
        }
        let width = 0;
        for (let i = 0; i < glyphs.length; i += 1) {
            const g = glyphs[i];
            g.adv = advanceOf(g.gid);
            g.kern = kerning && i + 1 < glyphs.length && kernSubs.length ? kern(g.gid, glyphs[i + 1].gid) : 0;
            width += g.adv + g.kern;
        }
        return { glyphs, width, missing };
    }

    return {
        unitsPerEm, bbox, ascent, descent, capHeight, italicAngle, weight, fixedPitch, numGlyphs,
        underline: { position: underlinePos, size: underlineSize }, strike: { position: strikePos, size: strikeSize },
        glyphFor, advanceOf, shape, bytes,
        /** fsType of OS/2: bit 1 set = restricted licence (we only ship OFL fonts, but a file that forbids embedding is refused). */
        embeddable: !tables['OS/2'] || (u16(dv, tables['OS/2'].off + 8) & 0x0002) === 0,
    };
}

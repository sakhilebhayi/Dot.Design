// Dev-only: turns icons.json / elements.json into renderable "template" documents for the contact sheet, so every
// icon, element, pattern, gradient and palette is drawn by the real engine.
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, DATA_DIR } from './server.mjs';

const read = (n) => JSON.parse(readFileSync(join(ROOT, 'resources/design', n), 'utf8'));
const text = (id, t, x, y, w, size, extra = {}) => ({ id, type: 'text', name: 'Label', role: 'other', x, y, width: w, height: size * 1.4, text: t, font: 'inter', fontSize: size, fontWeight: 400, align: 'center', color: '#444444', lineHeight: 1.2, ...extra });

export function iconSheets(perPage = 60, cols = 10) {
    const icons = read('icons.json');
    delete icons.$comment;
    const keys = Object.keys(icons).sort((a, b) => (icons[a].category + a).localeCompare(icons[b].category + b));
    const cell = 150; const pad = 24;
    const pages = [];
    for (let p = 0; p * perPage < keys.length; p += 1) {
        const slice = keys.slice(p * perPage, (p + 1) * perPage);
        const rows = Math.ceil(slice.length / cols);
        const els = [];
        slice.forEach((k, i) => {
            const cx = pad + (i % cols) * cell; const cy = pad + Math.floor(i / cols) * cell;
            els.push({ id: `i${i}`, type: 'icon', name: k, role: 'decor', x: cx + 45, y: cy + 10, width: 60, height: 60, icon: k, color: '#111111', strokeWidth: 2 });
            els.push(text(`t${i}`, k, cx, cy + 78, cell, 12));
            els.push(text(`c${i}`, icons[k].category, cx, cy + 96, cell, 10, { color: '#888888' }));
        });
        pages.push({ width: pad * 2 + cols * cell, height: pad * 2 + rows * cell, background: { type: 'solid', color: '#ffffff' }, elements: els });
    }
    return [{ slug: 'icons', width: pages[0].width, height: pages[0].height, pages }];
}

export function elementSheets() {
    const lib = read('elements.json');
    const out = [];
    for (const cat of ['dividers', 'corners', 'badges', 'bubbles', 'frames', 'price_tags', 'bursts', 'arrows', 'patterns']) {
        const list = lib[cat];
        const cols = cat === 'patterns' ? 3 : 4;
        const cellW = Math.max(...list.map((e) => e.width)) + 60;
        const cellH = Math.max(...list.map((e) => e.height)) + 90;
        const rows = Math.ceil(list.length / cols);
        const els = [];
        list.forEach((e, i) => {
            const x = 30 + (i % cols) * cellW; const y = 30 + Math.floor(i / cols) * cellH;
            els.push({ ...JSON.parse(JSON.stringify(e.element)), id: `e${i}`, x, y });
            els.push(text(`l${i}`, e.name, x - 20, y + e.height + 12, e.width + 40, 14));
            els.push(text(`k${i}`, (e.tags || []).join(', '), x - 20, y + e.height + 32, e.width + 40, 11, { color: '#888888' }));
        });
        const page = { width: 30 * 2 + cols * cellW, height: 30 * 2 + rows * cellH, background: { type: 'solid', color: cat === 'patterns' ? '#f1efe8' : '#ffffff' }, elements: els };
        out.push({ slug: `elements-${cat}`, width: page.width, height: page.height, pages: [page] });
    }
    // gradient presets
    const g = lib.gradients;
    const els = [];
    g.forEach((p, i) => {
        const x = 30 + (i % 4) * 330; const y = 30 + Math.floor(i / 4) * 290;
        els.push({ id: `g${i}`, type: 'shape', kind: 'rect', name: p.name, role: 'decor', x, y, width: 300, height: 200, fill: p.gradient });
        els.push(text(`gl${i}`, `${p.name}  ${p.gradient.kind} ${p.gradient.angle ?? ''}`, x, y + 208, 300, 14));
        els.push(text(`gu${i}`, p.use, x, y + 228, 300, 11, { color: '#888888', height: 40 }));
    });
    out.push({ slug: 'gradients', width: 1380, height: 30 * 2 + Math.ceil(g.length / 4) * 290, pages: [{ width: 1380, height: 30 * 2 + Math.ceil(g.length / 4) * 290, background: { type: 'solid', color: '#ffffff' }, elements: els }] });
    // palettes
    const pal = lib.palettes;
    const pels = [];
    pal.forEach((p, i) => {
        const x = 30 + (i % 4) * 330; const y = 30 + Math.floor(i / 4) * 170;
        p.colors.forEach((c, k) => pels.push({ id: `p${i}-${k}`, type: 'shape', kind: 'rect', name: c.name, role: 'decor', x: x + k * 60, y, width: 60, height: 80, fill: c.hex }));
        pels.push(text(`pl${i}`, p.name, x, y + 90, 300, 14, { align: 'left' }));
        pels.push(text(`pu${i}`, p.use, x, y + 110, 300, 11, { color: '#888888', align: 'left', height: 36 }));
    });
    const ph = 30 * 2 + Math.ceil(pal.length / 4) * 170;
    out.push({ slug: 'palettes', width: 1380, height: ph, pages: [{ width: 1380, height: ph, background: { type: 'solid', color: '#ffffff' }, elements: pels }] });
    return out;
}

export function writeLibraryData() {
    writeFileSync(join(DATA_DIR, 'icons-sheet.json'), JSON.stringify(iconSheets()));
    writeFileSync(join(DATA_DIR, 'elements-sheet.json'), JSON.stringify(elementSheets()));
}

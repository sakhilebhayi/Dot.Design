#!/usr/bin/env node
// End to end: instantiates templates through the real TemplateInstantiator (full brief and empty brief), exports page
// 1 (and every page with --all-pages) with the product's own output module in headless Chrome, writes the PNGs and
// checks the result:
//   full brief   every text slot holds the brief's value; no sample copy is left; nothing was reported as left
//   empty brief  every text slot keeps its sample copy and is reported as left (the UI can ask for it); pictures stay empty
// Usage: node scripts/dev/check-instantiation.mjs --out <dir> [--kit none|vivid|dull|navy|pastel] [--only a,b] [--scale 0.5] [--all-pages]
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { openHarness } from './qa/browser.mjs';
import { instantiate, loadTemplates } from './qa/load.mjs';

const args = process.argv.slice(2);
const opt = (n, d) => { const i = args.indexOf('--' + n); return i >= 0 ? args[i + 1] : d; };
const out = resolve(opt('out', 'instantiated'));
const kit = opt('kit', 'none');
const scale = Number(opt('scale', '0.5'));
const only = opt('only', 'ig-post-portrait-bold,facebook-post-clean,flyer-a4-corporate,business-card-clean,menu-a4-elegant,event-poster-dark,quotation-clean,logo-monogram-badge,presentation-dark-4,banner-leaderboard').split(',');
mkdirSync(out, { recursive: true });

const BRIEF = { business_name: "Thandi's Bakery", headline: 'Fresh Bread Fridays', tagline: 'Baked at dawn, sold by noon', subheadline: 'Warm loaves, rolls and pastries every Friday', offer: 'Buy two loaves, get a rooibos scone free', price: 'R45', date: 'Friday 6 June', time: '7am to 1pm', venue: 'Neighbourgoods Market, Woodstock', address: '14 Long Street, Cape Town', phone: '021 555 0123', whatsapp: '082 555 0123', email: 'hello@thandisbakery.co.za', website: 'www.thandisbakery.co.za', social_handle: '@thandisbakery', cta: 'Order today', description: 'Small batch sourdough and pastries, baked fresh every morning with local flour and delivered across the city.' };
const walk = (els, out = []) => { for (const e of els) { if (e.type === 'group') walk(e.children, out); else out.push(e); } return out; };

const problems = [];
const h = await openHarness({ templates: false });
try {
    const defs = Object.fromEntries(loadTemplates().map((t) => [t.slug, t]));
    for (const brief of ['full', 'empty']) {
        const runs = instantiate({ kit, brief }).filter((r) => only.some((o) => r.slug === o));
        for (const r of runs) {
            const def = defs[r.slug];
            const texts = r.pages.flatMap((p) => walk(p.elements)).filter((e) => e.type === 'text' && e.bind);
            const sample = def.pages.flatMap((p) => walk(p.elements)).filter((e) => e.type === 'text' && e.bind);
            const leftKeys = new Set(r.left.map((l) => l.slot));
            if (brief === 'full') {
                for (const e of texts) {
                    const want = e.bind === 'initials' ? null : BRIEF[e.bind];
                    if (want && e.text !== want) problems.push(`${r.slug}: ${e.name} [${e.bind}] is "${e.text}", the brief said "${want}"`);
                    if (e.bind === 'initials' && e.text !== 'TB') problems.push(`${r.slug}: monogram letters are "${e.text}", expected TB`);
                }
                for (const l of r.left) if (l.reason === 'no_value') problems.push(`${r.slug}: slot ${l.slot} reported as left although the brief has a value`);
                // sample strings that should have been replaced
                for (const e of texts) for (const s of sample.filter((x) => x.bind === e.bind)) if (s.text === e.text && BRIEF[e.bind] && s.text !== BRIEF[e.bind]) problems.push(`${r.slug}: ${e.name} still shows sample copy "${s.text}"`);
            } else {
                for (const e of sample) if (!leftKeys.has(e.bind)) problems.push(`${r.slug}: empty brief did not report ${e.bind} as left`);
                if (r.filled.length) problems.push(`${r.slug}: empty brief filled ${r.filled.length} slots`);
            }
            const count = args.includes('--all-pages') ? r.pages.length : 1;
            for (let i = 0; i < count; i += 1) {
                const png = await h.chrome.eval(`QA.exportPage(${JSON.stringify(r.pages)}, {width:${r.width},height:${r.height}}, {index:${i}, scale:${scale}})`);
                writeFileSync(join(out, `${r.slug}${count > 1 ? `-p${i + 1}` : ''}.${brief}.${kit}.png`), Buffer.from(png.base64, 'base64'));
            }
            console.log(`${brief.padEnd(5)} ${r.slug}: ${r.filled.length} filled, ${r.left.length} left${r.warnings.length ? ', warnings: ' + r.warnings.join(' | ') : ''}`);
        }
    }
    if (h.chrome.logs.length) console.log(h.chrome.logs.slice(0, 8).join('\n'));
} finally { h.close(); }
console.log(problems.length ? '\nPROBLEMS\n' + problems.join('\n') : '\nno problems');
process.exit(problems.length ? 1 : 0);

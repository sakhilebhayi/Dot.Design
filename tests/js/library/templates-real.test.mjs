/*
 * Every shipped template, checked with the engine's OWN text layout (layoutText) and cached real font metrics.
 *
 * The metrics (database/templates/Support/font-metrics.json) were measured in headless Chrome from the shipped woff2
 * files; scripts/dev/calibrate-metrics.mjs proves they give the same line breaks as the browser. This test asserts, for
 * the sample copy and again for much longer and much shorter copy, for every text element of every page:
 *   - no word is broken mid-word (a word wider than its box)
 *   - the text fits its box (shrink-to-fit is allowed for long copy, never needed for the sample copy)
 *   - nothing leaves the page or the format's safe zone; no two texts collide; text does not straddle a shape edge
 *   - contrast of at least 4.5:1 against what is actually behind it
 *   - the headline is 2x the body text; print and screen text stay above a small-print floor
 * and then instantiates templates through the real PHP TemplateInstantiator with brand kits (vivid and navy) and
 * briefs and checks the recoloured result the same way.
 *
 * Known limits live in waivers.json with their reason; nothing else may fail.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { analyseTemplate, analysePage, collectLeaves, normalise } from '../../../scripts/dev/qa/analyse.mjs';
import { loadFormats, loadTemplates, instantiate } from '../../../scripts/dev/qa/load.mjs';
import { createNodeMeasurer, loadMetrics } from '../../../scripts/dev/qa/metrics.mjs';
import { layoutText } from '../../../resources/js/studio/engine/text-layout.js';
import { normalisePage } from '../../../resources/js/studio/doc/schema.js';

const here = (p) => fileURLToPath(new URL(p, import.meta.url));
const waivers = JSON.parse(readFileSync(here('./waivers.json'), 'utf8'));
const measurer = createNodeMeasurer();
const formats = loadFormats();
const templates = loadTemplates();

const waived = (p, scenarioKind) => waivers.some((w) => w.slug === p.slug && (w.scenario === scenarioKind) && w.code === p.code && (!w.element || (p.element || '').startsWith(w.element)));
const show = (list) => list.map((p) => `${p.slug} [${p.scenario} p${p.page}] ${p.code}: ${p.element || ''} ${p.message}`).join('\n');

test('the cached font metrics reproduce the widths Chrome measured', () => {
    const fixture = JSON.parse(readFileSync(here('./fixtures/chrome-widths.json'), 'utf8'));
    assert.ok(fixture.length >= 100);
    let worst = 0;
    for (const f of fixture) {
        const spec = { font: f.el.font, fontSize: f.el.fontSize, fontWeight: f.el.fontWeight, italic: f.el.italic };
        const width = measurer.measure(f.text, spec) + (f.el.letterSpacing || 0) * f.el.fontSize * Array.from(f.text).length;
        worst = Math.max(worst, Math.abs(width - f.width) / f.width);
    }
    assert.ok(worst < 0.01, `widest relative error ${(worst * 100).toFixed(3)}%`);
});

test('the metrics cover every font the editor ships, every weight and italic', () => {
    const data = loadMetrics();
    const manifest = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(array_map(fn($f) => [$f["key"], $f["weights"], $f["italic_weights"] ?? []], (require "config/design_fonts.php")["fonts"]));'], { cwd: here('../../../') }).toString());
    for (const [key, weights, italics] of manifest) {
        assert.ok(data.fonts[key], `${key} missing`);
        for (const w of weights) assert.ok(data.fonts[key].variants[String(w)], `${key} ${w}`);
        for (const w of italics) assert.ok(data.fonts[key].variants[`${w}i`], `${key} ${w} italic`);
    }
});

test('the library has the 58 templates and every text element can shrink to fit', () => {
    assert.equal(templates.length, 58);
    const walk = (els, out) => { for (const e of els) { if (e.type === 'group') walk(e.children, out); else if (e.type === 'text') out.push(e); } return out; };
    const bad = [];
    for (const t of templates) for (const p of t.pages) for (const e of walk(p.elements, [])) if (e.autoFit !== 'shrink') bad.push(`${t.slug}: ${e.name}`);
    assert.deepEqual(bad, []);
});

for (const tpl of templates) {
    test(`${tpl.slug}: sample, long and short copy are composed properly`, () => {
        const problems = analyseTemplate(tpl, { measurer, formats }).filter((p) => !waived(p, p.scenario));
        assert.equal(problems.length, 0, '\n' + show(problems));
    });
}

test('the two templates that broke mid-word in the real renderer are fixed', () => {
    for (const [slug, word] of [['ig-story-bold', 'LANDED'], ['youtube-thumbnail-bold', 'OVERPAYING']]) {
        const tpl = templates.find((t) => t.slug === slug);
        const page = normalise(tpl)[0];
        const head = collectLeaves(page.elements).map((l) => l.el).find((e) => e.bind === 'headline');
        const L = layoutText(head, measurer);
        assert.equal(L.fontSize, head.fontSize, `${slug} must not need to shrink`);
        assert.ok(L.lines.some((l) => l.text.includes(word)), `${slug} keeps ${word} on one line`);
        assert.equal(L.overflow, false);
    }
});

test('slot text keeps its place: a long value shrinks inside its own box and never moves', () => {
    const tpl = templates.find((t) => t.slug === 'sale-ad-bold');
    const page = normalise(tpl)[0];
    const el = collectLeaves(page.elements).map((l) => l.el).find((e) => e.bind === 'offer');
    const long = layoutText({ ...el, text: 'Buy any two large pizzas and get a free 1.5 litre cold drink' }, measurer);
    assert.ok(long.fontSize < el.fontSize);
    assert.equal(long.overflow, false);
});

// ---- brand kits through the real PHP instantiator ----------------------------------------------------------------

const KITS = (process.env.LIBQA_KITS || 'vivid,navy').split(',');
for (const kit of KITS) {
    for (const brief of ['full', 'long']) {
        test(`instantiated with the ${kit} kit and the ${brief} brief: legible, fitting, no collisions`, () => {
            const all = [];
            for (const t of instantiate({ kit, brief })) {
                t.pages.forEach((raw, i) => {
                    const page = normalisePage({ ...raw, id: `p${i}`, revision: 1 }, { project: { width: t.width, height: t.height } });
                    all.push(...analysePage(page, { slug: t.slug, pageNumber: i + 1, formatKey: t.format_key, safe: formats[t.format_key]?.safe || [0, 0, 0, 0], measurer, scenario: `${kit}/${brief}` }).problems);
                });
            }
            const problems = all.filter((p) => !waived(p, 'recolour'));
            assert.equal(problems.length, 0, '\n' + show(problems));
        });
    }
}

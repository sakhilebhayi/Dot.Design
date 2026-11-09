// "Make this for another place" (R-45 output): pick one or more formats, see each result re-laid out (not stretched), then
// create a NEW design per format. The original is never modified. The layout work is output/resize.js (pure); the server
// re-sanitises every page (POST design/projects/{project}/copies).
import '../../../../css/studio-output.css';
import { h, clear, icon } from '../../output/ui-kit.js';
import { resizeDocumentWithReport, suggestTargets } from '../../output/resize.js';
import { createCanvasMeasurer } from '../../engine/text-layout.js';
import { renderPageToSvgString } from '../../engine/render.js';
import { createIconRegistry } from '../../engine/icons.js';
import { inlineImages, auditSvg } from '../../output/export-svg.js';

const DIALOG = 'resize';
const GROUP_LABELS = { social: 'Social media', print: 'Print', digital: 'Digital business', documents: 'Documents' };

function flattenFormats(config) {
    let list = config && config.formats;
    if (list && !Array.isArray(list)) list = list.formats || Object.values(list);
    if (list && list.length && list[0] && Array.isArray(list[0].formats)) list = list.flatMap((g) => g.formats.map((f) => ({ group: g.key, ...f })));
    return (list || []).filter((f) => f && f.key && (f.px || (f.width && f.height)));
}

export function register(ctx) {
    const canEdit = (c) => !(((c || ctx).config.permissions || {}).create === false);
    ctx.registerDialog(DIALOG, { title: 'Make this for another place', size: 'lg', mount: (el, props, c) => mountResize(el, props, c || ctx) });
    ctx.registerTopbarAction({ id: 'resize', label: 'Other sizes', icon: 'resize', order: 85, modes: ['simple', 'pro'], visible: canEdit, onClick: (c) => (c || ctx).openDialog(DIALOG, {}) });
}

export function mountResize(root, props, ctx) {
    const studio = ctx.studio;
    const config = ctx.config || {};
    const project = config.project || {};
    const formats = flattenFormats(config);
    const current = formats.find((f) => f.key === project.format_key) || null;
    const { suggested, others } = suggestTargets(formats, current || { key: '__none__', family: '', width: project.width, height: project.height, px: { width: project.width, height: project.height } });
    const groups = new Map();
    for (const f of others) { const g = f.group || 'other'; if (!groups.has(g)) groups.set(g, []); groups.get(g).push(f); }
    let destroyed = false;
    const state = { selected: new Set(), results: new Map(), phase: 'idle', created: new Map(), failed: new Map(), computing: false, fontsReady: false };
    const urls = new Set();
    const body = h('div', { class: 'out-resize' });
    root.appendChild(body);

    const assets = (() => { const a = config.assets; const m = new Map(); if (Array.isArray(a)) a.forEach((x) => m.set(Number(x.id), x)); else if (a) Object.values(a).forEach((x) => m.set(Number(x.id), x)); return m; })();
    const assetUrl = (id) => { const a = assets.get(Number(id)); return a ? a.url || a.src || null : null; };
    const assetSize = (id) => { const a = assets.get(Number(id)); return a && a.width ? { width: a.width, height: a.height } : null; };
    const icons = createIconRegistry(config.icons);
    const fonts = studio.fonts;
    const measure = createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight });
    const loadImage = async (url) => { const r = await fetch(url, { credentials: 'same-origin' }); if (!r.ok) throw new Error(`HTTP ${r.status}`); return { bytes: new Uint8Array(await r.arrayBuffer()) }; };

    async function ensureFonts() {
        if (state.fontsReady) return;
        const pages = studio.getState().doc.pages;
        await fonts.ensureLoaded(fonts.usedSpecs(pages));
        measure.clear();
        state.fontsReady = true;
    }

    async function previewOf(page) {
        const specs = fonts.usedSpecs([page]);
        await fonts.ensureLoaded(specs);
        const fontCss = await fonts.embedCss(specs);
        let svg = renderPageToSvgString(page, { measure, fonts, icons, assetUrl, assetSize, fontCss, forExport: false });
        try { svg = await inlineImages(svg, loadImage); } catch (e) { /* a missing picture leaves its frame empty in the preview */ }
        if (auditSvg(svg).length) return null;
        const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
        urls.add(url);
        return url;
    }

    let runToken = 0;
    async function compute() {
        const token = ++runToken;
        state.computing = true;
        render();
        try { await ensureFonts(); } catch (e) { /* fonts that fail fall back to their stack; the layout still runs */ }
        const pages = studio.getState().doc.pages;
        for (const f of formats.filter((x) => state.selected.has(x.key))) {
            if (state.results.has(f.key)) continue;
            try {
                const r = resizeDocumentWithReport(pages, current || { px: { width: pages[0].width, height: pages[0].height } }, f, { measure });
                const previews = await Promise.all(r.pages.slice(0, 1).map(previewOf));
                if (token !== runToken || destroyed) return;
                state.results.set(f.key, { pages: r.pages, report: r.report, preview: previews[0] });
            } catch (e) {
                console.error('[resize]', e);
                state.results.set(f.key, { error: 'This size could not be worked out.' });
            }
        }
        if (token !== runToken || destroyed) return;
        state.computing = false;
        render();
    }

    function toggle(key) {
        if (state.phase === 'creating') return;
        if (state.selected.has(key)) state.selected.delete(key); else state.selected.add(key);
        state.created.clear(); state.failed.clear();
        if (state.phase === 'done') state.phase = 'idle';
        compute();
    }

    function summary(report) {
        const els = report.pages.flatMap((p) => p.elements);
        const has = (a) => els.filter((e) => e.actions.includes(a)).length;
        const parts = [`${els.length} elements placed`];
        if (has('re-wrapped')) parts.push(`${has('re-wrapped')} text re-wrapped`);
        if (has('re-cropped')) parts.push(`${has('re-cropped')} picture${has('re-cropped') === 1 ? '' : 's'} re-cropped`);
        return parts.join(', ');
    }

    async function create() {
        const list = formats.filter((f) => state.selected.has(f.key) && state.results.get(f.key) && state.results.get(f.key).pages);
        if (!list.length) return;
        state.phase = 'creating'; state.created.clear(); state.failed.clear();
        render();
        const endpoint = (config.urls && (config.urls.copies || config.urls.design_copies)) || `/design/projects/${project.id}/copies`;
        for (const f of list) {
            const r = state.results.get(f.key);
            const pages = r.pages.map((p) => ({ name: p.name || null, background: p.background, duration_ms: p.duration_ms, transition: p.transition, elements: p.elements }));
            try {
                const res = await ctx.api.post(endpoint, { format_key: f.key, name: `${project.name || 'Design'} (${f.label})`, pages });
                if (res && res.ok && res.data && res.data.id) state.created.set(f.key, res.data); else state.failed.set(f.key, (res && (res.message || (res.data && res.data.message))) || 'The new design could not be saved.');
            } catch (e) { state.failed.set(f.key, 'The connection failed. Nothing was created for this size.'); }
            render();
            if (destroyed) return;
        }
        state.phase = 'done';
        const ok = state.created.size;
        ctx.toast(ok ? `${ok} new design${ok === 1 ? '' : 's'} created. Your original was not changed.` : 'No new design was created.', { kind: ok ? 'ok' : 'bad' });
        render();
    }

    function formatButton(f) {
        const w = f.px ? f.px.width : f.width; const hh = f.px ? f.px.height : f.height;
        return h('button', { type: 'button', class: 'out-format', 'aria-pressed': state.selected.has(f.key) ? 'true' : 'false', dataset: { fk: `fmt-${f.key}` }, disabled: state.phase === 'creating', onclick: () => toggle(f.key) },
            h('span', { class: 'out-format__frame', style: { '--w': String(w), '--h': String(hh) }, 'aria-hidden': 'true' }),
            h('span', {}, h('span', { class: 'out-format__name' }, f.label), h('span', { class: 'out-format__size' }, f.size_label || `${w} x ${hh}`)));
    }

    function resultCard(f) {
        const r = state.results.get(f.key);
        const done = state.created.get(f.key);
        const failed = state.failed.get(f.key);
        const info = h('div', { class: 'out-result__info' }, h('h4', {}, f.label));
        if (!r) info.appendChild(h('p', { class: 'out-muted' }, 'Working out the layout'));
        else if (r.error) info.appendChild(h('p', { class: 'out-note out-note--bad', role: 'alert' }, r.error));
        else {
            info.appendChild(h('p', { class: 'out-muted' }, summary(r.report)));
            const checks = r.report.pages.flatMap((p, i) => p.elements.filter((e) => e.needsCheck).map((e) => ({ ...e, page: i + 1 })));
            if (checks.length) {
                info.appendChild(h('p', { class: 'out-note out-note--warn', role: 'status' }, icon('warn'), ` ${checks.length} element${checks.length === 1 ? ' needs' : 's need'} your check after you open it.`));
                info.appendChild(h('details', {}, h('summary', {}, 'See which'), h('ul', {}, checks.slice(0, 12).map((e) => h('li', {}, `${e.name}${r.report.pages.length > 1 ? ` (page ${e.page})` : ''}: ${e.reason || 'check the position'}`)), checks.length > 12 ? h('li', {}, `and ${checks.length - 12} more`) : null)));
            } else info.appendChild(h('p', { class: 'out-note out-note--ok', role: 'status' }, 'Nothing looks wrong. Still worth a quick look.'));
        }
        if (done) info.appendChild(h('div', { class: 'out-row' }, h('a', { class: 'lt-btn lt-btn--secondary lt-btn--sm', href: done.url }, icon('external'), 'Open the new design')));
        if (failed) info.appendChild(h('p', { class: 'out-note out-note--bad', role: 'alert' }, `Not created: ${failed}`));
        return h('div', { class: 'out-result' },
            h('div', { class: 'out-result__stage' }, r && r.preview ? h('img', { src: r.preview, alt: `Preview of the ${f.label} version` }) : h('span', { class: 'out-muted' }, r && r.error ? 'No preview' : 'Drawing')),
            info);
    }

    function render() {
        if (destroyed) return;
        const active = document.activeElement;
        const fk = active && active.dataset ? active.dataset.fk : null;
        clear(body);
        body.appendChild(h('p', { class: 'out-note' }, 'Pick one or more sizes. Each one becomes a new design, laid out again for that shape. Your original stays exactly as it is.'));
        const sections = [];
        if (suggested.length) sections.push(h('div', { class: 'out-group' }, h('h3', {}, 'Suggested for this design'), h('div', { class: 'out-formats' }, suggested.map(formatButton))));
        for (const [g, list] of groups) sections.push(h('div', { class: 'out-group' }, h('h3', {}, GROUP_LABELS[g] || 'More'), h('div', { class: 'out-formats' }, list.map(formatButton))));
        if (!formats.length) sections.push(h('p', { class: 'out-note out-note--bad', role: 'alert' }, 'The list of sizes could not be loaded. Reload the editor and try again.'));
        const left = h('div', { class: 'out-groups' }, sections);
        const chosen = formats.filter((f) => state.selected.has(f.key));
        const right = h('div', { class: 'out-side' });
        if (chosen.length) {
            right.appendChild(h('div', { class: 'out-results', 'aria-live': 'polite' }, h('h3', { style: { margin: 0, fontSize: 'var(--lt-text-md)' } }, `Preview (${chosen.length})`), chosen.map(resultCard)));
            const ready = chosen.filter((f) => { const r = state.results.get(f.key); return r && r.pages; }).length;
            right.appendChild(h('div', { class: 'out-actions' },
                h('button', { type: 'button', class: 'lt-btn', disabled: state.phase === 'creating' || ready === 0 || state.computing, 'aria-busy': state.phase === 'creating' ? 'true' : null, onclick: create }, state.phase === 'creating' ? 'Creating' : `Create ${ready || chosen.length} cop${(ready || chosen.length) === 1 ? 'y' : 'ies'}`),
                state.phase === 'done' ? h('span', { class: 'out-muted' }, 'Done. Your original was not changed.') : null));
        } else right.appendChild(h('p', { class: 'out-muted' }, 'Choose a size to see how this design would look there.'));
        body.appendChild(h('div', { class: 'out-resize__cols' }, left, right));
        if (fk) { const t = body.querySelector(`[data-fk="${fk}"]`); if (t) t.focus(); }
    }

    render();
    return {
        destroy() { destroyed = true; runToken += 1; urls.forEach((u) => URL.revokeObjectURL(u)); urls.clear(); clear(root); },
    };
}

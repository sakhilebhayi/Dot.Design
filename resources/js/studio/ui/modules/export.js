// Export dialog (R-45 output). Registers the "Download" topbar action, the shortcut mod+shift+e and the dialog.
// Every file is made by the real renderer in the browser (resources/js/studio/output/*); nothing here pretends:
// "Prepare" runs the export, shows real progress and the real file size, and only then offers the download.
import '../../../../css/studio-output.css';
import { select as mcSelect, switchControl, checkbox, segmented, numberField, slider, radioCards } from './_controls.js';
import { applyPrintBoxes, hasPrintBoxes, COLOUR_NOTE, BLEED_CHOICES } from '../../output/print-boxes.js';
import { fmtSeconds } from '../../output/number-format.js';
import { filesEntryAvailable, openFilesDialog } from './_files-entry.js';
import { h, clear, icon, saveFile, debounce, chip } from '../../output/ui-kit.js';
import { quickActions, tabsFor, SCALE_CHOICES, DPI_CHOICES, FPS_GIF, FPS_VIDEO, EDGE_CHOICES, kindOf, targetsFromState, describeTarget, sizeLabel, sizeWarning, DEFAULT_SETTINGS } from '../../output/presets.js';
import { runExport, planAnimation, estimateJpegBytes, estimateGifSize } from '../../output/export-jobs.js';
import { browserDeps } from '../../output/export-raster.js';
import { prepareSvg, ExportError } from '../../output/export-svg.js';
import { webmSupport } from '../../output/webm.js';
import { animatedElements } from '../../output/animate.js';
import { formatBytes } from '../../output/bytes.js';

const EXPORT_DIALOG = 'export';

export function findFormat(config) {
    const key = config && config.project && config.project.format_key;
    let list = config && config.formats;
    if (list && !Array.isArray(list)) list = list.formats || Object.values(list);
    if (list && list.length && list[0] && Array.isArray(list[0].formats)) list = list.flatMap((g) => g.formats);
    return (list || []).find((f) => f.key === key) || null;
}

function auditScale(settings, page) {
    let s = 1;
    if (settings.format === 'png' || settings.format === 'jpg') s = Math.max(...(settings.sizes || [{ mode: 'scale', scale: 1 }]).map((t) => (t.mode === 'scale' ? t.scale : t.mode === 'dpi' ? t.dpi / 72 : t.width / page.width)));
    else if (settings.format === 'pdf') { const t = settings.pdfTarget || { mode: 'scale', scale: 1 }; s = t.mode === 'dpi' ? t.dpi / 72 : t.scale; } else if (settings.format === 'gif' || settings.format === 'webm') s = (settings.maxEdge || 480) / Math.max(page.width, page.height);
    return Math.min(8, Math.max(0.1, Math.round(s * 100) / 100));
}

/**
 * `ctx.exports.render({format, scope}) -> Promise<Blob>` for the modules that file an export elsewhere (Save to Dot.Files).
 * It runs the same job the Download dialog runs, with the dialog's own defaults for that kind of design (print: 300 dpi PDF,
 * screen: 1x pictures), so what is filed is what would be downloaded. Several files come back as the same ZIP.
 */
export function buildExporter(ctx) {
    return {
        async render({ format = 'png', scope = 'all' } = {}) {
            const studio = ctx.studio;
            const config = ctx.config || {};
            const project = { name: (config.project && config.project.name) || 'design', unit: (config.project && config.project.unit) || 'px', id: config.project && config.project.id };
            const kind = kindOf(project, findFormat(config));
            project.kind = kind;
            const pages = studio.getState().doc.pages;
            const m = /^page-(\d+)$/.exec(String(scope));
            const indexes = m ? [Number(m[1]) - 1].filter((i) => pages[i]) : pages.map((_, i) => i);
            if (!indexes.length) throw new Error('That page does not exist.');
            const res = await runExport(browserDeps(studio), {
                ...DEFAULT_SETTINGS, format, pages: indexes, quality: 0.92, embedFonts: true, sizes: [{ mode: 'scale', scale: 1 }],
                pdfTarget: kind === 'print' ? { mode: 'dpi', dpi: 300 } : { mode: 'scale', scale: 2 },
            }, project);
            const file = res.files[0];
            return new Blob([file.bytes], { type: file.mime });
        },
    };
}

export function register(ctx) {
    if (!ctx.exports) ctx.exports = buildExporter(ctx);
    const open = (c) => (c || ctx).openDialog(EXPORT_DIALOG, {});
    ctx.registerDialog(EXPORT_DIALOG, { title: 'Download', size: 'lg', mount: (el, props, c) => mountExport(el, props, c || ctx) });
    ctx.registerTopbarAction({
        id: 'export', label: 'Download', icon: 'download', order: 80, modes: ['simple', 'pro'],
        visible: (c) => !(((c || ctx).config.permissions || {}).export === false),
        onClick: (c) => open(c),
    });
    ctx.registerShortcut({ keys: 'mod+shift+e', label: 'Download', run: (c) => { if (((ctx.config.permissions || {}).export) !== false) open(c); } });
}

export function mountExport(root, props, ctx) {
    const studio = ctx.studio;
    const config = ctx.config || {};
    const project = { name: (config.project && config.project.name) || 'design', unit: (config.project && config.project.unit) || 'px', id: config.project && config.project.id };
    const format = findFormat(config);
    const kind = kindOf(project, format);
    project.kind = kind;
    const deps = browserDeps(studio);
    const getPages = () => studio.getState().doc.pages;
    const activeIndex = () => Math.max(0, getPages().findIndex((p) => p.id === studio.getState().activePageId));
    const animated = getPages().some((p) => animatedElements(p).length > 0);
    const video = webmSupport();
    const quick = quickActions({ project, format, animated, webmSupported: video.supported });
    const filesAvailable = filesEntryAvailable(ctx);
    let destroyed = false;
    let abort = null;
    let previewUrl = null;
    let resultUrl = null;

    const state = {
        format: kind === 'print' ? 'pdf' : 'png',
        pagesMode: getPages().length > 1 ? 'all' : 'current',
        selected: new Set([activeIndex()]),
        mode: kind === 'print' ? 'dpi' : 'scale',
        scales: new Set([1]), dpis: new Set([300]), customWidth: '', useWidth: false,
        pdfDpi: 300, pdfScale: 2,
        quality: 0.92, transparent: false, embedFonts: true,
        fps: 15, maxEdge: 480,
        phase: 'idle', stage: '', progress: 0, result: null, error: null, messages: [],
        estimate: null, estimating: false, preview: { loading: true, error: '' },
        advancedOpen: ctx.mode === 'pro',
        pdfMode: 'vector', bleedMm: 0, marks: false,
    };
    let prepared = null; // {svg, pageIndex, transparent} cached for previews and JPG estimates
    let previewBox_dims = null; // the page plus bleed and marks, when the preview shows them

    // ---- derived -----------------------------------------------------------------------------------------------------
    const pageIndexes = () => {
        const n = getPages().length;
        if (state.pagesMode === 'all') return Array.from({ length: n }, (_, i) => i);
        if (state.pagesMode === 'current') return [activeIndex()];
        const s = Array.from(state.selected).filter((i) => i < n).sort((a, b) => a - b);
        return s.length ? s : [activeIndex()];
    };
    const firstPage = () => getPages()[pageIndexes()[0]] || getPages()[0];
    const settings = () => {
        const f = state.format;
        const s = { ...DEFAULT_SETTINGS, format: f, pages: pageIndexes(), quality: state.quality, transparent: f === 'png' && state.transparent, embedFonts: state.embedFonts, fps: state.fps, maxEdge: state.maxEdge };
        s.sizes = targetsFromState(state, kind);
        if (!s.sizes.length) s.sizes = [{ mode: 'scale', scale: 1 }];
        s.pdfTarget = kind === 'print' ? { mode: 'dpi', dpi: state.pdfDpi } : { mode: 'scale', scale: state.pdfScale };
        s.pdfMode = state.pdfMode;
        s.bleedMm = kind === 'print' && ['png', 'jpg', 'pdf'].includes(f) ? state.bleedMm : 0;
        s.marks = kind === 'print' && ['png', 'jpg', 'pdf'].includes(f) ? state.marks : false;
        return s;
    };
    const resetResult = () => {
        if (state.phase === 'working') return;
        state.phase = 'idle'; state.result = null; state.error = null; state.messages = [];
        if (resultUrl) { URL.revokeObjectURL(resultUrl); resultUrl = null; }
    };

    // ---- preview + estimates ------------------------------------------------------------------------------------------
    async function loadPreview() {
        const idx = pageIndexes()[0];
        state.preview = { loading: true, error: '' };
        paintPreview();
        try {
            const p = await prepareSvg(deps, { pageIndex: idx, embedFonts: true, transparent: state.format === 'png' && state.transparent, inline: true });
            if (destroyed) return;
            prepared = { svg: p.svg, pageIndex: idx, transparent: state.format === 'png' && state.transparent, page: p.page };
            let shown = p.svg;
            previewBox_dims = null;
            if (kind === 'print' && ['png', 'jpg', 'pdf'].includes(state.format) && hasPrintBoxes({ bleedMm: state.bleedMm, marks: state.marks })) {
                const framed = applyPrintBoxes(p.svg, { width: p.page.width, height: p.page.height, bleedMm: state.bleedMm, marks: state.marks });
                if (framed.changed) { shown = framed.svg; previewBox_dims = { w: framed.width, h: framed.height }; }
            }
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(new Blob([shown], { type: 'image/svg+xml;charset=utf-8' }));
            state.preview = { loading: false, error: '' };
        } catch (e) {
            if (destroyed) return;
            prepared = null;
            state.preview = { loading: false, error: e instanceof ExportError ? e.message : 'The preview could not be drawn.' };
        }
        paintPreview();
        refreshEstimate();
    }
    const loadPreviewSoon = debounce(loadPreview, 250);

    async function estimate() {
        const f = state.format;
        state.estimate = null;
        if (f === 'jpg' && prepared) {
            const t = targetsFromState(state, kind)[0] || { mode: 'scale', scale: 1 };
            try { const bytes = await estimateJpegBytes(deps, { svg: prepared.svg, page: prepared.page, target: t, quality: state.quality }); if (!destroyed && state.format === 'jpg') state.estimate = bytes ? { bytes } : null; } catch (e) { state.estimate = null; }
        } else if (f === 'gif') {
            state.estimating = true; paintFacts();
            try { const r = await estimateGifSize(deps, { indexes: pageIndexes(), fps: state.fps, maxEdge: state.maxEdge }); if (!destroyed && state.format === 'gif') state.estimate = { bytes: r.bytes, plan: r.plan }; } catch (e) { state.estimate = null; }
            state.estimating = false;
        }
        if (!destroyed) paintFacts();
    }
    const refreshEstimate = debounce(estimate, 350);

    // ---- running ---------------------------------------------------------------------------------------------------------
    async function run(s, { auto = false } = {}) {
        if (state.phase === 'working') return;
        resetResult();
        state.phase = 'working'; state.stage = 'Starting'; state.progress = 0; state.error = null;
        abort = new AbortController();
        render();
        try {
            const res = await runExport(deps, s, project, {
                signal: abort.signal,
                onProgress: (v) => { state.progress = v; paintStatus(); },
                onStage: (t) => { state.stage = t; paintStatus(); },
            });
            if (destroyed) return;
            state.result = res; state.messages = res.messages || []; state.phase = 'ready';
            const file = res.files[0];
            const blob = new Blob([file.bytes], { type: file.mime });
            if (resultUrl) URL.revokeObjectURL(resultUrl);
            resultUrl = URL.createObjectURL(blob);
            render();
            if (auto) download();
        } catch (e) {
            if (destroyed) return;
            if (e instanceof ExportError && e.code === 'cancelled') { state.phase = 'idle'; state.error = null; ctx.toast('Cancelled. Nothing was downloaded.', { kind: 'warn' }); } else {
                state.phase = 'error';
                state.error = e instanceof ExportError ? { message: e.message, hint: e.hint } : { message: 'Something went wrong while making the file.', hint: 'Try again. If it keeps happening, reload the editor; your work is saved.' };
                if (!(e instanceof ExportError)) console.error('[export]', e);
            }
            render();
        } finally { abort = null; }
    }

    function download() {
        const res = state.result;
        if (!res) return;
        const file = res.files[0];
        saveFile(file.bytes, file.name, file.mime);
        ctx.toast(`Download started: ${file.name} (${formatBytes(file.bytes.length)})`, { kind: 'ok' });
        recordExport(res);
    }

    function recordExport(res) {
        // The audit row never blocks or changes the download: failures are ignored on purpose.
        try {
            const s = state.lastSettings || settings();
            const pages = getPages();
            const idx = s.pages;
            const body = { format: s.format, pages: idx.length === pages.length ? 'all' : idx.map((i) => pages[i] && pages[i].id).filter((id) => Number.isInteger(id)), scale: auditScale(s, pages[idx[0]] || pages[0]), bytes: res.files[0].bytes.length };
            if (Array.isArray(body.pages) && !body.pages.length) body.pages = 'all';
            const url = (config.urls && (config.urls.exports || config.urls.export_audit)) || `/design/projects/${project.id}/exports`;
            Promise.resolve(ctx.api.post(url, body)).catch(() => {});
        } catch (e) { /* never blocks the download */ }
    }

    // ---- DOM -------------------------------------------------------------------------------------------------------------
    const body = h('div', { class: 'out-export' });
    const previewBox = h('div', { class: 'out-preview' });
    const frameEl = h('div', { class: 'out-preview__frame' });
    const factsEl = h('dl', { class: 'out-facts', 'aria-live': 'polite' });
    const warnEl = h('p', { class: 'out-note out-note--warn', role: 'status', hidden: true });
    const statusEl = h('div', { class: 'out-status', 'aria-live': 'polite' });
    root.appendChild(body);

    function paintPreview() {
        clear(frameEl);
        const p = firstPage();
        const w = previewBox_dims ? previewBox_dims.w : p.width; const hh = previewBox_dims ? previewBox_dims.h : p.height;
        frameEl.style.aspectRatio = `${w} / ${hh}`;
        frameEl.style.width = `min(100%, ${Math.round(18 * (w / hh) * 16)}px)`;
        frameEl.dataset.clear = state.format === 'png' && state.transparent ? '1' : '0';
        const showResult = state.phase === 'ready' && state.result;
        if (showResult && state.format === 'webm' && resultUrl) {
            frameEl.appendChild(h('video', { src: resultUrl, controls: true, loop: true, muted: true, autoplay: true, playsinline: true, 'aria-label': 'Preview of the video' }));
        } else if (showResult && ['png', 'jpg', 'gif', 'svg'].includes(state.format) && !state.result.zip && resultUrl) {
            frameEl.appendChild(h('img', { src: resultUrl, alt: `Preview of ${state.result.files[0].name}` }));
        } else if (previewUrl && !state.preview.error) {
            frameEl.appendChild(h('img', { src: previewUrl, alt: 'Preview of the page as it will be exported' }));
        }
        if (state.preview.loading && !(showResult)) frameEl.appendChild(h('div', { class: 'out-preview__loading' }, 'Drawing preview'));
        if (state.preview.error) frameEl.appendChild(h('div', { class: 'out-preview__loading', role: 'alert' }, state.preview.error));
    }

    function paintFacts() {
        clear(factsEl);
        const p = firstPage();
        const s = settings();
        const f = state.format;
        const rows = [];
        const add = (k, v) => rows.push(h('div', {}, h('dt', {}, k), h('dd', {}, v)));
        if (f === 'png' || f === 'jpg') {
            const sizes = s.sizes.map((t) => describeTarget(p, t));
            add(sizes.length > 1 ? 'Sizes' : 'Size', sizes.map((d) => `${d.width} x ${d.height}`).join(', ') + ' px');
        } else if (f === 'pdf') {
            if (state.pdfMode === 'vector') add('Text', 'Stays text (selectable)');
            else { const d = describeTarget(p, s.pdfTarget); add('Picture size', `${d.width} x ${d.height} px per page`); }
        } else if (f === 'svg') add('Size', `${Math.round(p.width)} x ${Math.round(p.height)} units`);
        else if (f === 'gif' || f === 'webm') {
            const plan = planAnimation(getPages(), pageIndexes(), { fps: s.fps, maxEdge: s.maxEdge });
            add('Size', `${plan.width} x ${plan.height} px`);
            add('Length', `${fmtSeconds(plan.durationMs)} s, ${plan.frames} frames`);
        }
        const n = pageIndexes().length;
        add('Pages', n === 1 ? `Page ${pageIndexes()[0] + 1}` : `${n} pages`);
        if (s.bleedMm > 0) add('Bleed', `${s.bleedMm} mm`);
        if (s.marks) add('Crop marks', 'On');
        if (state.phase === 'ready' && state.result) {
            const r = state.result;
            add('File', r.files[0].name);
            add('Real size', formatBytes(r.bytes));
            if (r.zip) add('Inside', `${r.inner.length} files`);
        } else if (state.estimating) add('Size', 'Working it out');
        else if (state.estimate && (f === 'jpg' || f === 'gif')) add('Size', `about ${formatBytes(state.estimate.bytes)} (estimate)`);
        else add('File size', 'Shown after you prepare the file');
        rows.forEach((r) => factsEl.appendChild(r));
        const big = f === 'gif' && state.phase !== 'ready' && state.estimate && state.estimate.bytes > 8 * 1024 * 1024;
        warnEl.hidden = !big;
        warnEl.textContent = big ? `This GIF will be about ${formatBytes(state.estimate.bytes)}. That is heavy for email and messages. Choose a smaller size or fewer frames per second.` : '';
    }

    function paintStatus() {
        clear(statusEl);
        if (state.phase === 'working') {
            statusEl.appendChild(h('div', { class: 'out-status__line' }, h('span', {}, state.stage), h('span', { class: 'out-num' }, `${Math.round(state.progress * 100)}%`)));
            const bar = h('div', { class: 'out-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(state.progress * 100)), 'aria-label': 'Preparing your file' }, h('span', { style: { '--p': String(state.progress) } }));
            statusEl.appendChild(bar);
        }
    }

    function section(title, ...kids) { return h('div', { class: 'out-section' }, h('h3', {}, title), ...kids); }

    function pagesSection() {
        const pages = getPages();
        if (pages.length < 2) return null;
        const seg = segmented({
            label: 'Which pages', value: state.pagesMode,
            options: [{ value: 'all', label: `All pages (${pages.length})` }, { value: 'current', label: 'This page' }, { value: 'select', label: 'Choose pages' }],
            onChange: (v) => { state.pagesMode = v; resetResult(); render(); loadPreviewSoon(); },
        });
        const list = state.pagesMode === 'select' ? h('div', { class: 'out-pagelist', role: 'group', 'aria-label': 'Pages to download' }, pages.map((p, i) => checkbox({ label: p.name && String(p.name).trim() ? p.name : `Page ${i + 1}`, checked: state.selected.has(i), onChange: (on) => { if (on) state.selected.add(i); else state.selected.delete(i); resetResult(); paintFacts(); renderActions(); loadPreviewSoon(); } }))) : null;
        return section('Pages', seg, list);
    }

    function sizeSection() {
        const f = state.format;
        const p = firstPage();
        if (f === 'svg') return section('Fonts', switchControl({ label: 'Include the fonts in the file', checked: state.embedFonts, onChange: (on) => { state.embedFonts = on; resetResult(); render(); } }), h('p', { class: 'out-muted' }, state.embedFonts ? 'The file is bigger but looks the same everywhere. Text stays text.' : 'The file is smaller, but computers without these fonts will show other ones.'));
        if (f === 'gif' || f === 'webm') {
            const fpsList = f === 'gif' ? FPS_GIF : FPS_VIDEO;
            if (!fpsList.includes(state.fps)) state.fps = fpsList[1] || fpsList[0];
            return section('Quality', h('div', { class: 'out-row', role: 'group', 'aria-label': 'Frames per second' }, h('span', { class: 'out-muted' }, 'Smoothness'), fpsList.map((v) => chip({ label: `${v} per second`, pressed: state.fps === v, onClick: () => { state.fps = v; resetResult(); render(); refreshEstimate(); } }))),
                h('div', { class: 'out-row', role: 'group', 'aria-label': 'Picture size' }, h('span', { class: 'out-muted' }, 'Picture size'), EDGE_CHOICES.map((e) => chip({ label: `${e.label} (${e.id})`, pressed: state.maxEdge === e.id, onClick: () => { state.maxEdge = e.id; resetResult(); render(); refreshEstimate(); } }))),
                f === 'gif' ? h('p', { class: 'out-muted' }, 'GIF files get big quickly. Small and 10 or 15 per second is a good start.') : h('p', { class: 'out-muted' }, 'The video is recorded at real speed, so preparing it takes about as long as the animation. Keep this tab in front until it is ready.'));
        }
        if (f === 'pdf') {
            const choices = kind === 'print' ? DPI_CHOICES : [1, 2, 3];
            const modes = radioCards({
                name: 'out-pdfmode', label: 'What kind of PDF', value: state.pdfMode,
                options: [
                    { value: 'vector', label: 'PDF (text stays text)', description: 'Text can be selected and searched, and shapes stay sharp at any zoom. Pages with shadows, picture filters or blend modes are saved as pictures, and you are told which.' },
                    { value: 'picture', label: 'PDF (as pictures)', description: 'Each page is one picture, exactly as you see it, effects included. The text cannot be selected or searched.' },
                ],
                onChange: (v) => { state.pdfMode = v; resetResult(); render(); },
            });
            return section('PDF', modes,
                h('div', { class: 'out-row', role: 'group', 'aria-label': 'PDF picture quality' }, h('span', { class: 'out-muted' }, state.pdfMode === 'vector' ? 'Quality of pages saved as pictures' : 'Picture quality'), choices.map((v) => chip({ label: kind === 'print' ? `${v} dpi` : `${v}x`, pressed: kind === 'print' ? state.pdfDpi === v : state.pdfScale === v, onClick: () => { if (kind === 'print') state.pdfDpi = v; else state.pdfScale = v; resetResult(); render(); } }))));
        }
        // png / jpg
        const out = [];
        const scaleChips = SCALE_CHOICES.map((v) => chip({ label: `${v}x`, pressed: state.scales.has(v) && state.mode === 'scale', onClick: () => { state.mode = 'scale'; if (state.scales.has(v) && (state.scales.size > 1 || (state.useWidth && parseInt(state.customWidth, 10) >= 16))) state.scales.delete(v); else state.scales.add(v); resetResult(); render(); refreshEstimate(); } }));
        if (kind === 'print') {
            out.push(h('div', { class: 'out-row', role: 'group', 'aria-label': 'Print quality' }, h('span', { class: 'out-muted' }, 'Print quality'), DPI_CHOICES.map((v) => chip({ label: `${v} dpi`, pressed: state.mode === 'dpi' && state.dpis.has(v), onClick: () => { state.mode = 'dpi'; if (state.dpis.has(v) && state.dpis.size > 1) state.dpis.delete(v); else state.dpis.add(v); resetResult(); render(); refreshEstimate(); } }))));
            out.push(h('div', { class: 'out-row', role: 'group', 'aria-label': 'Screen sizes' }, h('span', { class: 'out-muted' }, 'Or screen size'), scaleChips));
        } else out.push(h('div', { class: 'out-row', role: 'group', 'aria-label': 'Size multipliers' }, scaleChips));
        const widthInput = numberField({ value: state.customWidth === '' ? NaN : Number(state.customWidth), min: 16, max: 20000, step: 10, decimals: 0, label: 'Exact width in pixels', unit: 'px', disabled: !state.useWidth, placeholder: 'Width', dataFk: 'width', onChange: (v) => { state.customWidth = String(v); resetResult(); paintFacts(); renderActions(); } });
        out.push(h('div', { class: 'out-row' }, switchControl({ label: 'Exact width', checked: state.useWidth, dataFk: 'use-width', onChange: (on) => { state.useWidth = on; if (!state.useWidth && !state.scales.size && !state.dpis.size) state.scales.add(1); resetResult(); render(); if (state.useWidth) setTimeout(() => { const i = body.querySelector('.mc-num__input'); if (i) i.focus(); }, 0); } }), widthInput, h('span', { class: 'out-muted' }, 'height follows')));
        const sizes = targetsFromState(state, kind);
        out.push(h('p', { class: 'out-muted' }, sizes.length > 1 ? `${sizes.length} sizes per page. They go together in one ZIP.` : 'Pick more than one size to get all of them in one ZIP.'));
        const d = sizes[0] ? describeTarget(p, sizes[0]) : null;
        const warn = d ? sizeWarning(d.width, d.height, f) : '';
        if (warn) out.push(h('p', { class: 'out-note out-note--warn', role: 'status' }, warn));
        return section(kind === 'print' ? 'Size and quality' : 'Size', ...out);
    }

    /** Bleed, crop marks and the colour note: print formats only, for the file types that go to a print shop. */
    function printSection() {
        if (kind !== 'print' || !['png', 'jpg', 'pdf'].includes(state.format)) return null;
        const bleed = segmented({
            label: 'Bleed', value: String(state.bleedMm),
            options: BLEED_CHOICES.map((v) => ({ value: String(v), label: v === 0 ? 'None' : `${v} mm` })),
            onChange: (v) => { state.bleedMm = Number(v); resetResult(); render(); loadPreviewSoon(); },
        });
        const marks = switchControl({ label: 'Crop marks', description: 'Short lines in the corners that show the printer where to cut.', checked: state.marks, dataFk: 'marks', onChange: (on) => { state.marks = on; resetResult(); render(); loadPreviewSoon(); } });
        return section('Ready for the printer',
            h('div', { class: 'out-row' }, h('span', { class: 'out-muted' }, 'Bleed'), bleed),
            h('p', { class: 'out-muted' }, state.bleedMm > 0 ? `The background continues ${state.bleedMm} mm past the edge, so a small cutting error never shows a white line. Ask your printer which bleed they want; 3 mm is the usual one.` : 'Bleed is extra background beyond the edge that gets trimmed off. Most print shops ask for 3 mm.'),
            marks,
            h('p', { class: 'out-note' }, COLOUR_NOTE));
    }

    function optionsSection() {
        const f = state.format;
        const out = [];
        if (f === 'png') out.push(switchControl({ label: 'See-through background', checked: state.transparent, dataFk: 'transparent', onChange: (on) => { state.transparent = on; resetResult(); render(); loadPreviewSoon(); } }), state.transparent ? h('p', { class: 'out-muted' }, 'The page colour is left out. Shapes and pictures keep their own colours.') : null);
        if (f === 'jpg') out.push(slider({ value: Math.round(state.quality * 100), min: 40, max: 100, step: 1, label: 'JPG quality', format: (v) => `${v}%`, onInput: (v) => { state.quality = v / 100; resetResult(); refreshEstimate(); estimateSoon(); } }), h('p', { class: 'out-muted' }, 'Lower quality makes a smaller file. Below about 70% you may see blocky edges.'));
        return out.length ? section(f === 'jpg' ? 'Quality' : 'Background', ...out) : null;
    }
    const estimateSoon = debounce(() => { paintFacts(); }, 400);

    function tabs() {
        const list = tabsFor({ animated });
        return h('div', { class: 'out-tabs', role: 'tablist', 'aria-label': 'File type' }, list.map((t) => h('button', {
            type: 'button', role: 'tab', class: 'out-tab', 'aria-selected': state.format === t.id ? 'true' : 'false', title: t.help, dataset: { fk: `tab-${t.id}` },
            onclick: () => { state.format = t.id; state.estimate = null; resetResult(); render(); loadPreviewSoon(); refreshEstimate(); },
        }, t.label)));
    }

    function renderActions() {
        const box = body.querySelector('[data-actions]');
        if (!box) return;
        clear(box);
        const f = state.format;
        if (f === 'webm' && !video.supported) {
            box.appendChild(h('p', { class: 'out-note out-note--warn', role: 'status' }, video.reason));
            box.appendChild(h('button', { type: 'button', class: 'lt-btn lt-btn--secondary', onclick: () => { state.format = 'gif'; resetResult(); render(); refreshEstimate(); } }, 'Use GIF instead'));
            return;
        }
        if (state.phase === 'working') { box.appendChild(h('button', { type: 'button', class: 'lt-btn lt-btn--secondary', onclick: () => { if (abort) abort.abort(); } }, 'Cancel')); return; }
        const noPages = pageIndexes().length === 0;
        const badSize = (f === 'png' || f === 'jpg') && state.useWidth && targetsFromState(state, kind).length === 0;
        if (state.phase === 'ready' && state.result) {
            box.appendChild(h('button', { type: 'button', class: 'lt-btn', dataset: { fk: 'download' }, onclick: download }, icon('download'), `Download (${formatBytes(state.result.bytes)})`));
            box.appendChild(h('button', { type: 'button', class: 'lt-btn lt-btn--ghost', onclick: () => { resetResult(); render(); } }, 'Change settings'));
            return;
        }
        box.appendChild(h('button', { type: 'button', class: 'lt-btn', disabled: noPages || badSize, onclick: () => { state.lastSettings = settings(); run(state.lastSettings); } }, 'Prepare file'));
        if (badSize) box.appendChild(h('span', { class: 'out-muted' }, 'Type a width between 16 and 20000.'));
    }

    function resultNotes() {
        const out = [];
        if (state.phase === 'error' && state.error) out.push(h('div', { class: 'out-note out-note--bad', role: 'alert' }, h('strong', {}, state.error.message), ' ', state.error.hint));
        for (const m of state.messages) out.push(h('p', { class: 'out-note out-note--warn', role: 'status' }, m));
        if (state.phase === 'ready' && state.result && !state.messages.length) out.push(h('p', { class: 'out-note out-note--ok', role: 'status' }, state.result.zip ? `Ready: ${state.result.inner.length} files in one ZIP.` : 'Ready. Check the preview, then download.'));
        return out;
    }

    let focusKey = null;
    function render() {
        const ae = document.activeElement;
        focusKey = ae && ae.dataset && ae.dataset.fk ? ae.dataset.fk : null;
        const busy = state.phase === 'working';
        clear(body);
        // one-click presets
        const quickEl = h('div', { class: 'out-quick', role: 'group', 'aria-label': 'Quick downloads' }, quick.map((q) => {
            const multi = getPages().length > 1;
            const qs = { ...q.settings, pages: ['pdf', 'gif', 'webm'].includes(q.settings.format) ? getPages().map((_, i) => i) : [activeIndex()] };
            const where = multi ? (qs.pages.length > 1 ? `, all ${qs.pages.length} pages` : ', this page') : '';
            return h('button', { type: 'button', 'data-primary': q.primary ? '1' : '0', disabled: busy || (q.settings.format === 'webm' && !video.supported), onclick: () => { state.lastSettings = qs; state.format = q.settings.format; run(qs, { auto: true }); } }, h('span', { class: 'out-quick__label' }, q.label), h('span', { class: 'out-quick__hint' }, q.hint + where));
        }));
        body.appendChild(quickEl);
        body.appendChild(statusEl);
        paintStatus();
        const adv = h('details', { class: 'out-adv', open: state.advancedOpen, ontoggle: (e) => { state.advancedOpen = e.target.open; } },
            h('summary', {}, 'More options', icon('chevron')),
            h('div', { class: 'out-adv__body' },
                h('div', { class: 'out-options' }, tabs(), state.format === 'webm' && !video.supported ? null : pagesSection(), state.format === 'webm' && !video.supported ? null : sizeSection(), state.format === 'webm' && !video.supported ? null : optionsSection(), printSection(), ...resultNotes(), h('div', { class: 'out-actions', dataset: { actions: '1' } })),
                (() => { clear(previewBox); paintPreview(); paintFacts(); previewBox.appendChild(h('div', { class: 'out-preview__stage' }, frameEl)); previewBox.appendChild(factsEl); previewBox.appendChild(warnEl); return previewBox; })()));
        body.appendChild(adv);
        if (filesAvailable) body.appendChild(h('div', { class: 'out-files' }, h('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => openFilesDialog(ctx) }, 'Save a copy to Dot.Files'), h('span', { class: 'out-muted' }, 'Filed for everyone in your business.')));
        renderActions();
        if (focusKey) { const t = body.querySelector(`[data-fk="${focusKey}"]`); if (t) t.focus(); }
    }

    render();
    loadPreview();

    return {
        destroy() {
            destroyed = true;
            if (abort) abort.abort();
            loadPreviewSoon.cancel(); refreshEstimate.cancel(); estimateSoon.cancel();
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            if (resultUrl) URL.revokeObjectURL(resultUrl);
            clear(root);
        },
    };
}

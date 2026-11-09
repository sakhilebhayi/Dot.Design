/*
 * Export jobs: turn export settings into files. Pure orchestration over the writers; every browser capability is injected
 * through `deps` (see export-raster.js) so the whole thing runs in node tests with stubs.
 *
 * settings = {
 *   format: 'png'|'jpg'|'pdf'|'svg'|'gif'|'webm',
 *   pages: [pageIndex...],
 *   sizes: [target...]            png/jpg: one file per page per size (target = resolveTarget spec)
 *   quality, transparent,         jpg quality 0.4..1, png transparency
 *   embedFonts,                   svg
 *   pdfTarget,                    {mode:'dpi', dpi} or {mode:'scale', scale}
 *   fps, maxEdge,                 gif / webm
 * }
 * project = {name, unit}
 * Result: {files: [{name, mime, bytes: Uint8Array}], zip: bool, messages: [], bytes}
 */
import { concat } from './bytes.js';
import { buildDesignFilename, buildFilename } from './filenames.js';
import { createZip } from './zip.js';
import { buildPdf, pageSizePt } from './export-pdf.js';
import { exportVectorPdf } from './export-vector.js';
import { edgeMessage, hasPrintBoxes, padUnits } from './print-boxes.js';
import { encodeGif, estimateGifBytes } from './gif.js';
import { ExportError, exportSvg, prepareSvg } from './export-svg.js';
import { drawSvgToCanvas, exportPageRaster, disposeCanvas, resolveTarget } from './export-raster.js';
import { evaluate, pageDuration } from './animate.js';
import { applyAnimationToSvg } from './animate-apply.js';
import { recordWebm } from './webm.js';

export const PRINT_DPIS = [150, 300, 600];
export const TRANSITION_MS = 500;
export const FRAME_BUDGET_BYTES = 360 * 1024 * 1024;
export const MAX_GIF_FRAMES = 600;

const pageLabel = (page, i) => (page.name && String(page.name).trim() ? page.name : null);

export function frameTimes(durationMs, fps) {
    const n = Math.max(1, Math.round((durationMs / 1000) * fps));
    return Array.from({ length: n }, (_, i) => (i * 1000) / fps);
}

/** What an animated export will contain, so the UI can show size warnings before anything is rendered. */
export function planAnimation(pages, indexes, { fps = 15, maxEdge = 720 } = {}) {
    const first = pages[indexes[0]];
    const k = Math.min(1, maxEdge / Math.max(first.width, first.height));
    let width = Math.max(2, Math.round(first.width * k));
    let height = Math.max(2, Math.round(first.height * k));
    let frames = 0;
    let durationMs = 0;
    indexes.forEach((pi, n) => {
        const p = pages[pi];
        const d = pageDuration(p);
        frames += frameTimes(d, fps).length;
        durationMs += d;
        const next = indexes[n + 1] !== undefined;
        if (next && p.transition && p.transition !== 'none') { frames += Math.round((TRANSITION_MS / 1000) * fps); durationMs += TRANSITION_MS; }
    });
    let reduced = false;
    while (frames * width * height * 4 > FRAME_BUDGET_BYTES && width > 64) { width = Math.round(width * 0.85); height = Math.round(height * 0.85); reduced = true; }
    return { frames, width, height, durationMs, reduced, tooLong: frames > MAX_GIF_FRAMES };
}

function transitionFrame(ctx, type, a, b, p, w, h) {
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    if (type === 'slide') {
        ctx.drawImage(a, -p * w, 0, w, h);
        ctx.drawImage(b, (1 - p) * w, 0, w, h);
    } else if (type === 'zoom') {
        const s = 1 + 0.15 * p;
        ctx.globalAlpha = 1 - p;
        ctx.drawImage(a, (w - w * s) / 2, (h - h * s) / 2, w * s, h * s);
        ctx.globalAlpha = p;
        ctx.drawImage(b, 0, 0, w, h);
        ctx.globalAlpha = 1;
    } else { // fade
        ctx.drawImage(a, 0, 0, w, h);
        ctx.globalAlpha = p;
        ctx.drawImage(b, 0, 0, w, h);
        ctx.globalAlpha = 1;
    }
}

/**
 * Renders every frame of an animated export through the SAME renderer and evaluator as the preview.
 * emit(frame) is called with {canvas (reused: copy what you need), delayMs} in order.
 */
export async function renderAnimationFrames(deps, { indexes, fps, maxEdge, signal, onProgress, emit }) {
    const state = deps.studio.getState();
    const pages = state.doc.pages;
    const plan = planAnimation(pages, indexes, { fps, maxEdge });
    const { width, height } = plan;
    const delayMs = 1000 / fps;
    const messages = [];
    if (plan.reduced) messages.push('The picture size was reduced so the whole animation fits in memory.');
    let done = 0;
    const total = plan.frames;
    const scratch = deps.createCanvas(width, height);
    const sctx = scratch.getContext('2d');
    let prevLast = null; // canvas holding the last frame of the previous page (for transitions)
    for (let n = 0; n < indexes.length; n += 1) {
        const pi = indexes[n];
        const page = pages[pi];
        const prepared = await prepareSvg(deps, { pageIndex: pi, embedFonts: true, inline: true, signal });
        const duration = pageDuration(page);
        const times = frameTimes(duration, fps);
        const draw = async (t) => {
            const svg = applyAnimationToSvg(prepared.svg, page, evaluate(page, t));
            const d = await drawSvgToCanvas(deps, svg, { width, height, flatten: true, signal });
            if (d.width !== width || d.height !== height) throw new ExportError('canvas', 'This browser could not make frames of that size.', 'Choose a smaller size and try again.');
            return d.canvas;
        };
        // transition from the previous page into this one
        if (prevLast) {
            const outgoing = pages[indexes[n - 1]];
            if (outgoing.transition && outgoing.transition !== 'none') {
                const incoming = await draw(0);
                const steps = Math.round((TRANSITION_MS / 1000) * fps);
                for (let s = 1; s <= steps; s += 1) {
                    if (signal && signal.aborted) throw new ExportError('cancelled', 'Cancelled.');
                    transitionFrame(sctx, outgoing.transition, prevLast, incoming, s / (steps + 1), width, height);
                    await emit({ canvas: scratch, delayMs });
                    done += 1; if (onProgress) onProgress(done, total);
                }
                disposeCanvas(incoming);
            }
            disposeCanvas(prevLast);
            prevLast = null;
        }
        const nextExists = indexes[n + 1] !== undefined;
        for (let i = 0; i < times.length; i += 1) {
            if (signal && signal.aborted) throw new ExportError('cancelled', 'Cancelled.');
            const c = await draw(times[i]);
            await emit({ canvas: c, delayMs });
            done += 1; if (onProgress) onProgress(done, total);
            if (i === times.length - 1 && nextExists && page.transition && page.transition !== 'none') prevLast = c; else disposeCanvas(c);
        }
    }
    disposeCanvas(scratch);
    return { width, height, frames: total, messages, fps };
}

export async function exportGif(deps, { indexes, fps = 15, maxEdge = 480, signal, onProgress, onStage, dither = false }) {
    const frames = [];
    if (onStage) onStage('Drawing frames');
    const info = await renderAnimationFrames(deps, {
        indexes, fps, maxEdge, signal,
        onProgress: (d, t) => onProgress && onProgress(d / t * 0.8),
        async emit({ canvas, delayMs }) {
            const ctx = canvas.getContext('2d');
            frames.push({ data: ctx.getImageData(0, 0, canvas.width, canvas.height).data, delayMs });
        },
    });
    if (onStage) onStage('Building the GIF');
    await new Promise((r) => setTimeout(r, 0));
    const bytes = encodeGif({
        width: info.width, height: info.height, frames, loop: 0, dither,
        shouldCancel: () => signal && signal.aborted,
        onFrame: (i, n) => onProgress && onProgress(0.8 + 0.2 * (i / n)),
    });
    return { bytes, width: info.width, height: info.height, frames: frames.length, fps, messages: info.messages, mime: 'image/gif' };
}

export async function exportWebm(deps, { indexes, fps = 30, maxEdge = 720, signal, onProgress, onStage }) {
    const sources = [];
    if (onStage) onStage('Drawing frames');
    const info = await renderAnimationFrames(deps, {
        indexes, fps, maxEdge, signal,
        onProgress: (d, t) => onProgress && onProgress(d / t * 0.5),
        async emit({ canvas, delayMs }) {
            const copy = deps.createCanvas(canvas.width, canvas.height);
            copy.getContext('2d').drawImage(canvas, 0, 0);
            const source = typeof deps.toBitmap === 'function' ? await deps.toBitmap(copy) : copy;
            if (source !== copy) disposeCanvas(copy);
            sources.push({ source, delayMs });
        },
    });
    if (onStage) onStage('Recording the video');
    const blob = await recordWebm({
        frames: sources, width: info.width, height: info.height, fps, createCanvas: deps.createCanvas, signal,
        onProgress: (i, n) => onProgress && onProgress(0.5 + 0.5 * (i / n)),
    });
    for (const s of sources) { if (s.source.close) s.source.close(); else disposeCanvas(s.source); }
    return { bytes: new Uint8Array(await blob.arrayBuffer()), blob, width: info.width, height: info.height, frames: sources.length, fps, messages: info.messages, mime: blob.type || 'video/webm' };
}

/** One PDF of the chosen pages; every page is a JPEG picture at the chosen resolution. */
export async function exportPdf(deps, { indexes, target, project, quality = 0.92, signal, onProgress, date, bleedMm = 0, marks = false }) {
    const pages = deps.studio.getState().doc.pages;
    const out = [];
    const messages = [];
    const print = { bleedMm, marks };
    for (let n = 0; n < indexes.length; n += 1) {
        const pi = indexes[n];
        const page = pages[pi];
        const r = await exportPageRaster(deps, { pageIndex: pi, format: 'jpg', target, quality, signal, print });
        const size = pageSizePt(page, project.unit);
        const pad = hasPrintBoxes(print) ? padUnits(print) * (project.unit === 'mm' || project.unit === 'in' ? 1 : 0.75) : 0;
        out.push({ jpeg: await r.bytes(), pxWidth: r.width, pxHeight: r.height, widthPt: size.widthPt + 2 * pad, heightPt: size.heightPt + 2 * pad, trim: hasPrintBoxes(print) ? { x: pad, y: pad, w: size.widthPt, h: size.heightPt, bleed: (bleedMm * 72) / 25.4 } : null });
        if (r.message) messages.push(r.message);
        const edge = edgeMessage(page, bleedMm);
        if (edge) messages.push(indexes.length > 1 ? `Page ${pi + 1}: ${edge}` : edge);
        if (onProgress) onProgress((n + 1) / indexes.length);
    }
    const bytes = buildPdf({ pages: out, title: project.name || 'Design', date: date || new Date() });
    return { bytes, pages: out.length, width: out[0].pxWidth, height: out[0].pxHeight, messages, mime: 'application/pdf' };
}

/**
 * runExport(deps, settings, project, hooks) -> {files, zip, messages, bytes, name}
 * More than one file becomes ONE ZIP (store-only).
 */
export async function runExport(deps, settings, rawProject, { signal, onProgress, onStage } = {}) {
    // A print design (format kind "print") is stored in 72 dpi units whatever the project's unit says, so one unit is one point.
    const project = rawProject.kind === 'print' && rawProject.unit !== 'mm' && rawProject.unit !== 'in' ? { ...rawProject, unit: 'mm' } : rawProject;
    const pages = deps.studio.getState().doc.pages;
    const indexes = (settings.pages && settings.pages.length ? settings.pages : [0]).filter((i) => pages[i]);
    if (!indexes.length) throw new ExportError('no-page', 'No page is selected.', 'Choose at least one page.');
    const design = project.name || 'design';
    const files = [];
    const messages = [];
    const fmt = settings.format;
    const progress = (v) => { if (onProgress) onProgress(Math.min(1, v)); };

    if (fmt === 'png' || fmt === 'jpg') {
        const sizes = settings.sizes && settings.sizes.length ? settings.sizes : [{ mode: 'scale', scale: 1 }];
        const total = indexes.length * sizes.length;
        let done = 0;
        for (const pi of indexes) {
            for (const sz of sizes) {
                if (onStage) onStage(total > 1 ? `Making file ${done + 1} of ${total}` : 'Making the picture');
                const print = project.unit === 'mm' || project.unit === 'in' ? { bleedMm: settings.bleedMm || 0, marks: !!settings.marks } : null;
                const r = await exportPageRaster(deps, { pageIndex: pi, format: fmt, target: sz, quality: settings.quality, transparent: !!settings.transparent, signal, print });
                if (print && hasPrintBoxes(print) && !messages.some((m) => m.startsWith('Print:'))) messages.push(`Print: bleed and crop marks are drawn into the ${fmt.toUpperCase()}. The picture is bigger than the page by that margin.`);
                { const edge = edgeMessage(pages[pi], print ? print.bleedMm : 0); if (edge && !messages.includes(edge)) messages.push(edge); }
                if (r.message) messages.push(r.message);
                const suffix = sizes.length > 1 ? (sz.mode === 'scale' ? `${sz.scale}x` : sz.mode === 'dpi' ? `${sz.dpi}dpi` : `w${sz.width}`) : '';
                files.push({ name: buildFilename({ design, page: pageLabel(pages[pi], pi), pageNumber: pi + 1, width: r.width, height: r.height, ext: fmt, suffix: sizes.length > 1 ? suffix : '' }), mime: r.mime, bytes: await r.bytes() });
                done += 1; progress(done / total);
            }
        }
    } else if (fmt === 'svg') {
        let done = 0;
        for (const pi of indexes) {
            if (onStage) onStage('Making the SVG');
            const r = await exportSvg(deps, { pageIndex: pi, embedFonts: settings.embedFonts !== false, transparent: !!settings.transparent, title: `${design}${indexes.length > 1 ? ` - page ${pi + 1}` : ''}`, signal });
            files.push({ name: buildFilename({ design, page: pageLabel(pages[pi], pi), pageNumber: pi + 1, width: r.width, height: r.height, ext: 'svg' }), mime: r.mime, bytes: r.bytes });
            done += 1; progress(done / indexes.length);
        }
    } else if (fmt === 'pdf') {
        if (onStage) onStage('Making the PDF');
        const printable = project.unit === 'mm' || project.unit === 'in';
        const bleedMm = printable ? settings.bleedMm || 0 : 0;
        const marks = printable ? !!settings.marks : false;
        const common = { indexes, target: settings.pdfTarget || { mode: 'dpi', dpi: 150 }, project, quality: settings.quality || 0.92, signal, onProgress: progress, bleedMm, marks };
        const r = settings.pdfMode === 'picture'
            ? await exportPdf(deps, common)
            : await exportVectorPdf(deps, { indexes, pdfTarget: common.target, project, quality: common.quality, bleedMm, marks, signal, onProgress: progress, onStage });
        messages.push(...r.messages);
        files.push({ name: buildDesignFilename({ design, width: pages[indexes[0]].width, height: pages[indexes[0]].height, ext: 'pdf' }), mime: r.mime, bytes: r.bytes });
    } else if (fmt === 'gif' || fmt === 'webm') {
        const run = fmt === 'gif' ? exportGif : exportWebm;
        const r = await run(deps, { indexes, fps: settings.fps, maxEdge: settings.maxEdge, signal, onProgress: progress, onStage });
        messages.push(...r.messages);
        files.push({ name: buildDesignFilename({ design, width: r.width, height: r.height, ext: fmt }), mime: r.mime, bytes: r.bytes });
    } else {
        throw new ExportError('bad-format', 'That file type is not available.', 'Choose PNG, JPG, PDF, SVG, GIF or video.');
    }

    if (files.length > 1) {
        const zip = createZip(files.map((f) => ({ name: f.name, data: f.bytes })));
        return { files: [{ name: buildDesignFilename({ design, ext: 'zip', suffix: `${files.length}-files` }), mime: 'application/zip', bytes: zip }], inner: files.map((f) => f.name), zip: true, messages, bytes: zip.length };
    }
    return { files, inner: files.map((f) => f.name), zip: false, messages, bytes: files[0].bytes.length };
}

export { concat, estimateGifBytes, resolveTarget };

// ---- estimates (shown before anything is made) ------------------------------------------------------------------

/** JPG size estimate: encode a small sample at the chosen quality and scale by pixels. Honest label: "about". */
export async function estimateJpegBytes(deps, { svg, page, target, quality }) {
    const size = resolveTarget(page, target);
    const sampleW = Math.min(640, size.width);
    const k = sampleW / size.width;
    const sampleH = Math.max(1, Math.round(size.height * k));
    const d = await drawSvgToCanvas(deps, svg, { width: sampleW, height: sampleH, flatten: true });
    const blob = await deps.canvasToBlob(d.canvas, 'image/jpeg', quality);
    disposeCanvas(d.canvas);
    if (!blob) return null;
    // larger pictures compress a little better per pixel than the small sample did
    return Math.round(blob.size * ((size.width * size.height) / (d.width * d.height)) * 0.8);
}

/**
 * GIF size estimate from real frames: one full frame, plus the size of the change between consecutive frames sampled at
 * several moments of the animation (a still start costs nothing, the middle of an entrance costs a lot), extrapolated to the
 * frame count. Always shown as "about".
 */
export async function estimateGifSize(deps, { indexes, fps, maxEdge }) {
    const pages = deps.studio.getState().doc.pages;
    const plan = planAnimation(pages, indexes, { fps, maxEdge });
    const page = pages[indexes[0]];
    const prepared = await prepareSvg(deps, { pageIndex: indexes[0], embedFonts: true, inline: true });
    const D = pageDuration(page);
    const step = 1000 / fps;
    const grab = async (t) => {
        const svg = applyAnimationToSvg(prepared.svg, page, evaluate(page, t));
        const d = await drawSvgToCanvas(deps, svg, { width: plan.width, height: plan.height, flatten: true });
        const data = d.canvas.getContext('2d').getImageData(0, 0, d.width, d.height).data;
        disposeCanvas(d.canvas);
        return { data };
    };
    const fractions = [0.05, 0.2, 0.35, 0.5, 0.65, 0.8, 0.92];
    const deltas = [];
    let full = null;
    for (const f of fractions) {
        const t = Math.max(0, Math.min(D - 2 * step, D * f));
        const a = await grab(t);
        const b = await grab(t + step);
        const single = encodeGif({ width: plan.width, height: plan.height, frames: [a] }).length;
        const pair = encodeGif({ width: plan.width, height: plan.height, frames: [a, b] }).length;
        if (!full || f === 0.5) full = single;
        deltas.push(Math.max(120, pair - single));
    }
    const avg = deltas.reduce((s, v) => s + v, 0) / deltas.length;
    return { bytes: Math.round(full + avg * Math.max(0, plan.frames - 1)), plan };
}

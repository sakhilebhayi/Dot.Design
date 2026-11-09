/*
 * WebM (video) through the browser's own MediaRecorder, feature-detected. There is no fake fallback: when the
 * browser cannot record a canvas, `webmSupport()` says why and the UI offers GIF instead.
 *
 * Frames are rendered first (progress, cancel), then played onto a canvas in real time while MediaRecorder
 * captures it, so the video runs at the true speed even when rendering a frame is slower than a frame interval.
 */
import { ExportError } from './export-svg.js';

const CANDIDATES = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];

export function webmSupport(env = globalThis, canvasProto = null) {
    if (typeof env.MediaRecorder === 'undefined') return { supported: false, mime: null, reason: 'This browser cannot record video from a page. Use the GIF option, or a recent Chrome, Edge or Firefox.' };
    const probeCanvas = canvasProto || (env.document && env.document.createElement ? env.document.createElement('canvas') : null);
    if (!probeCanvas || typeof probeCanvas.captureStream !== 'function') return { supported: false, mime: null, reason: 'This browser cannot capture a canvas as video. Use the GIF option.' };
    const mime = CANDIDATES.find((m) => { try { return env.MediaRecorder.isTypeSupported(m); } catch (e) { return false; } });
    if (!mime) return { supported: false, mime: null, reason: 'This browser cannot make WebM video. Use the GIF option.' };
    return { supported: true, mime, reason: '' };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, Math.max(0, ms)));

/**
 * recordWebm({frames: [{source (canvas|ImageBitmap), delayMs}], width, height, fps, createCanvas, mime, signal, onProgress, bitsPerSecond})
 * -> Promise<Blob>
 */
export async function recordWebm({ frames, width, height, fps = 30, createCanvas, mime, signal, onProgress, bitsPerSecond, env = globalThis }) {
    const support = webmSupport(env);
    if (!support.supported) throw new ExportError('webm-unsupported', support.reason, 'Use the GIF option instead.');
    const hidden = () => typeof document !== 'undefined' && document.hidden === true;
    if (hidden()) throw new ExportError('webm-hidden', 'The video can only be recorded while this tab is in front.', 'Keep this tab open and visible, then try again.');
    const canvas = createCanvas(width, height);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, width, height);
    const stream = canvas.captureStream(fps);
    const recorder = new env.MediaRecorder(stream, { mimeType: mime || support.mime, videoBitsPerSecond: bitsPerSecond || Math.min(12e6, Math.max(1.5e6, width * height * fps * 0.12)) });
    const chunks = [];
    recorder.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
    const stopped = new Promise((resolve, reject) => {
        recorder.onstop = () => resolve();
        recorder.onerror = (e) => reject(new ExportError('webm-record', 'The browser stopped recording the video.', 'Try again, or use the GIF option.', { error: e && e.error && e.error.name }));
    });
    recorder.start(250);
    const t0 = performance.now();
    let at = 0;
    try {
        for (let i = 0; i < frames.length; i += 1) {
            if (signal && signal.aborted) throw new ExportError('cancelled', 'Cancelled.');
            // a hidden tab slows its timers to one a second, which would stretch the video: stop instead of recording slow motion
            if (hidden()) throw new ExportError('webm-hidden', 'The recording stopped because this tab was not in front.', 'Keep this tab open and visible until the video is ready, then try again.');
            await sleep(t0 + at - performance.now());
            ctx.drawImage(frames[i].source, 0, 0, width, height);
            at += frames[i].delayMs;
            if (onProgress) onProgress(i + 1, frames.length);
        }
        await sleep(t0 + at - performance.now() + 60);
    } catch (e) {
        try { recorder.stop(); } catch (x) { /* already stopped */ }
        stream.getTracks().forEach((t) => t.stop());
        throw e;
    }
    recorder.stop();
    stream.getTracks().forEach((t) => t.stop());
    await stopped;
    const blob = new Blob(chunks, { type: (mime || support.mime).split(';')[0] });
    if (!blob.size) throw new ExportError('webm-empty', 'The video came out empty.', 'Try again, or use the GIF option.');
    return blob;
}

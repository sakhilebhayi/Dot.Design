// Glue between the previewer and the page: draw a template into a proof frame when it scrolls into view.
import { mountSvg } from './preview.js';

/** Call `fn` once when `el` is (nearly) on screen. Without IntersectionObserver it runs at once. Returns a stop function. */
export function whenVisible(el, fn, { rootMargin = '200px' } = {}) {
    if (typeof IntersectionObserver === 'undefined') { fn(); return () => {}; }
    const io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) { io.disconnect(); fn(); }
    }, { rootMargin });
    io.observe(el);
    return () => io.disconnect();
}

/** Size a proof frame to the template's true proportions and draw the page into it. */
export async function drawInto(frame, previewer, queue, template, pageIndex = 0, opts = {}) {
    frame.style.setProperty('--w', String(template.width));
    frame.style.setProperty('--h', String(template.height));
    const svg = await queue(() => previewer.svgFor(template, pageIndex, opts));
    mountSvg(frame, svg);
    frame.classList.remove('lt-frame--blank');
    frame.classList.add('is-drawn');
    return svg;
}

/** Load the icon set only when a preview needs it (55 KB, its own chunk). */
let iconsPromise = null;
export function loadIcons() {
    iconsPromise ||= import('../../../../design/icons.json').then((m) => m.default || m).catch(() => null);
    return iconsPromise;
}

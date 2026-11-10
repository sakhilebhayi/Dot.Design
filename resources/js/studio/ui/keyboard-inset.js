/*
 * The on-screen keyboard must not cover what is being typed. Chrome on Android resizes the layout viewport itself
 * (interactive-widget=resizes-content); iOS Safari only shrinks the visual viewport. This reads the visual viewport and
 * lowers the bottom edge of the workspace by the part the keyboard covers (--st-kb), so the stage, the sheets and the
 * page strip all end above the keys. While text is being edited, the stage is panned so the element stays in view.
 */
export function coveredByKeyboard(innerHeight, vvHeight, vvOffsetTop, threshold = 80) {
    const covered = Math.max(0, innerHeight - vvHeight - vvOffsetTop);
    return covered > threshold ? Math.round(covered) : 0;
}

/** How far to pan (negative = up) so a box ending at `bottom` stays inside a stage `visibleHeight` tall, with a margin. */
export function panToReveal(bottom, visibleHeight, margin = 24) {
    return bottom > visibleHeight - margin ? -(bottom - visibleHeight + margin) : 0;
}

export function installKeyboardInset(ctx, root) {
    const vv = typeof window !== 'undefined' ? window.visualViewport : null;
    if (!vv) return;
    const studio = ctx.studio;
    let last = 0;
    const apply = () => {
        const kb = coveredByKeyboard(window.innerHeight, vv.height, vv.offsetTop);
        if (kb === last) return;
        last = kb;
        root.style.setProperty('--st-kb', kb + 'px');
        if (!kb) return;
        // wait for the engine to take the new stage size, then keep the text being edited in view
        setTimeout(() => {
            const s = studio.getState();
            if (!s.editingId) return;
            const page = s.doc.pages.find((p) => p.id === s.activePageId);
            const walk = (list) => { for (const e of list) { if (e.id === s.editingId) return e; if (e.children) { const r = walk(e.children); if (r) return r; } } return null; };
            const el = page && walk(page.elements);
            if (!el) return;
            const stage = studio.root.getBoundingClientRect();
            const bottom = studio.viewport.pageToScreen(el.x, el.y + el.height).y;
            const dy = panToReveal(bottom, stage.height);
            if (dy) studio.viewport.panBy(0, dy);
        }, 120);
    };
    vv.addEventListener('resize', apply);
    vv.addEventListener('scroll', apply);
    apply();
}

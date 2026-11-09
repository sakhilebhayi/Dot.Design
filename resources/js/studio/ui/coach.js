/*
 * A gentle first-run hint: one slim strip above the page, dismissible, remembered in the person's settings. It sits in
 * its own row (the stage starts below it), so it never covers the page. It also goes away on the first click on anything.
 * Never a tutorial. The first saved design is marked once with a toast (see installMilestone).
 */
import { h, icon } from './dom.js';
import { userKey } from './shell.js';

export function installCoach(ctx, shell) {
    if (ctx.readOnly || ctx.settings.onboarded || ctx.mode !== 'simple') return;
    const card = h('aside', { class: 'st-coach', attrs: { 'aria-label': 'A quick tip', role: 'note' } },
        icon('sparkle'),
        h('p', { class: 'st-coach__text' }, h('strong', { text: 'Click anything on the page to change it.' }), h('span', { class: 'st-coach__more', text: ' The bar on the left adds text, pictures and shapes. Your work saves by itself.' })),
        h('button', { class: 'lt-btn lt-btn--sm', attrs: { type: 'button' }, text: 'Got it', onClick: dismiss }));
    function dismiss() { card.remove(); shell.stageWrap.removeAttribute('data-coach'); ctx.saveSetting({ onboarded: true }); ctx.config.settings.onboarded = true; }
    shell.stageWrap.appendChild(card);
    shell.stageWrap.dataset.coach = '1';
    ctx.on('selection', () => { if (ctx.selection().count && card.isConnected) dismiss(); });
}

/**
 * Once per person (in this browser): when the first edit of any design has been saved, say so, with the next step.
 * Honest: it only fires after the engine reports "saved" for a design that has content and a change behind it.
 */
export function installMilestone(ctx) {
    if (ctx.readOnly) return;
    const key = userKey(ctx, 'first-save');
    let done = false;
    try { done = localStorage.getItem(key) === '1'; } catch (e) { /* storage blocked: no milestone */ done = true; }
    if (done) return;
    ctx.on('save-state', ({ state }) => {
        if (done || state !== 'saved') return;
        const st = ctx.studio;
        if (!st.history().canUndo || st.getState().doc.pages.every((p) => !p.elements.length)) return;
        done = true;
        try { localStorage.setItem(key, '1'); } catch (e) { /* storage blocked */ }
        ctx.toast('Your first design is saved. It is safe here, and you can download it whenever you are ready.', { warm: true, ms: 9000, action: ctx.can('export') ? { label: 'Download', onClick: () => ctx.openDialog('export', {}) } : undefined });
    });
}

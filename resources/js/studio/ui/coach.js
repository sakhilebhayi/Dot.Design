/* A gentle first-run hint. One card, dismissible, remembered in the person's settings. Never a tutorial. */
import { h, icon } from './dom.js';

export function installCoach(ctx, shell) {
    if (ctx.readOnly || ctx.settings.onboarded || ctx.mode !== 'simple') return;
    const card = h('aside', { class: 'st-coach', attrs: { 'aria-label': 'A quick tip', role: 'note' } },
        icon('sparkle'),
        h('div', { class: 'st-coach__text' }, h('strong', { text: 'Click anything on the page to change it.' }), h('span', { text: ' Use the panel on the left to add text, pictures and shapes. Your work saves by itself.' })),
        h('button', { class: 'lt-btn lt-btn--sm', attrs: { type: 'button' }, text: 'Got it', onClick: dismiss }));
    function dismiss() { card.remove(); ctx.saveSetting({ onboarded: true }); ctx.config.settings.onboarded = true; }
    shell.stageWrap.appendChild(card);
    ctx.on('selection', () => { if (ctx.selection().count && card.isConnected) dismiss(); });
}

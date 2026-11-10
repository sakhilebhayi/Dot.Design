/*
 * Reliability UX. Nothing here changes the document; it tells the truth about
 * saving and gives the person a way out of every bad state without losing work:
 *   - crash recovery: unsaved work found from last time (restore or discard)
 *   - failed save: a banner with retry, and "your work is kept on this device"
 *   - conflict: reload theirs or overwrite with mine, in plain words
 *   - offline indicator
 *   - expired session: sign in again in another tab, the document stays, saving resumes
 */
import { h, icon, replace } from './dom.js';

export function recoveryDialog() {
    return {
        title: 'We found work that was not saved', size: 'md',
        mount(el, props, ctx) {
            const items = ctx.studio.recovery.list();
            if (!items.length) { el.append(h('p', { text: 'There is nothing left to recover.' })); return null; }
            const fmt = (t) => { try { return new Date(t).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return ''; } };
            const pages = ctx.studio.getState().doc.pages;
            const stale = items.some((i) => i.stale);
            el.append(h('p', { text: 'Last time, changes were made that never reached the server. This can happen if the connection dropped or the window closed.' }),
                h('ul', { class: 'st-recovlist' }, ...items.map((i) => { const idx = pages.findIndex((p) => p.id === i.pageId); return h('li', {}, h('strong', { text: `Page ${idx + 1}` }), ' ', h('span', { class: 'st-note', text: `changed ${fmt(i.at)}` }), i.stale ? h('span', { class: 'lt-tag lt-tag--warn', text: 'A newer version was saved since' }) : null); })));
            if (stale) el.append(h('div', { class: 'st-notebox st-notebox--warn' }, icon('triangle-alert'), h('div', {}, h('strong', { text: 'Restoring replaces a newer saved version.' }), h('p', { text: 'Someone saved this design after your unsaved changes were made. If you restore, their later changes on that page are replaced. A restore point of the current version is not kept, so only restore if you are sure.' }))));
            const restore = h('button', { class: ['lt-btn', stale && 'lt-btn--danger'], attrs: { type: 'button', autofocus: true }, text: stale ? 'Restore my changes anyway' : 'Restore my changes' });
            const discard = h('button', { class: 'lt-btn lt-btn--secondary', attrs: { type: 'button' }, text: 'Discard them' });
            const later = h('button', { class: 'lt-btn lt-btn--ghost', attrs: { type: 'button' }, text: 'Decide later', onClick: () => ctx.closeDialog() });
            restore.addEventListener('click', () => {
                const r = ctx.studio.recovery.apply(items.map((i) => i.pageId), { allowStale: true });
                if (r.ok) { ctx.toast('Your changes are back. They save again in a moment.', { action: { label: 'Undo', onClick: () => ctx.studio.undo() } }); ctx.closeDialog(); } else ctx.toast(r.error ? r.error.message : 'They could not be restored.', { kind: 'bad' });
            });
            discard.addEventListener('click', async () => {
                const ok = await ctx.confirm({ title: 'Discard the unsaved work?', body: 'It is deleted from this device and cannot be brought back.', confirmLabel: 'Discard', danger: true });
                if (ok) { ctx.studio.recovery.discard(items.map((i) => i.pageId)); ctx.closeDialog(); ctx.toast('Discarded.'); }
            });
            el.append(h('div', { class: 'lt-dialog__foot st-dialog__foot' }, later, discard, restore));
            return null;
        },
    };
}

export function conflictDialog() {
    return {
        title: 'This design was changed somewhere else', size: 'md',
        mount(el, props, ctx) {
            el.append(h('p', { text: 'Someone else, or you in another window, saved this design after you opened it. To protect their work, we have stopped saving yours. Choose what should happen.' }),
                h('div', { class: 'st-choices' },
                    h('div', { class: 'st-choice' }, h('strong', { text: 'Load the latest version' }), h('p', { text: 'You see what was saved. The changes you made since opening are thrown away.' }), h('button', { class: 'lt-btn lt-btn--secondary', attrs: { type: 'button' }, text: 'Load the latest', onClick: () => { ctx.allowUnload(); ctx.studio.save.resolveReload(); } })),
                    h('div', { class: 'st-choice' }, h('strong', { text: 'Keep my version' }), h('p', { text: 'Your page is saved over theirs. Their changes on this page are replaced. We save their version as a restore point first, so it can be brought back.' }), h('button', { class: 'lt-btn lt-btn--danger', attrs: { type: 'button' }, text: 'Save mine over theirs', onClick: async () => {
                        const ok = await ctx.confirm({ title: 'Replace their changes?', body: 'Their changes on this page will be replaced by yours. We save their version as a restore point first.', confirmLabel: 'Replace', danger: true });
                        if (!ok) return;
                        // Their version is what the server holds right now: keep it before it is overwritten.
                        const cp = await ctx.api.post(ctx.url('design.projects.versions.store'), { label: 'Their version, before I saved mine' });
                        if (!cp.ok) { ctx.toast('We could not save a restore point, so nothing was replaced. ' + (cp.message || ''), { kind: 'bad' }); return; }
                        ctx.studio.save.resolveOverwrite(); ctx.closeDialog();
                    } }))));
            return null;
        },
    };
}

export function sessionDialog() {
    return {
        title: 'Sign in again to keep saving', size: 'md',
        mount(el, props, ctx) {
            const login = ctx.config.urls.login || '/login';
            const status = h('p', { class: 'st-note', attrs: { role: 'status', 'aria-live': 'polite' }, text: 'Waiting for you to sign in.' });
            el.append(h('p', { text: 'Your session ended, so changes are not reaching the server. Nothing is lost: your design stays open here and your work is kept on this device.' }),
                h('ol', { class: 'st-steps' }, h('li', { text: 'Open the sign-in page in a new tab.' }), h('li', { text: 'Sign in, then come back to this tab.' }), h('li', { text: 'We will notice and save your work.' })),
                h('a', { class: 'lt-btn', href: login, target: '_blank', rel: 'noopener' }, icon('external'), 'Open sign-in in a new tab'), status);
            let stop = false;
            async function poll() {
                if (stop) return;
                const r = await ctx.api.get(ctx.url('design.settings.show'));
                if (r.ok) { stop = true; status.textContent = 'Signed in. Saving your work.'; ctx.emit('session-restored'); return; }
                setTimeout(poll, 4000);
            }
            const onVis = () => { if (document.visibilityState === 'visible') poll(); };
            document.addEventListener('visibilitychange', onVis);
            setTimeout(poll, 3000);
            return { destroy() { stop = true; document.removeEventListener('visibilitychange', onVis); } };
        },
    };
}

export function installReliability(ctx, shell) {
    const st = ctx.studio;
    const banner = shell.banner;
    let sessionLost = false;
    let conflictShown = false;

    function paint() {
        const state = st.save.state; const info = st.save.info || {};
        const online = typeof navigator === 'undefined' || navigator.onLine !== false;
        let spec = null;
        if (sessionLost) spec = { kind: 'bad', icon: 'triangle-alert', title: 'You are signed out.', text: 'Your work is kept on this device, but it is not reaching the server.', action: ['Sign in again', () => ctx.openDialog('session-expired')] };
        else if (state === 'conflict') spec = { kind: 'bad', icon: 'triangle-alert', title: 'This design was changed somewhere else.', text: 'Saving is paused so nothing is overwritten.', action: ['Choose what to do', () => ctx.openDialog('conflict')] };
        else if (state === 'failed') spec = { kind: 'bad', icon: 'circle-alert', title: 'Your latest changes are not saved.', text: `${info.message || 'Saving failed.'} Your work is kept on this device.${info.retryAt ? ' We are trying again automatically.' : ''}`, action: ['Try again', () => st.save.retry()] };
        else if (state === 'offline' || !online) spec = { kind: 'warn', icon: 'triangle-alert', title: 'You are offline.', text: 'You can keep working. Changes are kept on this device and save when you are back online.' };
        banner.hidden = !spec;
        if (!spec) { banner.replaceChildren(); return; }
        banner.dataset.kind = spec.kind;
        replace(banner, icon(spec.icon), h('div', { class: 'st-banner__text', attrs: { role: 'status' } }, h('strong', { text: spec.title + ' ' }), h('span', { text: spec.text })), spec.action ? h('button', { class: 'lt-btn lt-btn--sm lt-btn--secondary', attrs: { type: 'button' }, text: spec.action[0], onClick: spec.action[1] }) : null);
    }

    ctx.registerDialog('recovery', recoveryDialog());
    ctx.registerDialog('conflict', conflictDialog());
    ctx.registerDialog('session-expired', sessionDialog());

    ctx.on('save-state', ({ state, info }) => {
        paint();
        if (state === 'conflict' && !conflictShown) { conflictShown = true; ctx.openDialog('conflict'); }
        if (state !== 'conflict') conflictShown = false;
        if (info && state === 'failed' && /session has expired|sign in/i.test(info.message || '')) { sessionLost = true; paint(); ctx.openDialog('session-expired'); }
        if (state === 'saved' && sessionLost) { sessionLost = false; paint(); }
    });
    ctx.on('conflict-open', () => ctx.openDialog('conflict'));
    ctx._hooks.onSession = () => { if (sessionLost) return; sessionLost = true; paint(); if (!document.querySelector('dialog[open]')) ctx.openDialog('session-expired'); };
    ctx.on('session-restored', () => { sessionLost = false; ctx.closeDialog(); paint(); st.save.retry(); ctx.toast('Signed in again. Saving your work.'); });
    window.addEventListener('online', paint); window.addEventListener('offline', paint);

    // crash recovery: the engine finds a journal of unsaved pages when it opens
    let recoveryShown = false;
    const showRecovery = () => { if (recoveryShown || !st.recovery.list().length) return; recoveryShown = true; ctx.openDialog('recovery'); };
    st.on('recoverable', showRecovery);
    setTimeout(showRecovery, 50);
    paint();
}

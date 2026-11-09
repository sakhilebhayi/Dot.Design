/*
 * Review and approval (R-45 collab).
 *
 *  - Status chip: shown on the canvas (and on the topbar action when the shell lets a registered action change its label).
 *  - Request review: pick a colleague whose role can approve, add a note. A copy of the design as it is NOW is saved and
 *    sent; the reviewer decides on that copy, not on later edits (said in the dialog).
 *  - Reviewer: a bar with Approve and Request changes (a note is required to ask for changes) and "Compare with now".
 *  - Requester: Cancel request. After approval: Download final (opens the export dialog).
 *  - Edits after approval: the server marks the approval stale; the banner says so and offers a new review.
 *  - Every refusal the server gives (separation of duties, wrong reviewer, already closed) is shown in plain words.
 *
 * What the server decides is never second-guessed here: buttons are only offered when the rules allow them, and the
 * server's answer always wins.
 */
import '../../../../css/studio-collab.css';
import { h, clear } from '../../collab/dom.js';
import { busyWhile, button, empty, field, notice, select, skeleton, tag, textarea } from '../../collab/ui.js';
import { can, collabUrls } from '../../collab/urls.js';
import { explain } from '../../collab/errors.js';
import { peopleStore } from '../../collab/people.js';
import { deriveReviewState, requestNotice, reviewerChoices } from '../../collab/review-state.js';
import { dateTime, timeAgo } from '../../collab/format.js';

export function register(ctx) {
    if (!can(ctx.config, 'view')) return;
    const controller = createReviewController(ctx);

    ctx.registerDialog('review.request', { title: 'Request a review', size: 'md', mount: (el, props, c) => controller.mountRequest(el, props || {}, c || ctx) });
    ctx.registerDialog('review.decide', { title: 'Your answer', size: 'md', mount: (el, props, c) => controller.mountDecide(el, props || {}, c || ctx) });
    ctx.registerDialog('review.status', { title: 'Review', size: 'md', mount: (el, props, c) => controller.mountStatus(el, props || {}, c || ctx) });

    const action = ctx.registerTopbarAction({
        id: 'review',
        label: 'Review',
        icon: 'badge-check',
        order: 85,
        modes: ['simple', 'pro'],
        onClick: (c) => {
            const k = controller.derived();
            (c || ctx).openDialog(k && k.canRequest ? 'review.request' : 'review.status');
        },
    });
    controller.onChange((d) => {
        // Optional shell handle: show the status on the topbar action itself.
        if (action && typeof action.update === 'function') action.update({ label: d.key === 'draft' ? 'Review' : d.label, tone: d.tone });
    });
    controller.start();
}

export function createReviewController(ctx) {
    const urls = collabUrls(ctx.config);
    const people = peopleStore(ctx);
    const user = ctx.config.user || {};
    const state = { reviews: [], status: 'idle', error: null, people: [] };
    const listeners = new Set();
    let derivedCache = null;
    let bar = null;
    let started = false;
    let timer = null;
    let offs = [];

    const derived = () => derivedCache;

    function recompute() {
        const data = people.cached;
        const me = data && data.people.find((p) => p.is_you);
        derivedCache = deriveReviewState({
            project: ctx.config.project,
            reviews: state.reviews,
            user,
            permissions: { review: can(ctx.config, 'review'), approve: can(ctx.config, 'approve') },
            role: me ? me.role : null,
            memberCount: data ? data.people.length : 2,
        });
        return derivedCache;
    }

    async function load() {
        state.status = state.reviews.length ? 'ready' : 'loading';
        const [res] = await Promise.all([ctx.api.get(urls.reviews), people.load()]);
        if (res.ok && res.data) {
            state.reviews = res.data.reviews || [];
            state.status = 'ready';
            state.error = null;
            recompute();
        } else {
            state.status = 'error';
            state.error = explain(res, { what: 'load the review' });
            recompute();
        }
        paintBar();
        for (const fn of listeners) fn(derivedCache);
    }

    function start() {
        if (started) return;
        started = true;
        recompute();
        ensureBar();
        load();
        try {
            offs.push(ctx.studio.on('save-state', (e) => {
                // An approval goes stale only when an edit is saved, so look again shortly after a save while approved.
                if (e && e.state === 'saved' && derivedCache && derivedCache.key === 'approved') { clearTimeout(timer); timer = setTimeout(load, 1200); }
            }));
        } catch (e) { /* no engine yet: the focus refresh below still keeps it honest */ }
        window.addEventListener('focus', onFocus);
    }
    const onFocus = () => { if (started) load(); };

    // ------------------------------------------------------------------ canvas bar
    function ensureBar() {
        if (bar) return bar;
        let root = null;
        try { root = ctx.studio && ctx.studio.root; } catch (e) { root = null; }
        if (!root) return null;
        const el = h('div', { class: 'cb-revbar', 'data-studio-ignore': '', hidden: true });
        for (const type of ['pointerdown', 'pointerup', 'dblclick', 'contextmenu', 'keydown']) el.addEventListener(type, (e) => e.stopPropagation());
        root.append(el);
        bar = el;
        return bar;
    }

    function paintBar() {
        const el = ensureBar();
        const d = derivedCache;
        if (!el || !d) return;
        clear(el);
        if (d.key === 'draft' && !d.banner) { el.hidden = true; return; }
        const chip = h('button', { type: 'button', class: `cb-statuschip cb-statuschip--${d.tone}`, onclick: () => ctx.openDialog('review.status'), 'aria-label': `Review status: ${d.label}. Open details` }, h('span', { class: 'cb-dot', 'aria-hidden': 'true' }), d.label);
        const parts = [chip];

        if (d.canDecide) {
            const who = (d.review.requester && d.review.requester.name) || 'A colleague';
            parts.push(h('span', { class: 'cb-revbar__text', text: `${who} asked you to review the version they sent.` }),
                button({ label: 'Approve', variant: 'primary', size: 'sm', onClick: () => ctx.openDialog('review.decide', { mode: 'approve' }) }),
                button({ label: 'Request changes', variant: 'secondary', size: 'sm', onClick: () => ctx.openDialog('review.decide', { mode: 'changes' }) }),
                d.pinnedVersionId ? button({ label: 'Compare with now', variant: 'ghost', size: 'sm', onClick: () => ctx.openDialog('versions.view', { versionId: d.pinnedVersionId }) }) : null);
        } else if (d.banner) {
            parts.push(h('span', { class: 'cb-revbar__text', text: d.banner.text }));
            if (d.banner.note) parts.push(h('span', { class: 'cb-revbar__note', text: d.banner.note }));
            if (d.key === 'stale' || d.key === 'changes_requested') {
                if (d.canRequest) parts.push(button({ label: 'Request a new review', variant: 'primary', size: 'sm', onClick: () => ctx.openDialog('review.request') }));
            }
            if (d.canDownloadFinal && can(ctx.config, 'export')) parts.push(button({ label: 'Download final', variant: 'secondary', size: 'sm', onClick: () => ctx.openDialog('export') }));
        }
        el.append(...parts.filter(Boolean));
        el.hidden = false;
        el.classList.toggle('is-attention', d.canDecide || d.key === 'stale');
    }

    // ------------------------------------------------------------------ dialogs
    function pinnedLine(d) {
        const v = d.review && d.review.version;
        const when = d.review && d.review.created_at ? ` on ${dateTime(d.review.created_at)}` : '';
        return v ? `The version sent${when}` : '';
    }

    function mountRequest(el, props, c) {
        let destroyed = false;
        const root = h('div', { class: 'cb-review' });
        el.append(root);
        root.append(skeleton(3));

        (async () => {
            const r = await people.load(true);
            if (destroyed) return;
            clear(root);
            if (!r.ok) { const x = explain(r.res, { what: 'load the people in this workspace' }); root.append(notice(x.text, x.kind)); return; }
            recompute();
            const choices = reviewerChoices(r.data.people, user.id);
            if (!choices.length) {
                root.append(empty('Nobody can approve yet', 'Only workspace owners, admins and reviewers can approve a design, and you cannot approve your own. Add one in the workspace settings, then ask again.',
                    r.data.workspace && r.data.workspace.url ? { label: 'Open workspace settings', onClick: () => { window.location.href = r.data.workspace.url; } } : null));
                return;
            }
            const solo = r.data.people.length <= 1;
            const reviewer = select(choices.map((p) => ({ value: String(p.id), label: `${p.name} (${p.role_label.toLowerCase()})` })), { value: String(choices[0].id) });
            const note = textarea({ rows: 4, maxlength: 2000, placeholder: 'What should they look at? Anything they should know?' });
            const err = h('div', { class: 'cb-formerr' });
            const submit = button({ label: 'Send for review', variant: 'primary', type: 'submit' });
            const form = h('form', { class: 'cb-form', novalidate: true },
                h('p', { class: 'cb-pinned' }, 'A copy of the design as it is now is saved and sent. The reviewer judges that copy, so edits you make afterwards do not change what they see. If the design changes after it is approved, the approval no longer applies.'),
                solo ? h('p', { class: 'cb-hint', text: 'You are the only person in this workspace, so you review your own work.' }) : null,
                field('Who should review it', reviewer),
                field('Note for the reviewer (optional)', note),
                err,
                h('div', { class: 'cb-actions' }, submit, button({ label: 'Cancel', variant: 'ghost', onClick: () => ctx.closeDialog() })));
            root.append(form);
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                clear(err);
                // Unsaved edits are saved first, so the pinned copy is what the person sees on screen.
                try { if (ctx.studio && ctx.studio.save && ctx.studio.save.flush) await ctx.studio.save.flush(); } catch (x) { /* the server copy is used */ }
                const res = await busyWhile(submit, ctx.api.post(urls.reviews, { reviewer_id: Number(reviewer.value), note: note.value.trim() || null }), 'Sending...');
                if (destroyed) return;
                if (res.ok && res.data && res.data.review) {
                    const picked = choices.find((p) => String(p.id) === reviewer.value);
                    const notifyRes = await ctx.api.get(urls.integrations);
                    const notify = notifyRes.ok && notifyRes.data && Array.isArray(notifyRes.data.integrations) ? notifyRes.data.integrations.find((i) => i.key === 'notify') : null;
                    clear(root);
                    root.append(h('div', { class: 'cb-done' }, h('h3', { class: 'cb-h', text: 'Review requested' }), h('p', { text: requestNotice(picked && picked.name, notify) }),
                        h('div', { class: 'cb-actions' }, button({ label: 'Done', variant: 'primary', onClick: () => ctx.closeDialog() }))));
                    await load();
                } else {
                    const x = explain(res, { what: 'send the review', review: true });
                    err.append(notice(x.text, x.kind));
                    if (res.status === 409) load();
                }
            });
        })();
        return { destroy() { destroyed = true; clear(el); } };
    }

    function mountDecide(el, props) {
        const d = recompute();
        const root = h('div', { class: 'cb-review' });
        el.append(root);
        if (!d.canDecide) {
            root.append(notice(d.ownRequest ? 'You asked for this review, so a colleague has to answer it.' : 'This review is not waiting for your answer. Reload to see where it stands.', 'warn'),
                h('div', { class: 'cb-actions' }, button({ label: 'Close', variant: 'secondary', onClick: () => ctx.closeDialog() })));
            return { destroy() { clear(el); } };
        }
        const approving = props.mode !== 'changes';
        const review = d.review;
        const note = textarea({ rows: 4, maxlength: 2000, placeholder: approving ? 'Anything to add? (optional)' : 'What needs to change?' });
        const f = field(approving ? 'Note (optional)' : 'What needs to change', note);
        const err = h('div', { class: 'cb-formerr' });
        const submit = button({ label: approving ? 'Approve' : 'Send changes', variant: 'primary', type: 'submit' });
        const form = h('form', { class: 'cb-form', novalidate: true },
            h('p', { class: 'cb-pinned' }, `${pinnedLine(d) || 'The version that was sent'} is what you are answering about. ${approving ? 'If the design changes after you approve, the approval no longer applies.' : 'The author will see your note.'}`),
            d.pinnedVersionId ? button({ label: 'Compare that version with now', variant: 'ghost', size: 'sm', onClick: () => ctx.openDialog('versions.view', { versionId: d.pinnedVersionId }) }) : null,
            review && review.note ? h('blockquote', { class: 'cb-quote' }, h('span', { class: 'cb-quote__who', text: `${(review.requester && review.requester.name) || 'The author'} wrote` }), h('p', { text: review.note })) : null,
            f, err,
            h('div', { class: 'cb-actions' }, submit, button({ label: 'Cancel', variant: 'ghost', onClick: () => ctx.closeDialog() })));
        root.append(form);
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clear(err); f.setError('');
            if (!approving && !note.value.trim()) { f.setError('Say what needs to change so the author knows what to do.'); note.focus(); return; }
            const res = await busyWhile(submit, ctx.api.post(urls.review(review.id, approving ? 'approve' : 'request-changes'), { note: note.value.trim() || null }), 'Sending...');
            if (res.ok) {
                ctx.toast(approving ? 'Approved.' : 'Changes requested. The author will see your note.', { kind: 'ok' });
                ctx.closeDialog();
                load();
            } else {
                const x = explain(res, { what: 'send your answer', review: true });
                if (res.status === 422 && x.field === 'note') f.setError(x.text); else err.append(notice(x.text, x.kind));
                if (res.status === 409 || res.status === 403) load();
            }
        });
        return { destroy() { clear(el); } };
    }

    function mountStatus(el) {
        let destroyed = false;
        const root = h('div', { class: 'cb-review' });
        el.append(root);
        const paint = () => {
            if (destroyed) return;
            const d = recompute();
            clear(root);
            if (state.status === 'loading') { root.append(skeleton(3)); return; }
            if (state.status === 'error') { root.append(notice(state.error.text, state.error.kind, { action: { label: 'Try again', onClick: () => load().then(paint) } })); return; }
            const r = d.review;
            root.append(h('div', { class: 'cb-statushead' }, h('span', { class: `cb-statuschip cb-statuschip--${d.tone}` }, h('span', { class: 'cb-dot', 'aria-hidden': 'true' }), d.label)));
            const lines = {
                draft: 'This design is a draft. Ask a colleague to review it when it is ready.',
                in_review: d.canDecide ? 'This design is waiting for your answer.' : `Waiting for ${(r.reviewer && r.reviewer.name) || 'the reviewer'} to answer. They are judging the copy saved when you sent it.`,
                changes_requested: `${(r && r.reviewer && r.reviewer.name) || 'The reviewer'} asked for changes. Make them, then ask for a new review.`,
                approved: `${(r && r.reviewer && r.reviewer.name) || 'The reviewer'} approved the version you sent. If the design changes, the approval no longer applies.`,
                stale: 'This design changed after it was approved. Ask for a new review.',
                archived: 'This design is archived. Make it a draft again before asking for a review.',
            };
            root.append(h('p', { text: lines[d.key] }));
            if (d.ownRequest) root.append(notice('You asked for this review, so a colleague has to answer it.', 'warn'));
            if (r && r.decision_note) root.append(h('blockquote', { class: 'cb-quote' }, h('span', { class: 'cb-quote__who', text: `${(r.reviewer && r.reviewer.name) || 'The reviewer'} wrote` }), h('p', { text: r.decision_note })));
            if (r && r.note && d.key === 'in_review') root.append(h('blockquote', { class: 'cb-quote' }, h('span', { class: 'cb-quote__who', text: `${(r.requester && r.requester.name) || 'The author'} wrote` }), h('p', { text: r.note })));
            const actions = h('div', { class: 'cb-actions' });
            if (d.canDecide) actions.append(button({ label: 'Approve', variant: 'primary', onClick: () => ctx.openDialog('review.decide', { mode: 'approve' }) }), button({ label: 'Request changes', variant: 'secondary', onClick: () => ctx.openDialog('review.decide', { mode: 'changes' }) }));
            if (d.canRequest) actions.append(button({ label: d.key === 'draft' ? 'Request a review' : 'Request a new review', variant: 'primary', onClick: () => ctx.openDialog('review.request') }));
            if (d.canCancel) {
                const cancel = button({ label: 'Cancel request', variant: 'secondary', onClick: async () => {
                    const ok = await ctx.confirm({ title: 'Cancel this review request?', body: 'The reviewer will no longer be asked to answer it. You can ask again at any time.', confirmLabel: 'Cancel request', danger: true });
                    if (!ok) return;
                    const res = await busyWhile(cancel, ctx.api.post(urls.review(r.id, 'cancel'), {}), 'Cancelling...');
                    if (res.ok) { ctx.toast('Review cancelled.', { kind: 'ok' }); await load(); paint(); }
                    else { const x = explain(res, { what: 'cancel the review', review: true }); ctx.toast(x.text, { kind: x.kind }); load().then(paint); }
                } });
                actions.append(cancel);
            }
            if (d.canDownloadFinal && can(ctx.config, 'export')) actions.append(button({ label: 'Download final', variant: 'secondary', onClick: () => ctx.openDialog('export') }));
            if (d.pinnedVersionId) actions.append(button({ label: 'Compare the sent version with now', variant: 'ghost', onClick: () => ctx.openDialog('versions.view', { versionId: d.pinnedVersionId }) }));
            if (actions.children.length) root.append(actions);

            const history = state.reviews.slice().sort((a, b) => b.id - a.id);
            if (history.length > 1 || (history.length === 1 && history[0].id !== (r && r.id))) {
                root.append(h('h4', { class: 'cb-h cb-h--sub', text: 'Earlier requests' }));
                const ul = h('ul', { class: 'cb-versions' });
                for (const item of history.slice(r ? 1 : 0, 6)) {
                    ul.append(h('li', { class: 'cb-version' }, h('div', { class: 'cb-version__main' },
                        h('span', { class: 'cb-version__title' }, (item.reviewer && item.reviewer.name) || 'A colleague', ' ', tag(statusWord(item.status), item.status === 'approved' ? 'ok' : item.status === 'pending' ? 'accent' : 'neutral')),
                        h('span', { class: 'cb-version__meta', text: `Asked by ${(item.requester && item.requester.name) || 'a colleague'}, ${timeAgo(item.created_at)}` }))));
                }
                root.append(ul);
            }
        };
        paint();
        load().then(paint);
        return { destroy() { destroyed = true; clear(el); } };
    }

    return {
        start, derived,
        mountRequest, mountDecide, mountStatus,
        onChange(fn) { listeners.add(fn); if (derivedCache) fn(derivedCache); },
        reload: load,
        destroy() { offs.forEach((o) => { try { o(); } catch (e) { /* ignore */ } }); window.removeEventListener('focus', onFocus); clearTimeout(timer); if (bar) bar.remove(); bar = null; started = false; },
    };
}

function statusWord(s) {
    return ({ pending: 'Waiting', approved: 'Approved', changes_requested: 'Changes requested', cancelled: 'Cancelled', stale: 'Changed after approval' })[s] || s;
}

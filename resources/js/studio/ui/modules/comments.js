/*
 * Comments (R-45 collab): the "Comments" left tab and the pins on the canvas.
 *
 *  - A pin is a numbered marker over the stage. It is positioned from page coordinates through the engine's viewport
 *    (studio.viewport.view: {zoom, x, y}) and redrawn on every 'viewport', 'change' and 'page' event, so it stays on
 *    its spot while you zoom, pan, move or rotate the element it is pinned to (see collab/pins.js for the convention).
 *  - Add a comment: choose "Add comment" (or press C), then click the design. With one element selected and the click inside
 *    it, the comment is pinned to that element; otherwise to the point.
 *  - People who can comment but not edit (reviewers, viewers with a comment grant) get the same panel and pins.
 *  - Nothing live: the list refreshes when the tab is opened, after your own actions, and when the window regains focus.
 */
import '../../../../css/studio-collab.css';
import { h, clear } from '../../collab/dom.js';
import { busyWhile, button, empty, notice, skeleton } from '../../collab/ui.js';
import { can, collabUrls } from '../../collab/urls.js';
import { explain } from '../../collab/errors.js';
import { peopleStore } from '../../collab/people.js';
import { countThreads, filterThreads, remove, upsert } from '../../collab/comments-state.js';
import { anchorForClick, anchorForElement, elementScreenQuad, layoutMarkers, leader, markerHome, numberThreads, pinPagePoint, pinScreen, popoverPosition, quadBounds, screenToPage } from '../../collab/pins.js';
import { switchControl } from './_controls.js';
import { createComposer, threadCard } from '../../collab/comment-ui.js';

export function register(ctx) {
    if (!can(ctx.config, 'view')) return;
    const controller = createController(ctx);

    const tab = ctx.registerLeftTab({
        id: 'comments',
        label: 'Comments',
        icon: 'comment',
        order: 50,
        modes: ['simple', 'pro'],
        mount(el, c) { return controller.mountPanel(el, c || ctx); },
    });
    controller.onCount((n) => {
        // Optional handle from the shell: a count on the tab. Without it the count is shown inside the panel.
        if (tab && typeof tab.setBadge === 'function') tab.setBadge(n > 0 ? n : null);
    });

    if (can(ctx.config, 'comment')) {
        ctx.registerShortcut({ keys: 'c', label: 'Add a comment', run: () => controller.startPlacing() });
        ctx.registerContextAction({
            id: 'comment-on-element',
            label: 'Comment on this',
            icon: 'comment',
            when: (sel) => can(ctx.config, 'comment') && selectedIds(sel, ctx).length === 1,
            run: () => controller.commentOnSelection(),
        });
    }
    controller.start();
}

function selectedIds(sel, ctx) {
    if (Array.isArray(sel)) return sel;
    if (sel && Array.isArray(sel.ids)) return sel.ids;
    try { return ctx.studio.getState().selection || []; } catch (e) { return []; }
}

/** Exposed for the tests of the module's wiring; the rest of the logic is in collab/*.js. */
export function createController(ctx) {
    const urls = collabUrls(ctx.config);
    const people = peopleStore(ctx);
    const canComment = can(ctx.config, 'comment');
    const state = { threads: [], load: 'idle', error: null, filter: { status: 'open', scope: 'all' }, placing: false, showPins: true, active: null, draft: null };
    const countListeners = new Set();
    const panels = new Set();
    let overlay = null;
    let started = false;
    let raf = 0;
    let studioOff = [];
    let peopleList = [];

    const studio = () => ctx.studio;
    const activePageId = () => { try { return studio().getState().activePageId; } catch (e) { return null; } };
    const activePage = () => { try { return studio().activePage(); } catch (e) { return null; } };
    const peopleById = () => new Map(peopleList.map((p) => [p.id, p]));
    const pageName = (pageId) => {
        try {
            const pages = studio().getState().doc.pages;
            const i = pages.findIndex((p) => String(p.id) === String(pageId));
            return i >= 0 ? `Page ${i + 1}` : 'A page that was removed';
        } catch (e) { return ''; }
    };

    // ------------------------------------------------------------------ data
    let inflight = false;
    async function load() {
        if (inflight) return;
        inflight = true;
        if (state.load !== 'ready') { state.load = 'loading'; notify(); }
        const [res, ppl] = await Promise.all([ctx.api.get(urls.comments), people.load()]);
        if (ppl.ok) peopleList = ppl.data.people;
        if (res.ok && res.data) { state.threads = res.data.comments || []; state.load = 'ready'; state.error = null; }
        else if (state.load !== 'ready') { state.load = 'error'; state.error = explain(res, { what: 'load the comments' }); }
        inflight = false;
        notify();
    }

    function replaceThreads(next) { state.threads = next; notify(); }

    async function post(payload) {
        const res = await ctx.api.post(urls.comments, payload);
        if (res.ok && res.data && res.data.comment) { replaceThreads(upsert(state.threads, res.data.comment)); return { ok: true, comment: res.data.comment }; }
        return { ok: false, res };
    }

    const env = (extra = {}) => ({
        people: peopleList,
        peopleById: peopleById(),
        canComment,
        number: 0,
        active: false,
        pageLabel: (t) => (t.page_id ? pageName(t.page_id) : ''),
        async onReply(thread, p) { const r = await post({ body: p.body, mentions: p.mentions, parent_id: thread.id }); if (r.ok) refreshPopover(); return r; },
        async onEdit(comment, p) {
            const res = await ctx.api.put(urls.comment(comment.id), { body: p.body, mentions: p.mentions });
            if (res.ok && res.data && res.data.comment) { replaceThreads(upsert(state.threads, { ...res.data.comment, replies: state.threads.find((t) => t.id === comment.id)?.replies || [] })); refreshPopover(); return { ok: true }; }
            return { ok: false, res };
        },
        async onDelete(comment) {
            const isThread = !comment.parent_id;
            const ok = await ctx.confirm({ title: isThread ? 'Delete this conversation?' : 'Delete this reply?', body: isThread ? 'The comment and every reply under it will be removed for everyone.' : 'The reply will be removed for everyone.', confirmLabel: 'Delete', danger: true });
            if (!ok) return;
            const res = await ctx.api.del(urls.comment(comment.id));
            if (res.ok) { replaceThreads(remove(state.threads, comment.id)); if (state.active === comment.id && isThread) closePopover(); else refreshPopover(); }
            else { const e = explain(res, { what: 'delete the comment' }); ctx.toast(e.text, { kind: e.kind }); }
        },
        async onResolve(thread, resolve, btn) {
            const res = await busyWhile(btn, resolve ? ctx.api.post(urls.resolve(thread.id), {}) : ctx.api.del(urls.resolve(thread.id)));
            if (res.ok && res.data && res.data.comment) { replaceThreads(upsert(state.threads, { ...res.data.comment, replies: thread.replies })); refreshPopover(); }
            else { const e = explain(res, { what: resolve ? 'resolve the conversation' : 'reopen the conversation' }); ctx.toast(e.text, { kind: e.kind }); }
        },
        onFocus(thread) { focusThread(thread.id, { fromList: true }); },
        ...extra,
    });

    // ------------------------------------------------------------------ canvas overlay
    function ensureOverlay() {
        if (overlay) return overlay;
        let root = null;
        try { root = studio() && studio().root; } catch (e) { root = null; }
        if (!root) return null;
        const layer = h('div', { class: 'cb-pins', 'data-studio-ignore': '' });
        const pinsEl = h('div', { class: 'cb-pins__layer' });
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'cb-pins__svg'); svg.setAttribute('aria-hidden', 'true');
        const tip = h('div', { class: 'cb-tip', hidden: true, role: 'tooltip' });
        const pop = h('div', { class: 'cb-pop', hidden: true, role: 'dialog', 'aria-label': 'Comment' });
        const toggle = h('button', { type: 'button', class: 'cb-pintoggle', hidden: true, onclick: () => setShowPins(!state.showPins), onpointerdown: (e) => e.stopPropagation() });
        layer.append(svg, pinsEl, tip, pop, toggle);
        for (const type of ['pointerdown', 'pointerup', 'pointermove', 'wheel', 'dblclick', 'contextmenu', 'keydown']) {
            pop.addEventListener(type, (e) => { if (type !== 'wheel' || pop.scrollHeight > pop.clientHeight) e.stopPropagation(); });
        }
        layer.addEventListener('pointerdown', onStagePointer);
        root.append(layer);
        overlay = { root, layer, pinsEl, pop, svg, tip, toggle };
        return overlay;
    }

    function onStagePointer(e) {
        if (!state.placing || e.target !== overlay.layer || e.button > 0) return;
        e.preventDefault(); e.stopPropagation();
        const rect = overlay.root.getBoundingClientRect();
        const view = studio().viewport.view;
        const p = screenToPage(view, e.clientX - rect.left, e.clientY - rect.top);
        const page = activePage();
        const sel = studio().getState().selection || [];
        const anchor = anchorForClick(page, p.x, p.y, sel.length === 1 ? sel[0] : null);
        setPlacing(false);
        openDraft({ page_id: page ? page.id : activePageId(), ...anchor });
    }

    function setPlacing(on) {
        state.placing = on && canComment;
        if (overlay) overlay.layer.classList.toggle('is-placing', state.placing);
        if (state.placing) ctx.toast('Click the design where the comment belongs. Press Escape to cancel.', { kind: 'ok' });
        notify();
    }

    function openDraft(target) {
        state.draft = target; state.active = null;
        draw();
        showPopover();
    }

    function closePopover() {
        state.active = null; state.draft = null;
        if (overlay) { overlay.pop.hidden = true; clear(overlay.pop); }
        draw();
        notify();
    }

    function focusThread(id, { fromList = false } = {}) {
        const thread = state.threads.find((t) => t.id === id);
        if (!thread) return;
        state.draft = null;
        state.active = id;
        // Jump to the page the pin is on, then show it.
        if (thread.page_id && String(thread.page_id) !== String(activePageId()) && studio().setActivePage) studio().setActivePage(thread.page_id);
        ensureOverlay();
        draw();
        showPopover();
        notify();
        if (!fromList && overlay) { const b = overlay.pinsEl.querySelector(`[data-thread="${id}"]`); if (b) b.focus(); }
    }

    function refreshPopover() { if (state.active && !state.draft && overlay && !overlay.pop.hidden) showPopover(); }

    function popoverAnchor() {
        const page = activePage();
        if (state.draft) return pinPagePointForDraft(page);
        const t = state.threads.find((x) => x.id === state.active);
        return t ? pinPagePoint(t, page) : null;
    }
    function pinPagePointForDraft(page) { return pinPagePoint({ x: state.draft.x, y: state.draft.y, element_id: state.draft.element_id }, page); }

    function showPopover() {
        if (!overlay) return;
        const pop = overlay.pop;
        clear(pop);
        if (state.draft) {
            const d = state.draft;
            const composer = createComposer({
                people: peopleList, label: 'New comment', submitLabel: 'Comment',
                onSubmit: async (p) => {
                    const r = await post({ body: p.body, mentions: p.mentions, page_id: d.page_id, element_id: d.element_id, x: d.x, y: d.y });
                    if (r.ok) { state.draft = null; state.active = r.comment.id; draw(); showPopover(); }
                    return r;
                },
                onCancel: closePopover,
            });
            pop.append(h('p', { class: 'cb-pop__title', text: d.element_id ? 'Comment on this element' : 'New comment' }), composer.el);
            pop.hidden = false;
            position();
            composer.focus();
            return;
        }
        const t = state.threads.find((x) => x.id === state.active);
        if (!t) { pop.hidden = true; return; }
        const numbers = numberThreads(visible());
        pop.append(
            h('div', { class: 'cb-pop__bar' }, h('span', { class: 'cb-pop__title', text: `Comment ${numbers.get(t.id) || ''}` }), button({ label: 'Close', size: 'sm', variant: 'ghost', onClick: closePopover, ariaLabel: 'Close comment' })),
            threadCard(t, env({ number: 0, active: false, onFocus: null, pageLabel: null })));
        pop.hidden = false;
        position();
    }

    function position() {
        if (!overlay || overlay.pop.hidden) return;
        const anchor = popoverAnchor();
        const size = { width: overlay.root.clientWidth, height: overlay.root.clientHeight };
        if (!anchor) { overlay.pop.style.left = '8px'; overlay.pop.style.top = '8px'; return; }
        const mk = overlay.markers && overlay.markers.get(state.draft ? 'draft' : state.active);
        const pin = mk ? { x: mk.x, y: mk.y } : pinScreen(studio().viewport.view, anchor, size);
        const box = { width: Math.min(320, size.width - 16), height: overlay.pop.offsetHeight || 220 };
        const pos = popoverPosition(pin, size, box);
        overlay.pop.style.width = `${box.width}px`;
        overlay.pop.style.left = `${pos.left}px`;
        overlay.pop.style.top = `${pos.top}px`;
    }

    const visible = () => filterThreads(state.threads, { status: 'all', scope: 'page', pageId: activePageId() })
        .filter((t) => !t.resolved || state.filter.status !== 'open' || t.id === state.active);

    function setShowPins(on) {
        state.showPins = !!on;
        draw();
        for (const p of panels) p.render();
    }

    const MARKER = 22;
    const NS = 'http://www.w3.org/2000/svg';
    const svgEl = (tag, attrs) => { const n = document.createElementNS(NS, tag); for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, String(v)); return n; };

    function showTip(t, marker) {
        if (!overlay) return;
        const tip = overlay.tip;
        const who = t.is_guest ? 'A guest' : (t.author && t.author.name) || 'Someone';
        const replies = (t.replies || []).length;
        const body = String(t.body || '').replace(/\s+/g, ' ').trim();
        tip.replaceChildren(...[h('strong', { text: who }), h('span', { class: 'cb-tip__body', text: body.length > 120 ? `${body.slice(0, 119)}...` : body }), replies ? h('span', { class: 'cb-tip__meta', text: `${replies} ${replies === 1 ? 'reply' : 'replies'}${t.resolved ? ', resolved' : ''}` }) : (t.resolved ? h('span', { class: 'cb-tip__meta', text: 'Resolved' }) : null)].filter(Boolean));
        tip.hidden = false;
        const size = { width: overlay.root.clientWidth, height: overlay.root.clientHeight };
        const x = parseFloat(marker.dataset.x); const y = parseFloat(marker.dataset.y);
        const w = Math.min(260, size.width - 16);
        tip.style.width = `${w}px`;
        const left = Math.max(8, Math.min(x - w / 2, size.width - w - 8));
        const below = y < 90;
        tip.style.left = `${left}px`;
        tip.style.top = below ? `${y + MARKER / 2 + 8}px` : `${Math.max(8, y - MARKER / 2 - 8 - tip.offsetHeight)}px`;
    }
    function hideTip() { if (overlay) overlay.tip.hidden = true; }

    function draw() {
        if (!overlay) return;
        const page = activePage();
        const view = studio().viewport.view;
        const size = { width: overlay.root.clientWidth, height: overlay.root.clientHeight };
        overlay.layer.classList.toggle('is-hidden', !state.showPins);
        const pageThreads = state.threads.filter((t) => String(t.page_id) === String(activePageId()) && t.x !== null && t.x !== undefined);
        const shown = state.filter.status === 'resolved' ? pageThreads.filter((t) => t.resolved) : state.filter.status === 'open' ? pageThreads.filter((t) => !t.resolved || t.id === state.active) : pageThreads;
        const numbers = numberThreads(shown);
        // anchors first (screen points and element boxes), then marker positions that avoid each other
        const entries = [];
        for (const t of shown) {
            const point = pinPagePoint(t, page);
            if (!point || point.detached) continue;
            const a = pinScreen(view, point, size);
            if (!a.visible) continue;
            const quad = t.element_id ? elementScreenQuad(view, page, t.element_id) : null;
            entries.push({ t, anchor: { x: a.x, y: a.y }, quad, home: markerHome({ x: a.x, y: a.y }, quad, { diameter: MARKER }) });
        }
        if (state.draft) {
            const point = pinPagePointForDraft(page);
            if (point) { const a = pinScreen(view, point, size); const quad = state.draft.element_id ? elementScreenQuad(view, page, state.draft.element_id) : null; entries.push({ draft: true, anchor: { x: a.x, y: a.y }, quad, home: markerHome({ x: a.x, y: a.y }, quad, { diameter: MARKER }) }); }
        }
        // the open thread is placed first so it keeps its home; the rest follow in number order
        entries.sort((x, y) => (state.active && x.t && x.t.id === state.active ? -1 : 0) - (state.active && y.t && y.t.id === state.active ? -1 : 0));
        const laid = layoutMarkers(entries.map((e, i) => ({ id: i, anchor: e.anchor, home: e.home, avoid: e.quad ? quadBounds(e.quad) : null })), { diameter: MARKER, bounds: size });

        const frag = document.createDocumentFragment();
        const lines = document.createDocumentFragment();
        entries.forEach((e, i) => {
            const m = laid.get(i);
            const l = leader(e.anchor, m, MARKER);
            const active = e.t && state.active === e.t.id;
            const cls = `${active ? 'is-active' : ''}${e.t && e.t.resolved ? ' is-resolved' : ''}`;
            // the anchored element, outlined when its thread is open: the person sees exactly what the comment is about
            if (active && e.quad) lines.append(svgEl('polygon', { points: e.quad.map((p) => `${p.x},${p.y}`).join(' '), class: 'cb-outline' }));
            if (l) lines.append(svgEl('line', { x1: l.x1, y1: l.y1, x2: l.x2, y2: l.y2, class: `cb-leader ${cls}` }));
            lines.append(svgEl('circle', { cx: e.anchor.x, cy: e.anchor.y, r: active ? 4 : 3, class: `cb-anchor ${cls}${e.draft ? ' is-draft' : ''}` }));
            if (e.draft) { frag.append(h('span', { class: 'cb-pin cb-pin--draft', style: `transform: translate(${m.x}px, ${m.y}px)`, 'aria-hidden': 'true' }, h('span', { text: '+' }))); return; }
            const t = e.t;
            const who = t.is_guest ? 'a guest' : (t.author && t.author.name) || 'someone';
            const b = h('button', {
                type: 'button', class: `cb-pin${t.resolved ? ' is-resolved' : ''}${active ? ' is-active' : ''}`,
                'data-thread': t.id, 'data-x': m.x, 'data-y': m.y, style: `transform: translate(${m.x}px, ${m.y}px)`, 'data-studio-ignore': '',
                'aria-label': `Comment ${numbers.get(t.id)} by ${who}${t.resolved ? ', resolved' : ''}. Open`,
                onclick: (ev) => { ev.stopPropagation(); hideTip(); focusThread(t.id); },
                onpointerdown: (ev) => ev.stopPropagation(),
                onpointerenter: () => { if (!active) showTip(t, b); },
                onpointerleave: hideTip,
                onfocus: () => { if (!active) showTip(t, b); },
                onblur: hideTip,
            }, h('span', { text: String(numbers.get(t.id)) }));
            frag.append(b);
        });
        overlay.markers = new Map();
        entries.forEach((e, i) => overlay.markers.set(e.draft ? 'draft' : e.t.id, laid.get(i)));
        overlay.svg.replaceChildren(lines);
        overlay.pinsEl.replaceChildren(frag);
        const count = pageThreads.filter((t) => !t.resolved).length;
        overlay.toggle.hidden = pageThreads.length === 0;
        overlay.toggle.textContent = state.showPins ? 'Hide comments' : `Show comments (${count})`;
        overlay.toggle.setAttribute('aria-pressed', String(!state.showPins));
        position();
    }

    const redraw = () => { if (raf) return; raf = requestAnimationFrame(() => { raf = 0; draw(); }); };

    // ------------------------------------------------------------------ lifecycle
    function start() {
        if (started) return;
        started = true;
        const ok = ensureOverlay();
        if (!ok) { started = false; return; }
        for (const ev of ['viewport', 'change', 'page']) {
            try { studioOff.push(studio().on(ev, redraw)); } catch (e) { /* an engine without the event simply does not redraw on it */ }
        }
        window.addEventListener('focus', onFocus);
        document.addEventListener('visibilitychange', onFocus);
        window.addEventListener('keydown', onKey, true);
        load();
    }
    const onFocus = () => { if (document.visibilityState !== 'hidden' && started) load(); };
    const onKey = (e) => { if (e.key === 'Escape' && (state.placing || state.draft || state.active)) { if (state.placing) setPlacing(false); else closePopover(); } };

    function startPlacing() {
        if (!canComment) { ctx.toast('You can read comments on this design but not add them.', { kind: 'warn' }); return; }
        start();
        if (!ensureOverlay()) { ctx.toast('Open the design first, then add a comment.', { kind: 'warn' }); return; }
        closePopover();
        setPlacing(!state.placing);
    }

    function commentOnSelection() {
        start();
        const page = activePage();
        const sel = (studio().getState().selection || []);
        const anchor = sel.length === 1 ? anchorForElement(page, sel[0]) : null;
        if (!anchor) { ctx.toast('Select one element first, or press C and click the design.', { kind: 'warn' }); return; }
        openDraft({ page_id: page.id, ...anchor });
    }

    function notify() {
        const counts = countThreads(state.threads, activePageId());
        for (const fn of countListeners) fn(counts.open);
        for (const p of panels) p.render();
        draw();
    }

    // ------------------------------------------------------------------ left panel
    function mountPanel(el) {
        start();
        const root = h('div', { class: 'cb-panel' });
        el.append(root);
        const panel = { render };
        panels.add(panel);
        let destroyedPanel = false;

        function chip(label, pressed, onClick) {
            return h('button', { type: 'button', class: 'cb-chip', 'aria-pressed': String(pressed), onclick: onClick, text: label });
        }

        function render() {
            if (destroyedPanel) return;
            const counts = countThreads(state.threads, activePageId());
            const top = h('div', { class: 'cb-panel__top' },
                h('h2', { class: 'cb-panel__title' }, 'Comments ', h('span', { class: 'cb-count', text: counts.open ? `${counts.open} open` : '' })),
                canComment ? h('button', { type: 'button', class: 'cb-btn cb-btn--primary cb-btn--sm', 'aria-pressed': String(state.placing), onclick: () => startPlacing(), text: state.placing ? 'Click the design...' : 'Add comment' }) : null);
            const filters = h('div', { class: 'cb-filters' },
                h('div', { class: 'cb-chips', role: 'group', 'aria-label': 'Show' },
                    chip(`Open (${counts.open})`, state.filter.status === 'open', () => { state.filter.status = 'open'; notify(); }),
                    chip(`Resolved (${counts.resolved})`, state.filter.status === 'resolved', () => { state.filter.status = 'resolved'; notify(); }),
                    chip('All', state.filter.status === 'all', () => { state.filter.status = 'all'; notify(); })),
                h('div', { class: 'cb-chips', role: 'group', 'aria-label': 'Where' },
                    chip('This page', state.filter.scope === 'page', () => { state.filter.scope = 'page'; notify(); }),
                    chip('All pages', state.filter.scope === 'all', () => { state.filter.scope = 'all'; notify(); })),
                switchControl({ label: 'Show comments on the design', checked: state.showPins, onChange: (on) => { state.showPins = on; draw(); }, className: 'cb-pinswitch' }));

            const body = h('div', { class: 'cb-panel__list', 'aria-live': 'polite' });
            if (state.load === 'loading') body.append(skeleton(3));
            else if (state.load === 'error') body.append(notice(state.error.text, state.error.kind, { action: { label: 'Try again', onClick: () => load() } }));
            else {
                const list = filterThreads(state.threads, { ...state.filter, pageId: activePageId() });
                const numbers = numberThreads(state.threads.filter((t) => String(t.page_id) === String(activePageId())));
                if (!list.length) {
                    const none = state.threads.length === 0;
                    body.append(none
                        ? empty('No comments yet', canComment ? 'Press C, or choose Add comment, then click the exact spot on the design. Colleagues and people with a comment link can reply.' : 'When someone comments on this design, it appears here.')
                        : empty(state.filter.status === 'resolved' ? 'Nothing is resolved yet' : 'Nothing open here', 'Change the filters above to see other comments.'));
                } else {
                    for (const t of list) body.append(threadCard(t, env({ number: numbers.get(t.id) || 0, active: state.active === t.id })));
                }
            }
            const note = !canComment ? h('p', { class: 'cb-hint', text: 'You can read comments on this design but not add them.' }) : null;
            root.replaceChildren(...[top, filters, note, body].filter(Boolean));
        }
        render();
        load();
        return {
            destroy() { destroyedPanel = true; panels.delete(panel); clear(el); },
        };
    }

    return {
        start, mountPanel, startPlacing, commentOnSelection,
        onCount(fn) { countListeners.add(fn); },
        // testing hooks
        _state: state,
        destroy() { studioOff.forEach((off) => { try { off(); } catch (e) { /* ignore */ } }); window.removeEventListener('focus', onFocus); document.removeEventListener('visibilitychange', onFocus); window.removeEventListener('keydown', onKey, true); if (overlay) overlay.layer.remove(); overlay = null; started = false; },
    };
}

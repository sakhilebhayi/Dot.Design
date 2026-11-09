/*
 * The shell: builds the page skeleton (top bar, tab rail, left panel, stage,
 * right panel, page strip), renders registered tabs and inspector sections, and
 * runs the compact (phone and tablet) model where panels become bottom sheets.
 * It holds no editing logic; panels and the engine do that.
 */
import { COMPACT_QUERY, DRAWER_QUERY, h, icon, isCompact, isDrawer, layoutMode } from './dom.js';

const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } },
};
export { store };

/** Per person (not just per browser profile): the key carries the user id so two people on one machine keep their own. */
export const userKey = (ctx, name) => `dot-design-${name}:${(ctx.config.user && ctx.config.user.id) || 'anon'}`;
const PANEL_SNAPS = ['peek', 'half', 'full'];

export function createShell(ctx) {
    const root = ctx.root;
    root.textContent = '';
    root.classList.add('st-app');

    const top = h('header', { class: 'st-top' });
    const rail = h('nav', { class: 'st-rail', attrs: { 'aria-label': 'Add and manage' } });
    const leftTitle = h('h2', { class: 'st-panel__title', text: '' });
    const leftClose = h('button', { class: 'lt-icon-btn st-panel__close', attrs: { type: 'button', 'aria-label': 'Close panel' } }, icon('x'));
    const leftBody = h('div', { class: 'st-panel__body' });
    const left = h('aside', { class: 'st-left', hidden: true, attrs: { 'aria-label': 'Panel' } },
        h('header', { class: 'st-panel__head' }, h('button', { class: 'st-sheet__grip', attrs: { type: 'button', 'aria-label': 'Make the panel taller or shorter' } }), leftTitle, leftClose), leftBody);
    const stageWrap = h('main', { class: 'st-stagewrap' });
    const stage = ctx.studio.root;
    stageWrap.appendChild(stage);
    const rightHead = h('header', { class: 'st-panel__head st-right__head' });
    const rightTitle = h('h2', { class: 'st-panel__title', text: 'Edit' });
    const rightClose = h('button', { class: 'lt-icon-btn st-panel__close', attrs: { type: 'button', 'aria-label': 'Close panel' } }, icon('x'));
    rightHead.append(h('button', { class: 'st-sheet__grip', attrs: { type: 'button', 'aria-label': 'Make the panel taller or shorter' } }), rightTitle, rightClose);
    const rightTabs = h('div', { class: 'st-right__tabs', attrs: { role: 'tablist', 'aria-label': 'Panel' } });
    const rightBody = h('div', { class: 'st-panel__body st-right__body' });
    const proFoot = h('div', { class: 'st-right__foot' },
        h('p', { class: 'st-prohint__text' }, h('strong', { text: 'Need more? ' }), 'Pro adds gradients, shadows, layers, exact sizes, rulers and guides.'),
        h('button', { class: 'lt-btn lt-btn--ghost lt-btn--sm st-linkbtn', attrs: { type: 'button' }, onClick: () => ctx.setMode('pro') }, 'Switch to Pro'));
    const right = h('aside', { class: 'st-right', attrs: { 'aria-label': 'Properties' } }, rightHead, rightTabs, rightBody, proFoot);
    // Tablet: the inspector is a drawer over the stage, with a tab on the stage edge to fold it away or bring it back.
    const drawerTab = h('button', { class: 'st-drawertab', attrs: { type: 'button', 'aria-controls': 'st-right', 'aria-expanded': 'true', 'aria-label': 'Hide the edit panel' } }, icon('panel-left'), h('span', { class: 'st-drawertab__label', text: 'Edit' }));
    right.id = 'st-right';
    const pages = h('footer', { class: 'st-pages', attrs: { 'aria-label': 'Pages' } });
    const banner = h('div', { class: 'st-banner', hidden: true });
    root.append(top, banner, rail, left, stageWrap, right, pages);
    stageWrap.appendChild(drawerTab);

    // ---- left tabs ---------------------------------------------------------------------------------
    const mounted = new Map(); // tab id -> {pane, handle}
    let activeTab = null;
    const railButtons = new Map();

    function renderRail() {
        const tabs = ctx.registry.leftTabs(ctx.mode).filter((t) => !t.visible || t.visible(ctx));
        rail.textContent = '';
        railButtons.clear();
        const list = h('div', { class: 'st-rail__list', attrs: { role: 'tablist', 'aria-orientation': 'vertical', 'aria-label': 'Panels' } });
        for (const t of tabs) {
            const b = h('button', { class: 'st-rail__tab', attrs: { type: 'button', role: 'tab', 'aria-selected': String(activeTab === t.id), 'aria-controls': 'st-left-pane', id: 'st-tab-' + t.id }, data: { tab: t.id }, onClick: () => toggleTab(t.id) },
                icon(t.icon || 'layers'), h('span', { class: 'st-rail__label', text: t.label }));
            railButtons.set(t.id, b);
            list.appendChild(b);
        }
        list.addEventListener('keydown', (e) => {
            const btns = Array.from(railButtons.values());
            const i = btns.indexOf(document.activeElement);
            if (i < 0) return;
            const horizontal = isCompact();
            const next = e.key === (horizontal ? 'ArrowRight' : 'ArrowDown') ? 1 : e.key === (horizontal ? 'ArrowLeft' : 'ArrowUp') ? -1 : 0;
            if (next) { e.preventDefault(); btns[(i + next + btns.length) % btns.length].focus(); }
        });
        rail.appendChild(list);
        if (activeTab && !tabs.some((t) => t.id === activeTab)) closeTabs();
        fadeRail();
    }
    // The rail is a scrolling tab bar on a phone: show a fade on the side that has more, and keep the open tab in view.
    function fadeRail() {
        const more = rail.scrollWidth > rail.clientWidth + 2 && isCompact();
        rail.dataset.fadeStart = more && rail.scrollLeft > 4 ? '1' : '0';
        rail.dataset.fadeEnd = more && rail.scrollLeft + rail.clientWidth < rail.scrollWidth - 4 ? '1' : '0';
    }
    function revealTab(id) {
        const b = railButtons.get(id);
        if (!b || !isCompact() || typeof b.scrollIntoView !== 'function') return;
        const reduce = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
        // scroll the rail only (scrollIntoView would also scroll the page): centre the button in the bar
        const target = b.offsetLeft - (rail.clientWidth - b.offsetWidth) / 2;
        if (typeof rail.scrollTo === 'function') rail.scrollTo({ left: Math.max(0, target), behavior: reduce ? 'auto' : 'smooth' }); else rail.scrollLeft = target;
    }
    rail.addEventListener('scroll', fadeRail, { passive: true });
    if (typeof ResizeObserver === 'function') new ResizeObserver(fadeRail).observe(rail);

    function openTab(id, opts = {}) {
        const tab = ctx.registry.leftTabs(ctx.mode).find((t) => t.id === id);
        if (!tab) return false;
        activeTab = id;
        leftTitle.textContent = tab.label;
        for (const [tid, m] of mounted) m.pane.hidden = tid !== id;
        if (!mounted.has(id)) {
            const pane = h('div', { class: 'st-pane', id: 'st-left-pane', attrs: { role: 'tabpanel', 'aria-labelledby': 'st-tab-' + id }, data: { tab: id } });
            leftBody.appendChild(pane);
            let handle = null;
            try { handle = tab.mount(pane, ctx) || null; } catch (e) {
                console.error('[studio] tab "' + id + '" failed to mount', e);
                pane.appendChild(h('p', { class: 'st-note st-note--bad', text: 'This panel could not load. Reload the page to try again.' }));
            }
            mounted.set(id, { pane, handle });
        } else {
            const m = mounted.get(id);
            m.pane.hidden = false;
            if (m.handle && m.handle.show) m.handle.show(opts);
        }
        left.hidden = false;
        root.dataset.left = 'open';
        for (const [tid, b] of railButtons) b.setAttribute('aria-selected', String(tid === id));
        if (opts.persist !== false) store.set(userKey(ctx, 'tab'), id);
        revealTab(id);
        if (isCompact()) { right.hidden = true; root.dataset.right = 'closed'; setSnap('half'); }
        ctx.emit('tab', { id });
        if (opts.focus) { const f = mounted.get(id).pane.querySelector('input, button'); if (f) f.focus(); }
        return true;
    }
    function closeTabs(opts = {}) {
        activeTab = null;
        left.hidden = true;
        root.dataset.left = 'closed';
        for (const b of railButtons.values()) b.setAttribute('aria-selected', 'false');
        if (opts.persist !== false) store.set(userKey(ctx, 'tab'), 'none');
        ctx.emit('tab', { id: null });
    }
    function toggleTab(id) { if (activeTab === id) closeTabs(); else openTab(id, { focus: true }); }
    leftClose.addEventListener('click', () => closeTabs());

    // ---- right panel -------------------------------------------------------------------------------
    const sectionHost = h('div', { class: 'st-inspector' });
    const layersHost = h('div', { class: 'st-layers-host', hidden: true });
    const simpleHost = h('div', { class: 'st-simple', hidden: true });
    rightBody.append(simpleHost, sectionHost, layersHost);
    const sections = new Map(); // id -> {wrap, handle}
    let rightTab = 'design';

    function renderRightTabs() {
        rightTabs.textContent = '';
        rightTabs.hidden = ctx.mode !== 'pro' || isCompact() && false;
        if (ctx.mode !== 'pro') { layersHost.hidden = true; return; }
        for (const [id, label] of [['design', 'Design'], ['layers', 'Layers']]) {
            const b = h('button', { class: 'st-right__tab', attrs: { type: 'button', role: 'tab', 'aria-selected': String(rightTab === id) }, text: label, onClick: () => { rightTab = id; renderRightTabs(); applyRightTab(); } });
            rightTabs.appendChild(b);
        }
    }
    function applyRightTab() {
        const pro = ctx.mode === 'pro';
        simpleHost.hidden = pro;
        sectionHost.hidden = pro ? rightTab !== 'design' : false;
        layersHost.hidden = !pro || rightTab !== 'layers';
        rightTitle.textContent = pro ? (rightTab === 'layers' ? 'Layers' : 'Design') : rightTitle.dataset.simple || 'Edit';
        if (typeof renderJump === 'function') renderJump();
    }

    let refreshQueued = false;
    function refreshSections() {
        if (refreshQueued) return; refreshQueued = true;
        requestAnimationFrame(() => { refreshQueued = false; syncSections(); updateSections(); });
    }
    const FOLDS_KEY = userKey(ctx, 'folds');
    const readFolds = () => { try { return JSON.parse(store.get(FOLDS_KEY, '{}')) || {}; } catch (e) { return {}; } };
    const foldKey = (id, simple) => (simple ? 's:' : 'p:') + id;
    const foldState = (id, dflt, simple) => { const v = readFolds()[foldKey(id, simple)]; return typeof v === 'boolean' ? v : dflt; };
    const saveFold = (id, open, simple) => { const all = readFolds(); all[foldKey(id, simple)] = open; store.set(FOLDS_KEY, JSON.stringify(all)); };
    function syncSections() {
        const sel = ctx.selection();
        const want = ctx.registry.inspectorSections(ctx.mode, sel, ctx);
        // In Pro the core sections form the whole inspector; in Simple only sections that opted into 'simple' show, below the contextual panel.
        const ids = new Set(want.map((s) => s.id));
        for (const [id, inst] of sections) if (!ids.has(id)) { try { if (inst.handle && inst.handle.destroy) inst.handle.destroy(); } catch (e) { /* ignore */ } inst.wrap.remove(); sections.delete(id); }
        let prev = null;
        for (const s of want) {
            let inst = sections.get(s.id);
            if (!inst) {
                const simple = ctx.mode === 'simple';
                const label = simple && s.id === 'animation' ? 'Make it move' : s.label;
                const wrap = h('section', { class: ['st-isec', s.bare && 'st-isec--bare'], data: { section: s.id }, attrs: { 'aria-label': label } });
                const body = h('div', { class: 'st-isec__body' });
                if (!s.bare) {
                    // Every group folds: Simple keeps extras ("Make it move") shut until asked for; Pro remembers what each person closed.
                    const open = foldState(s.id, simple ? false : true, simple);
                    const head = h('button', { class: 'st-isec__head', attrs: { type: 'button', 'aria-expanded': String(open) } }, h('span', { class: 'st-isec__title', text: label }), icon('chevron-down', 'st-isec__chev'));
                    wrap.classList.toggle('is-closed', !open);
                    head.addEventListener('click', () => { const o = head.getAttribute('aria-expanded') !== 'true'; head.setAttribute('aria-expanded', String(o)); wrap.classList.toggle('is-closed', !o); saveFold(s.id, o, simple); });
                    wrap.appendChild(head);
                }
                wrap.appendChild(body);
                let handle = null;
                try { handle = s.mount(body, ctx) || null; } catch (e) { console.error('[studio] section "' + s.id + '" failed to mount', e); body.appendChild(h('p', { class: 'st-note st-note--bad', text: 'This section could not load.' })); }
                inst = { wrap, handle };
                sections.set(s.id, inst);
            }
            const anchor = prev ? prev.nextSibling : sectionHost.firstChild;
            if (inst.wrap !== anchor) sectionHost.insertBefore(inst.wrap, anchor);
            prev = inst.wrap;
        }
    }
    function updateSections() {
        for (const [id, inst] of sections) { try { if (inst.handle && inst.handle.update) inst.handle.update(ctx.selection()); } catch (e) { console.error('[studio] section "' + id + '" update failed', e); } }
    }
    for (const ev of ['selection', 'change', 'page']) ctx.on(ev, refreshSections);
    ctx.registry.onChange((kind) => { if (kind === 'sections') refreshSections(); if (kind === 'tabs') renderRail(); });

    // ---- phone sheets and the tablet drawer ----------------------------------------------------------
    // Phone: a bottom sheet with three snap points. "half" leaves the canvas at least 45% of the screen; "peek" shows
    // only the panel's title and first row; "full" is for reading a long panel. Tablet: the inspector is a drawer.
    let snap = 'half';
    let drawerUserClosed = false;
    let drawerSelKey = '';
    function applySnap() { root.dataset.snap = snap; }
    function setSnap(next) { snap = PANEL_SNAPS.includes(next) ? next : 'half'; applySnap(); }
    function nudgeSnap(dir) {
        const i = PANEL_SNAPS.indexOf(snap) + dir;
        if (i < 0) return false;
        setSnap(PANEL_SNAPS[Math.min(i, PANEL_SNAPS.length - 1)]);
        return true;
    }
    function openEditSheet() {
        if (!isCompact()) { if (isDrawer()) setDrawer(true); return; }
        left.hidden = true; root.dataset.left = 'closed';
        right.hidden = false; root.dataset.right = 'open';
        setSnap('half');
        for (const b of railButtons.values()) b.setAttribute('aria-selected', 'false');
    }
    function closeEditSheet() {
        if (isCompact()) { right.hidden = true; root.dataset.right = 'closed'; } else if (isDrawer()) { drawerUserClosed = true; setDrawer(false); }
    }
    rightClose.addEventListener('click', closeEditSheet);

    function setDrawer(open) {
        root.dataset.right = open ? 'open' : 'closed';
        drawerTab.setAttribute('aria-expanded', String(open));
        drawerTab.setAttribute('aria-label', open ? 'Hide the edit panel' : 'Show the edit panel');
        right.toggleAttribute('inert', !open);
    }
    drawerTab.addEventListener('click', () => { const open = root.dataset.right !== 'open'; drawerUserClosed = !open; setDrawer(open); });
    // A new selection brings the drawer back unless the person folded it away for that very selection.
    function onSelectionForDrawer() {
        if (!isDrawer()) return;
        const sel = ctx.selection();
        const k = sel.ids.join(',');
        if (k !== drawerSelKey) { drawerSelKey = k; drawerUserClosed = false; }
        if (sel.count && root.dataset.right !== 'open' && !drawerUserClosed) setDrawer(true);
    }
    ctx.on('selection', onSelectionForDrawer);

    function onLayoutChange() {
        const mode = layoutMode();
        const c = mode === 'phone';
        root.dataset.layout = mode;
        root.dataset.compact = c ? '1' : '0';
        right.removeAttribute('inert');
        if (c) { right.hidden = root.dataset.right !== 'open'; left.hidden = !activeTab; applySnap(); } else { right.hidden = false; left.hidden = !activeTab; }
        if (mode === 'tablet') setDrawer(root.dataset.right === 'open' || ctx.selection().count > 0); else if (mode === 'desktop') root.dataset.right = 'open';
        drawerTab.hidden = mode !== 'tablet';
        renderRightTabs(); applyRightTab(); fadeRail();
    }
    for (const q of [COMPACT_QUERY, DRAWER_QUERY]) { const mq = typeof matchMedia === 'function' ? matchMedia(q) : null; if (mq && mq.addEventListener) mq.addEventListener('change', onLayoutChange); }

    // The sheet grip: tap to step through the snap points, drag (or swipe) up for taller, down for shorter, down from peek to close.
    for (const [panel, close] of [[left, closeTabs], [right, closeEditSheet]]) {
        const head = panel.querySelector('.st-panel__head');
        const grip = head.querySelector('.st-sheet__grip');
        let y0 = null; let moved = false;
        head.addEventListener('pointerdown', (e) => { if (isCompact() && (!e.target.closest('button') || e.target === grip)) { y0 = e.clientY; moved = false; } });
        head.addEventListener('pointermove', (e) => { if (y0 !== null && Math.abs(e.clientY - y0) > 8) moved = true; });
        head.addEventListener('pointerup', (e) => {
            if (y0 === null) return;
            const dy = e.clientY - y0; y0 = null;
            if (Math.abs(dy) > 40) { if (dy < 0) nudgeSnap(1); else if (!nudgeSnap(-1)) close(); }
        });
        head.addEventListener('pointercancel', () => { y0 = null; });
        grip.addEventListener('click', () => { if (moved) { moved = false; return; } setSnap({ half: 'full', full: 'peek', peek: 'half' }[snap] || 'half'); });
    }

    // Pro: a row of jump chips over the Design tab, one per group that applies, so every kind of control is visible at a glance.
    const jump = h('nav', { class: 'st-jump', hidden: true, attrs: { 'aria-label': 'Jump to a group of controls' } });
    sectionHost.before(jump);
    function renderJump() {
        const pro = ctx.mode === 'pro' && rightTab === 'design';
        jump.hidden = !pro || sections.size < 2;
        if (jump.hidden) return;
        const items = Array.from(sections.entries()).map(([id, inst]) => [id, inst]);
        const key = items.map(([id]) => id).join('|');
        if (jump.dataset.key === key) return;
        jump.dataset.key = key;
        jump.replaceChildren(...items.map(([id, inst]) => {
            const label = (inst.wrap.getAttribute('aria-label') || id).replace(/^Position and size$/, 'Position');
            return h('button', { class: 'st-jump__chip', attrs: { type: 'button' }, text: label, onClick: () => {
                const head = inst.wrap.querySelector('.st-isec__head');
                if (head && head.getAttribute('aria-expanded') === 'false') head.click();
                inst.wrap.scrollIntoView({ block: 'start', behavior: 'smooth' });
            } });
        }));
    }
    ctx.on('selection', () => requestAnimationFrame(renderJump)); ctx.on('mode', () => requestAnimationFrame(renderJump));

    ctx.on('mode', () => {
        // a section keeps its fold style for one mode only (Simple folds extras shut, Pro keeps each person's choice)
        for (const [id, inst] of sections) { try { if (inst.handle && inst.handle.destroy) inst.handle.destroy(); } catch (e) { /* ignore */ } inst.wrap.remove(); sections.delete(id); }
        jump.dataset.key = '';
        renderRail(); renderRightTabs(); applyRightTab(); syncSections(); updateSections(); renderJump();
    });
    ctx.openTab = (id, opts) => openTab(id, opts);
    ctx.closeTabs = closeTabs;
    ctx.openEditSheet = openEditSheet;
    ctx.closeEditSheet = closeEditSheet;
    ctx.activeTab = () => activeTab;

    /** Is there anything on any page yet? An empty design opens on Templates; a worked-on one opens on the page itself. */
    const designIsEmpty = () => ctx.studio.getState().doc.pages.every((p) => !p.elements.length);

    // The contextual Simple panel and the Layers panel are mounted by their own modules.
    function init() {
        renderRail();
        onLayoutChange();
        const saved = store.get(userKey(ctx, 'tab'), null);
        const tabs = ctx.registry.leftTabs(ctx.mode);
        const has = (id) => tabs.some((t) => t.id === id);
        // First open (nothing remembered): the page is the focus, panels stay shut, unless the design is empty. A tab the person
        // opened or closed themselves is remembered for them. An empty design always shows Templates, without changing that memory.
        if (!isCompact() && designIsEmpty() && has('templates') && !ctx.readOnly) openTab('templates', { persist: false });
        else if (!isCompact() && saved && saved !== 'none' && has(saved)) openTab(saved);
        else closeTabs({ persist: false });
        if (isCompact()) { right.hidden = true; root.dataset.right = 'closed'; }
        syncSections(); updateSections(); renderJump();
    }

    return {
        top, banner, rail, left, stage, stageWrap, right, pages, simpleHost, layersHost, sectionHost, rightTitle, init, renderRail, applyRightTab,
        setSimpleTitle(t) { rightTitle.dataset.simple = t; if (ctx.mode === 'simple') rightTitle.textContent = t; },
        refreshSections,
    };
}

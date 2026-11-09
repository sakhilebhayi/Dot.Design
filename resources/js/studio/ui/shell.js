/*
 * The shell: builds the page skeleton (top bar, tab rail, left panel, stage,
 * right panel, page strip), renders registered tabs and inspector sections, and
 * runs the compact (phone and tablet) model where panels become bottom sheets.
 * It holds no editing logic; panels and the engine do that.
 */
import { COMPACT_QUERY, h, icon, isCompact } from './dom.js';

const TAB_KEY = 'dot-design-tab';
const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } },
};
export { store };

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
        h('header', { class: 'st-panel__head' }, h('div', { class: 'st-sheet__grip', attrs: { 'aria-hidden': 'true' } }), leftTitle, leftClose), leftBody);
    const stageWrap = h('main', { class: 'st-stagewrap' });
    const stage = ctx.studio.root;
    stageWrap.appendChild(stage);
    const rightHead = h('header', { class: 'st-panel__head st-right__head' });
    const rightTitle = h('h2', { class: 'st-panel__title', text: 'Edit' });
    const rightClose = h('button', { class: 'lt-icon-btn st-panel__close', attrs: { type: 'button', 'aria-label': 'Close panel' } }, icon('x'));
    rightHead.append(h('div', { class: 'st-sheet__grip', attrs: { 'aria-hidden': 'true' } }), rightTitle, rightClose);
    const rightTabs = h('div', { class: 'st-right__tabs', attrs: { role: 'tablist', 'aria-label': 'Panel' } });
    const rightBody = h('div', { class: 'st-panel__body st-right__body' });
    const right = h('aside', { class: 'st-right', attrs: { 'aria-label': 'Properties' } }, rightHead, rightTabs, rightBody);
    const pages = h('footer', { class: 'st-pages', attrs: { 'aria-label': 'Pages' } });
    const banner = h('div', { class: 'st-banner', hidden: true });
    root.append(top, banner, rail, left, stageWrap, right, pages);

    // ---- left tabs ---------------------------------------------------------------------------------
    const mounted = new Map(); // tab id -> {pane, handle}
    let activeTab = null;
    const railButtons = new Map();

    function syncRailStop() {
        const stop = railButtons.get(activeTab) || railButtons.values().next().value;
        for (const b of railButtons.values()) b.tabIndex = b === stop ? 0 : -1;
    }

    function renderRail() {
        const tabs = ctx.registry.leftTabs(ctx.mode).filter((t) => !t.visible || t.visible(ctx));
        rail.textContent = '';
        railButtons.clear();
        const list = h('div', { class: 'st-rail__list', attrs: { role: 'tablist', 'aria-orientation': 'vertical', 'aria-label': 'Panels' } });
        for (const t of tabs) {
            const b = h('button', { class: 'st-rail__tab', attrs: { type: 'button', role: 'tab', 'aria-selected': String(activeTab === t.id), 'aria-controls': 'st-pane-' + t.id, tabindex: '-1', id: 'st-tab-' + t.id }, data: { tab: t.id }, onClick: () => toggleTab(t.id) },
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
            if (next) { e.preventDefault(); btns[(i + next + btns.length) % btns.length].focus(); } else if (e.key === 'Home') { e.preventDefault(); btns[0].focus(); } else if (e.key === 'End') { e.preventDefault(); btns[btns.length - 1].focus(); }
        });
        // One tab stop for the whole rail (roving tabindex): the open tab, else the first.
        list.addEventListener('focusin', (e) => { for (const b of railButtons.values()) b.tabIndex = b === e.target ? 0 : -1; });
        rail.appendChild(list);
        syncRailStop();
        if (activeTab && !tabs.some((t) => t.id === activeTab)) closeTabs();
    }

    function openTab(id, opts = {}) {
        const tab = ctx.registry.leftTabs(ctx.mode).find((t) => t.id === id);
        if (!tab) return false;
        activeTab = id;
        leftTitle.textContent = tab.label;
        for (const [tid, m] of mounted) m.pane.hidden = tid !== id;
        if (!mounted.has(id)) {
            const pane = h('div', { class: 'st-pane', id: 'st-pane-' + id, attrs: { role: 'tabpanel', 'aria-labelledby': 'st-tab-' + id }, data: { tab: id } });
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
        syncRailStop();
        store.set(TAB_KEY, id);
        if (isCompact()) { right.hidden = true; root.dataset.right = 'closed'; }
        ctx.emit('tab', { id });
        if (opts.focus) { const f = mounted.get(id).pane.querySelector('input, button'); if (f) f.focus(); }
        return true;
    }
    function closeTabs() {
        const closedId = activeTab;
        activeTab = null;
        left.hidden = true;
        root.dataset.left = 'closed';
        const wasFocus = left.contains(document.activeElement) ? railButtons.get(closedId) : null;
        for (const b of railButtons.values()) b.setAttribute('aria-selected', 'false');
        syncRailStop();
        if (wasFocus) wasFocus.focus({ preventScroll: true });
        store.set(TAB_KEY, 'none');
        ctx.emit('tab', { id: null });
    }
    function toggleTab(id) { if (activeTab === id) closeTabs(); else openTab(id, { focus: true }); }
    leftClose.addEventListener('click', closeTabs);

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
    }

    let refreshQueued = false;
    function refreshSections() {
        if (refreshQueued) return; refreshQueued = true;
        requestAnimationFrame(() => { refreshQueued = false; syncSections(); updateSections(); });
    }
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
                const wrap = h('section', { class: 'st-isec', data: { section: s.id }, attrs: { 'aria-label': s.label } });
                if (!s.bare) wrap.appendChild(h('h3', { class: 'st-isec__title', text: s.label }));
                const body = h('div', { class: 'st-isec__body' });
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

    // ---- compact (sheet) model ---------------------------------------------------------------------
    function openEditSheet() {
        if (!isCompact()) return;
        left.hidden = true; root.dataset.left = 'closed';
        right.hidden = false; root.dataset.right = 'open';
        for (const b of railButtons.values()) b.setAttribute('aria-selected', 'false');
    }
    function closeEditSheet() { if (isCompact()) { right.hidden = true; root.dataset.right = 'closed'; } }
    rightClose.addEventListener('click', closeEditSheet);
    function onCompactChange() {
        const c = isCompact();
        root.dataset.compact = c ? '1' : '0';
        if (c) { right.hidden = root.dataset.right !== 'open'; left.hidden = !activeTab; } else { right.hidden = false; left.hidden = !activeTab; }
        renderRightTabs(); applyRightTab();
    }
    const mq = typeof matchMedia === 'function' ? matchMedia(COMPACT_QUERY) : null;
    if (mq && mq.addEventListener) mq.addEventListener('change', onCompactChange);

    // swipe down on a sheet header closes it (touch)
    for (const [panel, close] of [[left, closeTabs], [right, closeEditSheet]]) {
        const head = panel.querySelector('.st-panel__head');
        let y0 = null;
        head.addEventListener('pointerdown', (e) => { if (isCompact() && !e.target.closest('button')) y0 = e.clientY; });
        head.addEventListener('pointerup', (e) => { if (y0 !== null && e.clientY - y0 > 40) close(); y0 = null; });
        head.addEventListener('pointercancel', () => { y0 = null; });
    }

    ctx.on('mode', () => { renderRail(); renderRightTabs(); applyRightTab(); syncSections(); updateSections(); });
    ctx.openTab = (id, opts) => openTab(id, opts);
    ctx.closeTabs = closeTabs;
    ctx.openEditSheet = openEditSheet;
    ctx.closeEditSheet = closeEditSheet;
    ctx.activeTab = () => activeTab;

    // The contextual Simple panel and the Layers panel are mounted by their own modules.
    function init() {
        renderRail();
        onCompactChange();
        const saved = store.get(TAB_KEY, null);
        const tabs = ctx.registry.leftTabs(ctx.mode);
        const first = saved === 'none' ? null : tabs.some((t) => t.id === saved) ? saved : (isCompact() ? null : (tabs[0] && tabs[0].id));
        if (first && !isCompact()) openTab(first); else closeTabs();
        if (isCompact()) { right.hidden = true; root.dataset.right = 'closed'; }
        syncSections(); updateSections();
    }

    return {
        top, banner, rail, left, stage, stageWrap, right, pages, simpleHost, layersHost, sectionHost, rightTitle, init, renderRail, applyRightTab,
        setSimpleTitle(t) { rightTitle.dataset.simple = t; if (ctx.mode === 'simple') rightTitle.textContent = t; },
        refreshSections,
    };
}

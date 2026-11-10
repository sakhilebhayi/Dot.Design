/*
 * The page strip: real thumbnails (rendered by the same renderer as exports,
 * cached per page object, redrawn lazily), add, duplicate, delete and reorder.
 * Reordering works by dragging, and by "Move earlier / later" in each page's
 * menu so it never needs a pointer.
 */
import { activePage } from './describe.js';
import { debounce, h, icon, setVars } from './dom.js';
import { openMenu } from './popover.js';
import { parseSvg } from './preview.js';

export function createPageStrip(ctx, shell) {
    const studio = ctx.studio;
    const strip = shell.pages;
    const cache = new WeakMap(); // page object -> svg node (already rendered)
    const items = new Map();     // page id -> {li, btn, thumb, lastPage}
    const list = h('ol', { class: 'st-pagelist', attrs: { 'aria-label': 'Pages' } });
    const addBtn = h('button', { class: 'st-pageadd', attrs: { type: 'button', 'aria-label': 'Add a page', 'data-tip': 'Add a page', 'data-tip-side': 'top' }, onClick: () => addPage() }, icon('plus'), h('span', { class: 'st-pageadd__label', text: 'Add page' }));
    const toggle = h('button', { class: 'st-pages__toggle lt-icon-btn', attrs: { type: 'button', 'aria-label': 'Show or hide pages', 'aria-expanded': 'true' }, onClick: () => { const c = strip.classList.toggle('is-collapsed'); toggle.setAttribute('aria-expanded', String(!c)); } }, icon('chevron-down'));
    strip.append(list, h('div', { class: 'st-pages__end' }, addBtn, toggle));
    if (ctx.readOnly) addBtn.hidden = true;

    function addPage() {
        const pages = studio.getState().doc.pages;
        const at = pages.findIndex((p) => p.id === studio.getState().activePageId) + 1;
        ctx.run('page.add', { index: at });
    }

    function renderThumb(page, node) {
        const cached = cache.get(page);
        if (cached) { node.replaceChildren(cached.cloneNode(true)); return; }
        const i = studio.getState().doc.pages.indexOf(page);
        if (i < 0) return;
        let markup = '';
        try { markup = studio.renderPageToSvg(i, { forExport: true, preview: true, idPrefix: 'ps' + String(page.id).replace(/[^A-Za-z0-9]/g, '') + '-' }); } catch (e) { markup = ''; }
        const svg = markup ? parseSvg(markup) : null;
        if (svg) { cache.set(page, svg); node.replaceChildren(svg.cloneNode(true)); }
    }

    const idle = (fn) => (typeof requestIdleCallback === 'function' ? requestIdleCallback(fn, { timeout: 600 }) : setTimeout(fn, 60));
    const refreshThumbs = debounce(() => {
        const s = studio.getState();
        s.doc.pages.forEach((p) => {
            const it = items.get(p.id);
            if (!it || it.lastPage === p) return;
            it.lastPage = p;
            idle(() => renderThumb(p, it.thumb));
        });
    }, 320);

    function menuFor(page, index, anchor) {
        const pages = studio.getState().doc.pages;
        const items2 = [
            { label: 'Duplicate page', icon: 'copy', onClick: () => ctx.run('page.duplicate', { id: page.id }) },
            { label: 'Move earlier', disabled: index === 0, onClick: () => ctx.run('page.reorder', { id: page.id, to: index - 1 }) },
            { label: 'Move later', disabled: index === pages.length - 1, onClick: () => ctx.run('page.reorder', { id: page.id, to: index + 1 }) },
            { sep: true },
            { label: 'Delete page', icon: 'trash', danger: true, disabled: pages.length <= 1, onClick: () => removePage(page, index) },
        ];
        return openMenu(anchor, items2, { label: `Page ${index + 1} actions` });
    }

    async function removePage(page, index) {
        const has = page.elements.length > 0;
        const ok = await ctx.confirm({ title: `Delete page ${index + 1}?`, body: has ? 'Everything on this page goes with it. You can undo this right after.' : 'This page is empty. You can undo this right after.', confirmLabel: 'Delete page', danger: true });
        if (ok) ctx.run('page.remove', { id: page.id });
    }

    // drag to reorder (pointer based so touch works)
    let drag = null;
    function startDrag(e, li, page, index) {
        if (ctx.readOnly || e.button !== 0) return;
        drag = { li, page, index, x: e.clientX, y: e.clientY, active: false, target: index };
        e.currentTarget.setPointerCapture(e.pointerId);
    }
    function moveDrag(e) {
        if (!drag) return;
        if (!drag.active) { if (Math.abs(e.clientX - drag.x) < 8) return; drag.active = true; list.classList.add('is-sorting'); drag.li.classList.add('is-drag'); }
        const lis = Array.from(list.children);
        let target = lis.length - 1;
        for (let i = 0; i < lis.length; i += 1) { const r = lis[i].getBoundingClientRect(); if (e.clientX < r.left + r.width / 2) { target = i; break; } }
        drag.target = target;
        lis.forEach((li, i) => li.classList.toggle('is-drop-before', i === target && target < drag.index), li);
        lis.forEach((li, i) => li.classList.toggle('is-drop-after', i === target && target > drag.index));
        setVars(drag.li, { dx: (e.clientX - drag.x) + 'px' });
    }
    function endDrag() {
        if (!drag) return;
        const d = drag; drag = null;
        list.classList.remove('is-sorting');
        Array.from(list.children).forEach((li) => li.classList.remove('is-drag', 'is-drop-before', 'is-drop-after'));
        d.li.style.removeProperty('--dx');
        if (d.active && d.target !== d.index) ctx.run('page.reorder', { id: d.page.id, to: d.target });
        d.moved = d.active;
        lastDragged = d.active ? performance.now() : 0;
    }
    let lastDragged = 0;

    function sync() {
        const s = studio.getState();
        const pages = s.doc.pages;
        const seen = new Set();
        pages.forEach((p, i) => {
            seen.add(p.id);
            let it = items.get(p.id);
            if (!it) {
                const thumb = h('span', { class: 'st-page__thumb lt-frame', attrs: { 'aria-hidden': 'true' } });
                const btn = h('button', { class: 'st-page__btn', attrs: { type: 'button' } }, thumb, h('span', { class: 'st-page__num lt-num' }));
                const menuBtn = h('button', { class: 'st-page__menu', attrs: { type: 'button' }, hidden: ctx.readOnly }, icon('more', 'lt-icon--sm'));
                const li = h('li', { class: 'st-page' }, btn, menuBtn);
                it = { li, btn, thumb, menuBtn, num: btn.lastChild, lastPage: null };
                items.set(p.id, it);
                btn.addEventListener('click', () => { if (performance.now() - lastDragged < 250) return; studio.setActivePage(it.pageId); });
                btn.addEventListener('pointerdown', (e) => startDrag(e, li, it.page, it.index));
                btn.addEventListener('pointermove', moveDrag);
                btn.addEventListener('pointerup', endDrag); btn.addEventListener('pointercancel', endDrag);
                btn.addEventListener('contextmenu', (e) => { e.preventDefault(); menuFor(it.page, it.index, btn); });
                menuBtn.addEventListener('click', () => menuFor(it.page, it.index, menuBtn));
                btn.addEventListener('keydown', (e) => {
                    const lis = Array.from(list.children);
                    const at = lis.indexOf(li);
                    if (e.key === 'ArrowRight' && lis[at + 1]) { e.preventDefault(); lis[at + 1].querySelector('.st-page__btn').focus(); } else if (e.key === 'ArrowLeft' && lis[at - 1]) { e.preventDefault(); lis[at - 1].querySelector('.st-page__btn').focus(); }
                });
            }
            it.page = p; it.index = i; it.pageId = p.id;
            const active = p.id === s.activePageId;
            it.li.classList.toggle('is-active', active);
            it.btn.setAttribute('aria-current', active ? 'page' : 'false');
            it.btn.setAttribute('aria-label', `Page ${i + 1}${p.name ? ', ' + p.name : ''}${active ? ', current' : ''}`);
            it.menuBtn.setAttribute('aria-label', `Page ${i + 1} actions`);
            it.num.textContent = String(i + 1);
            setVars(it.thumb, { w: p.width, h: p.height });
            if (list.children[i] !== it.li) list.insertBefore(it.li, list.children[i] || null);
        });
        for (const [id, it] of items) if (!seen.has(id)) { it.li.remove(); items.delete(id); }
        refreshThumbs();
        // keep the current thumbnail fresh while editing
        const act = activePage(s);
        const cur = items.get(act.id);
        if (cur && cur.lastPage !== act) { /* picked up by refreshThumbs */ }
    }
    ctx.on('page', sync); ctx.on('change', sync);
    // a first paint without waiting for the debounce
    sync();
    const first = studio.getState().doc.pages;
    first.forEach((p) => { const it = items.get(p.id); if (it) { it.lastPage = p; idle(() => renderThumb(p, it.thumb)); } });
    studio.on('font', () => { items.forEach((it) => { it.lastPage = null; }); refreshThumbs(); });
    ctx.on('assets', () => { items.forEach((it) => { it.lastPage = null; }); refreshThumbs(); });
    return { sync };
}

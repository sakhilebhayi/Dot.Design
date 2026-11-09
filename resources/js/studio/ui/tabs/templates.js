/*
 * Templates tab: browse the library (previews drawn in the browser), then use a
 * template for a new page or replace the current page. Replacing is never
 * silent: unsaved work is flushed, a restore point is saved on the server, the
 * person confirms, and the swap is one undo step.
 */
import { buildBrief, summariseFill } from '../brief.js';
import { activePage } from '../describe.js';
import { h, icon } from '../dom.js';
import { createPreviewRenderer, whenVisible } from '../template-render.js';
import { chipRow, emptyState, errorNote, searchBox, skeletonGrid } from './common.js';

const cache = new Map();

export function mountTemplates(el, ctx) {
    const renderer = createPreviewRenderer(ctx);
    const state = { category: 'all', q: '', page: 1, last: 1, items: [], loading: false, error: null, total: 0, token: 0 };
    const grid = h('div', { class: 'st-cards' });
    const status = h('div', { class: 'st-status' });
    const more = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--block', attrs: { type: 'button' }, text: 'Show more templates', hidden: true, onClick: () => { state.page += 1; load(true); } });
    const detail = h('div', { class: 'st-detail', hidden: true });
    const list = h('div', { class: 'st-templist' }, grid, status, more);
    const search = searchBox({ label: 'Search templates', placeholder: 'Search templates, for example sale or menu', onInput: (q) => { state.q = q; state.page = 1; load(false); } });
    const cats = [['all', 'All'], ['marketing', 'Marketing'], ['social', 'Social media'], ['identity', 'Business identity'], ['commercial', 'Menus and prices'], ['document', 'Documents']];
    const chips = chipRow(cats, 'all', (v) => { state.category = v; state.page = 1; load(false); }, 'Template category');
    el.append(h('div', { class: 'st-tabhead' }, search.el, chips), list, detail);

    function card(t) {
        const frame = h('span', { class: 'st-card__frame lt-frame', vars: { w: t.preview ? t.preview.width : t.width, h: t.preview ? t.preview.height : t.height } });
        const b = h('button', { class: 'st-card', attrs: { type: 'button', 'aria-label': `${t.name}, ${t.page_count > 1 ? t.page_count + ' pages, ' : ''}${t.width} by ${t.height}` }, onClick: () => openDetail(t) },
            frame, h('span', { class: 'st-card__name', text: t.name }), h('span', { class: 'st-card__meta', text: (t.format_label || '') + (t.page_count > 1 ? ' - ' + t.page_count + ' pages' : '') }));
        if (t.preview) whenVisible(b, () => renderer.draw(frame, t.preview), el.closest('.st-panel__body'));
        return b;
    }

    function paint(append) {
        if (!append) grid.replaceChildren();
        const start = append ? grid.children.length : 0;
        grid.append(...state.items.slice(start).map(card));
        more.hidden = state.page >= state.last;
        status.replaceChildren();
        if (state.error) status.append(errorNote(state.error, () => load(append)));
        else if (state.loading) status.append(skeletonGrid(4));
        else if (!state.items.length) status.append(emptyState('No templates match', state.q ? 'Try a different word, or clear the search.' : 'Nothing in this group yet.', state.q ? h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, text: 'Clear search', onClick: () => { search.input.value = ''; state.q = ''; load(false); } }) : null));
    }

    async function load(append) {
        const token = ++state.token;
        state.loading = true; state.error = null;
        if (!append) { state.items = []; }
        paint(append);
        const params = { per_page: 12, page: state.page, include: 'first_page', sort: 'popular', ...(state.category !== 'all' ? { category: state.category } : {}), ...(state.q ? { q: state.q } : {}) };
        const key = JSON.stringify(params);
        let r = cache.get(key);
        if (!r) { r = await ctx.api.get(ctx.url('design.templates.index'), params); if (r.ok) cache.set(key, r); }
        if (token !== state.token) return;
        state.loading = false;
        if (!r.ok) { state.error = r.message || 'The templates could not be loaded.'; paint(append); return; }
        state.items = append ? state.items.concat(r.data.data) : r.data.data;
        state.last = r.data.meta.last_page; state.total = r.data.meta.total;
        paint(append);
    }

    // ---- detail ----------------------------------------------------------------------------------
    const brief = () => buildBrief(ctx.config.project, ctx.brand);

    async function fetchFilled(t) {
        const r = await ctx.api.post(ctx.url('design.templates.fill', { template: t.id }), { brief: brief() });
        if (!r.ok) { ctx.toast(r.message || 'The template could not be prepared.', { kind: 'bad' }); return null; }
        return r.data;
    }
    const sameSize = (t) => { const p = activePage(ctx.studio.getState()); return Math.round(t.width) === Math.round(p.width) && Math.round(t.height) === Math.round(p.height); };

    const summary = summariseFill;

    async function useNew(t, button) {
        button.setAttribute('aria-busy', 'true');
        const result = await fetchFilled(t);
        button.removeAttribute('aria-busy');
        if (!result) return;
        const s = ctx.studio.getState();
        const pages = s.doc.pages.slice();
        const at = pages.findIndex((p) => p.id === s.activePageId) + 1;
        await ctx.uploads.ensureAssets(result.pages);
        const incoming = result.pages.map((p) => ({ ...p, id: undefined }));
        const r = ctx.run('doc.replacePages', { pages: [...pages.slice(0, at), ...incoming, ...pages.slice(at)] });
        if (r.ok) {
            const now = ctx.studio.getState().doc.pages;
            const target = now[at];
            if (target) ctx.studio.setActivePage(target.id);
            ctx.toast(`Added ${incoming.length === 1 ? 'a new page' : incoming.length + ' new pages'}. ${summary(result)}`.trim(), { action: { label: 'Undo', onClick: () => ctx.studio.undo() } });
            closeDetail();
            if (ctx.activeTab() && matchMedia('(max-width: 48rem)').matches) ctx.closeTabs();
        }
    }

    async function replaceHere(t, button) {
        const ok = await ctx.confirm({ title: 'Replace this page?', body: ['The page you are on will be replaced by "' + t.name + '".', 'We save a restore point first, and you can undo right after.' + (t.page_count > 1 ? ' Only the first page of this template is used.' : '')], confirmLabel: 'Replace this page', danger: true });
        if (!ok) return;
        button.setAttribute('aria-busy', 'true');
        try {
            const flushed = await ctx.studio.save.flush();
            if (flushed === 'conflict' || flushed === 'failed') { ctx.toast('Your latest changes are not saved yet, so the page was not replaced. Fix saving first.', { kind: 'warn' }); return; }
            const cp = await ctx.api.post(ctx.url('design.projects.versions.store'), { label: 'Before using the template ' + t.name });
            if (!cp.ok) { ctx.toast('We could not save a restore point, so nothing was changed. ' + (cp.message || ''), { kind: 'bad' }); return; }
            const result = await fetchFilled(t);
            if (!result) return;
            await ctx.uploads.ensureAssets(result.pages);
            const s = ctx.studio.getState();
            const cur = activePage(s);
            const first = { ...result.pages[0], id: cur.id, revision: cur.revision };
            const r = ctx.run('doc.replacePages', { pages: s.doc.pages.map((p) => (p.id === cur.id ? first : p)), activePageId: cur.id });
            if (r.ok) { ctx.toast('Page replaced. A restore point was saved. ' + summary(result), { action: { label: 'Undo', onClick: () => ctx.studio.undo() } }); closeDetail(); }
        } finally { button.removeAttribute('aria-busy'); }
    }

    async function openDetail(t) {
        list.hidden = true; el.querySelector('.st-tabhead').hidden = true; detail.hidden = false;
        const frame = h('div', { class: 'st-detail__frame lt-frame', vars: { w: t.width, h: t.height } });
        if (t.preview) renderer.draw(frame, t.preview);
        const back = h('button', { class: 'lt-btn lt-btn--ghost lt-btn--sm', attrs: { type: 'button' }, onClick: closeDetail }, icon('arrow-left'), 'All templates');
        const usesNew = h('button', { class: 'lt-btn lt-btn--block', attrs: { type: 'button' }, onClick: (e) => useNew(t, e.currentTarget) }, icon('plus'), t.page_count > 1 ? `Add ${t.page_count} new pages` : 'Use for a new page');
        const repl = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--block', attrs: { type: 'button' }, onClick: (e) => replaceHere(t, e.currentTarget) }, icon('refresh'), 'Replace this page');
        const notes = [];
        if (!sameSize(t)) notes.push(h('p', { class: 'st-note' }, `This template is ${Math.round(t.width)} by ${Math.round(t.height)}. The new page keeps that size; your other pages do not change.`));
        const slots = (t.slots || []).map((x) => x.label).slice(0, 8);
        detail.replaceChildren(back, frame, h('h3', { class: 'st-detail__name', text: t.name }), h('p', { class: 'st-note', text: t.description || '' }), ...notes,
            slots.length ? h('p', { class: 'st-note' }, h('strong', { text: 'You can fill in: ' }), slots.join(', ') + '.') : null,
            ctx.readOnly ? null : h('div', { class: 'st-stack' }, usesNew, repl));
        detail.scrollIntoView({ block: 'start' });
        back.focus();
    }
    function closeDetail() { detail.hidden = true; list.hidden = false; el.querySelector('.st-tabhead').hidden = false; }

    load(false);
    return { show() { /* keep state */ } };
}

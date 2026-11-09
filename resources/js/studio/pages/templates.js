// The template browser: category tabs, filters, search, sort, a lazy grid of previews drawn in the browser, and a detail
// dialog with a small form that fills the template with the person's own words before the design is made.
import '../../../css/pages/templates.css';
import { createApi } from './lib/api.js';
import { clear, debounce, h, icon, readBoot } from './lib/dom.js';
import { createPreviewer, createRenderQueue } from './lib/preview.js';
import { drawInto, loadIcons, whenVisible } from './lib/preview-ui.js';
import { activeFilters, buildApiQuery, buildPageQuery, CATEGORY_TABS, clearAll, FILTER_KEYS, parseQuery, sameQuery, withFilter, withTab } from './lib/template-query.js';

const boot = readBoot('templates-boot');
const root = document.getElementById('templates-root');
const api = createApi();
if (boot && root) start();

function start() {
    const queue = createRenderQueue();
    let previewer = null;
    let iconsPromise = null;
    const getPreviewer = async () => {
        if (!previewer) { iconsPromise ||= loadIcons(); previewer = createPreviewer({ manifest: boot.fonts || [], icons: await iconsPromise }); }
        return previewer;
    };

    const PER_PAGE = 24;
    let state = parseQuery(window.location.search);
    if (new URLSearchParams(window.location.search).get('format') && !state.filters.format) state = withFilter(state, 'format', new URLSearchParams(window.location.search).get('format'));
    let list = { status: 'loading', items: [], page: 0, lastPage: 1, total: 0, message: '' };
    let facets = { by_category: {}, by_kind: {}, total: 0, styles: {}, industries: {}, purposes: {}, objectives: {}, kinds: {}, categories: boot.categories };
    let seq = 0;
    const fullCache = new Map();

    // ---- layout -------------------------------------------------------------------------------------------------
    const tabs = h('nav', { class: 'lt-tabs cf-tabs', 'aria-label': 'Template categories' });
    const workspaceSwitch = h('div', { class: 'cf-workspace', hidden: true });
    const toolbar = h('div', { class: 'cf-toolbar' });
    const chips = h('div', { class: 'cf-chips-on', 'aria-label': 'Filters in use' });
    const count = h('p', { class: 'cf-count lt-small', role: 'status', 'aria-live': 'polite' });
    const results = h('div', { class: 'cf-results' });
    clear(root).append(tabs, workspaceSwitch, toolbar, chips, count, results);

    // ---- tabs ---------------------------------------------------------------------------------------------------
    function paintTabs() {
        clear(tabs);
        const entries = [['all', 'All templates', facets.total || null], ...CATEGORY_TABS.map((k) => [k, boot.categories[k] || k, facets.by_category[k] ?? null]), ['workspace', 'From your workspace', null]];
        for (const [key, label, n] of entries) {
            const current = state.tab === key;
            const q = buildPageQuery({ ...state, tab: key, filters: state.filters });
            tabs.append(h('a', { class: 'lt-tab', href: `${boot.urls.templates}${q}`, 'aria-current': current ? 'page' : null, dataset: { tab: key }, onclick: (e) => { e.preventDefault(); go(withTab(state, key)); } }, label, n !== null ? h('span', { class: 'cf-tab__n', text: String(n) }) : null));
        }
        clear(workspaceSwitch);
        workspaceSwitch.hidden = state.tab !== 'workspace';
        if (state.tab === 'workspace') {
            workspaceSwitch.append(h('div', { class: 'lt-seg', role: 'group', 'aria-label': 'Whose templates' }, [['team', 'My team'], ['user', 'Only me']].map(([k, label]) => h('button', { type: 'button', 'aria-pressed': state.workspace === k ? 'true' : 'false', onclick: () => go({ ...state, workspace: k }) }, label))));
        }
    }

    // ---- toolbar: search, filters, sort --------------------------------------------------------------------------
    function selectField(id, label, value, options, onChange, placeholder) {
        const sel = h('select', { id, class: 'lt-select cf-filter', 'aria-label': label, onchange: (e) => onChange(e.target.value) },
            h('option', { value: '', text: placeholder || label }),
            options);
        sel.value = value || '';
        return sel;
    }

    function opts(map, keys) {
        return Object.entries(map).filter(([k]) => !keys || keys.includes(k)).map(([k, label]) => h('option', { value: k, text: label }));
    }

    let searchInput = null;
    function paintToolbar() {
        const focused = document.activeElement && document.activeElement.id;
        clear(toolbar);
        searchInput = h('input', { id: 'tpl-search', class: 'lt-input', type: 'search', placeholder: 'Search, for example bakery or sale', 'aria-label': 'Search templates', value: state.q, autocomplete: 'off', maxlength: '80' });
        const doSearch = debounce(() => { go(withFilter(state, 'q', searchInput.value.trim()), { replace: true, keepFocus: true }); }, 350);
        searchInput.addEventListener('input', doSearch);
        searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doSearch.flush(); } });
        toolbar.append(h('div', { class: 'cf-toolbar__search lt-input-wrap' }, icon('search'), searchInput));

        const f = state.filters;
        const filterRow = h('div', { class: 'cf-filters', id: 'tpl-filters' },
            selectField('flt-industry', 'Kind of business', f.industry, opts(facets.industries), (v) => go(withFilter(state, 'industry', v))),
            selectField('flt-purpose', 'What it is for', f.purpose, opts(facets.purposes), (v) => go(withFilter(state, 'purpose', v))),
            selectField('flt-style', 'Look', f.style, opts(facets.styles), (v) => go(withFilter(state, 'style', v))),
            selectField('flt-platform', 'Where it is used', f.platform, opts(boot.platforms), (v) => go(withFilter(state, 'platform', v))),
            (() => {
                const sel = h('select', { id: 'flt-format', class: 'lt-select cf-filter', 'aria-label': 'Size', onchange: (e) => go(withFilter(state, 'format', e.target.value)) },
                    h('option', { value: '', text: 'Size' }),
                    boot.formats.map((g) => h('optgroup', { label: g.label }, g.items.map((x) => h('option', { value: x.key, text: `${x.label}, ${x.size_label}` })))));
                sel.value = f.format || '';
                return sel;
            })(),
            selectField('flt-objective', 'Goal', f.objective, opts(facets.objectives), (v) => go(withFilter(state, 'objective', v))));
        const toggle = h('button', { type: 'button', class: 'lt-btn lt-btn--secondary cf-filters-toggle', 'aria-expanded': 'false', 'aria-controls': 'tpl-filters', onclick: (e) => { const open = filterRow.classList.toggle('is-open'); e.currentTarget.setAttribute('aria-expanded', String(open)); } }, 'Filters');
        toolbar.append(toggle, filterRow,
            h('div', { class: 'cf-toolbar__sort' }, h('label', { for: 'tpl-sort', class: 'lt-sr', text: 'Sort by' }), (() => {
                const sel = h('select', { id: 'tpl-sort', class: 'lt-select', onchange: (e) => go({ ...state, sort: e.target.value }) }, h('option', { value: 'popular', text: 'Most popular' }), h('option', { value: 'newest', text: 'Newest' }));
                sel.value = state.sort;
                return sel;
            })()));
        if (focused) { const el = document.getElementById(focused); if (el && el !== document.activeElement && el.id !== 'tpl-search') el.focus(); }
    }

    function paintChips() {
        clear(chips);
        const on = activeFilters(state);
        chips.hidden = on.length === 0;
        if (!on.length) return;
        const label = (key, value) => {
            const map = { industry: facets.industries, purpose: facets.purposes, style: facets.styles, objective: facets.objectives, kind: facets.kinds, platform: boot.platforms };
            if (key === 'q') return `Search: ${value}`;
            if (key === 'format') { for (const g of boot.formats) for (const x of g.items) if (x.key === value) return x.label; }
            return (map[key] && map[key][value]) || value;
        };
        for (const { key, value } of on) chips.append(h('button', { type: 'button', class: 'lt-chip is-on', 'aria-label': `Remove filter: ${label(key, value)}`, onclick: () => go(withFilter(state, key, '')) }, label(key, value), icon('x')));
        chips.append(h('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => go(clearAll(state)) }, 'Clear all'));
    }

    // ---- results ------------------------------------------------------------------------------------------------
    function skeletons(n = 8) {
        return h('ul', { class: 'cf-tgrid', role: 'list', 'aria-busy': 'true', 'aria-label': 'Loading templates' }, Array.from({ length: n }, () => h('li', { class: 'cf-tcard' }, h('span', { class: 'cf-tcard__stage' }, h('span', { class: 'lt-skeleton cf-tcard__sk' })), h('span', { class: 'lt-skeleton cf-line' }), h('span', { class: 'lt-skeleton cf-line cf-line--short' }))));
    }

    function emptyState() {
        const filtered = activeFilters(state).length > 0;
        if (state.tab === 'workspace' && !filtered) {
            return h('div', { class: 'lt-empty' }, h('h2', { class: 'lt-empty__title', text: state.workspace === 'team' ? 'Your team has not saved a template yet' : 'You have not saved a template yet' }),
                h('p', { class: 'lt-empty__text', text: 'A template you or your team save from a finished design shows up here, with the pictures taken out so each person adds their own.' }),
                h('div', { class: 'lt-empty__actions' }, h('button', { type: 'button', class: 'lt-btn', onclick: () => go(withTab(state, 'all')) }, 'Browse all templates')));
        }
        return h('div', { class: 'lt-empty' }, h('h2', { class: 'lt-empty__title', text: filtered ? 'No templates match that' : 'No templates in this group yet' }),
            h('p', { class: 'lt-empty__text', text: filtered ? 'Try fewer filters or a different word. Search also finds kinds of business, like bakery or salon.' : 'Pick another group, or start from a blank canvas.' }),
            h('div', { class: 'lt-empty__actions' }, filtered ? h('button', { type: 'button', class: 'lt-btn', onclick: () => go(clearAll(state)) }, 'Clear filters') : null, h('a', { class: 'lt-btn lt-btn--secondary', href: boot.urls.start }, 'Start from scratch')));
    }

    function errorState() {
        return h('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, icon('alert'), h('div', {}, h('strong', { text: 'We could not load the templates.' }), h('p', { text: list.message || 'Try again.' })), h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => load(true) }, 'Try again'));
    }

    function paintResults() {
        clear(results);
        if (list.status === 'loading') { results.append(skeletons()); count.textContent = ''; return; }
        if (list.status === 'error') { results.append(errorState()); count.textContent = ''; return; }
        if (!list.items.length) { results.append(emptyState()); count.textContent = ''; return; }
        count.textContent = `Showing ${list.items.length} of ${list.total} ${list.total === 1 ? 'template' : 'templates'}`;
        const grid = h('ul', { class: 'cf-tgrid', role: 'list' });
        for (const t of list.items) grid.append(card(t));
        results.append(grid);
        if (list.page < list.lastPage) {
            const more = h('button', { type: 'button', class: 'lt-btn lt-btn--secondary cf-more', 'data-cf-more': true, onclick: () => loadMore() }, 'Show more templates');
            results.append(h('div', { class: 'cf-more-wrap' }, more));
            whenVisible(more, () => { if (list.status === 'ready' && !list.loadingMore) loadMore(); }, { rootMargin: '300px' });
        }
        if (list.moreError) results.append(h('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, icon('alert'), h('p', { text: list.moreError }), h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => loadMore() }, 'Try again')));
    }

    function card(t) {
        const f = h('span', { class: 'lt-frame lt-frame--blank cf-tcard__frame', style: { '--w': String(t.width), '--h': String(t.height) } });
        const state = { page: 0, pages: t.page_count || 1 };
        const label = [t.name, t.kind_label, t.page_count > 1 ? `${t.page_count} pages` : null].filter(Boolean).join(', ');
        const open = h('button', { type: 'button', class: 'cf-tcard__open', 'aria-label': `Open ${label}`, onclick: (e) => openDetail(t.slug, e.currentTarget) }, h('span', { class: 'cf-tcard__stage', 'aria-hidden': 'true' }, f));
        const li = h('li', { class: 'cf-tcard', dataset: { slug: t.slug } }, open,
            h('div', { class: 'cf-tcard__meta' }, h('span', { class: 'cf-tcard__name', text: t.name }), h('span', { class: 'cf-tcard__sub', text: [t.kind_label, t.format_label].filter(Boolean).join(', ') }),
                t.page_count > 1 ? h('span', { class: 'lt-tag', text: `${t.page_count} pages` }) : null));
        if (t.page_count > 1) {
            const flip = h('div', { class: 'cf-flip', role: 'group', 'aria-label': 'Flip through pages' });
            const readout = h('span', { class: 'cf-flip__n', 'aria-live': 'polite', text: `1 / ${t.page_count}` });
            const go = async (delta) => {
                const next = (state.page + delta + state.pages) % state.pages;
                const full = await fullTemplate(t.slug);
                if (!full) { window.dotToast && window.dotToast('We could not load the other pages. Try again.', 'bad'); return; }
                state.page = next;
                readout.textContent = `${next + 1} / ${state.pages}`;
                try { await drawInto(f, await getPreviewer(), queue, full, next); } catch (e) { /* keep the current page */ }
            };
            flip.append(h('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': 'Previous page', onclick: () => go(-1) }, icon('arrow-left')), readout, h('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': 'Next page', onclick: () => go(1) }, icon('arrow-right')));
            li.append(flip);
        }
        whenVisible(li, async () => {
            if (!t.preview) { f.classList.add('is-failed'); return; }
            try { await drawInto(f, await getPreviewer(), queue, { ...t, pages: [t.preview] }, 0); } catch (e) { f.classList.add('is-failed'); }
        });
        return li;
    }

    function fullTemplate(slug) {
        if (!fullCache.has(slug)) {
            fullCache.set(slug, api.get(`${boot.urls.template_base}/${encodeURIComponent(slug)}`).then((r) => { if (!r.ok) { fullCache.delete(slug); return null; } return r.data; }));
        }
        return fullCache.get(slug);
    }

    // ---- loading ------------------------------------------------------------------------------------------------
    async function load() {
        const mine = (seq += 1);
        list = { status: 'loading', items: [], page: 0, lastPage: 1, total: 0, message: '' };
        paintResults();
        const res = await api.get(`${boot.urls.templates}?${buildApiQuery(state, { page: 1, perPage: PER_PAGE })}`);
        if (mine !== seq) return;
        if (!res.ok) { list = { ...list, status: 'error', message: res.message }; paintResults(); return; }
        const meta = res.data.meta;
        list = { status: 'ready', items: res.data.data, page: meta.current_page, lastPage: meta.last_page, total: meta.total, message: '' };
        paintResults();
    }

    async function loadMore() {
        if (list.loadingMore || list.page >= list.lastPage) return;
        list.loadingMore = true;
        list.moreError = '';
        const mine = seq;
        const more = document.querySelector('[data-cf-more]');
        if (more) more.setAttribute('aria-busy', 'true');
        const res = await api.get(`${boot.urls.templates}?${buildApiQuery(state, { page: list.page + 1, perPage: PER_PAGE })}`);
        if (mine !== seq) return;
        list.loadingMore = false;
        if (!res.ok) { list.moreError = res.message; paintResults(); return; }
        list = { ...list, items: [...list.items, ...res.data.data.filter((t) => !list.items.some((x) => x.id === t.id))], page: res.data.meta.current_page, lastPage: res.data.meta.last_page, total: res.data.meta.total };
        paintResults();
    }

    async function loadFacets() {
        const res = await api.get(boot.urls.facets);
        if (res.ok && res.data) { facets = { ...facets, ...res.data }; facets.kinds = res.data.kinds || {}; paintTabs(); paintToolbar(); paintChips(); }
    }

    // ---- navigation ---------------------------------------------------------------------------------------------
    function go(next, { replace = false, keepFocus = false } = {}) {
        const changed = !sameQuery(state, next) || state.tab !== next.tab || state.workspace !== next.workspace;
        state = next;
        const url = `${window.location.pathname.startsWith('/design/templates') ? boot.urls.templates : window.location.pathname}${buildPageQuery(state)}`;
        try { (replace ? history.replaceState : history.pushState).call(history, { cf: 1 }, '', url); } catch (e) { /* the address just does not update */ }
        paintTabs(); if (!keepFocus) paintToolbar(); paintChips();
        if (changed) load();
    }
    window.addEventListener('popstate', () => { state = parseQuery(window.location.search); paintTabs(); paintToolbar(); paintChips(); load(); });

    // ---- detail dialog ------------------------------------------------------------------------------------------
    const dialog = document.getElementById('template-detail');
    const detailHost = dialog.querySelector('[data-cf-detail]');
    let detailOpener = null;
    let detailToken = 0;

    dialog.addEventListener('close', () => {
        detailToken += 1;
        try { const u = new URL(window.location.href); u.searchParams.delete('open'); if (/\/design\/templates\/[^/]+$/.test(u.pathname)) u.pathname = '/design/templates'; history.replaceState({ cf: 1 }, '', u.pathname + u.search); } catch (e) { /* ignore */ }
        if (detailOpener && document.contains(detailOpener)) detailOpener.focus();
        detailOpener = null;
    });

    async function openDetail(slug, opener) {
        detailOpener = opener || null;
        const token = (detailToken += 1);
        clear(detailHost).append(h('div', { class: 'cf-detail__loading', role: 'status' }, h('div', { class: 'lt-progress lt-progress--busy', 'aria-label': 'Loading' }, h('span')), h('p', { class: 'lt-small', text: 'Opening the template...' })));
        dialog.querySelector('#template-detail-title').textContent = 'Template';
        if (!dialog.open) dialog.showModal();
        try { const u = new URL(window.location.href); u.searchParams.set('open', slug); history.replaceState({ cf: 1 }, '', u.pathname + u.search); } catch (e) { /* ignore */ }
        const full = await fullTemplate(slug);
        if (token !== detailToken) return;
        if (!full) {
            clear(detailHost).append(h('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, icon('alert'), h('div', {}, h('strong', { text: 'We could not open that template.' }), h('p', { text: 'It may have been removed, or the connection dropped.' })),
                h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => openDetail(slug, detailOpener) }, 'Try again')));
            return;
        }
        renderDetail(full, token);
    }

    function goalFor(t) {
        for (const [goal, purpose] of Object.entries(boot.goals)) if (purpose && (t.purposes || []).includes(purpose)) return goal;
        return 'something_else';
    }

    function renderDetail(t, token) {
        dialog.querySelector('#template-detail-title').textContent = t.name;
        const textSlots = (t.slots || []).filter((s) => s.type === 'text');
        const pictureSlots = (t.slots || []).filter((s) => s.type === 'image');
        const prev = boot.previous || {};
        const kit = boot.kit;
        const values = {};
        const fromKit = { tagline: kit && kit.tagline, website: kit && kit.website, ...(kit ? kit.contacts : {}) };
        for (const s of textSlots) {
            if (s.key === 'business_name') values[s.key] = prev.business_name || '';
            else values[s.key] = (prev.details && prev.details[s.key]) || fromKit[s.key] || '';
        }
        const state = { business_name: prev.business_name || '', values, brand: false, page: 0, errors: {}, busy: false };

        const stage = h('span', { class: 'lt-frame lt-frame--blank cf-detail__frame', style: { '--w': String(t.width), '--h': String(t.height) } });
        const note = h('p', { class: 'lt-meta cf-detail__note', role: 'status', 'aria-live': 'polite' });
        const thumbs = h('ul', { class: 'cf-pages', role: 'list', 'aria-label': 'Pages' });
        let filled = null;

        async function paintMain() {
            try { await drawInto(stage, await getPreviewer(), queue, t, state.page, { fill: filled }); } catch (e) { note.textContent = 'The preview could not be drawn.'; }
            for (const b of thumbs.querySelectorAll('button')) b.setAttribute('aria-current', Number(b.dataset.page) === state.page ? 'true' : 'false');
        }

        function briefFor() {
            const details = {};
            for (const s of textSlots) if (s.key !== 'business_name' && (state.values[s.key] || '').trim()) details[s.key] = state.values[s.key].trim();
            const brief = { goal: goalFor(t), details };
            const name = (state.business_name || '').trim();
            if (name) brief.business_name = name;
            if (kit) { brief.brand_kit_id = kit.id; brief.apply_brand = state.brand; } else brief.apply_brand = false;
            return brief;
        }

        const refill = debounce(async () => {
            const brief = briefFor();
            const any = brief.business_name || Object.keys(brief.details).length;
            if (!any && !state.brand) { filled = null; note.textContent = ''; paintMain(); return; }
            const res = await api.post(`${boot.urls.template_base}/${t.id}/fill`, { brief });
            if (token !== detailToken) return;
            if (res.ok && res.data && Array.isArray(res.data.pages)) {
                const values2 = { ...Object.fromEntries(Object.entries(brief.details)), business_name: brief.business_name || '', __apply: String(brief.apply_brand) };
                filled = { pages: res.data.pages, values: values2 };
                const left = (res.data.left || []).filter((x) => x.reason === 'no_value').length;
                note.textContent = (res.data.warnings || []).length ? res.data.warnings[0] : (left ? 'Spots you leave empty keep the sample words until you change them in the editor.' : 'Showing your words.');
                paintMain();
            } else if (res.status === 422) {
                note.textContent = 'Fix the highlighted answer to update the preview.';
            } else {
                note.textContent = res.status === 429 ? 'The live preview is paused for a moment. You can still make the design.' : 'The preview could not update. You can still make the design.';
            }
        }, 650);

        if ((t.pages || []).length > 1) {
            (t.pages || []).forEach((p, i) => {
                const thumb = h('span', { class: 'lt-frame lt-frame--blank cf-pages__frame', style: { '--w': String(t.width), '--h': String(t.height) } });
                thumbs.append(h('li', {}, h('button', { type: 'button', class: 'cf-pages__btn', dataset: { page: String(i) }, 'aria-label': `Page ${i + 1} of ${t.pages.length}`, 'aria-current': i === 0 ? 'true' : 'false', onclick: () => { state.page = i; paintMain(); } }, thumb)));
                whenVisible(thumb, async () => { try { await drawInto(thumb, await getPreviewer(), queue, t, i); } catch (e) { /* neutral */ } });
            });
        }

        // the form
        const form = h('form', { class: 'cf-fill', novalidate: true, 'aria-labelledby': 'fill-title' });
        form.append(h('h3', { class: 'lt-h3', id: 'fill-title', text: 'Make it yours' }), h('p', { class: 'lt-small', text: 'Type your own words. The preview updates as you go, and you can change everything in the editor.' }));
        const field = (key, label, help, example, required, multiline) => {
            const id = `fill-${key}`;
            const input = multiline ? h('textarea', { id, class: 'lt-textarea', rows: '2', placeholder: example || '' }) : h('input', { id, class: 'lt-input', type: 'text', placeholder: example || '', maxlength: '140', autocomplete: 'off' });
            input.value = key === 'business_name' ? state.business_name : (state.values[key] || '');
            input.addEventListener('input', () => { if (key === 'business_name') state.business_name = input.value; else state.values[key] = input.value; delete state.errors[key]; paintFieldErrors(); refill(); });
            return h('div', { class: 'lt-field' }, h('label', { class: 'lt-label', for: id }, label, required ? h('span', { class: 'cf-req', text: ' (needed)' }) : null), input, help ? h('p', { class: 'lt-hint', text: help }) : null, h('p', { class: 'lt-error', id: `${id}-error`, 'data-fill-error': key, hidden: true }));
        };
        const nameSlot = textSlots.find((s) => s.key === 'business_name');
        form.append(field('business_name', 'Your business name', 'We put it on your design, spelled exactly like this.', (nameSlot && nameSlot.example) || 'Thandi\'s Bakery', true));
        for (const s of textSlots.filter((x) => x.key !== 'business_name')) form.append(field(s.key, s.label, s.help, s.example, false, s.key === 'description'));
        if (pictureSlots.length) form.append(h('p', { class: 'lt-hint cf-pics', text: `${pictureSlots.length > 1 ? 'Pictures are' : 'The picture is'} added in the editor.` }));

        if (kit) {
            const swId = 'fill-brand';
            const sw = h('input', { type: 'checkbox', id: swId, role: 'switch', class: 'cf-switch__input', onchange: (e) => { state.brand = e.target.checked; refill(); } });
            form.append(h('label', { class: 'cf-switch', for: swId }, sw, h('span', { class: 'cf-switch__text' }, h('span', { class: 'cf-switch__label', text: 'Fill with my brand colours' }), h('span', { class: 'lt-hint', text: `Uses colours and fonts from ${kit.name}. Off keeps the template’s own colours.` })), h('span', { class: 'cf-swatches', 'aria-hidden': 'true' }, kit.colors.map((c) => h('span', { class: 'cf-swatch', style: { '--c': c.hex } })))));
        }

        const status = h('div', { class: 'cf-create-status', 'aria-live': 'polite' });
        const useBtn = h('button', { type: 'submit', class: 'lt-btn' }, 'Use this template');
        form.append(status, h('div', { class: 'cf-fill__actions' }, useBtn, h('button', { type: 'button', class: 'lt-btn lt-btn--ghost', 'data-lt-dialog-close': true }, 'Cancel')));

        function paintFieldErrors() {
            for (const el of form.querySelectorAll('[data-fill-error]')) {
                const m = state.errors[el.dataset.fillError] || '';
                el.textContent = m; el.hidden = !m;
                const input = document.getElementById(`fill-${el.dataset.fillError}`);
                if (input) { if (m) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', el.id); } else { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); } }
            }
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (state.busy) return;
            state.errors = {};
            if (!(state.business_name || '').trim()) { state.errors.business_name = 'Add your business name. We put it on your design for you.'; paintFieldErrors(); document.getElementById('fill-business_name').focus(); return; }
            state.busy = true; useBtn.setAttribute('aria-busy', 'true'); useBtn.disabled = true;
            clear(status).append(h('p', { class: 'lt-small', text: 'Making your design...' }));
            const res = await api.post(boot.urls.from_brief, { brief: briefFor(), template: t.slug });
            if (res.ok && res.data && res.data.project) {
                clear(status).append(h('p', { class: 'lt-small cf-ok', text: 'Done. Opening your design...' }));
                window.location.assign(res.data.project.url);
                return;
            }
            state.busy = false; useBtn.removeAttribute('aria-busy'); useBtn.disabled = false;
            clear(status);
            if (res.status === 422 && res.errors && Object.keys(res.errors).length) {
                for (const [k, m] of Object.entries(res.errors)) {
                    if (k === 'business_name') state.errors.business_name = m;
                    else if (k.startsWith('details.')) state.errors[k.slice(8)] = m;
                }
                const other = Object.entries(res.errors).filter(([k]) => k !== 'business_name' && !k.startsWith('details.')).map(([, m]) => m);
                if (other.length) status.append(h('div', { class: 'lt-status lt-status--bad', role: 'alert' }, icon('alert'), h('p', { class: 'lt-status__text', text: other[0] })));
                paintFieldErrors();
                const first = form.querySelector('[aria-invalid="true"]'); if (first) first.focus();
                return;
            }
            status.append(h('div', { class: 'lt-status lt-status--bad', role: 'alert' }, icon('alert'), h('p', { class: 'lt-status__text', text: `We could not make your design. ${res.message}` })));
        });

        const slotChips = h('ul', { class: 'cf-slots', role: 'list', 'aria-label': 'What you can change' }, (t.slots || []).map((s) => h('li', { class: 'lt-tag', text: s.label })));
        const facts = h('dl', { class: 'cf-facts' },
            h('div', {}, h('dt', { text: 'Size' }), h('dd', { text: t.format ? `${t.format.label}, ${t.format.size_label}` : `${t.width} x ${t.height} px` })),
            h('div', {}, h('dt', { text: 'Pages' }), h('dd', { text: String(t.page_count || 1) })),
            t.platforms && t.platforms.length ? h('div', {}, h('dt', { text: 'Fits' }), h('dd', { text: t.platforms.map((p) => boot.platforms[p] || p).join(', ') })) : null);

        clear(detailHost).append(h('div', { class: 'cf-detail__grid' },
            h('div', { class: 'cf-detail__preview' }, h('div', { class: 'cf-detail__stage' }, stage), note, thumbs),
            h('div', { class: 'cf-detail__side' }, t.description ? h('p', { class: 'cf-detail__desc', text: t.description }) : null, facts,
                h('div', {}, h('h3', { class: 'lt-h3', text: 'What you can change' }), slotChips), form)));
        paintMain();
        if (state.business_name || Object.values(state.values).some(Boolean)) refill();
        document.getElementById('fill-business_name') && document.getElementById('fill-business_name').focus({ preventScroll: true });
    }

    // ---- go -----------------------------------------------------------------------------------------------------
    paintTabs(); paintToolbar(); paintChips();
    load();
    loadFacets();
    if (boot.open) openDetail(boot.open, null);
}

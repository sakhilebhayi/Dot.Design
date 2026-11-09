/*
 * The Assistant panel (DOM). Draws what the controller exposes; holds no rules of its own. Plain language in
 * Simple mode and Pro mode alike. Text is always set with textContent, never as markup; the only parsed markup is
 * the SVG our own renderer produced for the before/after preview.
 */
import { createIconRegistry } from '../engine/icons.js';
import { createCanvasMeasurer } from '../engine/text-layout.js';
import { renderPageToSvgString } from '../engine/render.js';
import { COPY_KINDS, IMPROVE_PRESETS } from './controller.js';

const SVG_PARSER = typeof DOMParser !== 'undefined' ? new DOMParser() : null;

function h(tag, attrs = {}, kids = []) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === false || v === null || v === undefined) continue;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of [].concat(kids)) if (kid) el.append(kid);
    return el;
}

const plural = (n, one, many) => (n === 1 ? one : many);

export function mountPanel(root, ctx, controller, options = {}) {
    const { machine } = controller;
    const view = { tab: 'improve', draft: '', form: { kind: 'headline', business_name: '', product: '', audience: '', details: '', count: '3' } };
    let destroyed = false;
    let fixesCache = controller.fixes();
    let raf = null;
    const offs = [];

    root.classList.add('asst');

    // --------------------------------------------------------------- shell
    const tabs = h('div', { class: 'asst-tabs', role: 'tablist', 'aria-label': 'Assistant sections' });
    const tabButtons = {};
    for (const [key, label] of [['improve', 'Improve this page'], ['write', 'Write text']]) {
        const b = h('button', { type: 'button', role: 'tab', id: `asst-tab-${key}`, 'aria-controls': `asst-panel-${key}`, class: 'asst-tab', text: label, onclick: () => selectTab(key) });
        b.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); selectTab(key === 'improve' ? 'write' : 'improve', true); }
        });
        tabButtons[key] = b;
        tabs.append(b);
    }
    const live = h('div', { class: 'asst-live', role: 'status', 'aria-live': 'polite' });
    const improvePanel = h('div', { id: 'asst-panel-improve', role: 'tabpanel', 'aria-labelledby': 'asst-tab-improve', class: 'asst-pane' });
    const writePanel = h('div', { id: 'asst-panel-write', role: 'tabpanel', 'aria-labelledby': 'asst-tab-write', class: 'asst-pane', hidden: true });
    root.append(tabs, live, improvePanel, writePanel);

    function selectTab(key, focus) {
        view.tab = key;
        for (const [k, b] of Object.entries(tabButtons)) { b.setAttribute('aria-selected', String(k === key)); b.tabIndex = k === key ? 0 : -1; }
        improvePanel.hidden = key !== 'improve';
        writePanel.hidden = key !== 'write';
        if (focus) tabButtons[key].focus();
    }

    // ------------------------------------------------------------- improve
    function renderImprove() {
        const keepScroll = root.scrollTop;
        improvePanel.replaceChildren();
        const s = machine.state;

        if (s.phase !== 'idle') {
            improvePanel.append(renderReview(s));
        } else {
            improvePanel.append(renderFixes(), renderAsk());
        }
        root.scrollTop = keepScroll;

        live.textContent = s.phase === 'working' ? (s.source === 'ai' ? 'The assistant is looking at your page.' : 'Checking your page.')
            : s.phase === 'review' ? 'A suggestion is ready to review.'
            : s.phase === 'applying' ? 'Applying.'
            : s.notice || (['error', 'nothing', 'limit', 'unavailable'].includes(s.phase) ? s.message : '');
    }

    function renderFixes() {
        const { available, unavailable } = fixesCache;
        const sec = h('section', { class: 'asst-sec', 'aria-labelledby': 'asst-h-fixes' });
        sec.append(h('h3', { id: 'asst-h-fixes', class: 'asst-h', text: 'Quick fixes' }), h('p', { class: 'asst-note', text: 'Automatic. These follow fixed rules and do not use AI. You review each one before it is applied.' }));

        if (machine.state.notice) sec.append(h('p', { class: 'asst-ok', text: machine.state.notice }));

        if (!available.length) {
            sec.append(h('p', { class: 'asst-empty', text: controller.selection().length ? 'Nothing to fix in the selected items.' : 'Nothing to fix on this page. Text is readable, inside the margins and lined up.' }));
        } else {
            const ul = h('ul', { class: 'asst-list' });
            for (const fix of available) {
                ul.append(h('li', {}, h('button', { type: 'button', class: 'asst-row', 'data-fix': fix.key, onclick: () => controller.runAutomatic(fix) }, [
                    h('span', { class: 'asst-row-main' }, [h('span', { class: 'asst-row-title', text: fix.label }), h('span', { class: 'asst-row-help', text: fix.help })]),
                    h('span', { class: 'asst-row-meta', text: `${fix.count} ${plural(fix.count, 'change', 'changes')}` }),
                ])));
            }
            sec.append(ul);
        }

        if (unavailable.length) {
            sec.append(h('ul', { class: 'asst-list asst-list-quiet' }, unavailable.map((u) => h('li', { class: 'asst-row asst-row-off' }, h('span', { class: 'asst-row-main' }, [h('span', { class: 'asst-row-title', text: u.label }), h('span', { class: 'asst-row-help', text: u.reason })])))));
        }
        return sec;
    }

    function renderAsk() {
        const sec = h('section', { class: 'asst-sec', 'aria-labelledby': 'asst-h-ask' });
        sec.append(h('h3', { id: 'asst-h-ask', class: 'asst-h', text: 'Ask the assistant' }));

        if (!controller.status.enabled) {
            sec.append(renderOffWell());
            return sec;
        }

        const sel = controller.selection().length;
        sec.append(h('p', { class: 'asst-note', text: sel ? `Changes apply to the ${sel} ${plural(sel, 'item', 'items')} you selected.` : 'Changes apply to this whole page.' }));

        const chips = h('div', { class: 'asst-chips', role: 'group', 'aria-label': 'Ideas' });
        for (const p of IMPROVE_PRESETS) chips.append(h('button', { type: 'button', class: 'asst-chip', text: p.label, onclick: () => controller.ask(p.instruction) }));
        sec.append(chips);

        const id = 'asst-free';
        const area = h('textarea', { id, class: 'asst-input', rows: 3, maxlength: 500, placeholder: 'For example: make the price bigger and the background calmer' });
        area.value = view.draft;
        const go = h('button', { type: 'button', class: 'asst-btn asst-btn-primary', text: 'Ask', disabled: view.draft.trim().length < 3, onclick: submit });
        area.addEventListener('input', () => { view.draft = area.value; go.disabled = area.value.trim().length < 3; });
        area.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter' && !go.disabled) { e.preventDefault(); submit(); } });
        function submit() { const text = view.draft.trim(); view.draft = ''; controller.ask(text); }

        sec.append(h('div', { class: 'asst-field' }, [h('label', { for: id, class: 'asst-label', text: 'Or say what you want' }), area]), h('div', { class: 'asst-actions' }, go));
        sec.append(renderMeter(), h('p', { class: 'asst-note', text: 'The assistant changes layout, colours and sizes. It never rewrites your words and it does not make pictures.' }));
        return sec;
    }

    function renderOffWell() {
        return h('div', { class: 'asst-well', role: 'note' }, [
            h('p', { class: 'asst-well-title', text: 'The assistant is switched off' }),
            h('p', { text: controller.status.message || 'It is not switched on for this workspace. Ask the person who runs your workspace to turn it on. Quick fixes, templates and sizes still work.' }),
        ]);
    }

    function renderMeter() {
        const u = machine.state.usage && machine.state.usage.user;
        if (!u || !u.limit) return null;
        const pct = Math.min(100, Math.round((u.used / u.limit) * 100));
        const low = pct >= 80;
        const bar = h('div', { class: `asst-meter-bar${low ? ' is-low' : ''}`, role: 'progressbar', 'aria-label': 'Assistant requests used today', 'aria-valuemin': 0, 'aria-valuemax': u.limit, 'aria-valuenow': u.used }, h('span', { class: 'asst-meter-fill' }));
        bar.firstChild.style.transform = `scaleX(${pct / 100})`;
        return h('div', { class: 'asst-meter' }, [bar, h('p', { class: 'asst-meter-label', text: `${u.used} of ${u.limit} requests used today${u.used >= u.limit ? '. Quick fixes still work.' : low ? '. Almost used up.' : ''}` })]);
    }

    // -------------------------------------------------------------- review
    function renderReview(s) {
        const box = h('section', { class: 'asst-sec asst-review', 'aria-label': 'Suggestion' });

        if (s.phase === 'working') {
            box.append(
                h('p', { class: 'asst-working', text: s.source === 'ai' ? 'The assistant is looking at your page...' : 'Checking your page...' }),
                h('div', { class: 'asst-skel', 'aria-hidden': 'true' }, [h('span'), h('span'), h('span')]),
                h('div', { class: 'asst-actions' }, h('button', { type: 'button', class: 'asst-btn', text: 'Cancel', onclick: () => controller.dismiss() })),
                h('p', { class: 'asst-note', text: 'You can keep working. Nothing changes until you press Apply.' }),
            );
            return box;
        }

        if (s.phase === 'error' || s.phase === 'nothing' || s.phase === 'limit' || s.phase === 'unavailable') {
            const bad = s.phase === 'error';
            box.append(h('p', { class: `asst-msg${bad ? ' is-bad' : ''}`, role: bad ? 'alert' : null, text: s.message }));
            if (s.phase === 'unavailable') box.append(renderOffWell());
            if (s.phase === 'limit') box.append(renderMeter());
            const actions = h('div', { class: 'asst-actions' });
            if (bad && s.retryable && s.label) actions.append(h('button', { type: 'button', class: 'asst-btn asst-btn-primary', text: 'Try again', onclick: () => { const label = s.label; const src = s.source; controller.dismiss(); if (src === 'ai') controller.ask(label); else { const fix = fixesCache.available.find((f) => f.label === label); if (fix) controller.runAutomatic(fix); } } }));
            actions.append(h('button', { type: 'button', class: 'asst-btn', text: 'Back', onclick: () => controller.dismiss() }));
            box.append(actions);
            return box;
        }

        const p = s.proposal;
        const busy = s.phase === 'applying';
        box.append(h('div', { class: 'asst-head' }, [
            h('h3', { class: 'asst-h', text: p.summary || 'Suggestion' }),
            h('span', { class: `asst-tag${p.source === 'ai' ? ' is-accent' : ''}`, text: p.source === 'ai' ? 'Assistant suggestion' : 'Automatic fix' }),
        ]));

        if (p.type === 'suggest_resize') {
            box.append(renderResize(p));
        } else {
            box.append(renderCompare(p), renderChanges(p));
            for (const n of p.notes || []) box.append(h('p', { class: 'asst-warn', text: n }));
        }

        if (s.message) box.append(h('p', { class: 'asst-msg is-bad', role: 'alert', text: s.message }));

        const apply = p.type === 'suggest_resize'
            ? h('button', { type: 'button', class: 'asst-btn asst-btn-primary', text: 'Open Resize', onclick: () => { controller.openResize(p.format_key); controller.dismiss(); } })
            : h('button', { type: 'button', class: 'asst-btn asst-btn-primary', text: busy ? 'Applying...' : 'Apply', 'aria-busy': busy, disabled: busy, onclick: () => controller.apply() });
        box.append(h('div', { class: 'asst-actions' }, [apply, h('button', { type: 'button', class: 'asst-btn', text: 'Dismiss', disabled: busy, onclick: () => controller.dismiss() })]));
        if (p.type !== 'suggest_resize') box.append(h('p', { class: 'asst-note', text: 'Applying is one step. Undo brings the old version back, and a copy of the current design is saved first.' }));
        return box;
    }

    function renderCompare(p) {
        const before = controller.activePage();
        const afterPage = controller.preview();
        const fig = (label, page) => {
            const frame = h('div', { class: 'asst-page' });
            const r = page.width / page.height;
            frame.style.aspectRatio = String(r);
            frame.style.maxWidth = `${Math.round((r >= 1.25 ? 300 : 190) * (r >= 1.25 ? 1 : r))}px`;
            const svg = drawPage(page, ctx, options);
            if (svg) frame.append(svg); else frame.append(h('span', { class: 'asst-noprev', text: 'No preview' }));
            return h('figure', { class: 'asst-fig' }, [h('div', { class: 'asst-stage' }, frame), h('figcaption', { text: label })]);
        };
        if (!afterPage) return h('p', { class: 'asst-msg is-bad', text: 'This suggestion no longer fits the page. Dismiss it and ask again.' });
        const wide = before.width / before.height >= 1.25;
        return h('div', { class: `asst-compare${wide ? ' is-stacked' : ''}` }, [fig('Before', before), fig('After', afterPage)]);
    }

    function renderChanges(p) {
        const list = (p.changes || []);
        if (!list.length) return null;
        const ul = h('ul', { class: 'asst-changes', 'aria-label': 'What will change' });
        const hexRe = /^#[0-9a-f]{6}$/i;
        const swatch = (hex) => { const sw = h('span', { class: 'asst-swatch', role: 'img', 'aria-label': `Colour ${hex}`, title: hex }); sw.style.backgroundColor = hex; return sw; };
        for (const c of list) {
            const li = h('li', {}, h('span', { class: 'asst-change-text', text: c.text }));
            if ((c.prop === 'color' || c.prop === 'fill') && typeof c.to === 'string' && hexRe.test(c.to)) {
                li.append(h('span', { class: 'asst-swatches' }, [typeof c.from === 'string' && hexRe.test(c.from) ? swatch(c.from) : null, swatch(c.to)]));
            }
            ul.append(li);
        }
        return h('div', {}, [h('p', { class: 'asst-label', text: `${list.length} ${plural(list.length, 'change', 'changes')}` }), ul]);
    }

    function renderResize(p) {
        const f = (options.formats || []).find((x) => x.key === p.format_key);
        const w = f ? (f.px ? f.px.width : f.width) : 1; const hh = f ? (f.px ? f.px.height : f.height) : 1;
        const frame = h('div', { class: 'asst-page' });
        frame.style.aspectRatio = String(w / hh);
        frame.style.maxWidth = `${Math.round(120 * (w / hh))}px`;
        return h('div', { class: 'asst-resize' }, [h('div', { class: 'asst-stage' }, frame), h('p', { text: `${f ? f.label : p.format_key}${f && f.size_label ? `, ${f.size_label}` : ''}. Your design is not changed by this suggestion.` })]);
    }

    // ---------------------------------------------------------------- write
    function renderWrite() {
        writePanel.replaceChildren();
        const c = controller.copy;
        const sec = h('section', { class: 'asst-sec', 'aria-labelledby': 'asst-h-write' });
        sec.append(h('h3', { id: 'asst-h-write', class: 'asst-h', text: 'Write text for this design' }));

        if (!controller.status.enabled) {
            sec.append(renderOffWell());
            writePanel.append(sec);
            return;
        }

        sec.append(h('p', { class: 'asst-note', text: 'Suggestions only use the numbers, prices and links you type below. You choose where each one goes.' }));
        const field = (id, label, input) => h('div', { class: 'asst-field' }, [h('label', { for: id, class: 'asst-label', text: label }), input]);
        const text = (key, id, label, ph, max = 120) => { const i = h('input', { id, class: 'asst-input', type: 'text', maxlength: max, placeholder: ph, autocomplete: 'off' }); i.value = view.form[key]; i.addEventListener('input', () => { view.form[key] = i.value; }); return field(id, label, i); };

        const kind = h('select', { id: 'asst-kind', class: 'asst-input' }, COPY_KINDS.map((k) => h('option', { value: k.key, text: k.label, selected: k.key === view.form.kind })));
        kind.addEventListener('change', () => { view.form.kind = kind.value; });
        const count = h('select', { id: 'asst-count', class: 'asst-input' }, [1, 2, 3, 4, 5].map((n) => h('option', { value: String(n), text: String(n), selected: String(n) === view.form.count })));
        count.addEventListener('change', () => { view.form.count = count.value; });
        const details = h('textarea', { id: 'asst-details', class: 'asst-input', rows: 3, maxlength: 400, placeholder: 'Prices, dates, phone numbers or links to include' });
        details.value = view.form.details;
        details.addEventListener('input', () => { view.form.details = details.value; });

        const busy = c.phase === 'working';
        const go = h('button', { type: 'button', class: 'asst-btn asst-btn-primary', text: busy ? 'Writing...' : 'Suggest text', 'aria-busy': busy, disabled: busy, onclick: () => controller.suggestCopy({ kind: view.form.kind, business_name: view.form.business_name, product: view.form.product, audience: view.form.audience, details: view.form.details, count: Number(view.form.count) }) });
        sec.append(
            field('asst-kind', 'What do you need?', kind),
            text('business_name', 'asst-biz', 'Business or brand name', 'Thandi Bakery'),
            text('product', 'asst-prod', 'What are you promoting?', 'Hot pies for winter', 200),
            text('audience', 'asst-aud', 'Who is it for?', 'Families and office workers', 200),
            field('asst-details', 'Details to include', details),
            field('asst-count', 'How many suggestions', count),
            h('div', { class: 'asst-actions' }, go),
            renderMeter(),
        );

        if (busy) sec.append(h('div', { class: 'asst-skel', 'aria-hidden': 'true' }, [h('span'), h('span'), h('span')]));

        if (['error', 'limit', 'nothing', 'unavailable'].includes(c.phase)) sec.append(h('p', { class: `asst-msg${c.phase === 'error' ? ' is-bad' : ''}`, role: c.phase === 'error' ? 'alert' : null, text: c.message }));

        if (c.phase === 'ready') {
            const canReplace = !!controller.selectedText();
            const ul = h('ul', { class: 'asst-suggestions' });
            for (const s of c.suggestions) {
                ul.append(h('li', {}, [
                    h('p', { class: 'asst-suggestion', text: s.text }),
                    h('div', { class: 'asst-actions' }, [
                        h('button', { type: 'button', class: 'asst-btn', text: 'Add to page', onclick: () => place(s.text, 'add') }),
                        h('button', { type: 'button', class: 'asst-btn', text: 'Replace selected text', disabled: !canReplace, title: canReplace ? null : 'Select one text box on the page first', onclick: () => place(s.text, 'replace') }),
                    ]),
                ]));
            }
            sec.append(h('p', { class: 'asst-label', text: `${c.suggestions.length} ${plural(c.suggestions.length, 'suggestion', 'suggestions')}` }), ul);
            if (c.dropped) sec.append(h('p', { class: 'asst-note', text: `${c.dropped} ${plural(c.dropped, 'suggestion was', 'suggestions were')} left out because ${plural(c.dropped, 'it had', 'they had')} numbers or contact details you did not give, or ${plural(c.dropped, 'was', 'were')} too long.` }));
            if (!canReplace) sec.append(h('p', { class: 'asst-note', text: 'Select one text box on the page to replace its words.' }));
        }
        writePanel.append(sec);
    }

    function place(text, mode) {
        const res = controller.insertCopy(text, mode);
        if (res.ok) ctx.toast(mode === 'replace' ? 'Text replaced. Undo brings the old words back.' : 'Text added to the page.', { kind: 'ok' });
        else ctx.toast(res.message, { kind: 'warn' });
    }

    // ---------------------------------------------------------------- wiring
    function schedule() {
        if (raf !== null || destroyed) return;
        raf = setTimeout(() => { raf = null; if (destroyed) return; fixesCache = controller.fixes(); if (machine.state.phase === 'idle') renderImprove(); renderWriteIfIdle(); }, 250);
    }
    const renderWriteIfIdle = () => { if (!writePanel.hidden || controller.copy.phase !== 'working') renderWrite(); };

    offs.push(machine.subscribe(() => renderImprove()));
    offs.push(controller.onCopy(() => renderWrite()));
    offs.push(controller.onStatus(() => { renderImprove(); renderWrite(); }));
    for (const ev of ['selection', 'page']) { const off = ctx.on(ev, schedule); if (typeof off === 'function') offs.push(off); }
    const offChange = ctx.studio.on('change', (e) => { if (!e || !e.system) schedule(); });
    if (typeof offChange === 'function') offs.push(offChange);

    selectTab('improve');
    renderImprove();
    renderWrite();
    controller.loadStatus();

    return {
        destroy() {
            destroyed = true;
            if (raf !== null) clearTimeout(raf);
            offs.forEach((off) => { try { off(); } catch (e) { /* already gone */ } });
            root.replaceChildren();
            root.classList.remove('asst');
        },
        refresh() { fixesCache = controller.fixes(); renderImprove(); renderWrite(); },
    };
}

/** The page drawn by the same renderer as the canvas, on a scratch copy. Returns an <svg> element or null. */
export function drawPage(page, ctx, options = {}) {
    if (!SVG_PARSER) return null;
    try {
        const fonts = ctx.studio.fonts || null;
        const measure = options.measurer || (fonts ? createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight }) : undefined);
        const assets = new Map(((ctx.config && ctx.config.assets) ? (Array.isArray(ctx.config.assets) ? ctx.config.assets : Object.values(ctx.config.assets)) : []).map((a) => [Number(a.id), a]));
        const markup = renderPageToSvgString(page, {
            forExport: false,
            measure,
            icons: createIconRegistry(ctx.config && ctx.config.icons),
            fonts,
            assetUrl: (id) => (assets.get(Number(id)) || {}).url || null,
            assetSize: (id) => { const a = assets.get(Number(id)); return a && a.width && a.height ? { width: a.width, height: a.height } : null; },
            idPrefix: `asst${Math.random().toString(36).slice(2, 7)}-`,
        });
        const doc = SVG_PARSER.parseFromString(markup, 'image/svg+xml');
        const svg = doc.documentElement;
        if (!svg || svg.nodeName.toLowerCase() !== 'svg' || doc.querySelector('parsererror')) return null;
        svg.querySelectorAll('script, foreignObject').forEach((n) => n.remove());
        const node = document.importNode(svg, true);
        node.removeAttribute('width');
        node.removeAttribute('height');
        node.setAttribute('aria-hidden', 'true');
        node.setAttribute('focusable', 'false');
        return node;
    } catch (e) {
        return null;
    }
}

/*
 * Review one design's brand update. The server lists exactly which token-linked colours and fonts differ from the kit now; this page
 * shows each as before and after, draws the design with the chosen changes applied (in the browser only), warns about text that would
 * become harder to read, and then sends the explicit Apply. Looking at this page changes nothing. Apply snapshots a version first.
 */
import { api, append, clear, confirmDialog, debounce, el, firstError, fontLabel, fontManager, fontStack, icon, loadFonts, readBoot, toast } from './brand-core.js';
import { applyItemsToPages } from './brand-apply.js';
import { changedBoxes, evaluateLegibility, groupItems, modeOf, selectionFor, summarise, toggle, toggleMany } from './brand-review.js';
import { previewSurface } from './brand-preview.js';

const boot = readBoot('brand-boot');
const root = document.querySelector('[data-review]');
fontManager(boot.fonts || []);

const PROP_LABEL = { color: 'Colour', fill: 'Fill colour', stroke_color: 'Outline colour', shadow_color: 'Shadow colour', font: 'Font' };
const MODE_LABEL = { all: 'Everything', colors: 'Colours only', fonts: 'Fonts only', none: 'Nothing' };

let data = null;      // GET brand
let doc = null;       // GET document
let selected = new Set();
let surfaces = [];    // [{pageId, before, after, node}]
let result = null;    // what the last apply returned

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

async function load() {
    clear(root);
    append(root, el('div', { class: 'br-rev' }, [el('div', { class: 'lt-skeleton', style: { height: '3.5rem' } }), el('div', { class: 'lt-skeleton', style: { height: '14rem' } }), el('p', { class: 'lt-small', role: 'status', text: 'Loading what would change' })]));
    const [brand, document_] = await Promise.all([api('GET', boot.urls.brand), api('GET', boot.urls.document)]);
    if (!brand.ok || !document_.ok) {
        clear(root);
        append(root, el('div', { class: 'br-rev' }, [
            el('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, [icon('alert'), el('p', { text: (!brand.ok ? brand.message : document_.message) })]),
            el('div', { class: 'br-actions' }, [el('button', { type: 'button', class: 'lt-btn', onclick: load }, [icon('refresh'), 'Try again'])]),
        ]));
        return;
    }
    data = brand.data;
    doc = document_.data;
    selected = new Set(); // nothing is selected until the person chooses
    render();
}

function assetUrl(id) {
    const a = doc.assets && doc.assets[id];
    return a ? a.url : null;
}

// ---- view -------------------------------------------------------------------------------------------------------------

function renderEmpty() {
    const kitName = data.kit ? data.kit.name : null;
    const outdated = data.brand_outdated;
    const box = el('div', { class: 'br-rev' });
    if (!data.kit) {
        box.appendChild(el('div', { class: 'lt-status' }, [icon('info'), el('p', { class: 'lt-status__text', text: 'This design is not linked to a brand kit, and you have no default kit to take from yet.' }), el('a', { class: 'lt-btn lt-btn--secondary lt-btn--sm', href: boot.urls.brand_index, text: 'Open brand kits' })]));
    } else {
        box.appendChild(el('div', { class: 'lt-status lt-status--ok' }, [icon('circle-check'), el('p', { class: 'lt-status__text', text: outdated ? `${kitName} has changed, but nothing in this design is linked to the parts that changed. Nothing needs to be applied.` : `This design already matches ${kitName}. Nothing needs to be applied.` })]));
        if (outdated && boot.canEdit) {
            box.appendChild(el('div', { class: 'br-actions' }, [el('button', { type: 'button', class: 'lt-btn', onclick: dismiss }, 'Got it, clear this notice')]));
        }
    }
    if (result) box.prepend(resultPanel());
    box.appendChild(el('div', { class: 'br-actions' }, [el('a', { class: 'lt-btn lt-btn--secondary', href: boot.urls.updates, text: 'Back to all updates' })]));
    clear(root); root.appendChild(box);
}

function resultPanel() {
    return el('div', { class: 'br-result' }, [
        el('div', { class: 'lt-alert lt-alert--ok', role: 'status' }, [icon('circle-check'), el('div', {}, [
            el('p', { text: result.applied ? `Applied ${plural(result.applied, 'change', 'changes')} to this design.` : 'Nothing was applied.' }),
            result.skipped && result.skipped.length ? el('p', { text: `${plural(result.skipped.length, 'change was', 'changes were')} skipped because the element no longer matches.` }) : null,
        ])]),
        result.snapshot_taken ? el('p', { class: 'br-undo' }, [icon('history', 'sm'), el('span', {}, ['A version was saved before applying. To undo this, open the design and restore it from ', el('b', { text: 'Version history' }), '.'])]) : null,
        el('div', { class: 'br-actions' }, [el('a', { class: 'lt-btn', href: boot.urls.design, text: 'Open the design' })]),
    ]);
}

function render() {
    if (!data.items.length) { renderEmpty(); return; }
    const items = data.items;
    const groups = groupItems(items);
    const root_ = el('div', { class: 'br-rev' });

    if (result) root_.appendChild(resultPanel());

    root_.appendChild(el('p', { class: 'lt-small', style: { 'max-width': '64ch' }, text: `${data.kit.name} changed. This design uses its colours and fonts in ${plural(data.counts.elements, 'place', 'places')}. Nothing has changed in the design yet. Choose what to apply.` }));
    if (!data.linked) root_.appendChild(el('div', { class: 'lt-status lt-status--warn' }, [icon('info'), el('p', { class: 'lt-status__text', text: `This design is not linked to a kit. Applying will link it to ${data.kit.name}.` })]));

    // quick choices
    const modes = el('div', { class: 'br-modes', role: 'group', 'aria-label': 'What to apply' });
    const current = modeOf(items, selected);
    for (const m of ['all', 'colors', 'fonts', 'none']) {
        // "Colours only" and "Fonts only" are shown only when they differ from "Everything" and from nothing
        if ((m === 'colors' || m === 'fonts') && (selectionFor(items, m).size === 0 || selectionFor(items, m).size === items.length)) continue;
        modes.appendChild(el('button', { type: 'button', class: 'lt-chip', 'aria-pressed': current === m ? 'true' : 'false', disabled: !boot.canEdit, onclick: () => { selected = selectionFor(items, m); redraw(); }, text: MODE_LABEL[m] }));
    }
    if (current === 'choose') modes.appendChild(el('span', { class: 'lt-chip is-on', 'aria-pressed': 'true', text: 'Your own choice' }));

    const sum = summarise(items, selected);
    const sumline = el('div', { class: 'br-sumline', role: 'status' });
    const warnBox = el('div', { class: 'br-warns', 'aria-live': 'polite' });
    const applyBtn = el('button', { type: 'button', class: 'lt-btn', disabled: !boot.canEdit || sum.changes === 0, onclick: apply });
    const keepBtn = el('button', { type: 'button', class: 'lt-btn lt-btn--secondary', disabled: !boot.canEdit, onclick: dismiss, text: 'Keep my design as it is' });

    function drawSummary() {
        const s = summarise(items, selected);
        clear(sumline);
        append(sumline, [
            el('p', { class: 'br-sumline__text', text: s.changes === 0 ? `Nothing is selected. ${plural(s.total, 'change is', 'changes are')} available.` : `${plural(s.changes, 'change', 'changes')} selected across ${plural(s.elements, 'element', 'elements')}${s.colors && s.fonts ? ` (${plural(s.colors, 'colour', 'colours')}, ${plural(s.fonts, 'font', 'fonts')})` : ''}.` }),
        ]);
        applyBtn.textContent = s.changes ? `Apply ${plural(s.changes, 'change', 'changes')}` : 'Apply selected changes';
        applyBtn.disabled = !boot.canEdit || s.changes === 0;
    }

    function drawWarnings() {
        clear(warnBox);
        if (!selected.size) return;
        const { warnings } = evaluateLegibility(doc.pages, items, selected);
        if (!warnings.length) return;
        warnBox.appendChild(el('div', { class: 'lt-alert lt-alert--warn' }, [icon('alert'), el('div', {}, [
            el('p', { text: warnings.length === 1 ? 'One piece of text would be harder to read:' : `${warnings.length} pieces of text would be harder to read:` }),
            el('ul', {}, warnings.slice(0, 8).map((w) => el('li', { text: w.text }))),
            el('p', { class: 'lt-small', text: 'You can still apply. Choosing the matching background colour change too usually fixes it.' }),
        ])]));
    }

    // before and after, per page that has changes
    const pagesWrap = el('div', { class: 'br-rev' });
    surfaces = [];
    for (const g of groups) {
        const page = doc.pages.find((p) => p.id === g.page_id);
        if (!page) continue;
        const before = el('div', { class: 'br-ba__page', style: { '--w': page.width, '--h': page.height } });
        const after = el('div', { class: 'br-ba__page', style: { '--w': page.width, '--h': page.height } });
        const beforeS = previewSurface(before, { manifest: boot.fonts, assetUrl });
        const afterS = previewSurface(after, { manifest: boot.fonts, assetUrl });
        const beforeMarks = markLayer(before, page);
        const afterMarks = markLayer(after, page);
        surfaces.push({ page, beforeS, afterS, beforeMarks, afterMarks });

        const changes = el('div', { class: 'br-changes' });
        for (const elGroup of g.elements) changes.appendChild(elementRow(elGroup, items));

        pagesWrap.appendChild(el('section', { 'aria-label': `Page ${g.page_number}` }, [
            el('div', { class: 'br-pagehead' }, [el('h2', { class: 'lt-h3', text: `Page ${g.page_number}${page.name && page.name !== `Page ${g.page_number}` ? `, ${page.name}` : ''}` }), el('span', { class: 'lt-meta', text: plural(g.elements.reduce((n, e) => n + e.items.length, 0), 'change', 'changes') })]),
            el('p', { class: 'lt-meta br-ba__legend' }, [el('span', { class: 'br-ba__key', 'aria-hidden': 'true' }), 'The outlined parts are the ones that change.']),
            el('div', { class: 'br-ba' }, [
                el('div', { class: 'br-ba__cell' }, [el('p', { class: 'br-ba__label', text: 'Now' }), el('div', { class: 'br-ba__stage' }, before)]),
                el('div', { class: 'br-ba__cell' }, [el('p', { class: 'br-ba__label', 'data-after-label': '', text: 'With the changes you picked' }), el('div', { class: 'br-ba__stage' }, after)]),
            ]),
            changes,
        ]));
    }

    function paintSurfaces() {
        const afterPages = applyItemsToPages(doc.pages, items, selected);
        for (const s of surfaces) {
            const idx = doc.pages.findIndex((p) => p.id === s.page.id);
            s.beforeS.show(doc.pages[idx], `Page ${s.page.page_number} as it is now`);
            s.afterS.show(afterPages[idx], `Page ${s.page.page_number} with the selected changes`);
            // outline what changes: every change on the left, the ticked ones on the right
            paintMarks(s.beforeMarks, changedBoxes(doc.pages[idx], items, null));
            paintMarks(s.afterMarks, changedBoxes(doc.pages[idx], items, selected));
        }
    }

    const fontsNeeded = [...new Set(items.filter((i) => i.kind === 'font').flatMap((i) => [i.current, i.proposed]))];
    loadFonts(fontsNeeded, () => { root_.querySelectorAll('[data-font-key]').forEach((n) => { n.style.fontFamily = fontStack(n.getAttribute('data-font-key')); }); });

    append(root_, [
        el('div', { class: 'br-rev__top' }, [modes, sumline, warnBox]),
        pagesWrap,
        el('div', { class: 'br-actions' }, [applyBtn, keepBtn, el('a', { class: 'lt-btn lt-btn--ghost', href: boot.urls.updates, text: 'Decide later' })]),
        el('p', { class: 'br-undo' }, [icon('history', 'sm'), el('span', { text: 'Before anything is applied, a version of the design is saved. If you change your mind, restore it from Version history in the editor. Open editors will ask to reload.' })]),
        !boot.canEdit ? el('p', { class: 'lt-small', text: 'Your role lets you review this update but not apply it.' }) : null,
    ]);
    clear(root);
    root.appendChild(root_);
    drawSummary();
    drawWarnings();
    paintSurfaces();
}

const SVG_NS = 'http://www.w3.org/2000/svg';

/** A transparent layer over a preview, in the page's own units, that holds the outlines of changed elements. */
function markLayer(host, page) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'br-ba__marks');
    svg.setAttribute('viewBox', `0 0 ${page.width} ${page.height}`);
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.setAttribute('aria-hidden', 'true');
    host.appendChild(svg);
    return svg;
}

function paintMarks(svg, boxes) {
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    for (const kind of ['halo', 'ring']) {
        for (const b of boxes) {
            const r = document.createElementNS(SVG_NS, 'rect');
            r.setAttribute('x', String(b.x)); r.setAttribute('y', String(b.y)); r.setAttribute('width', String(b.w)); r.setAttribute('height', String(b.h));
            r.setAttribute('class', `br-ba__mark br-ba__mark--${kind}`);
            svg.appendChild(r);
        }
    }
}

function valueChip(item, which) {
    const value = which === 'from' ? item.current : item.proposed;
    if (item.kind === 'font') {
        return el('span', { class: 'br-chip' }, [el('span', { class: 'br-chip__font', 'data-font-key': value, style: { 'font-family': fontStack(value) }, text: 'Aa' }), el('span', { class: 'br-chip__fontname', text: fontLabel(value) })]);
    }
    return el('span', { class: 'br-chip' }, [el('span', { class: 'br-chip__sw', style: { '--c': value || 'transparent' } }), el('code', { text: value || 'none' })]);
}

function elementRow(group, allItems) {
    const rows = [];
    for (const item of group.items) {
        const id = `chg-${item.key.replace(/[^a-z0-9]/gi, '-')}`;
        const box = el('input', { type: 'checkbox', id, checked: selected.has(item.key), disabled: !boot.canEdit, 'aria-label': `${PROP_LABEL[item.prop] || item.prop} of ${group.name}`, onchange: () => { selected = toggle(selected, item.key); redraw(); } });
        rows.push(el('div', { class: 'br-change' }, [
            box,
            el('label', { class: 'br-change__what', for: id }, [el('b', { text: group.name }), el('span', { text: PROP_LABEL[item.prop] || item.prop })]),
            el('div', { class: 'br-change__diff' }, [valueChip(item, 'from'), icon('arrow-right', 'sm'), valueChip(item, 'to')]),
        ]));
    }
    return el('div', {}, rows);
}

// redraw without losing scroll or focus order: a full render is cheap, and the document is not refetched
function redraw() { const y = window.scrollY; render(); window.scrollTo(0, y); }

// ---- actions ----------------------------------------------------------------------------------------------------------

async function apply() {
    const items = data.items;
    if (!selected.size) return;
    const { warnings } = evaluateLegibility(doc.pages, items, selected);
    const s = summarise(items, selected);
    const body = el('div', { class: 'br-stack br-stack--tight' }, [
        el('p', { text: `${plural(s.changes, 'change', 'changes')} will be applied to this design. A version is saved first.` }),
        warnings.length ? el('div', { class: 'lt-alert lt-alert--warn' }, [icon('alert'), el('div', {}, [el('p', { text: 'Some text would be harder to read:' }), el('ul', {}, warnings.slice(0, 5).map((w) => el('li', { text: w.text })))])]) : null,
    ]);
    const ok = await confirmDialog({ title: warnings.length ? 'Apply anyway?' : 'Apply these changes?', body, confirmLabel: 'Apply', cancelLabel: 'Not yet' });
    if (!ok) return;

    const payload = { items: [...selected] };
    if (!data.linked && data.kit) payload.kit_id = data.kit.id;
    const res = await api('POST', boot.urls.apply, payload);
    if (!res.ok) {
        toast(firstError(res.errors) || res.message, 'bad');
        if (res.status === 422) await load();
        return;
    }
    result = res.data;
    toast(`Applied ${plural(res.data.applied, 'change', 'changes')}.`, 'ok');
    const [brand, document_] = await Promise.all([api('GET', boot.urls.brand), api('GET', boot.urls.document)]);
    if (brand.ok && document_.ok) { data = brand.data; doc = document_.data; selected = new Set(); render(); window.scrollTo(0, 0); }
}

async function dismiss() {
    const ok = await confirmDialog({ title: 'Keep the design as it is?', body: `Nothing in the design changes. The notice goes away until ${data.kit ? data.kit.name : 'the kit'} changes again.`, confirmLabel: 'Keep it as it is', cancelLabel: 'Cancel' });
    if (!ok) return;
    const res = await api('POST', boot.urls.dismiss);
    if (!res.ok) { toast(res.message, 'bad'); return; }
    toast('Kept as it is. The notice is cleared.', 'ok');
    window.location.href = boot.urls.updates;
}

load();

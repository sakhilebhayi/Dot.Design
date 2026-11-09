/*
 * The accessible model of the canvas (R-45 a11y). The design is drawn, so a screen reader cannot read it as a document;
 * this gives it a second form: a summary of the page and a list of its elements, top layer first, each saying what it is,
 * what it says and where it is. Activating an item selects that element (the same selection the keyboard, Layers and pointer
 * use). The list is visually hidden, outside the canvas region, and its buttons are not tab stops (Tab on the canvas already
 * walks the elements), so keyboard users meet nothing extra.
 */
import { activePage, describeElementLine, describePage } from './describe.js';
import { h } from './dom.js';
import { displayUnit } from './units.js';

const CAP = 400;

export function installStageModel(ctx, shell) {
    const studio = ctx.studio;
    const unit = displayUnit(ctx.config.project, ctx.config.formats);
    const summary = h('p', { class: 'st-model__summary', attrs: { id: 'st-model-summary' } });
    const list = h('ol', { class: 'st-model__list', attrs: { 'aria-label': 'Elements on this page, top layer first' } });
    const box = h('section', { class: 'lt-sr st-model', attrs: { 'aria-label': 'Design contents' } }, summary, list);
    shell.stageWrap.insertBefore(box, shell.stage);
    shell.stage.setAttribute('aria-describedby', 'st-model-summary');

    let builtFor = null;
    let timer = null;
    const buttons = new Map();

    function items(elements, depth, out) {
        const ol = out || h('ol', { class: 'st-model__list' });
        let shown = 0;
        for (let i = elements.length - 1; i >= 0; i -= 1) {
            const e = elements[i];
            if (buttons.size >= CAP) break;
            const b = h('button', { class: 'st-model__item', attrs: { type: 'button', tabindex: '-1' }, data: { id: e.id }, text: describeElementLine(e, unit) });
            b.addEventListener('click', () => { if (ctx.readOnly && !studio.select) return; studio.select([e.id]); });
            buttons.set(e.id, b);
            const li = h('li', null, b);
            if (e.type === 'group' && Array.isArray(e.children) && e.children.length) li.appendChild(h('ol', { attrs: { 'aria-label': `Inside ${e.name || 'group'}` } }, ...items(e.children, depth + 1, h('ol')).children));
            ol.appendChild(li);
            shown += 1;
        }
        return ol;
    }

    function rebuild() {
        const s = studio.getState();
        const page = activePage(s);
        buttons.clear();
        const ol = items(page.elements, 0, null);
        list.replaceChildren(...ol.children);
        const total = page.elements.length;
        if (buttons.size >= CAP && total > 0) list.appendChild(h('li', { text: 'More elements are on this page than are listed here. Open Layers to reach them.' }));
        builtFor = page.elements;
        refresh();
    }

    function refresh() {
        const s = studio.getState();
        const page = activePage(s);
        const idx = s.doc.pages.findIndex((p) => p.id === page.id);
        summary.textContent = describePage(page, Math.max(0, idx), s.doc.pages.length, s.selection.length, unit);
        const sel = new Set(s.selection);
        for (const [id, b] of buttons) { if (sel.has(id)) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current'); }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const page = activePage(studio.getState());
            if (page.elements !== builtFor || list.childElementCount === 0) rebuild(); else refresh();
        }, 250);
    }

    for (const ev of ['change', 'selection', 'page']) ctx.on(ev, schedule);
    rebuild();
    return { rebuild, refresh };
}

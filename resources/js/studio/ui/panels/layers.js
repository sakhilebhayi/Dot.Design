/*
 * The Layers panel (Pro): the page as a tree, top layer first. Click selects
 * (shift or cmd/ctrl adds), double click or F2 renames, the eye and lock
 * toggle, groups fold, and layers drag to reorder inside their group.
 */
import { elementLabel, typeIcon } from '../describe.js';
import { h, icon } from '../dom.js';

export function mountLayers(ctx, shell) {
    const studio = ctx.studio;
    const host = shell.layersHost;
    const list = h('ul', { class: 'st-layers', attrs: { role: 'tree', 'aria-label': 'Layers, top layer first', 'aria-multiselectable': 'true' } });
    const empty = h('p', { class: 'st-note', text: 'Nothing on this page yet. Add text, a shape or a picture and it shows up here.' });
    host.append(list, empty);
    const collapsed = new Set();
    let drag = null;
    let editing = null;

    function rowsFor(elements, depth, parentId, out) {
        for (let i = elements.length - 1; i >= 0; i -= 1) {
            const e = elements[i];
            out.push({ e, depth, parentId, index: i, siblings: elements.length });
            if (e.type === 'group' && !collapsed.has(e.id)) rowsFor(e.children, depth + 1, e.id, out);
        }
        return out;
    }

    function render() {
        const s = studio.getState();
        const page = s.doc.pages.find((p) => p.id === s.activePageId) || s.doc.pages[0];
        const rows = rowsFor(page.elements, 0, null, []);
        empty.hidden = rows.length > 0;
        const sel = new Set(s.selection);
        const frag = document.createDocumentFragment();
        for (const r of rows) {
            const e = r.e;
            const li = h('li', { class: ['st-layer', sel.has(e.id) && 'is-on', e.hidden && 'is-hidden', e.locked && 'is-locked'], attrs: { role: 'treeitem', 'aria-selected': String(sel.has(e.id)), 'aria-level': r.depth + 1, 'aria-expanded': e.type === 'group' ? String(!collapsed.has(e.id)) : null }, data: { id: e.id, parent: r.parentId || '', index: r.index } });
            li.style.setProperty('--depth', r.depth);
            const fold = e.type === 'group'
                ? h('button', { class: 'st-layer__fold', attrs: { type: 'button', 'aria-label': `${collapsed.has(e.id) ? 'Expand' : 'Collapse'} ${elementLabel(e)}`, 'aria-expanded': String(!collapsed.has(e.id)), tabindex: '-1' }, onClick: (ev) => { ev.stopPropagation(); if (collapsed.has(e.id)) collapsed.delete(e.id); else collapsed.add(e.id); render(); } }, icon(collapsed.has(e.id) ? 'chevron-right' : 'chevron-down', 'lt-icon--sm'))
                : h('span', { class: 'st-layer__fold' });
            const label = h('span', { class: 'st-layer__name', text: elementLabel(e) });
            const main = h('button', { class: 'st-layer__main', attrs: { type: 'button', tabindex: sel.has(e.id) || (!s.selection.length && r === rows[0]) ? '0' : '-1' } }, icon(typeIcon(e), 'st-layer__type'), label);
            main.addEventListener('click', (ev) => {
                if (drag && drag.moved) return;
                const add = ev.shiftKey || ev.metaKey || ev.ctrlKey;
                if (add) studio.select(sel.has(e.id) ? s.selection.filter((x) => x !== e.id) : [...s.selection, e.id], { group: r.parentId });
                else studio.select([e.id], { group: r.parentId });
            });
            main.addEventListener('dblclick', () => startRename(e, label));
            main.addEventListener('keydown', (ev) => {
                const items = Array.from(list.querySelectorAll('.st-layer__main'));
                const at = items.indexOf(main);
                if (ev.key === 'ArrowDown' && items[at + 1]) { ev.preventDefault(); items[at + 1].focus(); } else if (ev.key === 'ArrowUp' && items[at - 1]) { ev.preventDefault(); items[at - 1].focus(); } else if (ev.key === 'F2') { ev.preventDefault(); startRename(e, label); } else if ((ev.key === 'Delete' || ev.key === 'Backspace') && sel.has(e.id)) { ev.preventDefault(); ctx.run('element.remove', { ids: [e.id] }); } else if (ev.key === 'ArrowRight' && e.type === 'group' && collapsed.has(e.id)) { collapsed.delete(e.id); render(); } else if (ev.key === 'ArrowLeft' && e.type === 'group' && !collapsed.has(e.id)) { collapsed.add(e.id); render(); } else if ((ev.altKey) && (ev.key === 'ArrowUp' || ev.key === 'ArrowDown')) { ev.preventDefault(); ctx.run('element.reorder', { ids: [e.id], to: ev.key === 'ArrowUp' ? 'forward' : 'back' }); }
            });
            main.addEventListener('focus', () => { if (!sel.has(e.id)) studio.select([e.id], { group: r.parentId }); });
            const rove = sel.has(e.id) || (!s.selection.length && r === rows[0]) ? '0' : '-1';
            const eye = h('button', { class: 'st-layer__btn', attrs: { type: 'button', tabindex: rove, 'aria-label': e.hidden ? `Show ${elementLabel(e)}` : `Hide ${elementLabel(e)}`, 'aria-pressed': String(!!e.hidden) }, onClick: (ev) => { ev.stopPropagation(); ctx.run('element.hide', { ids: [e.id], value: !e.hidden }); } }, icon(e.hidden ? 'eye-off' : 'eye', 'lt-icon--sm'));
            const lock = h('button', { class: 'st-layer__btn', attrs: { type: 'button', tabindex: rove, 'aria-label': e.locked ? `Unlock ${elementLabel(e)}` : `Lock ${elementLabel(e)}`, 'aria-pressed': String(!!e.locked) }, onClick: (ev) => { ev.stopPropagation(); ctx.run('element.lock', { ids: [e.id], value: !e.locked }); } }, icon(e.locked ? 'lock' : 'unlock', 'lt-icon--sm'));
            li.append(fold, main, h('span', { class: 'st-layer__tools' }, eye, lock));
            // drag to reorder within the same parent
            main.addEventListener('pointerdown', (ev) => { if (ev.button !== 0 || ev.target.closest('.st-layer__fold')) return; drag = { r, startY: ev.clientY, moved: false, li, target: null }; });
            frag.appendChild(li);
        }
        const hadFocus = list.contains(document.activeElement);
        list.replaceChildren(frag);
        if (hadFocus) { const f = list.querySelector('.is-on .st-layer__main'); if (f) f.focus({ preventScroll: true }); }
        if (editing) editing = null;
    }

    function startRename(e, label) {
        const input = h('input', { class: 'st-layer__rename lt-input', attrs: { type: 'text', 'aria-label': 'Layer name', maxlength: 80 } });
        input.value = e.name || elementLabel(e);
        label.replaceWith(input);
        input.focus(); input.select();
        let done = false;
        const finish = (commit) => { if (done) return; done = true; if (commit) { const v = input.value.trim(); ctx.run('element.rename', { id: e.id, name: v || null }); } render(); };
        input.addEventListener('keydown', (ev) => { ev.stopPropagation(); if (ev.key === 'Enter') finish(true); else if (ev.key === 'Escape') finish(false); });
        input.addEventListener('blur', () => finish(true));
        editing = input;
    }

    window.addEventListener('pointermove', (ev) => {
        if (!drag) return;
        if (!drag.moved && Math.abs(ev.clientY - drag.startY) < 6) return;
        drag.moved = true;
        list.classList.add('is-sorting');
        const items = Array.from(list.children).filter((li) => (li.dataset.parent || '') === (drag.r.parentId || ''));
        let target = null;
        for (const li of items) { const rect = li.getBoundingClientRect(); if (ev.clientY < rect.top + rect.height / 2) { target = li; break; } }
        Array.from(list.children).forEach((li) => li.classList.remove('is-drop-before', 'is-drop-end'));
        if (target) target.classList.add('is-drop-before'); else if (items.length) items[items.length - 1].classList.add('is-drop-end');
        drag.target = target;
        drag.endTarget = !target;
    });
    window.addEventListener('pointerup', () => {
        if (!drag) return;
        const d = drag; setTimeout(() => { drag = null; }, 0);
        list.classList.remove('is-sorting');
        Array.from(list.children).forEach((li) => li.classList.remove('is-drop-before', 'is-drop-end'));
        if (!d.moved) return;
        // rows are top-first, so "before" a row means above it: a higher index in the elements array
        const from = d.r.index;
        let to;
        if (d.target) { const ti = parseInt(d.target.dataset.index, 10); to = ti >= from ? ti : ti + 1; } else to = 0;
        if (to === from) return;
        const steps = Math.abs(to - from);
        const dir = to > from ? 'forward' : 'back';
        for (let i = 0; i < steps; i += 1) studio.exec('element.reorder', { ids: [d.r.e.id], to: dir }, { key: 'layers:drag:' + d.r.e.id });
    });

    for (const ev of ['change', 'selection', 'page']) ctx.on(ev, () => { if (!editing && ctx.mode === 'pro') requestAnimationFrame(render); });
    ctx.on('mode', () => { if (ctx.mode === 'pro') render(); });
    render();
}

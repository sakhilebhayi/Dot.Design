/*
 * In-place text editing. A plain <textarea> (not contenteditable) is laid over
 * the element, inside the stage so it inherits the viewport's pan and zoom, with
 * the same font, size, line height, spacing, alignment and rotation. While it is
 * open the renderer hides the element's own glyphs so nothing is drawn twice.
 * Every keystroke is applied as a live `text.edit`; the whole session is ONE
 * undo step, committed on blur, Escape or Ctrl/Cmd+Enter. IME composition is
 * respected (Enter never commits mid-composition). Emptying a text and leaving
 * removes it.
 */
import { elementMatrix, multiply } from '../doc/geometry.js';
import { find, parentMatrix } from '../doc/tree.js';
import { layoutText } from './text-layout.js';

const CASE = { upper: 'uppercase', lower: 'lowercase', title: 'capitalize', none: 'none' };

export function createTextEditor({ stage, core, fonts, measure, emit, focusRoot }) {
    const doc = stage.ownerDocument;
    let active = null;

    function elementOf(id) {
        const s = core.getState();
        const hit = find(core.activePage(s).elements, id);
        return hit ? hit : null;
    }

    function apply(hit) {
        const el = hit.element;
        const ta = active.ta;
        const m = multiply(parentMatrix(hit.ancestors), elementMatrix({ ...el, flip_x: false, flip_y: false }));
        const lay = layoutText({ ...el, text: ta.value, fontWeight: fonts.resolveWeight(el.font, el.fontWeight, el.italic) }, measure);
        const st = ta.style;
        st.width = `${el.width}px`;
        st.height = `${el.autoFit === 'grow' ? Math.max(el.height, lay.autoHeight) : el.height}px`;
        st.transform = `matrix(${m.map((v) => Math.round(v * 10000) / 10000).join(',')})`;
        st.fontFamily = fonts.stackFor(el.font);
        st.fontSize = `${lay.fontSize}px`;
        st.fontWeight = String(fonts.resolveWeight(el.font, el.fontWeight, el.italic));
        st.fontStyle = el.italic ? 'italic' : 'normal';
        st.lineHeight = `${lay.lineHeight}px`;
        st.letterSpacing = `${(el.letterSpacing || 0) * lay.fontSize}px`;
        st.textAlign = el.align;
        st.color = el.color;
        st.caretColor = el.color;
        st.textTransform = CASE[el.textCase] || 'none';
        st.textDecoration = [el.underline ? 'underline' : '', el.strike ? 'line-through' : ''].filter(Boolean).join(' ') || 'none';
        st.paddingTop = `${lay.offsetY}px`;
    }

    function sync() {
        if (!active) return;
        const hit = elementOf(active.id);
        if (!hit) { end(false); return; }
        apply(hit);
    }

    function start(id, options = {}) {
        if (active) end(true);
        const hit = elementOf(id);
        if (!hit || hit.element.type !== 'text' || hit.element.locked || hit.ancestors.some((a) => a.locked)) return false;
        core.begin('text.edit');
        core.setEditing(id);
        const ta = doc.createElement('textarea');
        ta.value = hit.element.text;
        ta.setAttribute('aria-label', 'Edit text');
        ta.setAttribute('data-studio-ignore', '');
        ta.setAttribute('data-studio-text-editor', '');
        const st = ta.style;
        st.position = 'absolute'; st.left = '0'; st.top = '0'; st.margin = '0'; st.padding = '0'; st.border = '0';
        st.background = 'transparent'; st.resize = 'none'; st.overflow = 'hidden'; st.boxSizing = 'border-box';
        st.whiteSpace = 'pre-wrap'; st.overflowWrap = 'break-word'; st.wordBreak = 'normal'; st.transformOrigin = '0 0';
        st.outline = '1px dashed currentColor'; st.outlineOffset = '2px'; st.zIndex = '5'; st.touchAction = 'auto';
        st.userSelect = 'text'; st.webkitUserSelect = 'text';
        stage.appendChild(ta);
        active = { id, ta, composing: false, startText: hit.element.text };

        ta.addEventListener('input', () => {
            core.exec('text.edit', { id, text: ta.value }, { live: true });
            const h = elementOf(id);
            if (h) apply(h);
            emit('text-edit', { phase: 'input', id });
        });
        ta.addEventListener('compositionstart', () => { if (active) active.composing = true; });
        ta.addEventListener('compositionend', () => { if (active) active.composing = false; });
        ta.addEventListener('keydown', (e) => {
            e.stopPropagation();
            if (!active) return;
            if (e.isComposing || active.composing || e.keyCode === 229) return;
            if (e.key === 'Escape') { e.preventDefault(); end(true); }
            else if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); end(true); }
        });
        ta.addEventListener('blur', () => {
            setTimeout(() => { if (active && active.ta === ta && doc.activeElement !== ta) end(true); }, 0);
        });
        apply(hit);
        ta.focus({ preventScroll: true });
        if (options.selectAll === false) ta.setSelectionRange(ta.value.length, ta.value.length);
        else ta.select();
        emit('text-edit', { phase: 'start', id });
        return true;
    }

    function end(commit) {
        if (!active) return false;
        const { id, ta } = active;
        active = null;
        const value = ta.value;
        ta.remove();
        let result = { ok: true, changed: false };
        if (commit) {
            if (value.trim() === '') core.exec('element.remove', { ids: [id] }, { live: true });
            result = core.commit({ name: 'text.edit' });
        } else core.cancel();
        core.setEditing(null);
        if (focusRoot) focusRoot();
        emit('text-edit', { phase: 'end', id, committed: !!commit, changed: !!result.changed });
        return true;
    }

    return { start, end, sync, active: () => !!active, activeId: () => (active ? active.id : null), destroy() { if (active) end(false); } };
}

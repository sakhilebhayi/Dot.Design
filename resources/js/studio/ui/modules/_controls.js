/*
 * Custom form controls for the feature modules (export, animate, resize, share, review, comments, assistant, files).
 * Built to the Light Table tokens (--lt-*), so a dialog never shows a browser-drawn select, check box or number field next
 * to the design system's own controls (jury item 5).
 *
 *   select({options, value, label, onChange})       a listbox button (keyboard: arrows, Home/End, type to jump, Enter, Escape)
 *   combobox({items, value, label, onChange})       a searchable picker with avatars (people lists)
 *   switchControl({label, checked, onChange})       a labelled switch with a visible track and an on/off state
 *   checkbox({label, checked, onChange})            a custom check box
 *   radioCards({name, options, value, onChange})    a radio group drawn as cards (title + sentence)
 *   segmented({options, value, onChange, label})    a segmented control with roving focus
 *   numberField({value, min, max, step, decimals})  a text field with steppers; the dot is always the decimal mark
 *
 * Every control returns a DOM node. Interactive nodes expose `.value` (or `.checked`) and fire a bubbling 'change' event, so
 * code written for the native element keeps working. No innerHTML anywhere: text goes in through textContent.
 */
import '../../../../css/studio-controls.css';
import { fmtFixed, parseDecimal, snapTo } from '../../output/number-format.js';

let uidN = 0;
const uid = (p) => `${p}-${(uidN += 1)}`;
const SVG_NS = 'http://www.w3.org/2000/svg';

export function el(tag, props = {}, ...kids) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(props || {})) {
        if (v === undefined || v === null || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = v;
        else if (k === 'dataset') Object.assign(node.dataset, v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
        else if (v === true) node.setAttribute(k, '');
        else node.setAttribute(k, String(v));
    }
    for (const kid of kids.flat(Infinity)) {
        if (kid === null || kid === undefined || kid === false) continue;
        node.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
    }
    return node;
}

function svgIcon(d, cls = 'mc-ic') {
    const s = document.createElementNS(SVG_NS, 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round');
    s.setAttribute('aria-hidden', 'true'); s.setAttribute('class', cls);
    const p = document.createElementNS(SVG_NS, 'path'); p.setAttribute('d', d); s.append(p);
    return s;
}
const CHEVRON = 'M6 9l6 6 6-6';
const CHECK = 'M20 6L9 17l-5-5';
const PLUS = 'M12 5v14M5 12h14';
const MINUS = 'M5 12h14';

function fire(node, name = 'change') { node.dispatchEvent(new Event(name, { bubbles: true })); }

// ---------------------------------------------------------------------------------------------------------------------
// select

/**
 * options: [{value, label, hint?, disabled?}]. `label` is the accessible name (or pass `labelledBy`: an element id).
 * Returns the wrapper; `.value` reads and writes the selected value, `.setOptions(list)` swaps the options.
 */
export function select({ options = [], value, label, labelledBy, onChange, id, className = '', disabled = false, size, placeholder = 'Choose', dataFk } = {}) {
    let opts = options.slice();
    let current = value !== undefined ? String(value) : (opts[0] ? String(opts[0].value) : '');
    const listId = uid('mc-list');
    const btnId = id || uid('mc-sel');
    const text = el('span', { class: 'mc-select__text' });
    const btn = el('button', {
        type: 'button', id: btnId, class: 'mc-select__btn', role: 'combobox', 'aria-haspopup': 'listbox', 'aria-expanded': 'false', 'aria-controls': listId,
        'aria-label': labelledBy ? null : label, 'aria-labelledby': labelledBy || null,
    }, text, svgIcon(CHEVRON, 'mc-select__chev'));
    if (dataFk) btn.dataset.fk = dataFk;
    const list = el('ul', { class: 'mc-list', role: 'listbox', id: listId, tabindex: '-1', hidden: true, 'aria-label': label || null });
    const wrap = el('div', { class: `mc-select${size === 'sm' ? ' mc-select--sm' : ''} ${className}`.trim() }, btn, list);
    let open = false;
    let active = -1;
    let typed = '';
    let typedTimer = null;

    const find = (v) => opts.findIndex((o) => String(o.value) === String(v));
    function paintText() {
        const i = find(current);
        text.textContent = i >= 0 ? opts[i].label : placeholder;
        text.classList.toggle('is-placeholder', i < 0);
    }
    function buildList() {
        list.replaceChildren(...opts.map((o, i) => {
            const li = el('li', { class: 'mc-opt', role: 'option', id: `${listId}-${i}`, 'aria-selected': String(String(o.value) === current), 'aria-disabled': o.disabled ? 'true' : null, dataset: { i: String(i) } },
                el('span', { class: 'mc-opt__label', text: o.label }), o.hint ? el('span', { class: 'mc-opt__hint', text: o.hint }) : null,
                svgIcon(CHECK, 'mc-opt__tick'));
            li.addEventListener('pointerdown', (e) => e.preventDefault());
            li.addEventListener('click', () => { if (!o.disabled) choose(i); });
            li.addEventListener('pointermove', () => setActive(i, false));
            return li;
        }));
    }
    function setActive(i, scroll = true) {
        active = i;
        for (const li of list.children) li.classList.toggle('is-active', Number(li.dataset.i) === i);
        if (i >= 0) { btn.setAttribute('aria-activedescendant', `${listId}-${i}`); if (scroll) { const li = list.children[i]; if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' }); } } else btn.removeAttribute('aria-activedescendant');
    }
    function place() {
        wrap.classList.remove('is-up');
        const r = btn.getBoundingClientRect();
        const need = Math.min(list.scrollHeight, 260) + 8;
        const below = window.innerHeight - r.bottom;
        if (below < need && r.top > below) wrap.classList.add('is-up');
        list.style.minWidth = `${Math.round(r.width)}px`;
    }
    function openList() {
        if (open || btn.disabled) return;
        open = true;
        buildList();
        list.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        wrap.classList.add('is-open');
        place();
        const i = find(current);
        setActive(i >= 0 ? i : opts.findIndex((o) => !o.disabled));
    }
    function closeList({ focus = false } = {}) {
        if (!open) return;
        open = false;
        list.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.removeAttribute('aria-activedescendant');
        wrap.classList.remove('is-open', 'is-up');
        if (focus) btn.focus();
    }
    function choose(i) {
        const o = opts[i];
        if (!o || o.disabled) return;
        const changed = String(o.value) !== current;
        current = String(o.value);
        paintText();
        closeList({ focus: true });
        if (changed) { if (onChange) onChange(current); fire(wrap); }
    }
    function move(dir) {
        let i = active;
        for (let n = 0; n < opts.length; n += 1) {
            i = (i + dir + opts.length) % opts.length;
            if (!opts[i].disabled) break;
        }
        setActive(i);
    }
    btn.addEventListener('click', () => (open ? closeList() : openList()));
    btn.addEventListener('keydown', (e) => {
        const k = e.key;
        if (k === 'ArrowDown' || k === 'ArrowUp') {
            e.preventDefault(); e.stopPropagation();
            if (!open) openList(); else move(k === 'ArrowDown' ? 1 : -1);
        } else if (k === 'Home' && open) { e.preventDefault(); setActive(opts.findIndex((o) => !o.disabled)); } else if (k === 'End' && open) { e.preventDefault(); setActive(opts.length - 1 - [...opts].reverse().findIndex((o) => !o.disabled)); } else if (k === 'Enter' || k === ' ') {
            if (open && active >= 0 && !(k === ' ' && typed)) { e.preventDefault(); e.stopPropagation(); choose(active); } else if (!open) { e.preventDefault(); openList(); }
        } else if (k === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); closeList({ focus: true }); } else if (k === 'Tab' && open) closeList();
        else if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            typed += k.toLowerCase();
            clearTimeout(typedTimer); typedTimer = setTimeout(() => { typed = ''; }, 600);
            const from = open ? active : find(current);
            const hit = [...opts.keys()].map((n) => (n + from + 1) % opts.length).find((n) => !opts[n].disabled && opts[n].label.toLowerCase().startsWith(typed));
            if (hit !== undefined) { e.preventDefault(); if (!open) openList(); setActive(hit); }
        }
    });
    btn.addEventListener('blur', () => { setTimeout(() => { if (open && !wrap.contains(document.activeElement)) closeList(); }, 0); });
    document.addEventListener('pointerdown', (e) => { if (open && !wrap.contains(e.target)) closeList(); }, true);

    Object.defineProperty(wrap, 'value', {
        get: () => current,
        set: (v) => { current = String(v); paintText(); if (open) buildList(); },
    });
    Object.defineProperty(wrap, 'disabled', {
        get: () => btn.disabled,
        set: (v) => { btn.disabled = !!v; wrap.classList.toggle('is-disabled', !!v); },
    });
    wrap.setOptions = (next, nextValue) => { opts = next.slice(); if (nextValue !== undefined) current = String(nextValue); else if (find(current) < 0 && opts[0]) current = String(opts[0].value); paintText(); if (open) buildList(); };
    wrap.focus = () => btn.focus();
    wrap.button = btn;
    wrap.isOpen = () => open;
    paintText();
    if (disabled) wrap.disabled = true;
    return wrap;
}

// ---------------------------------------------------------------------------------------------------------------------
// combobox (searchable, with avatars)

const AVATAR_TONES = ['#0a6a91', '#1b6e3a', '#8a5400', '#7a3f8f', '#b3261e', '#3d5a80'];
export function initials(name) {
    const parts = String(name || '?').replace(/\(.*?\)/g, '').trim().split(/\s+/).filter(Boolean);
    return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}
export function avatar(name, size = 'md') {
    const sum = [...String(name || '?')].reduce((s, c) => s + c.charCodeAt(0), 0);
    const a = el('span', { class: `mc-avatar mc-avatar--${size}`, 'aria-hidden': 'true', text: initials(name) });
    a.style.setProperty('--mc-tone', AVATAR_TONES[sum % AVATAR_TONES.length]);
    return a;
}

/** Case and accent insensitive match; every typed word must start a word of the name or appear in the sub line. */
export function matchItem(item, query) {
    const q = String(query || '').trim().toLowerCase();
    if (!q) return true;
    const hay = `${item.label} ${item.sub || ''}`.toLowerCase();
    return q.split(/\s+/).every((w) => hay.split(/[\s(),]+/).some((t) => t.startsWith(w)) || hay.includes(w));
}

/** items: [{value, label, sub?}]. Typing filters; arrows move; Enter picks; Escape closes. */
export function combobox({ items = [], value, label, labelledBy, onChange, placeholder = 'Search by name', emptyText = 'Nobody matches that', id, className = '' } = {}) {
    let all = items.slice();
    let current = value !== undefined && value !== null ? String(value) : '';
    const listId = uid('mc-cb');
    const input = el('input', {
        type: 'text', id: id || uid('mc-cbi'), class: 'mc-cb__input', role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': listId,
        'aria-label': labelledBy ? null : label, 'aria-labelledby': labelledBy || null, autocomplete: 'off', spellcheck: 'false', placeholder,
    });
    const lead = el('span', { class: 'mc-cb__lead' });
    const chev = svgIcon(CHEVRON, 'mc-select__chev');
    const list = el('ul', { class: 'mc-list mc-list--people', role: 'listbox', id: listId, hidden: true, 'aria-label': label || null });
    const field = el('div', { class: 'mc-cb__field' }, lead, input, chev);
    const wrap = el('div', { class: `mc-cb ${className}`.trim() }, field, list);
    let open = false;
    let shown = [];
    let active = -1;
    const byValue = (v) => all.find((i) => String(i.value) === String(v));

    function paintLead() {
        const it = byValue(current);
        lead.replaceChildren(it ? avatar(it.label, 'sm') : svgIcon('M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75', 'mc-ic mc-cb__ph'));
    }
    function setInputFromValue() { const it = byValue(current); input.value = it ? it.label : ''; }
    function render() {
        shown = all.filter((it) => matchItem(it, filterText));
        list.replaceChildren(...(shown.length ? shown.map((it, i) => {
            const li = el('li', { class: 'mc-opt mc-opt--person', role: 'option', id: `${listId}-${i}`, 'aria-selected': String(String(it.value) === current), dataset: { i: String(i) } },
                avatar(it.label), el('span', { class: 'mc-opt__stack' }, el('span', { class: 'mc-opt__label', text: it.label }), it.sub ? el('span', { class: 'mc-opt__hint', text: it.sub }) : null), svgIcon(CHECK, 'mc-opt__tick'));
            li.addEventListener('pointerdown', (e) => e.preventDefault());
            li.addEventListener('click', () => pick(i));
            li.addEventListener('pointermove', () => setActive(i, false));
            return li;
        }) : [el('li', { class: 'mc-empty', role: 'presentation', text: emptyText })]));
        setActive(shown.length ? Math.max(0, shown.findIndex((it) => String(it.value) === current)) : -1);
    }
    let filterText = '';
    function setActive(i, scroll = true) {
        active = i;
        for (const li of list.querySelectorAll('.mc-opt')) li.classList.toggle('is-active', Number(li.dataset.i) === i);
        if (i >= 0) { input.setAttribute('aria-activedescendant', `${listId}-${i}`); const li = list.children[i]; if (scroll && li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' }); } else input.removeAttribute('aria-activedescendant');
    }
    function openList() { if (open) return; open = true; list.hidden = false; input.setAttribute('aria-expanded', 'true'); wrap.classList.add('is-open'); render(); }
    function closeList() { if (!open) return; open = false; list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); wrap.classList.remove('is-open'); filterText = ''; setInputFromValue(); }
    function pick(i) {
        const it = shown[i]; if (!it) return;
        const changed = String(it.value) !== current;
        current = String(it.value); paintLead(); closeList(); input.focus();
        if (changed) { if (onChange) onChange(current); fire(wrap); }
    }
    input.addEventListener('focus', () => { input.select(); });
    input.addEventListener('click', () => openList());
    chev.addEventListener('pointerdown', (e) => { e.preventDefault(); if (open) closeList(); else { input.focus(); openList(); } });
    input.addEventListener('input', () => { filterText = input.value; if (!open) openList(); else render(); });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault(); e.stopPropagation();
            if (!open) { openList(); return; }
            if (shown.length) setActive((active + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length);
        } else if (e.key === 'Enter' && open) { e.preventDefault(); e.stopPropagation(); if (active >= 0) pick(active); } else if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); closeList(); } else if (e.key === 'Tab') closeList();
    });
    input.addEventListener('blur', () => { setTimeout(() => { if (!wrap.contains(document.activeElement)) closeList(); }, 0); });
    Object.defineProperty(wrap, 'value', { get: () => current, set: (v) => { current = String(v); paintLead(); setInputFromValue(); } });
    wrap.setItems = (next) => { all = next.slice(); paintLead(); setInputFromValue(); if (open) render(); };
    wrap.focus = () => input.focus();
    wrap.input = input;
    paintLead(); setInputFromValue();
    return wrap;
}

// ---------------------------------------------------------------------------------------------------------------------
// switch, checkbox, radio cards, segmented

/** A real checkbox input (role switch) inside a label, with a drawn track. `.checked` reads and writes. */
export function switchControl({ label, checked = false, onChange, description, disabled = false, id, className = '', dataFk } = {}) {
    const input = el('input', { type: 'checkbox', role: 'switch', class: 'mc-switch__input', id: id || uid('mc-sw') });
    input.checked = !!checked; input.disabled = !!disabled;
    if (dataFk) input.dataset.fk = dataFk;
    const track = el('span', { class: 'mc-switch__track', 'aria-hidden': 'true' }, el('span', { class: 'mc-switch__thumb' }, svgIcon(CHECK, 'mc-switch__tick')));
    const state = el('span', { class: 'mc-switch__state', 'aria-hidden': 'true' });
    const body = el('span', { class: 'mc-switch__body' }, el('span', { class: 'mc-switch__label', text: label }), description ? el('span', { class: 'mc-switch__desc', text: description }) : null);
    const wrap = el('label', { class: `mc-switch ${className}`.trim(), for: input.id }, input, track, body, state);
    const sync = () => { state.textContent = input.checked ? 'On' : 'Off'; wrap.dataset.on = input.checked ? '1' : '0'; };
    input.addEventListener('change', () => { sync(); if (onChange) onChange(input.checked); });
    sync();
    Object.defineProperty(wrap, 'checked', { get: () => input.checked, set: (v) => { input.checked = !!v; sync(); } });
    Object.defineProperty(wrap, 'disabled', { get: () => input.disabled, set: (v) => { input.disabled = !!v; } });
    wrap.input = input;
    wrap.focus = () => input.focus();
    return wrap;
}

export function checkbox({ label, checked = false, onChange, description, disabled = false, id, className = '', dataFk } = {}) {
    const input = el('input', { type: 'checkbox', class: 'mc-check__input', id: id || uid('mc-cb') });
    input.checked = !!checked; input.disabled = !!disabled;
    if (dataFk) input.dataset.fk = dataFk;
    const box = el('span', { class: 'mc-check__box', 'aria-hidden': 'true' }, svgIcon(CHECK, 'mc-check__tick'));
    const wrap = el('label', { class: `mc-check ${className}`.trim(), for: input.id }, input, box,
        el('span', { class: 'mc-check__body' }, el('span', { class: 'mc-check__label' }, label), description ? el('span', { class: 'mc-check__desc', text: description }) : null));
    input.addEventListener('change', () => { if (onChange) onChange(input.checked); });
    Object.defineProperty(wrap, 'checked', { get: () => input.checked, set: (v) => { input.checked = !!v; } });
    Object.defineProperty(wrap, 'disabled', { get: () => input.disabled, set: (v) => { input.disabled = !!v; } });
    wrap.input = input;
    wrap.focus = () => input.focus();
    return wrap;
}

/** options: [{value, label, description?, disabled?}]. A group of cards; arrows move and select; the group is one tab stop. */
export function radioCards({ name, options = [], value, onChange, label, labelledBy, className = '' } = {}) {
    const group = el('div', { class: `mc-cards ${className}`.trim(), role: 'radiogroup', 'aria-label': labelledBy ? null : label, 'aria-labelledby': labelledBy || null });
    let current = value !== undefined ? String(value) : '';
    const inputs = options.map((o, i) => {
        const input = el('input', { type: 'radio', class: 'mc-card__input', name: name || uid('mc-rg'), value: String(o.value), id: uid('mc-rc') });
        input.checked = String(o.value) === current; input.disabled = !!o.disabled;
        input.addEventListener('change', () => { if (!input.checked) return; current = String(o.value); if (onChange) onChange(current); fire(group); });
        const card = el('label', { class: 'mc-card', for: input.id }, input, el('span', { class: 'mc-card__dot', 'aria-hidden': 'true' }),
            el('span', { class: 'mc-card__body' }, el('span', { class: 'mc-card__label', text: o.label }), o.description ? el('span', { class: 'mc-card__desc', text: o.description }) : null));
        group.append(card);
        return input;
    });
    Object.defineProperty(group, 'value', { get: () => current, set: (v) => { current = String(v); inputs.forEach((i) => { i.checked = i.value === current; }); } });
    group.inputs = inputs;
    return group;
}

/** A small radio set drawn as a segmented control (like the Simple/Pro switch). Roving focus; arrows select. */
export function segmented({ options = [], value, onChange, label, labelledBy, className = '', size } = {}) {
    let current = value !== undefined ? String(value) : (options[0] ? String(options[0].value) : '');
    const group = el('div', { class: `mc-seg${size === 'sm' ? ' mc-seg--sm' : ''} ${className}`.trim(), role: 'radiogroup', 'aria-label': labelledBy ? null : label, 'aria-labelledby': labelledBy || null });
    const btns = options.map((o) => {
        const b = el('button', { type: 'button', class: 'mc-seg__btn', role: 'radio', dataset: { v: String(o.value) }, title: o.title || null, disabled: o.disabled || null, text: o.label });
        b.addEventListener('click', () => set(String(o.value), true));
        b.addEventListener('keydown', (e) => {
            const dir = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
            if (!dir) return;
            e.preventDefault();
            const enabled = btns.filter((x) => !x.disabled);
            const next = enabled[(enabled.indexOf(b) + dir + enabled.length) % enabled.length];
            if (next) { next.focus(); set(next.dataset.v, true); }
        });
        return b;
    });
    function paint() { for (const b of btns) { const on = b.dataset.v === current; b.setAttribute('aria-checked', String(on)); b.tabIndex = on ? 0 : -1; } if (!btns.some((b) => b.dataset.v === current) && btns[0]) btns[0].tabIndex = 0; }
    function set(v, user) { if (v === current) return; current = v; paint(); if (user) { if (onChange) onChange(v); fire(group); } }
    group.append(...btns);
    Object.defineProperty(group, 'value', { get: () => current, set: (v) => { current = String(v); paint(); } });
    paint();
    return group;
}

// ---------------------------------------------------------------------------------------------------------------------
// number field

/**
 * A text field for numbers. The browser's number input prints "5,0" in many locales; this one always prints "5.0", accepts
 * either mark when typing, clamps on commit (blur or Enter), and has -/+ steppers and Up/Down keys.
 * `.value` is the NUMBER (NaN while the text is not a number); onChange(number) fires on commit only.
 */
export function numberField({ value, min = -Infinity, max = Infinity, step = 1, decimals = 0, unit, label, labelledBy, onChange, className = '', id, disabled = false, placeholder, dataFk, stepper = true } = {}) {
    let current = Number.isFinite(Number(value)) ? Number(value) : NaN;
    const input = el('input', { type: 'text', inputmode: decimals > 0 ? 'decimal' : 'numeric', class: 'mc-num__input', id: id || uid('mc-num'), 'aria-label': labelledBy ? null : label, 'aria-labelledby': labelledBy || null, autocomplete: 'off', spellcheck: 'false', placeholder: placeholder || null });
    if (dataFk) input.dataset.fk = dataFk;
    input.disabled = !!disabled;
    const fmt = (v) => (Number.isFinite(v) ? fmtFixed(v, decimals) : '');
    const dec = el('button', { type: 'button', class: 'mc-num__step', tabindex: '-1', 'aria-label': `Decrease ${label || 'value'}` }, svgIcon(MINUS));
    const inc = el('button', { type: 'button', class: 'mc-num__step', tabindex: '-1', 'aria-label': `Increase ${label || 'value'}` }, svgIcon(PLUS));
    const wrap = el('div', { class: `mc-num ${className}`.trim() }, stepper ? dec : null, input, unit ? el('span', { class: 'mc-num__unit', text: unit, 'aria-hidden': 'true' }) : null, stepper ? inc : null);
    function commit() {
        const parsed = parseDecimal(input.value);
        if (!Number.isFinite(parsed)) { input.value = fmt(current); wrap.classList.remove('is-bad'); return; }
        const v = snapTo(parsed, { min, max, step: 0 });
        input.value = fmt(v);
        wrap.classList.remove('is-bad');
        if (v !== current) { current = v; if (onChange) onChange(v); fire(wrap); }
    }
    function bump(dir, big) {
        const base = Number.isFinite(parseDecimal(input.value)) ? parseDecimal(input.value) : (Number.isFinite(current) ? current : 0);
        const v = snapTo(base + dir * step * (big ? 10 : 1), { min, max, step });
        input.value = fmt(v);
        if (v !== current) { current = v; if (onChange) onChange(v); fire(wrap); }
    }
    input.addEventListener('blur', commit);
    input.addEventListener('input', () => { const p = parseDecimal(input.value); wrap.classList.toggle('is-bad', input.value.trim() !== '' && !Number.isFinite(p)); });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); commit(); } else if (e.key === 'ArrowUp') { e.preventDefault(); bump(1, e.shiftKey); } else if (e.key === 'ArrowDown') { e.preventDefault(); bump(-1, e.shiftKey); } else e.stopPropagation();
    });
    dec.addEventListener('click', () => bump(-1, false));
    inc.addEventListener('click', () => bump(1, false));
    input.value = fmt(current);
    Object.defineProperty(wrap, 'value', { get: () => current, set: (v) => { current = Number(v); input.value = fmt(current); } });
    Object.defineProperty(wrap, 'disabled', { get: () => input.disabled, set: (v) => { input.disabled = !!v; dec.disabled = !!v; inc.disabled = !!v; } });
    wrap.input = input;
    wrap.focus = () => input.focus();
    wrap.commit = commit;
    return wrap;
}

/** A labelled slider with a visible value, built on a real range input (keyboard and touch come for free). */
export function slider({ value, min = 0, max = 100, step = 1, label, format = (v) => String(v), onInput, onChange, className = '' } = {}) {
    const input = el('input', { type: 'range', class: 'mc-range', min: String(min), max: String(max), step: String(step), 'aria-label': label });
    input.value = String(value);
    const out = el('output', { class: 'mc-range__out', text: format(Number(value)) });
    const paint = () => { input.style.setProperty('--mc-fill', `${((Number(input.value) - min) / (max - min)) * 100}%`); out.textContent = format(Number(input.value)); };
    input.addEventListener('input', () => { paint(); if (onInput) onInput(Number(input.value)); });
    input.addEventListener('change', () => { if (onChange) onChange(Number(input.value)); });
    paint();
    const wrap = el('div', { class: `mc-slider ${className}`.trim() }, input, out);
    Object.defineProperty(wrap, 'value', { get: () => Number(input.value), set: (v) => { input.value = String(v); paint(); } });
    wrap.input = input;
    return wrap;
}

/** A label wrapper: visible label above a control, with an optional hint. The control keeps its own accessible name. */
export function fieldRow(label, control, hint, { forId } = {}) {
    const id = forId || uid('mc-f');
    const lab = el('span', { class: 'mc-fieldrow__label', id, text: label });
    return el('div', { class: 'mc-fieldrow' }, lab, control, hint ? el('span', { class: 'mc-fieldrow__hint', text: hint }) : null);
}

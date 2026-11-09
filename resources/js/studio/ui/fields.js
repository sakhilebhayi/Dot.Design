/*
 * Form controls for the panels. Each factory returns {el, set(value), ...} and
 * calls back with changes; none talks to the engine. Numeric fields scrub: drag
 * the label sideways (shift = x10, alt = x0.1), or type, or use the arrow keys.
 */
import { MIXED } from './describe.js';
import { h, icon, uid } from './dom.js';
import { openPopover } from './popover.js';

const clampN = (v, min, max) => Math.min(max ?? Infinity, Math.max(min ?? -Infinity, v));

export function fieldRow(label, control, { hint, id, wide } = {}) {
    const lid = id || uid('f');
    const lab = h('label', { class: 'st-field__label', attrs: { for: lid }, text: label });
    if (control && control.input) control.input.id = lid;
    return h('div', { class: ['st-field', wide && 'st-field--wide'] }, lab, control.el || control, hint ? h('div', { class: 'lt-hint', text: hint }) : null);
}

/**
 * numberField({label, value, min, max, step, decimals, suffix, format(v)->string, parse(text)->number|null, onInput(v), onCommit(v)})
 * onInput fires while scrubbing or typing arrows (live); onCommit when the gesture ends.
 */
export function numberField(o) {
    let value = o.value;
    let scrubbing = false;
    const stepOf = () => (typeof o.step === 'function' ? o.step() : (o.step ?? 1));
    const fmt = o.format || ((v) => (Number.isInteger(v) || o.decimals === 0 ? String(Math.round(v)) : String(+v.toFixed(o.decimals ?? 2))));
    const input = h('input', { class: 'st-num__input lt-num', attrs: { type: 'text', inputmode: 'decimal', autocomplete: 'off', spellcheck: 'false', 'aria-label': o.label } });
    const unit = o.suffix ? h('span', { class: 'st-num__unit', text: o.suffix, attrs: { 'aria-hidden': 'true' } }) : null;
    const grip = h('span', { class: 'st-num__grip', attrs: { 'aria-hidden': 'true', title: 'Drag to change' }, text: o.short || o.label.slice(0, 1) });
    const el = h('div', { class: ['st-num', o.compact && 'st-num--compact'] }, grip, input, unit);
    function show() {
        if (value === MIXED) { input.value = ''; input.placeholder = 'Mixed'; } else if (value === undefined || value === null || Number.isNaN(value)) { input.value = ''; input.placeholder = ''; } else { input.value = fmt(value); input.placeholder = ''; }
    }
    function emit(v, live) {
        v = clampN(v, o.min, o.max);
        if (o.decimals !== undefined) v = +v.toFixed(o.decimals);
        value = v; show();
        if (live) { if (o.onInput) o.onInput(v); } else if (o.onCommit) o.onCommit(v); else if (o.onInput) o.onInput(v);
    }
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { input.blur(); return; }
        if (e.key === 'Escape') { show(); input.blur(); return; }
        if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && typeof value === 'number') {
            e.preventDefault();
            const m = e.shiftKey ? 10 : e.altKey ? 0.1 : 1;
            emit(value + (e.key === 'ArrowUp' ? 1 : -1) * stepOf() * m, true);
        }
    });
    input.addEventListener('change', () => {
        const parsed = o.parse ? o.parse(input.value) : parseFloat(String(input.value).replace(',', '.'));
        if (parsed === null || parsed === undefined || Number.isNaN(parsed)) { show(); return; }
        emit(parsed, false);
    });
    input.addEventListener('focus', () => input.select());
    input.addEventListener('blur', () => show());
    // scrub by dragging the grip
    let startX = 0; let startV = 0; let moved = false;
    grip.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || typeof value !== 'number') return;
        grip.setPointerCapture(e.pointerId);
        startX = e.clientX; startV = value; moved = false; scrubbing = true;
        e.preventDefault();
    });
    grip.addEventListener('pointermove', (e) => {
        if (!scrubbing) return;
        const dx = e.clientX - startX;
        if (!moved && Math.abs(dx) < 3) return;
        moved = true;
        const m = e.shiftKey ? 10 : e.altKey ? 0.1 : 1;
        emit(startV + dx * stepOf() * m * (o.scrub ?? 1), true);
    });
    const end = () => { if (!scrubbing) return; scrubbing = false; if (moved && o.onCommit) o.onCommit(value); };
    grip.addEventListener('pointerup', end);
    grip.addEventListener('pointercancel', end);
    show();
    return { el, input, set(v) { if (scrubbing || document.activeElement === input) return; value = v; show(); } };
}

export function sliderField(o) {
    const out = h('output', { class: 'st-slider__out lt-num' });
    const input = h('input', { class: 'st-slider__input', attrs: { type: 'range', min: o.min, max: o.max, step: o.step ?? 1, 'aria-label': o.label } });
    const fmt = o.format || ((v) => String(Math.round(v)));
    const el = h('div', { class: 'st-slider' }, input, out);
    let value = o.value;
    function show() { if (value === MIXED || value === undefined) { input.value = String(o.min); out.textContent = 'Mixed'; } else { input.value = String(value); out.textContent = fmt(value); } input.style.setProperty('--fill', (((value === MIXED || value === undefined ? o.min : value) - o.min) / (o.max - o.min)) * 100 + '%'); }
    input.addEventListener('input', () => { value = parseFloat(input.value); show(); if (o.onInput) o.onInput(value); });
    input.addEventListener('change', () => { if (o.onCommit) o.onCommit(value); });
    show();
    return { el, input, set(v) { if (document.activeElement === input) return; value = v; show(); } };
}

/** A row of mutually exclusive icon/text buttons. items: [{value,label,icon?,text?}] */
export function segmented({ items, value, label, onChange, small }) {
    const el = h('div', { class: ['st-seg', small && 'st-seg--small'], attrs: { role: 'radiogroup', 'aria-label': label } });
    const btns = items.map((it) => {
        const b = h('button', { class: 'st-seg__btn', attrs: { type: 'button', role: 'radio', 'aria-label': it.label, title: it.label }, data: { value: it.value }, onClick: () => { set(it.value); onChange(it.value); } },
            it.icon ? icon(it.icon) : null, it.text ? h('span', { text: it.text }) : null);
        el.appendChild(b); return b;
    });
    function set(v) { btns.forEach((b) => { const on = b.dataset.value === String(v); b.setAttribute('aria-checked', String(on)); b.classList.toggle('is-on', on); b.tabIndex = on || (v === undefined || v === MIXED) && b === btns[0] ? 0 : -1; }); }
    el.addEventListener('keydown', (e) => {
        const i = btns.indexOf(document.activeElement);
        if (i < 0) return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); const n = btns[(i + 1) % btns.length]; n.focus(); n.click(); } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); const n = btns[(i - 1 + btns.length) % btns.length]; n.focus(); n.click(); }
    });
    set(value);
    return { el, set };
}

export function toggleButton({ label, iconName, text, pressed, onChange, small }) {
    const b = h('button', { class: ['st-tog', small && 'st-tog--small'], attrs: { type: 'button', 'aria-pressed': String(!!pressed), 'aria-label': label, title: label } }, iconName ? icon(iconName) : null, text ? h('span', { text }) : null);
    b.addEventListener('click', () => { const next = b.getAttribute('aria-pressed') !== 'true'; b.setAttribute('aria-pressed', String(next)); onChange(next); });
    return { el: b, set(v) { b.setAttribute('aria-pressed', String(!!v)); } };
}

export function selectField({ label, options, value, onChange }) {
    const sel = h('select', { class: 'lt-select st-select', attrs: { 'aria-label': label } }, options.map((o) => h('option', { value: o.value, text: o.label })));
    sel.addEventListener('change', () => onChange(sel.value));
    sel.value = value ?? '';
    return { el: sel, input: sel, set(v) { if (v === MIXED || v === undefined) { sel.selectedIndex = -1; } else sel.value = v; } };
}

// ---- colour ------------------------------------------------------------------------------------

const HEX = /^#[0-9a-f]{6}([0-9a-f]{2})?$/i;
export const normHex = (t) => {
    let s = String(t || '').trim();
    if (!s) return null;
    if (!s.startsWith('#')) s = '#' + s;
    if (/^#[0-9a-f]{3}$/i.test(s)) s = '#' + s.slice(1).split('').map((c) => c + c).join('');
    return HEX.test(s) ? s.toLowerCase() : null;
};

const SAVED_KEY = 'dot-design-colours';
function savedColours() { try { const v = JSON.parse(localStorage.getItem(SAVED_KEY) || '[]'); return Array.isArray(v) ? v.filter((c) => HEX.test(c)).slice(0, 16) : []; } catch (e) { return []; } }
function saveColour(hex) { try { const list = [hex, ...savedColours().filter((c) => c !== hex)].slice(0, 16); localStorage.setItem(SAVED_KEY, JSON.stringify(list)); } catch (e) { /* storage blocked: custom colours last for this visit */ } }

export function swatch(hex, { label, onClick, selected, size, none } = {}) {
    const b = h('button', { class: ['st-sw', selected && 'is-on', none && 'st-sw--none', size === 'lg' && 'st-sw--lg'], attrs: { type: 'button', 'aria-label': label || hex, title: label || hex, 'aria-pressed': selected === undefined ? null : String(!!selected) }, onClick });
    if (!none) b.style.setProperty('--c', hex);
    return b;
}

/** The colour chooser body: brand chips, colours on the page, saved colours, a custom picker. onPick(hex, {token}). */
export function colourChooser(ctx, { value, onPick, allowNone, onNone, alpha, page }) {
    const root = h('div', { class: 'st-colour' });
    const kit = ctx.brand;
    const group = (title, chips, cls) => { if (!chips.length) return; root.appendChild(h('div', { class: 'st-colour__group' }, h('div', { class: 'st-colour__title', text: title }), h('div', { class: ['st-colour__chips', cls] }, chips))); };

    if (kit && Array.isArray(kit.colors) && kit.colors.length) {
        group('Brand colours', kit.colors.filter((c) => HEX.test(c.hex)).map((c) => swatch(c.hex, { label: `${c.name || c.key} ${c.hex}`, selected: value && value.toLowerCase() === c.hex.toLowerCase(), onClick: () => onPick(c.hex, { token: 'brand.colors.' + c.key }) })));
    } else {
        root.appendChild(h('div', { class: 'st-colour__note' }, h('span', { text: 'No brand kit yet. ' }), ctx.url('design.brand.index') ? h('a', { href: ctx.url('design.brand.index'), text: 'Set up your brand', target: '_blank', rel: 'noopener' }) : null));
    }
    const pc = page ? ctx.pageColours() : [];
    group('On this page', pc.map((c) => swatch(c, { selected: value && value.toLowerCase().slice(0, 7) === c, onClick: () => onPick(c, {}) })));
    const saved = savedColours();
    const basics = ['#000000', '#ffffff', '#6b7280', '#ef4444', '#f59e0b', '#22c55e', '#0ea5e9', '#6366f1', '#a855f7', '#ec4899'];
    group('Basics', basics.map((c) => swatch(c, { selected: value && value.toLowerCase().slice(0, 7) === c, onClick: () => onPick(c, {}) })));
    const savedBox = h('div', { class: ['st-colour__chips'] });
    const savedWrap = h('div', { class: 'st-colour__group' }, h('div', { class: 'st-colour__title', text: 'Your colours' }), savedBox);
    const paintSaved = () => { savedBox.replaceChildren(...savedColours().map((c) => swatch(c, { onClick: () => onPick(c, {}) }))); savedWrap.hidden = !savedColours().length; };
    root.appendChild(savedWrap); paintSaved();
    void saved;

    const native = h('input', { class: 'st-colour__native', attrs: { type: 'color', 'aria-label': 'Pick any colour' } });
    native.value = value && HEX.test(value) ? value.slice(0, 7) : '#3b82f6';
    const hex = h('input', { class: 'lt-input st-colour__hex lt-mono', attrs: { type: 'text', 'aria-label': 'Colour code', placeholder: '#1a2b3c', maxlength: 9, spellcheck: 'false' } });
    hex.value = value && HEX.test(value) ? value : '';
    const save = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, text: 'Save colour' });
    native.addEventListener('input', () => { hex.value = native.value; onPick(native.value, {}); });
    hex.addEventListener('change', () => { const v = normHex(hex.value); if (!v) { hex.setAttribute('aria-invalid', 'true'); return; } hex.removeAttribute('aria-invalid'); native.value = v.slice(0, 7); onPick(v, {}); });
    save.addEventListener('click', () => { const v = normHex(hex.value) || native.value; saveColour(v); paintSaved(); });
    root.appendChild(h('div', { class: 'st-colour__custom' }, native, hex, save));
    if (allowNone) root.appendChild(h('button', { class: 'lt-btn lt-btn--ghost lt-btn--sm st-colour__none', attrs: { type: 'button' }, text: 'No colour', onClick: () => onNone && onNone() }));
    return root;
}

/**
 * colourField({ctx, label, value, onPick, allowNone, onNone}): a swatch button that opens the chooser.
 * value may be a hex, MIXED, or null (none).
 */
export function colourField({ ctx, label, value, onPick, allowNone, onNone, page = true }) {
    let current = value;
    const chip = h('span', { class: 'st-colourbtn__chip' });
    const text = h('span', { class: 'st-colourbtn__text lt-mono' });
    const btn = h('button', { class: 'st-colourbtn', attrs: { type: 'button', 'aria-haspopup': 'dialog', 'aria-label': label } }, chip, text);
    function show() {
        if (current === MIXED) { chip.classList.add('is-mixed'); chip.style.removeProperty('--c'); text.textContent = 'Mixed'; } else if (!current) { chip.classList.add('is-none'); chip.classList.remove('is-mixed'); chip.style.removeProperty('--c'); text.textContent = 'None'; } else { chip.classList.remove('is-mixed', 'is-none'); chip.style.setProperty('--c', current); text.textContent = String(current).toUpperCase(); }
    }
    btn.addEventListener('click', () => {
        openPopover(btn, (el, pop) => {
            el.appendChild(colourChooser(ctx, { value: typeof current === 'string' ? current : null, page, allowNone, onNone: () => { pop.close(); if (onNone) onNone(); },
                onPick: (hex, meta) => { current = hex; show(); onPick(hex, meta); } }));
            return null;
        }, { label, className: 'st-pop--colour', width: 264 });
    });
    show();
    return { el: btn, set(v) { current = v; show(); } };
}

/** A collapsible section for panels. */
export function section(title, body, { open = true, id, actions } = {}) {
    const sid = uid('sec');
    const head = h('button', { class: 'st-sec__head', attrs: { type: 'button', 'aria-expanded': String(open), 'aria-controls': sid } }, h('span', { class: 'st-sec__title', text: title }), icon('chevron-down', 'st-sec__chev'));
    const content = h('div', { class: 'st-sec__body', attrs: { id: sid } }, body);
    const el = h('section', { class: ['st-sec', !open && 'is-closed'], data: { section: id || null } }, h('div', { class: 'st-sec__bar' }, head, actions || null), content);
    head.addEventListener('click', () => { const o = head.getAttribute('aria-expanded') !== 'true'; head.setAttribute('aria-expanded', String(o)); el.classList.toggle('is-closed', !o); });
    return { el, body: content };
}

export function emptyNote(text) { return h('p', { class: 'st-note', text }); }

/* Kit editor sections: fonts (pickers, live specimens, pairings) and text styles. Fonts load only when something needs to show them. */
import { append, clear, el, fontLabel, fontManager, fontStack, icon, loadFonts, whenVisible } from './brand-core.js';
import { FONT_SLOTS, MAX_TYPE_STYLES, availablePairings } from './brand-kit-state.js';
import { button, errSlot, iconButton, uid } from './brand-ed-ui.js';

const SLOT_COPY = {
    heading: { title: 'Headings', sample: (ctx) => ctx.state.name || 'Fresh bread, every morning', mid: 'A short line to introduce the offer', small: 'Used for titles and the biggest words on a design.' },
    body: { title: 'Body text', sample: () => 'Handmade in small batches', mid: 'Everything you need to know, set in a font that is easy to read at a small size.', small: 'The quick brown fox jumps over the lazy dog. 0123456789' },
    accent: { title: 'Accents', sample: () => 'Open daily, 7 to 5', mid: 'Used sparingly, for a label, a price or a stamp.', small: 'Short words only. Accent fonts are not meant for long text.' },
};

const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900];

// ---- fonts ------------------------------------------------------------------------------------------------------

export function mountFonts(host, ctx) {
    const fm = fontManager(ctx.boot.fonts);
    const manifest = fm.list();
    const keys = manifest.map((f) => f.key);
    const slots = el('div', { class: 'br-slots' });
    const pairs = el('div', { class: 'br-pairs', role: 'group', 'aria-label': 'Font pairings' });
    const specimens = {};
    let loadedOnce = false;

    function refreshSpecimens() {
        for (const slot of FONT_SLOTS) {
            const s = specimens[slot];
            if (!s) continue;
            const stack = fontStack(ctx.state.fonts[slot]);
            for (const n of s.querySelectorAll('[data-sp]')) n.style.fontFamily = stack;
            const copy = SLOT_COPY[slot];
            s.querySelector('[data-sp="big"]').textContent = copy.sample(ctx);
        }
    }

    function ensure(keysToLoad) { return loadFonts(keysToLoad, () => { refreshSpecimens(); refreshPairs(); }); }

    function pairing(p) {
        const on = FONT_SLOTS.every((s) => ctx.state.fonts[s] === p[s]);
        const b = el('button', { type: 'button', class: 'br-pair', 'aria-pressed': on ? 'true' : 'false', disabled: !ctx.canEdit, 'aria-label': `${p.label}: ${fontLabel(p.heading)} with ${fontLabel(p.body)}`, onclick: () => {
            ctx.state.fonts = { heading: p.heading, body: p.body, accent: p.accent };
            ctx.change(); renderSlots(); ensure([p.heading, p.body, p.accent]);
        } }, [
            el('span', { class: 'br-pair__h', text: p.label, style: { 'font-family': fontStack(p.heading), 'font-weight': '700' } }),
            el('span', { class: 'br-pair__b', text: 'Body text in ' + fontLabel(p.body), style: { 'font-family': fontStack(p.body) } }),
            el('small', { text: `${fontLabel(p.heading)} and ${fontLabel(p.body)}` }),
        ]);
        return b;
    }

    function refreshPairs() {
        clear(pairs);
        for (const p of availablePairings(keys)) pairs.appendChild(pairing(p));
    }

    function slotBlock(slot) {
        const copy = SLOT_COPY[slot];
        const id = uid('fs');
        const select = el('select', { id, class: 'lt-select', 'data-path': `fonts.${slot}`, disabled: !ctx.canEdit }, manifest.map((f) => el('option', { value: f.key, text: f.label })));
        select.value = ctx.state.fonts[slot];
        select.addEventListener('change', () => { ctx.state.fonts = { ...ctx.state.fonts, [slot]: select.value }; ctx.change(); ensure([select.value]).then(refreshSpecimens); refreshSpecimens(); refreshPairs(); ctx.emit('fonts'); });
        const spec = el('div', { class: 'br-specimen', 'aria-label': `${copy.title} specimen` }, [
            el('p', { class: 'br-specimen__big', 'data-sp': 'big', text: copy.sample(ctx), style: { 'font-weight': slot === 'heading' ? '700' : '500' } }),
            el('p', { class: 'br-specimen__mid', 'data-sp': 'mid', text: copy.mid }),
            el('p', { class: 'br-specimen__small', 'data-sp': 'small', text: copy.small }),
        ]);
        specimens[slot] = spec;
        return el('div', { class: 'br-slot' }, [
            el('div', { class: 'br-slot__head' }, [
                el('div', { class: 'lt-field' }, [el('label', { class: 'lt-label', for: id, text: copy.title }), select, errSlot(`fonts.${slot}`)]),
                ctx.canEdit ? button('Browse fonts', { onclick: () => browse(slot) }) : null,
            ]),
            spec,
        ]);
    }

    function renderSlots() {
        clear(slots);
        for (const slot of FONT_SLOTS) slots.appendChild(slotBlock(slot));
        refreshSpecimens();
        refreshPairs();
    }

    function browse(slot) {
        const dialog = el('dialog', { class: 'lt-dialog', 'aria-labelledby': 'fb-title' });
        const close = () => { dialog.close(); dialog.remove(); };
        const grid = el('div', { class: 'br-fontgrid' });
        const draw = () => {
            clear(grid);
            for (const f of manifest) {
                grid.appendChild(el('button', { type: 'button', class: 'br-fontopt', 'aria-pressed': ctx.state.fonts[slot] === f.key ? 'true' : 'false', onclick: () => {
                    ctx.state.fonts = { ...ctx.state.fonts, [slot]: f.key }; ctx.change(); close(); renderSlots(); ensure([f.key]);
                } }, [
                    el('span', { class: 'br-fontopt__name', text: f.label, style: { 'font-family': fontStack(f.key) } }),
                    el('small', { text: `${f.category}, ${f.weights.length} weights` }),
                ]));
            }
        };
        append(dialog, el('div', { class: 'lt-dialog__wrap' }, el('div', { class: 'lt-dialog__box lt-dialog__box--wide' }, [
            el('div', { class: 'lt-dialog__head' }, [el('h2', { class: 'lt-dialog__title', id: 'fb-title', text: `Choose the ${SLOT_COPY[slot].title.toLowerCase()} font` }), el('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': 'Close', onclick: close }, icon('x'))]),
            el('div', { class: 'lt-dialog__body' }, [el('p', { class: 'lt-small', text: 'Each name is shown in its own font. These are the 18 fonts the editor offers.' }), grid]),
        ])));
        dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
        dialog.addEventListener('click', (e) => { if (e.target.classList && e.target.classList.contains('lt-dialog__wrap')) close(); });
        document.body.appendChild(dialog);
        draw();
        dialog.showModal();
        ensure(keys).then(draw);
    }

    host.appendChild(el('div', { class: 'br-stack' }, [
        el('div', { class: 'br-stack br-stack--tight' }, [el('h3', { class: 'br-sub', text: 'Start from a pairing' }), el('p', { class: 'br-help', text: 'Combinations that work well together. Pick one, then change any font below.' }), pairs]),
        slots,
    ]));
    renderSlots();

    whenVisible(host, () => {
        if (loadedOnce) return;
        loadedOnce = true;
        ensure([...Object.values(ctx.state.fonts), ...availablePairings(keys).flatMap((p) => [p.heading, p.body])]);
    });

    ctx.on('name', refreshSpecimens);
    ctx.on('reset', renderSlots);
}

// ---- text styles ------------------------------------------------------------------------------------------------

export function mountStyles(host, ctx) {
    const list = el('div', { class: 'br-styles' });
    const addErr = errSlot('type_styles');
    const slotLabel = { heading: 'Heading font', body: 'Body font', accent: 'Accent font' };
    const caseLabel = { none: 'As typed', upper: 'UPPERCASE', lower: 'lowercase', title: 'Title Case' };

    function previewOf(s, node) {
        node.style.fontFamily = fontStack(ctx.state.fonts[s.font]);
        node.style.fontSize = Math.min(Number(s.size) || 16, 44) + 'px';
        node.style.fontWeight = String(s.weight);
        node.style.lineHeight = String(s.line_height);
        node.style.letterSpacing = (Number(s.letter_spacing) || 0) + 'em';
        node.style.textTransform = { none: 'none', upper: 'uppercase', lower: 'lowercase', title: 'capitalize' }[s.case] || 'none';
    }

    function row(s, i) {
        const prev = el('p', { class: 'br-style__preview', 'aria-label': `Preview of ${s.label}`, text: ctx.state.name || 'Handmade bread, baked daily' });
        previewOf(s, prev);
        const upd = (key, value) => { ctx.state.type_styles[i] = { ...ctx.state.type_styles[i], [key]: value }; previewOf(ctx.state.type_styles[i], prev); ctx.change(); };

        const num = (label, key, { min, max, step }) => {
            const id = uid('n');
            const input = el('input', { id, class: 'lt-input', type: 'number', min, max, step, value: s[key], 'data-path': `type_styles.${i}.${key}`, disabled: !ctx.canEdit, inputmode: 'decimal' });
            input.addEventListener('input', () => { if (input.value !== '') upd(key, Number(input.value)); });
            return el('div', { class: 'lt-field' }, [el('label', { class: 'lt-label', for: id, text: label }), input, errSlot(`type_styles.${i}.${key}`)]);
        };
        const choice = (label, key, options, parse = String) => {
            const id = uid('c');
            const sel = el('select', { id, class: 'lt-select', 'data-path': `type_styles.${i}.${key}`, disabled: !ctx.canEdit }, options.map(([v, t]) => el('option', { value: v, text: t })));
            sel.value = String(s[key]);
            sel.addEventListener('change', () => upd(key, parse(sel.value)));
            return el('div', { class: 'lt-field' }, [el('label', { class: 'lt-label', for: id, text: label }), sel, errSlot(`type_styles.${i}.${key}`)]);
        };
        const weights = WEIGHTS.includes(Number(s.weight)) ? WEIGHTS : [...WEIGHTS, Number(s.weight)];
        const nameId = uid('n');
        const name = el('input', { id: nameId, class: 'lt-input', value: s.label, maxlength: 40, 'data-path': `type_styles.${i}.label`, disabled: !ctx.canEdit });
        name.addEventListener('input', () => upd('label', name.value));

        return el('div', { class: 'br-style' }, [
            el('div', { class: 'br-style__top' }, [
                el('div', { class: 'lt-field' }, [el('label', { class: 'lt-label', for: nameId, text: 'Style name' }), name, errSlot(`type_styles.${i}.label`)]),
                ctx.canEdit ? iconButton(`Remove style ${s.label}`, icon('trash'), () => { ctx.state.type_styles.splice(i, 1); ctx.change(); render(); }) : null,
            ]),
            el('div', { class: 'br-style__fields' }, [
                choice('Font', 'font', FONT_SLOTS.map((k) => [k, slotLabel[k]])),
                num('Size', 'size', { min: 8, max: 400, step: 1 }),
                choice('Weight', 'weight', weights.map((w) => [w, String(w)]), Number),
                num('Line height', 'line_height', { min: 0.6, max: 3, step: 0.05 }),
                num('Letter spacing', 'letter_spacing', { min: -0.2, max: 1, step: 0.01 }),
                choice('Capitals', 'case', Object.entries(caseLabel)),
            ]),
            prev,
        ]);
    }

    function render() {
        clear(list);
        ctx.state.type_styles.forEach((s, i) => list.appendChild(row(s, i)));
        if (!ctx.state.type_styles.length) list.appendChild(el('p', { class: 'lt-small', text: 'No text styles yet.' }));
        add.disabled = !ctx.canEdit || ctx.state.type_styles.length >= MAX_TYPE_STYLES;
        count.textContent = `${ctx.state.type_styles.length} of ${MAX_TYPE_STYLES} styles`;
    }

    const count = el('span', { class: 'lt-meta' });
    const add = button('Add a text style', { icon: icon('plus'), onclick: () => {
        ctx.state.type_styles.push({ key: 'style', label: `Style ${ctx.state.type_styles.length + 1}`, font: 'body', size: 24, weight: 400, line_height: 1.3, letter_spacing: 0, case: 'none' });
        ctx.change(); render();
    } });

    host.appendChild(el('div', { class: 'br-stack' }, [list, addErr, el('div', { class: 'br-row' }, [add, count])]));
    ctx.on('fonts', render);
    ctx.on('name', render);
    ctx.on('reset', render);
    render();
    whenVisible(host, () => loadFonts(Object.values(ctx.state.fonts), render));
}

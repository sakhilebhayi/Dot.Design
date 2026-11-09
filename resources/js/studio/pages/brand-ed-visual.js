/* Kit editor sections: logo (with the light and dark check) and colours (roles, reorder, logo colours, contrast checker). */
import { pickAsset, uploadAsset, ACCEPT } from './brand-assetpicker.js';
import { api, append, clear, el, firstError, icon, toast } from './brand-core.js';
import { NEAR_BLACK, WHITE, normaliseHex, parseTypedHex, ratio, readability, verdict } from './brand-contrast.js';
import { ROLE_LABELS, SINGLE_ROLES, acceptCandidate, acceptSuggested, addExtra, assignRole, candidatesFrom, remove, rename, reorder, setHex } from './brand-palette.js';
import { button, errSlot, iconButton, selectField, uid } from './brand-ed-ui.js';

const SHORT_ROLE = { primary: 'Main', secondary: 'Second', accent: 'Highlight', neutral: 'Neutral', extra: 'Extra' };

const LOGO_ROLES = [
    { role: 'logo', title: 'Logo', help: 'Your main logo, for light backgrounds.' },
    { role: 'logo-light', title: 'Logo for dark backgrounds', help: 'Optional. A white or light version that shows up on dark colours.' },
    { role: 'mark', title: 'Icon or mark', help: 'Optional. A small square version for profile pictures and stamps.' },
];

// ---- how a logo reads on light and dark: judged by its average colour --------------------------------------------

const averageCache = new Map();

/** The average colour of the visible pixels of a picture (as #rrggbb), or null when it cannot be read. */
export function averageColour(url) {
    if (averageCache.has(url)) return averageCache.get(url);
    const p = new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            try {
                const size = 48;
                const canvas = document.createElement('canvas');
                const scale = Math.min(1, size / Math.max(img.naturalWidth, img.naturalHeight));
                canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
                canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                let r = 0; let g = 0; let b = 0; let n = 0;
                for (let i = 0; i < data.length; i += 4) {
                    const a = data[i + 3] / 255;
                    if (a < 0.5) continue;
                    r += data[i]; g += data[i + 1]; b += data[i + 2]; n += 1;
                }
                if (!n) { resolve(null); return; }
                const h = (v) => Math.round(v / n).toString(16).padStart(2, '0');
                resolve('#' + h(r) + h(g) + h(b));
            } catch (e) { resolve(null); }
        };
        img.onerror = () => resolve(null);
        img.src = url;
    });
    averageCache.set(url, p);
    return p;
}

function onWhat(avg, background) {
    const r = ratio(avg, background);
    return r === null ? null : r;
}

// ---- logo section -------------------------------------------------------------------------------------------------

export function mountLogo(host, ctx) {
    const wrap = el('div', { class: 'br-logos' });
    host.appendChild(wrap);

    const current = (role) => ctx.assets.find((a) => a.role === role) || null;

    async function setFromFile(role, file) {
        const r = await uploadAsset(ctx.boot.urls.assetUpload, file, { maxKb: ctx.boot.limits.max_kb });
        if (!r.ok) { toast(r.message, 'bad'); return; }
        ctx.setSingleAsset(role, r.asset);
        toast(r.duplicate ? 'That picture was already in your assets. Using it.' : 'Uploaded. It is saved to your assets and joins the kit when you press Save.', 'ok');
    }

    function card({ role, title, help }) {
        const a = current(role);
        const fileInput = el('input', { type: 'file', accept: ACCEPT, hidden: true, 'aria-label': `Upload ${title.toLowerCase()}` });
        fileInput.addEventListener('change', () => { const f = fileInput.files[0]; fileInput.value = ''; if (f) setFromFile(role, f); });

        const tile = (kind) => el('div', { class: `br-logo__tile br-logo__tile--${a ? kind : 'empty'}` }, a
            ? el('img', { src: a.asset.url, alt: kind === 'light' ? `${title} on a light background` : `${title} on a dark background`, width: a.asset.width, height: a.asset.height })
            : [kind === 'light' ? 'No picture yet' : null].filter(Boolean));

        const verdicts = el('div', { class: 'br-logo__verdicts', 'aria-live': 'polite' });
        if (a) {
            averageColour(a.asset.url).then((avg) => {
                clear(verdicts);
                if (!avg) { verdicts.appendChild(el('p', { class: 'lt-meta', text: 'We could not read this picture to check how it shows up.' })); return; }
                const light = onWhat(avg, WHITE);
                const dark = onWhat(avg, NEAR_BLACK);
                const line = (kind, bg, r) => {
                    const ok = r >= 3;
                    return el('p', {}, [el('span', { class: 'br-dot br-dot--' + (ok ? 'ok' : (r >= 2 ? 'warn' : 'bad')), 'aria-hidden': 'true' }), el('span', { text: ok ? `Easy to see on ${bg}.` : `Hard to see on ${bg}.` })]);
                };
                verdicts.appendChild(line('light', 'light backgrounds', light));
                verdicts.appendChild(line('dark', 'dark backgrounds', dark));
                if (role === 'logo' && dark < 3 && !current('logo-light')) verdicts.appendChild(el('p', { class: 'lt-meta', text: 'Add a logo for dark backgrounds below, so templates with a dark backdrop stay readable.' }));
                verdicts.appendChild(el('p', { class: 'lt-meta', text: "Judged by the logo's average colour." }));
            });
        }

        const actions = el('div', { class: 'br-row' }, ctx.canEdit ? [
            button(a ? 'Replace' : 'Upload', { variant: a ? 'secondary' : 'primary', icon: icon('upload'), onclick: () => fileInput.click() }),
            button('Choose from assets', { icon: icon('image'), onclick: async () => { const picked = await pickAsset({ boot: ctx.boot, title: `Choose ${title.toLowerCase()}` }); if (picked) ctx.setSingleAsset(role, picked); } }),
            a ? button('Remove', { variant: 'ghost', icon: icon('x'), onclick: () => ctx.removeAsset(a) }) : null,
            fileInput,
        ] : []);

        const box = el('div', { class: 'br-logo br-drop', 'data-role': role }, [
            el('div', { class: 'br-logo__head' }, [el('h3', { class: 'br-sub', text: title }), el('p', { class: 'lt-meta', text: help })]),
            el('div', { class: 'br-logo__tiles' }, [tile('light'), tile('dark')]),
            verdicts,
            actions,
        ]);

        if (ctx.canEdit) {
            box.addEventListener('dragover', (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) { e.preventDefault(); box.classList.add('is-over'); } });
            box.addEventListener('dragleave', () => box.classList.remove('is-over'));
            box.addEventListener('drop', (e) => {
                e.preventDefault(); box.classList.remove('is-over');
                const f = e.dataTransfer && e.dataTransfer.files[0];
                if (f) setFromFile(role, f);
            });
        }
        return box;
    }

    function render() {
        clear(wrap);
        for (const r of LOGO_ROLES) wrap.appendChild(card(r));
    }
    ctx.on('assets', render);
    render();
}

// ---- colours section ----------------------------------------------------------------------------------------------

export function mountColors(host, ctx) {
    const rows = el('div', { class: 'br-colors', role: 'list', 'aria-label': 'Brand colours' });
    const cands = el('div', { 'aria-live': 'polite' });
    const checker = el('div', { class: 'br-contrast' });
    const reads = el('div', { class: 'br-reads' });
    const logoBtnHint = el('p', { class: 'lt-meta' });
    const colorsErr = errSlot('colors');
    let candidates = null;
    let dragFrom = null;

    const logoSource = () => ctx.assets.find((a) => a.role === 'logo') || ctx.assets.find((a) => a.role === 'logo-light') || ctx.assets.find((a) => a.role === 'mark') || null;

    function commit(next) { ctx.state.colors = next; ctx.change(); renderRows(); renderChecker(); renderReads(); renderCands(); }

    function colorRow(c, i) {
        const row = el('div', { class: 'br-color', role: 'listitem', 'data-index': i });
        const well = el('label', { class: 'br-color__well', style: { '--c': c.hex }, title: 'Pick a colour' });
        const picker = el('input', { type: 'color', value: normaliseHex(c.hex) || '#000000', 'aria-label': `Pick colour for ${c.name}`, disabled: !ctx.canEdit });
        well.appendChild(picker);
        const hex = el('input', { class: 'lt-input br-color__hex', value: c.hex, maxlength: 7, 'aria-label': `Colour code for ${c.name}`, 'data-path': `colors.${i}.hex`, spellcheck: 'false', autocomplete: 'off', disabled: !ctx.canEdit });
        const err = errSlot(`colors.${i}.hex`);

        picker.addEventListener('input', () => {
            ctx.state.colors = setHex(ctx.state.colors, i, picker.value);
            hex.value = ctx.state.colors[i].hex;
            well.style.setProperty('--c', ctx.state.colors[i].hex);
            hex.removeAttribute('aria-invalid');
            ctx.change(); renderChecker(); renderReads();
        });
        hex.addEventListener('input', () => {
            const parsed = parseTypedHex(hex.value);
            if (!parsed) { hex.setAttribute('aria-invalid', 'true'); return; }
            hex.removeAttribute('aria-invalid');
            ctx.state.colors = setHex(ctx.state.colors, i, parsed);
            picker.value = parsed;
            well.style.setProperty('--c', parsed);
            ctx.change(); renderChecker(); renderReads();
        });
        hex.addEventListener('blur', () => { hex.value = ctx.state.colors[i] ? ctx.state.colors[i].hex : hex.value; hex.removeAttribute('aria-invalid'); });

        const name = el('input', { class: 'lt-input', value: c.name, maxlength: 40, 'aria-label': `Name of colour ${i + 1}`, 'data-path': `colors.${i}.name`, disabled: !ctx.canEdit });
        name.addEventListener('input', () => { ctx.state.colors = rename(ctx.state.colors, i, name.value); ctx.change(); });
        const roleSel = el('select', { class: 'lt-select', 'aria-label': `Role of ${c.name}`, 'data-path': `colors.${i}.role`, disabled: !ctx.canEdit }, [...SINGLE_ROLES, 'extra'].map((r) => el('option', { value: r, text: SHORT_ROLE[r] })));
        roleSel.value = c.role;
        roleSel.addEventListener('change', () => commit(assignRole(ctx.state.colors, i, roleSel.value)));

        const grip = el('span', { class: 'br-color__grip', 'aria-hidden': 'true', title: 'Drag to reorder' }, icon('grip'));
        if (ctx.canEdit) {
            grip.addEventListener('mousedown', () => { row.draggable = true; });
            row.addEventListener('dragstart', (e) => { dragFrom = i; row.classList.add('is-dragging'); if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i)); } });
            row.addEventListener('dragend', () => { row.draggable = false; row.classList.remove('is-dragging'); dragFrom = null; rows.querySelectorAll('.is-over').forEach((n) => n.classList.remove('is-over')); });
            row.addEventListener('dragover', (e) => { if (dragFrom !== null) { e.preventDefault(); row.classList.add('is-over'); } });
            row.addEventListener('dragleave', () => row.classList.remove('is-over'));
            row.addEventListener('drop', (e) => { e.preventDefault(); if (dragFrom !== null) commit(reorder(ctx.state.colors, dragFrom, i)); });
        }

        const tools = el('div', { class: 'br-color__tools' }, ctx.canEdit ? [
            iconButton(`Move ${c.name} up`, icon('arrow-up'), () => commit(reorder(ctx.state.colors, i, i - 1)), { disabled: i === 0 }),
            iconButton(`Move ${c.name} down`, icon('arrow-down'), () => commit(reorder(ctx.state.colors, i, i + 1)), { disabled: i === ctx.state.colors.length - 1 }),
            iconButton(`Remove ${c.name}`, icon('trash'), () => commit(remove(ctx.state.colors, i))),
        ] : []);

        append(row, [grip, well, hex, el('div', { class: 'br-color__names' }, [name, roleSel]), tools, el('div', { class: 'br-color__err' }, [err, errSlot(`colors.${i}.role`), errSlot(`colors.${i}.name`)])]);
        return row;
    }

    function renderRows() {
        clear(rows);
        ctx.state.colors.forEach((c, i) => rows.appendChild(colorRow(c, i)));
        if (!ctx.state.colors.length) rows.appendChild(el('p', { class: 'lt-small', text: 'No colours yet. Add one below, or use the colours in your logo.' }));
    }

    // add an extra colour
    const addHex = el('input', { class: 'lt-input', value: '#', maxlength: 7, 'aria-label': 'Colour code for a new extra colour', spellcheck: 'false', autocomplete: 'off', disabled: !ctx.canEdit });
    const addPick = el('input', { type: 'color', value: '#888888', 'aria-label': 'Pick a new extra colour', disabled: !ctx.canEdit, style: { width: '2.5rem', height: '2.5rem', padding: '0', border: '0', background: 'none' } });
    addPick.addEventListener('input', () => { addHex.value = addPick.value; });
    const addMsg = el('p', { class: 'lt-error', hidden: true });
    const addBtn = button('Add colour', { icon: icon('plus'), onclick: () => {
        const parsed = parseTypedHex(addHex.value);
        if (!parsed) { addMsg.textContent = 'Use a colour code such as #1a2b3c.'; addMsg.hidden = false; return; }
        const r = addExtra(ctx.state.colors, parsed);
        if (!r.added) { addMsg.textContent = r.reason; addMsg.hidden = false; return; }
        addMsg.hidden = true; addHex.value = '#';
        commit(r.colors);
    } });

    // use my logo colours
    const logoBtn = button('Use my logo colours', { icon: icon('pipette'), onclick: async () => {
        const src = logoSource();
        if (!src) return;
        logoBtn.setAttribute('aria-busy', 'true');
        const res = await api('POST', ctx.boot.urls.palette, { asset_id: src.design_asset_id, max: 5 });
        logoBtn.removeAttribute('aria-busy');
        if (!res.ok) { toast(firstError(res.errors) || res.message, 'bad'); return; }
        candidates = res.data;
        renderCands();
    } });

    function syncLogoBtn() {
        const src = logoSource();
        logoBtn.disabled = !src || !ctx.canEdit;
        logoBtnHint.textContent = src ? '' : 'Add a logo first, then we can read its colours.';
    }

    function renderCands() {
        clear(cands);
        if (!candidates) return;
        const list = candidatesFrom(candidates, ctx.state.colors);
        if (!list.length) { cands.appendChild(el('p', { class: 'lt-small', text: 'We could not find colours in that picture.' })); return; }
        const panel = el('div', { class: 'br-cands' });
        panel.appendChild(el('div', { class: 'br-row' }, [
            el('p', { class: 'br-sub', text: 'Colours in your logo' }),
            el('span', { style: { flex: '1' } }),
            button('Use the suggestions', { variant: 'primary', onclick: () => { const r = acceptSuggested(ctx.state.colors, candidates); if (!r.changed) toast('Your colours already match the suggestions.', 'warn'); else { commit(r.colors); toast(`Updated ${r.changed} ${r.changed === 1 ? 'colour' : 'colours'}. Press Save to keep them.`, 'ok'); } } }),
            button('Close', { variant: 'ghost', onclick: () => { candidates = null; renderCands(); } }),
        ]));
        for (const c of list) {
            const sel = el('select', { class: 'lt-select', 'aria-label': `Use ${c.hex} as` }, [...SINGLE_ROLES, 'extra'].map((r) => el('option', { value: r, text: SHORT_ROLE[r] })));
            sel.value = c.suggestedRole || 'extra';
            panel.appendChild(el('div', { class: 'br-cand' }, [
                el('span', { class: 'br-cand__chip', style: { '--c': c.hex } }),
                el('p', { class: 'br-cand__what' }, [el('b', { text: c.hex }), ` ${Math.round(c.share * 100)}% of the logo${c.inKit ? '. Already in your kit.' : '.'}`]),
                el('div', { class: 'br-cand__use' }, [sel, button('Use', { onclick: () => {
                    const r = acceptCandidate(ctx.state.colors, c, sel.value);
                    if (!r.changed) { toast(r.reason || 'Nothing to change.', 'warn'); return; }
                    commit(r.colors);
                } })]),
            ]));
        }
        cands.appendChild(panel);
    }

    // contrast checker
    const sel = { fg: null, bg: 'white' };
    const choiceList = () => [...ctx.state.colors.map((c) => ({ id: 'kit:' + c.key + ':' + c.role, name: c.name, hex: c.hex })), { id: 'white', name: 'White', hex: WHITE }, { id: 'black', name: 'Near black', hex: NEAR_BLACK }];
    const hexOf = (id) => { const f = choiceList().find((c) => c.id === id); return f ? f.hex : null; };

    function renderChecker() {
        clear(checker);
        const choices = choiceList();
        if (!sel.fg || !choices.some((c) => c.id === sel.fg)) { const n = ctx.state.colors.find((c) => c.role === 'neutral'); sel.fg = n ? 'kit:' + n.key + ':' + n.role : 'black'; }
        if (!choices.some((c) => c.id === sel.bg)) sel.bg = 'white';
        const mk = (label, which) => {
            const id = uid('c');
            const s = el('select', { id, class: 'lt-select' }, choices.map((c) => el('option', { value: c.id, text: `${c.name} ${c.hex}` })));
            s.value = sel[which];
            s.addEventListener('change', () => { sel[which] = s.value; renderChecker(); });
            return el('div', { class: 'lt-field' }, [el('label', { class: 'lt-label', for: id, text: label }), s]);
        };
        const fg = hexOf(sel.fg); const bg = hexOf(sel.bg);
        const r = ratio(fg, bg);
        const v = verdict(r);
        append(checker, [
            el('div', { class: 'br-contrast__pick' }, [mk('Text colour', 'fg'), mk('Background colour', 'bg')]),
            el('div', { class: 'br-contrast__sample', style: { '--fg': fg, '--bg': bg } }, [el('p', { class: 'br-contrast__big', text: ctx.state.name || 'Your headline here' }), el('p', { class: 'br-contrast__small', text: 'Small print and body text are what most people read, so this is the line that matters most.' })]),
            el('p', { class: 'br-verdict', 'data-level': v.level, role: 'status' }, [icon(v.level === 'body' ? 'circle-check' : 'alert', 'sm'), el('span', {}, [el('b', { text: v.label }), ' ' + v.detail])]),
        ]);
    }

    function renderReads() {
        clear(reads);
        for (const r of readability(ctx.state.colors)) {
            reads.appendChild(el('div', { class: 'br-read', style: { '--c': r.hex, '--t': r.text } }, [el('b', { text: r.name }), el('span', { text: `${r.verdict.label}` }), el('span', { text: r.hex })]));
        }
    }

    host.appendChild(el('div', { class: 'br-stack' }, [
        rows, colorsErr,
        ctx.canEdit ? el('div', { class: 'br-addcolor' }, [addPick, addHex, addBtn]) : null, addMsg,
        ctx.canEdit ? el('div', { class: 'br-stack br-stack--tight' }, [el('div', { class: 'br-row' }, [logoBtn]), logoBtnHint, cands]) : null,
        el('div', { class: 'br-stack br-stack--tight' }, [el('h3', { class: 'br-sub', text: 'Check two colours together' }), el('p', { class: 'br-help', text: 'Pick a text colour and the background it sits on. The verdict says what it is good for.' }), checker]),
        el('div', { class: 'br-stack br-stack--tight' }, [el('h3', { class: 'br-sub', text: 'How each colour reads' }), el('p', { class: 'br-help', text: 'Each colour as a background, with the better of white or near black as its text.' }), reads]),
    ]));

    ctx.on('assets', () => { syncLogoBtn(); });
    ctx.on('reset', () => { candidates = null; renderRows(); renderChecker(); renderReads(); renderCands(); syncLogoBtn(); });
    renderRows(); renderChecker(); renderReads(); syncLogoBtn();
}

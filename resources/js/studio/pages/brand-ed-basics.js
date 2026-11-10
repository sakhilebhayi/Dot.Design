/* Kit editor sections: name, voice and guidelines, tagline and contacts, brand imagery. */
import { pickAsset, uploadAsset, ACCEPT } from './brand-assetpicker.js';
import { append, clear, el, icon, toast } from './brand-core.js';
import { addWord, toggleTone, toneWords } from './brand-kit-state.js';
import { button, errSlot, textField, uid } from './brand-ed-ui.js';

export function mountName(host, ctx) {
    host.appendChild(el('div', { class: 'br-namefield' }, textField(ctx, {
        label: 'Kit name', path: 'name', maxlength: 80, placeholder: 'Sunrise Bakery',
        get: () => ctx.state.name, set: (v) => { ctx.state.name = v; ctx.emit('name'); },
    })));
}

const TONES = ['Friendly', 'Professional', 'Bold', 'Playful', 'Calm', 'Luxurious', 'Direct', 'Warm', 'Expert', 'Witty'];

function wordList(ctx, { label, path, key, placeholder, avoid }) {
    const list = el('div', { class: 'br-wordlist' });
    const msg = el('p', { class: 'lt-error', hidden: true });
    const id = uid('w');
    const input = el('input', { id, class: 'lt-input', placeholder, maxlength: 30, 'aria-describedby': id + '-h', autocomplete: 'off', disabled: !ctx.canEdit, 'data-path': path });

    function draw() {
        clear(list);
        for (const [i, w] of ctx.state.voice[key].entries()) {
            list.appendChild(el('span', { class: 'br-word' + (avoid ? ' br-word--avoid' : '') }, [el('span', { text: w }), ctx.canEdit ? el('button', { type: 'button', 'aria-label': `Remove ${w}`, onclick: () => { ctx.state.voice[key].splice(i, 1); ctx.change(); draw(); } }, icon('x', 'sm')) : null]));
        }
    }
    function commit() {
        const r = addWord(ctx.state.voice[key], input.value);
        if (r.reason) { msg.textContent = r.reason; msg.hidden = false; return; }
        msg.hidden = true;
        if (r.added) { ctx.state.voice[key] = r.list; input.value = ''; ctx.change(); draw(); }
    }
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); commit(); } });
    input.addEventListener('blur', () => { if (input.value.trim()) commit(); });
    draw();
    ctx.on('reset', draw);
    return el('div', { class: 'br-words' }, [
        el('label', { class: 'lt-label', for: id, text: label }),
        list,
        ctx.canEdit ? el('div', { class: 'br-row' }, [el('div', { style: { flex: '1 1 12rem', 'max-width': '20rem' } }, input), button('Add', { onclick: commit })]) : null,
        el('p', { class: 'lt-hint', id: id + '-h', text: 'Press Enter to add. Up to 12, 30 characters each.' }),
        msg, errSlot(path),
    ]);
}

export function mountVoice(host, ctx) {
    const chips = el('div', { class: 'br-tones', role: 'group', 'aria-label': 'Tone of voice' });
    const toneInput = textField(ctx, {
        label: 'In your own words', path: 'voice.tone', maxlength: 160, placeholder: 'Warm and direct, like a neighbour who knows bread',
        get: () => ctx.state.voice.tone, set: (v) => { ctx.state.voice.tone = v; drawChips(); },
    });

    function drawChips() {
        clear(chips);
        const on = toneWords(ctx.state.voice.tone).map((w) => w.toLowerCase());
        for (const t of TONES) {
            chips.appendChild(el('button', { type: 'button', class: 'lt-chip', 'aria-pressed': on.includes(t.toLowerCase()) ? 'true' : 'false', disabled: !ctx.canEdit, onclick: () => {
                ctx.state.voice.tone = toggleTone(ctx.state.voice.tone, t);
                toneInput.querySelector('input').value = ctx.state.voice.tone;
                ctx.change(); drawChips();
            }, text: t }));
        }
    }
    drawChips();
    ctx.on('reset', () => { toneInput.querySelector('input').value = ctx.state.voice.tone || ''; drawChips(); });

    host.appendChild(el('div', { class: 'br-stack' }, [
        el('div', { class: 'br-stack br-stack--tight' }, [el('h3', { class: 'br-sub', text: 'Tone' }), chips, toneInput]),
        wordList(ctx, { label: 'Words you like to use', path: 'voice.keywords', key: 'keywords', placeholder: 'handmade, local, fresh' }),
        wordList(ctx, { label: 'Words to avoid', path: 'voice.avoid', key: 'avoid', placeholder: 'cheap, discount', avoid: true }),
        textField(ctx, { as: 'textarea', rows: 6, label: 'Guidelines', path: 'guidelines', maxlength: 4000, counter: true, placeholder: 'How your logo is used, how you write prices, what you never put on a poster.', get: () => ctx.state.guidelines, set: (v) => { ctx.state.guidelines = v; } }),
        el('p', { class: 'br-honest' }, [icon('info', 'sm'), el('span', { text: 'Your tone, words and guidelines guide suggestions and the assistant when it is switched on. They are never applied to a design by themselves, and they do not change any text you wrote.' })]),
    ]));
}

export function mountContact(host, ctx) {
    const fields = ctx.boot.options.contact_fields || {};
    const keys = ctx.boot.options.contact_keys || Object.keys(fields);
    host.appendChild(el('div', { class: 'br-stack' }, [
        textField(ctx, { label: 'Tagline', path: 'tagline', maxlength: 160, placeholder: 'Baked before sunrise', hint: 'One short line that sits under your name.', get: () => ctx.state.tagline, set: (v) => { ctx.state.tagline = v; } }),
        textField(ctx, { label: 'Website', path: 'website', maxlength: 200, type: 'text', inputmode: 'url', placeholder: 'www.yourbusiness.co.za', get: () => ctx.state.website, set: (v) => { ctx.state.website = v; } }),
        el('div', { class: 'lt-form__row' }, keys.map((k) => textField(ctx, {
            label: (fields[k] && fields[k].label) || k, path: `contacts.${k}`, maxlength: 140, placeholder: fields[k] && fields[k].example, inputmode: k === 'phone' || k === 'whatsapp' ? 'tel' : (k === 'email' ? 'email' : null),
            get: () => ctx.state.contacts[k] || '', set: (v) => { ctx.state.contacts[k] = v; },
        }))),
    ]));
}

const IMAGE_ROLES = [
    { role: 'image', title: 'Pictures', help: 'Photos and artwork that belong to your brand.' },
    { role: 'pattern', title: 'Patterns', help: 'Repeating textures for backgrounds and borders.' },
    { role: 'background', title: 'Backgrounds', help: 'Full-page backdrops.' },
];

export function mountImagery(host, ctx) {
    const wrap = el('div', { class: 'br-stack' });
    host.appendChild(wrap);

    async function addFromFile(role, file) {
        const r = await uploadAsset(ctx.boot.urls.assetUpload, file, { maxKb: ctx.boot.limits.max_kb });
        if (!r.ok) { toast(r.message, 'bad'); return; }
        ctx.addAsset(role, r.asset);
    }

    function block({ role, title, help }) {
        const items = ctx.assets.filter((a) => a.role === role);
        const file = el('input', { type: 'file', accept: ACCEPT, hidden: true, 'aria-label': `Upload to ${title.toLowerCase()}` });
        file.addEventListener('change', () => { const f = file.files[0]; file.value = ''; if (f) addFromFile(role, f); });
        return el('div', { class: 'br-imgrole' }, [
            el('div', {}, [el('h3', { class: 'br-sub', text: title }), el('p', { class: 'lt-meta', text: help })]),
            items.length ? el('div', { class: 'br-imgs', role: 'list' }, items.map((a) => {
                const label = el('input', { class: 'lt-input', value: a.label || '', maxlength: 80, placeholder: 'Label (optional)', 'aria-label': `Label for ${a.asset.name}`, disabled: !ctx.canEdit });
                label.addEventListener('input', () => { a.label = label.value; ctx.change(); });
                return el('div', { class: 'br-img', role: 'listitem' }, [
                    el('div', { class: 'br-img__pic' }, el('img', { src: a.asset.preview_url || a.asset.url, alt: a.asset.name, loading: 'lazy', width: 128, height: 128 })),
                    label,
                    ctx.canEdit ? button('Remove', { variant: 'ghost', onclick: () => ctx.removeAsset(a) }) : null,
                ]);
            })) : el('p', { class: 'lt-small', text: 'None yet.' }),
            ctx.canEdit ? el('div', { class: 'br-row' }, [
                button('Upload', { icon: icon('upload'), onclick: () => file.click() }),
                button('Choose from assets', { icon: icon('image'), onclick: async () => { const p = await pickAsset({ boot: ctx.boot, title: `Add to ${title.toLowerCase()}` }); if (p) ctx.addAsset(role, p); } }),
                file,
            ]) : null,
        ]);
    }

    function render() { clear(wrap); for (const r of IMAGE_ROLES) wrap.appendChild(block(r)); }
    ctx.on('assets', render);
    render();
}

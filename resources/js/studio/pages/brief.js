// The guided brief: one question at a time, every optional step skippable, answers kept if the page reloads, and a live
// strip of starting points. The state machine (lib/brief-machine.js) decides what is asked; this file draws it.
import '../../../css/pages/start.css';
import { createApi, uploadFile } from './lib/api.js';
import { createBriefMachine } from './lib/brief-machine.js';
import { clear, debounce, h, icon, readBoot } from './lib/dom.js';
import { createPreviewer, createRenderQueue } from './lib/preview.js';
import { drawInto, loadIcons, whenVisible } from './lib/preview-ui.js';
import { safeStorage } from './lib/storage.js';

const boot = readBoot('brief-boot');
const root = document.getElementById('brief-root');
const api = createApi();
if (boot && root) start();

function start() {
    const storage = safeStorage('session');
    const machine = createBriefMachine({
        steps: boot.steps,
        goal: boot.goal.key,
        previous: boot.previous || {},
        presetFormat: boot.preset_format || null,
        storage,
    });
    const kitKey = `dot-design-brief-logokit:${boot.goal.key}`;
    const queue = createRenderQueue();
    let previewer = null;
    let iconsLoaded = null;
    async function getPreviewer() {
        if (!previewer) { iconsLoaded ||= loadIcons(); previewer = createPreviewer({ manifest: boot.fonts || [], icons: await iconsLoaded }); }
        return previewer;
    }

    const ui = { assets: null, assetsState: 'idle', palette: null, paletteFor: null, upload: null, strip: { state: 'idle', templates: [], note: '' }, choose: { state: 'idle', cards: [], message: '' }, createState: { busy: false, message: '', kind: 'neutral' }, logoKit: null };
    try { const saved = JSON.parse(storage.get(kitKey) || 'null'); if (saved && Number.isInteger(saved.id) && saved.logo === machine.getState().answers.logo_asset_id) ui.logoKit = { id: saved.id, logo: saved.logo }; } catch (e) { /* none saved */ }
    const templateCache = new Map();
    const labels = { what: 'About', business_name: 'Name', details: 'Details', logo: 'Logo', style: 'Look', where: 'Size', choose: 'Design' };
    const copy = {
        where: { example: null },
    };

    // ---- frame --------------------------------------------------------------------------------------------------
    const main = h('div', { class: 'cf-brief__main' });
    const strip = h('aside', { class: 'cf-strip', 'aria-labelledby': 'strip-title' });
    clear(root).append(main, strip);

    // ---- helpers ------------------------------------------------------------------------------------------------
    const kit = boot.kit;
    // the kit the design is made with: the one made from the logo when that is the chosen colour source, else the person's own
    const kitId = () => (machine.getState().answers.colours === 'logo' ? (ui.logoKit ? ui.logoKit.id : null) : (kit ? kit.id : null));
    const briefFor = (lenient) => machine.toBrief({ kitId: kitId(), lenient });

    function fetchTemplate(id) {
        if (!templateCache.has(id)) {
            templateCache.set(id, api.get(`${boot.urls.template_base}/${id}`).then((r) => {
                if (!r.ok) { templateCache.delete(id); return null; }
                return r.data;
            }));
        }
        return templateCache.get(id);
    }

    function frame(width, height, extra = '') {
        return h('span', { class: `lt-frame lt-frame--blank ${extra}`.trim(), style: { '--w': String(width), '--h': String(height) } });
    }

    function fieldError(id, message) {
        return h('p', { class: 'lt-error', id: `${id}-error`, 'data-error-for': id, text: message || '', hidden: !message });
    }

    // ---- the step list and progress -----------------------------------------------------------------------------
    function stepNav() {
        const state = machine.getState();
        return h('ol', { class: 'cf-steps', 'aria-label': 'Questions' }, machine.steps.map((s, i) => {
            const status = machine.stepStatus(s.key);
            const reachable = i <= state.reached;
            const current = i === state.index;
            return h('li', {}, h('button', {
                type: 'button', class: `cf-steps__item is-${status}${current ? ' is-current' : ''}`, 'aria-current': current ? 'step' : null, disabled: !reachable || current,
                dataset: { step: s.key },
                onclick: () => { if (machine.goTo(s.key)) renderStep(); },
            }, status === 'todo' ? null : h('span', { class: 'cf-steps__mark', 'aria-hidden': 'true' }, status === 'answered' ? icon('check', 'sm') : '-'), h('span', { text: labels[s.key] || s.key }), status === 'skipped' ? h('span', { class: 'lt-sr', text: ', skipped' }) : null));
        }));
    }

    // ---- inputs per step ----------------------------------------------------------------------------------------
    function textInput({ id, value, onInput, placeholder, maxlength, type = 'text', inputmode, autocomplete, multiline, describedBy, onBlur }) {
        const attrs = { id, class: multiline ? 'lt-textarea' : 'lt-input', value: multiline ? null : value, placeholder, maxlength: maxlength || null, autocomplete: autocomplete || 'off', 'aria-describedby': describedBy || null };
        const el = multiline ? h('textarea', { ...attrs, rows: '3' }) : h('input', { ...attrs, type, inputmode: inputmode || null });
        if (multiline) el.value = value || '';
        el.addEventListener('input', () => onInput(el.value));
        if (onBlur) el.addEventListener('blur', onBlur);
        return el;
    }

    function stepWhat(step) {
        const a = machine.getState().answers;
        const id = 'q-what';
        return h('div', { class: 'lt-field' },
            h('label', { class: 'lt-label', for: id, text: 'In a few words' }),
            textInput({ id, value: a.what, maxlength: 120, placeholder: step.example || '', onInput: (v) => { machine.setAnswer('what', v); }, describedBy: `${id}-hint ${id}-error` }),
            step.example ? h('p', { class: 'lt-hint', id: `${id}-hint`, text: `For example: ${step.example}` }) : null,
            fieldError('what', machine.getState().errors.what));
    }

    function stepName(step) {
        const a = machine.getState().answers;
        const id = 'q-business_name';
        const from = machine.getState().prefilled.includes('business_name');
        return h('div', { class: 'lt-field' },
            h('label', { class: 'lt-label', for: id, text: 'Business name' }),
            textInput({ id, value: a.business_name, maxlength: 60, placeholder: step.example || '', autocomplete: 'organization', onInput: (v) => { machine.setAnswer('business_name', v); }, describedBy: `${id}-hint ${id}-error` }),
            h('p', { class: 'lt-hint', id: `${id}-hint`, text: from ? 'Filled in from your last design. Change it if this one is for a different business.' : (step.example ? `For example: ${step.example}` : '') }),
            fieldError('business_name', machine.getState().errors.business_name));
    }

    function stepDetails(step) {
        const state = machine.getState();
        return h('div', { class: 'cf-fields' }, (step.fields || []).map((f) => {
            const id = `q-details-${f.key}`;
            const type = f.type === 'email' ? 'email' : f.type === 'phone' ? 'tel' : 'text';
            const from = state.prefilled.includes(`details.${f.key}`);
            return h('div', { class: 'lt-field' },
                h('label', { class: 'lt-label', for: id, text: f.label }),
                textInput({
                    id, value: state.answers.details[f.key] || '', maxlength: f.max, type, multiline: f.type === 'multiline', placeholder: f.example || '',
                    inputmode: f.type === 'phone' ? 'tel' : f.type === 'url' ? 'url' : null, autocomplete: f.type === 'phone' ? 'tel' : f.type === 'email' ? 'email' : f.type === 'url' ? 'url' : 'off',
                    onInput: (v) => machine.setDetail(f.key, v), onBlur: () => { machine.checkDetailNow(f.key); }, describedBy: `${id}-hint ${id}-error`,
                }),
                h('p', { class: 'lt-hint', id: `${id}-hint`, text: from ? `${f.help} Filled in from your last design.` : f.help }),
                fieldError(`details.${f.key}`, state.errors[`details.${f.key}`]));
        }));
    }

    // logo ---------------------------------------------------------------------------------------------------------
    async function loadAssets(force = false) {
        if (ui.assetsState === 'loading' || (ui.assetsState === 'ready' && !force)) return;
        ui.assetsState = 'loading';
        refreshLogo();
        const res = await api.get(`${boot.urls.assets}?per_page=24`);
        if (res.ok && res.data && Array.isArray(res.data.assets)) { ui.assets = res.data.assets.filter((a) => a.url && !a.placeholder); ui.assetsState = 'ready'; } else { ui.assetsState = 'error'; ui.assetsMessage = res.message; }
        refreshLogo();
    }

    async function loadPalette(assetId) {
        if (ui.paletteFor === assetId) return;
        ui.paletteFor = assetId;
        ui.palette = { state: 'loading' };
        refreshLogo();
        const res = await api.post(boot.urls.palette, { asset_id: assetId, max: 5 });
        if (ui.paletteFor !== assetId) return;
        ui.palette = res.ok && res.data && Array.isArray(res.data.colors) && res.data.colors.length ? { state: 'ready', colors: res.data.colors, roles: res.data.roles || {} } : { state: 'none', message: res.ok ? 'We could not find clear colours in that picture.' : res.message };
        refreshLogo();
    }

    function startUpload(file) {
        if (ui.upload && ui.upload.state === 'sending') return;
        ui.upload = { state: 'sending', name: file.name, progress: 0 };
        const job = uploadFile(boot.urls.asset_upload, file, { onProgress: (p) => { ui.upload.progress = p; paintUpload(); } });
        ui.upload.job = job;
        refreshLogo();
        job.promise.then((res) => {
            if (res.aborted) { ui.upload = null; refreshLogo(); return; }
            if (res.ok && res.data && res.data.asset) {
                const asset = res.data.asset;
                ui.assets = [asset, ...(ui.assets || []).filter((a) => a.id !== asset.id)];
                ui.assetsState = 'ready';
                ui.upload = { state: 'done', name: file.name, duplicate: !!res.data.duplicate };
                machine.setAnswer('logo_asset_id', asset.id);
            } else {
                ui.upload = { state: 'failed', name: file.name, message: res.message, file };
            }
            refreshLogo();
        });
    }

    function paintUpload() {
        const bar = document.querySelector('[data-cf-upload-bar]');
        if (bar && ui.upload) { bar.style.setProperty('--p', String(ui.upload.progress)); bar.setAttribute('aria-valuenow', String(Math.round(ui.upload.progress * 100))); }
    }

    function logoBody() {
        const a = machine.getState().answers;
        const wrap = h('div', { class: 'cf-logo', 'data-cf-logo': true });
        const input = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp', class: 'lt-sr', id: 'q-logo-file', tabindex: '-1', onchange: (e) => { const f = e.target.files && e.target.files[0]; e.target.value = ''; if (f) startUpload(f); } });
        const upload = ui.upload;
        const uploadRow = h('div', { class: 'cf-upload' },
            h('button', { type: 'button', class: 'lt-btn lt-btn--secondary', disabled: upload && upload.state === 'sending', onclick: () => input.click() }, icon('upload'), 'Upload a picture'),
            h('p', { class: 'lt-hint', text: 'PNG, JPG or WebP, up to 8 MB. A logo with a clear background works best.' }),
            input);
        wrap.append(uploadRow);

        if (upload && upload.state === 'sending') {
            wrap.append(h('div', { class: 'cf-upload__state', role: 'status' },
                h('p', { class: 'lt-small', text: `Sending ${upload.name}...` }),
                h('div', { class: 'lt-progress', role: 'progressbar', 'aria-label': 'Upload progress', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(upload.progress * 100)), 'data-cf-upload-bar': true, style: { '--p': String(upload.progress) } }, h('span')),
                h('button', { type: 'button', class: 'lt-btn lt-btn--ghost lt-btn--sm', onclick: () => upload.job.abort() }, 'Cancel')));
        } else if (upload && upload.state === 'failed') {
            wrap.append(h('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, icon('alert'), h('div', {}, h('strong', { text: 'That picture was not added.' }), h('p', { text: upload.message })),
                h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => startUpload(upload.file) }, 'Try again')));
        } else if (upload && upload.state === 'done') {
            wrap.append(h('p', { class: 'lt-small cf-ok', role: 'status', text: upload.duplicate ? `${upload.name} was already in your uploads. It is selected.` : `${upload.name} was added and selected.` }));
        }

        if (ui.assetsState === 'loading' || ui.assetsState === 'idle') {
            wrap.append(h('div', { class: 'cf-assets cf-assets--loading', 'aria-busy': 'true' }, [1, 2, 3, 4].map(() => h('span', { class: 'lt-skeleton cf-asset' }))));
        } else if (ui.assetsState === 'error') {
            wrap.append(h('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, icon('alert'), h('div', {}, h('strong', { text: 'We could not load your uploads.' }), h('p', { text: ui.assetsMessage || 'Try again.' })), h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => loadAssets(true) }, 'Try again')));
        } else if (!ui.assets.length) {
            wrap.append(h('p', { class: 'lt-small cf-quiet', text: 'You have no pictures yet. Upload one above, or skip this step and add it in the editor.' }));
        } else {
            wrap.append(h('p', { class: 'lt-label', id: 'logo-pick', text: 'Or pick one you already uploaded' }),
                h('ul', { class: 'cf-assets', role: 'list', 'aria-labelledby': 'logo-pick' }, ui.assets.map((asset) => h('li', {}, h('button', {
                    type: 'button', class: 'cf-asset', 'aria-pressed': a.logo_asset_id === asset.id ? 'true' : 'false', title: asset.name,
                    onclick: () => { machine.setAnswer('logo_asset_id', a.logo_asset_id === asset.id ? null : asset.id); refreshLogo(); },
                }, h('img', { src: asset.url, alt: asset.name, loading: 'lazy', width: '96', height: '96' }), h('span', { class: 'cf-asset__name', text: asset.name }))))));
        }

        wrap.append(colourChoice());
        return wrap;
    }

    function colourChoice() {
        const state = machine.getState();
        const a = state.answers;
        const options = [{ value: 'own', label: 'Keep the design’s own colours', hint: 'Each starting design comes with colours chosen by a designer.' }];
        if (kit) options.push({ value: 'kit', label: `Use my brand kit: ${kit.name}`, hint: 'Your brand colours and fonts go into the design. The designs below show the result.', swatches: kit.colors.map((c) => c.hex) });
        const pal = ui.palette && ui.palette.state === 'ready' && a.logo_asset_id !== null ? ui.palette : null;
        if (pal) options.push({ value: 'logo', label: 'Use my logo colours', hint: 'Saved as a brand kit named after your business. Colours come from your logo and text switches to Inter, which you can change in your brand kit. The designs below show the result.', swatches: pal.colors.slice(0, 5).map((c) => c.hex) });
        if (options.length < 2 && !(ui.palette && ui.palette.state === 'loading' && a.logo_asset_id !== null)) return h('div', { 'data-cf-colours': true });
        const box = h('fieldset', { class: 'cf-colours', 'data-cf-colours': true }, h('legend', { class: 'lt-label', text: 'Colours' }));
        if (ui.palette && ui.palette.state === 'loading' && a.logo_asset_id !== null) box.append(h('p', { class: 'lt-small', role: 'status', text: 'Reading the colours in your picture...' }));
        if (ui.palette && ui.palette.state === 'none' && a.logo_asset_id !== null) box.append(h('p', { class: 'lt-small', role: 'status', text: ui.palette.message }));
        for (const o of options) {
            const id = `colours-${o.value}`;
            const input = h('input', { type: 'radio', name: 'colours', id, value: o.value, checked: a.colours === o.value, onchange: () => { machine.setAnswer('colours', o.value); } });
            box.append(h('label', { class: 'cf-choice', for: id }, input, h('span', { class: 'cf-choice__text' }, h('span', { class: 'cf-choice__label', text: o.label }), h('span', { class: 'lt-hint', text: o.hint })),
                o.swatches ? h('span', { class: 'cf-swatches', 'aria-hidden': 'true' }, o.swatches.map((hex) => h('span', { class: 'cf-swatch', style: { '--c': hex } }))) : null));
        }
        return box;
    }

    function refreshLogo() {
        const host = document.querySelector('[data-cf-logo]');
        if (!host || machine.current().key !== 'logo') return;
        const focused = document.activeElement && document.activeElement.id;
        const next = logoBody();
        host.replaceWith(next);
        if (focused) { const again = document.getElementById(focused); if (again) again.focus(); }
    }

    // style --------------------------------------------------------------------------------------------------------
    const styleCache = new Map();
    async function styleTemplate(style) {
        if (styleCache.has(style)) return styleCache.get(style);
        const purpose = boot.goal.purpose;
        const tryQuery = async (extra) => {
            const res = await api.get(`${boot.urls.templates}?${new URLSearchParams({ style, per_page: '1', sort: 'popular', include: 'first_page', ...extra })}`);
            return res.ok && res.data && res.data.data && res.data.data[0] ? res.data.data[0] : null;
        };
        const p = (async () => (purpose ? await tryQuery({ purpose }) : null) || await tryQuery({}))();
        styleCache.set(style, p);
        return p;
    }

    function stepStyle(step) {
        const a = machine.getState().answers;
        const grid = h('div', { class: 'cf-styles', role: 'radiogroup', 'aria-label': 'Look' });
        for (const s of boot.styles) {
            const stage = frame(3, 4, 'cf-style__frame');
            const btn = h('button', {
                type: 'button', role: 'radio', 'aria-checked': a.style === s.key ? 'true' : 'false', class: 'cf-style', dataset: { style: s.key },
                onclick: () => { machine.setAnswer('style', s.key); for (const b of grid.children) b.setAttribute('aria-checked', b.dataset.style === s.key ? 'true' : 'false'); },
            }, h('span', { class: 'cf-style__stage', 'aria-hidden': 'true' }, stage), h('span', { class: 'cf-style__label', text: s.label }), h('span', { class: 'cf-style__help', text: s.help }), h('span', { class: 'cf-style__eg', text: `Good for: ${s.example}` }));
            grid.append(btn);
            whenVisible(btn, async () => {
                const t = await styleTemplate(s.key);
                if (!t || !t.preview) return;
                try { await drawInto(stage, await getPreviewer(), queue, { ...t, pages: [t.preview] }, 0); } catch (e) { /* the neutral frame stays */ }
            });
        }
        return grid;
    }

    // where --------------------------------------------------------------------------------------------------------
    function stepWhere(step) {
        const a = machine.getState().answers;
        const wrap = h('div', { class: 'cf-where' });
        const group = h('div', { class: 'cf-sizes', role: 'radiogroup', 'aria-label': 'Where you will use it' });
        const options = step.options || [];
        const inList = options.some((o) => o.key === a.format_key);
        function select(key) {
            machine.setAnswer('format_key', key);
            for (const b of group.children) b.setAttribute('aria-checked', b.dataset.format === key ? 'true' : 'false');
            const sel = wrap.querySelector('select');
            if (sel && !options.some((o) => o.key === key)) sel.value = key; else if (sel) sel.value = '';
        }
        const px = (key) => {
            for (const g of boot.groups) for (const f of g.formats) if (f.key === key) return f;
            return null;
        };
        for (const o of options) {
            const f = px(o.key);
            group.append(h('button', { type: 'button', role: 'radio', class: 'cf-size', 'aria-checked': a.format_key === o.key ? 'true' : 'false', dataset: { format: o.key }, onclick: () => select(o.key) },
                h('span', { class: 'cf-size__stage', 'aria-hidden': 'true' }, h('span', { class: 'cf-size__frame', style: { '--w': String(f ? f.width : 1), '--h': String(f ? f.height : 1) } })),
                h('span', { class: 'cf-size__label', text: o.label }), h('span', { class: 'cf-size__use', text: o.use }), h('span', { class: 'cf-size__dim', text: o.size_label })));
        }
        wrap.append(group);
        const sel = h('select', { id: 'q-where-all', class: 'lt-select', onchange: (e) => { if (e.target.value) select(e.target.value); } },
            h('option', { value: '', text: 'Pick a different size' }),
            boot.groups.map((g) => h('optgroup', { label: g.label }, g.formats.filter((f) => !options.some((o) => o.key === f.key)).map((f) => h('option', { value: f.key, text: `${f.label}, ${f.size_label}`, selected: a.format_key === f.key && !inList })))));
        wrap.append(h('div', { class: 'lt-field cf-where__all' }, h('label', { class: 'lt-label', for: 'q-where-all', text: 'Not on the list?' }), sel));
        if (options[0]) wrap.append(h('p', { class: 'lt-hint', text: `If you skip this, we use ${options[0].label}, ${options[0].size_label}.` }));
        return wrap;
    }

    // ---- the step -----------------------------------------------------------------------------------------------
    function renderStep() {
        const state = machine.getState();
        const step = machine.current();
        const isChoose = step.key === 'choose';
        strip.hidden = isChoose;
        root.classList.toggle('is-choose', isChoose);
        const title = h('h2', { class: 'cf-step__title', id: 'step-title', tabindex: '-1', text: step.title });
        let body;
        if (step.key === 'what') body = stepWhat(step);
        else if (step.key === 'business_name') body = stepName(step);
        else if (step.key === 'details') body = stepDetails(step);
        else if (step.key === 'logo') { body = logoBody(); loadAssets(); }
        else if (step.key === 'style') body = stepStyle(step);
        else if (step.key === 'where') body = stepWhere(step);
        else body = chooseBody();

        const form = h('form', { class: 'cf-step', 'aria-labelledby': 'step-title', novalidate: true, onsubmit: (e) => { e.preventDefault(); if (!isChoose) advance(); } },
            h('div', { class: 'cf-step__head' }, title, step.essential ? h('span', { class: 'lt-tag lt-tag--accent', text: 'Needed' }) : (isChoose ? null : h('span', { class: 'lt-tag', text: 'Optional' }))),
            step.help ? h('p', { class: 'cf-step__help', id: 'step-help', text: step.help }) : null,
            h('div', { class: 'cf-step__body' }, body),
            isChoose ? null : h('div', { class: 'cf-step__actions' },
                state.index > 0 ? h('button', { type: 'button', class: 'lt-btn lt-btn--ghost', 'data-cf-back': true, onclick: () => { machine.back(); renderStep(); } }, icon('arrow-left'), 'Back') : h('span'),
                h('span', { class: 'cf-step__spacer' }),
                !step.essential ? h('button', { type: 'button', class: 'lt-btn lt-btn--secondary', 'data-cf-skip': true, onclick: () => { machine.skip(); renderStep(); } }, 'Skip, I will add this later') : null,
                h('button', { type: 'submit', class: 'lt-btn', 'data-cf-next': true }, machine.steps[state.index + 1] && machine.steps[state.index + 1].key === 'choose' ? 'See starting points' : 'Next', icon('arrow-right'))));

        const progress = h('div', { class: 'cf-progress' },
            h('p', { class: 'cf-progress__text', 'aria-live': 'polite', text: isChoose ? 'Last step' : `Question ${state.index + 1} of ${machine.total()}` }),
            stepNav());

        clear(main).append(progress, form);
        if (isChoose) { form.append(h('div', { class: 'cf-step__actions cf-step__actions--choose' }, h('button', { type: 'button', class: 'lt-btn lt-btn--ghost', onclick: () => { machine.back(); renderStep(); } }, icon('arrow-left'), 'Change my answers'))); loadChoices(); }

        // focus: the first field of a text step, otherwise the question itself
        const first = form.querySelector('.cf-step__body input[type="text"], .cf-step__body input[type="tel"], .cf-step__body input[type="email"], .cf-step__body textarea');
        const target = (first && ['what', 'business_name', 'details'].includes(step.key)) ? first : title;
        target.focus({ preventScroll: false });
        if (step.key === 'details' || step.key === 'what' || step.key === 'business_name') paintErrors();
    }

    function advance() {
        const r = machine.next();
        if (!r.ok) { paintErrors(true); return; }
        renderStep();
    }

    function paintErrors(focus = false) {
        const errors = machine.getState().errors;
        let firstBad = null;
        for (const el of document.querySelectorAll('[data-error-for]')) {
            const message = errors[el.dataset.errorFor] || '';
            el.textContent = message;
            el.hidden = !message;
            const input = document.getElementById(el.id.replace(/-error$/, ''));
            const idKey = el.dataset.errorFor;
            const fieldId = idKey.startsWith('details.') ? `q-details-${idKey.slice(8)}` : `q-${idKey}`;
            const field = document.getElementById(fieldId) || input;
            if (field) { if (message) { field.setAttribute('aria-invalid', 'true'); firstBad ||= field; } else field.removeAttribute('aria-invalid'); }
        }
        if (focus && firstBad) firstBad.focus();
    }

    machine.subscribe(() => {
        paintErrors();
        const step = machine.current();
        if (step.key === 'logo') {
            const a = machine.getState().answers;
            if (a.logo_asset_id !== null) loadPalette(a.logo_asset_id); else { ui.palette = null; ui.paletteFor = null; }
        }
        refreshStrip();
        paintNav();
    });

    function paintNav() {
        const nav = main.querySelector('.cf-steps');
        if (nav) nav.replaceWith(stepNav());
    }

    // ---- the starting-points strip -----------------------------------------------------------------------------
    const recommend = debounce(async () => {
        const seq = (recommend.seq = (recommend.seq || 0) + 1);
        if (recommend.abort) recommend.abort.abort();
        recommend.abort = new AbortController();
        ui.strip.state = ui.strip.templates.length ? 'refreshing' : 'loading';
        paintStrip();
        const res = await api.post(boot.urls.recommend, { brief: briefFor(true) }, { signal: recommend.abort.signal });
        if (seq !== recommend.seq || res.aborted) return;
        if (res.ok && res.data && Array.isArray(res.data.templates)) {
            ui.strip = { state: 'ready', templates: res.data.templates.slice(0, 4), note: '' };
        } else if (res.status === 422) {
            ui.strip = { ...ui.strip, state: ui.strip.templates.length ? 'ready' : 'idle', note: 'Fix the highlighted answer and the suggestions update.' };
        } else {
            ui.strip = { ...ui.strip, state: ui.strip.templates.length ? 'ready' : 'error', note: res.status === 429 ? 'Suggestions are paused for a moment. Your answers are kept.' : res.message };
        }
        paintStrip();
    }, 600);

    function refreshStrip() { if (machine.current().key !== 'choose') recommend(); }

    function paintStrip() {
        clear(strip);
        strip.append(h('h2', { class: 'cf-strip__title', id: 'strip-title', text: 'Starting points for you' }),
            h('p', { class: 'cf-strip__sub lt-small', text: 'These update as you answer.' }));
        const s = ui.strip;
        if (s.state === 'loading' || s.state === 'idle') {
            strip.append(stripSkeleton());
        } else if (s.state === 'error') {
            strip.append(h('div', { class: 'lt-status lt-status--bad', role: 'status' }, icon('alert'), h('p', { class: 'lt-status__text', text: s.note || 'We could not load suggestions.' }), h('div', { class: 'lt-status__action' }, h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => recommend.flush() }, 'Try again'))));
        } else if (!s.templates.length) {
            strip.append(h('p', { class: 'lt-small cf-quiet', text: 'Nothing fits those answers yet. Change an answer, or pick a blank page at the end.' }));
        } else {
            const list = h('ul', { class: `cf-strip__list${s.state === 'refreshing' ? ' is-refreshing' : ''}`, role: 'list', 'aria-busy': s.state === 'refreshing' ? 'true' : 'false' });
            for (const t of s.templates) {
                const f = frame(t.width, t.height, 'cf-mini__frame');
                list.append(h('li', { class: 'cf-mini' }, h('span', { class: 'cf-mini__stage', 'aria-hidden': 'true' }, f),
                    h('span', { class: 'cf-mini__text' }, h('span', { class: 'cf-mini__name', text: t.name }), h('span', { class: 'cf-mini__reason', text: t.reason || '' }), t.page_count > 1 ? h('span', { class: 'lt-meta', text: `${t.page_count} pages` }) : null)));
                whenVisible(f, async () => {
                    const full = await fetchTemplate(t.id);
                    if (!full) return;
                    try { await drawInto(f, await getPreviewer(), queue, full, 0); } catch (e) { /* neutral frame stays */ }
                });
            }
            strip.append(list);
        }
        if (s.note && s.state !== 'error') strip.append(h('p', { class: 'lt-meta', role: 'status', text: s.note }));
    }

    function stripSkeleton() {
        return h('ul', { class: 'cf-strip__list', role: 'list', 'aria-busy': 'true' }, [1, 2, 3].map(() => h('li', { class: 'cf-mini' }, h('span', { class: 'cf-mini__stage' }, h('span', { class: 'lt-skeleton cf-mini__sk' })), h('span', { class: 'cf-mini__text' }, h('span', { class: 'lt-skeleton cf-line' }), h('span', { class: 'lt-skeleton cf-line cf-line--short' })))));
    }

    // ---- the last step: choose a starting point ----------------------------------------------------------------
    let chooseSeq = 0;
    function chooseBody() {
        const wrap = h('div', { class: 'cf-choose', 'data-cf-choose': true });
        return wrap;
    }

    async function loadChoices() {
        const seq = (chooseSeq += 1);
        const host = main.querySelector('[data-cf-choose]');
        const status = h('div', { class: 'cf-create-status', 'data-cf-create-status': true, 'aria-live': 'polite' });
        host.append(status);
        host.append(h('div', { class: 'cf-cards', 'aria-busy': 'true' }, [1, 2, 3].map(() => h('div', { class: 'cf-pcard' }, h('span', { class: 'cf-pcard__stage' }, h('span', { class: 'lt-skeleton cf-pcard__sk' })), h('span', { class: 'lt-skeleton cf-line' })))));
        // the previews show the person's real logo and colours, so those are made ready first
        const a = machine.getState().answers;
        if (a.logo_asset_id !== null && !(ui.assets || []).some((x) => x.id === a.logo_asset_id)) await loadAssets(true);
        const made = await ensureLogoKit();
        if (seq !== chooseSeq) return;
        if (!made.ok) status.append(h('div', { class: 'lt-status lt-status--warn', role: 'status' }, icon('alert'), h('p', { class: 'lt-status__text', text: made.message })));
        const brief = briefFor(true);
        const res = await api.post(boot.urls.recommend, { brief });
        if (seq !== chooseSeq) return;
        host.querySelector('.cf-cards').remove();
        if (!res.ok) {
            host.append(h('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, icon('alert'), h('div', {}, h('strong', { text: 'We could not load starting points.' }), h('p', { text: res.message })), h('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: () => { host.replaceChildren(); loadChoices(); } }, 'Try again')),
                blankCard(res.data && res.data.formats ? res.data.formats : []));
            return;
        }
        const templates = res.data.templates.slice(0, 6);
        const grid = h('div', { class: 'cf-cards' });
        for (const t of templates) grid.append(templateCard(t, brief));
        grid.append(blankCard(res.data.formats || []));
        host.append(grid);
        if (!templates.length) host.insertBefore(h('p', { class: 'lt-small cf-quiet', text: 'No ready-made design fits these answers. A blank page is a good place to start.' }), grid);
    }

    function flatValues(brief) {
        const v = {};
        if (brief.business_name) v.business_name = brief.business_name;
        if (brief.what) v.what = brief.what;
        for (const [k, val] of Object.entries(brief.details || {})) v[k] = val;
        v.__logo = String(brief.logo_asset_id || '');
        v.__apply = String(brief.apply_brand);
        return v;
    }

    function templateCard(t, brief) {
        const f = frame(t.width, t.height, 'cf-pcard__frame');
        const busyAttr = ui.createState.busy;
        const button = h('button', { type: 'button', class: 'lt-btn lt-btn--secondary', 'data-cf-use': t.slug, disabled: busyAttr, onclick: () => create(t.slug) }, 'Use this design');
        const card = h('div', { class: 'cf-pcard' },
            h('span', { class: 'cf-pcard__stage', 'aria-hidden': 'true' }, f),
            h('div', { class: 'cf-pcard__body' }, h('h3', { class: 'cf-pcard__name', text: t.name }),
                h('p', { class: 'cf-pcard__reason', text: t.reason || '' }),
                h('p', { class: 'lt-meta', text: [t.format_label || null, t.page_count > 1 ? `${t.page_count} pages` : null].filter(Boolean).join(', ') }),
                button));
        whenVisible(f, async () => {
            const full = await fetchTemplate(t.id);
            if (!full) { f.classList.add('is-failed'); return; }
            const filled = await api.post(`${boot.urls.template_base}/${t.id}/fill`, { brief: { ...brief, skipped: undefined } });
            try {
                const p = await getPreviewer();
                p.registerAssets(ui.assets);
                const fill = filled.ok && filled.data && Array.isArray(filled.data.pages) ? { pages: filled.data.pages, values: flatValues(brief) } : null;
                await drawInto(f, p, queue, full, 0, { fill });
            } catch (e) { f.classList.add('is-failed'); }
        }, { rootMargin: '400px' });
        return card;
    }

    function blankCard(formats) {
        const a = machine.getState().answers;
        const key = a.format_key || (formats[0] && formats[0].key) || (machine.steps.find((s) => s.key === 'where').options[0] || {}).key;
        let f = null;
        for (const g of boot.groups) for (const x of g.formats) if (x.key === key) f = x;
        f ||= { width: 3, height: 4, label: 'Blank page', size_label: '' };
        return h('div', { class: 'cf-pcard cf-pcard--blank' },
            h('span', { class: 'cf-pcard__stage', 'aria-hidden': 'true' }, frame(f.width, f.height, 'cf-pcard__frame')),
            h('div', { class: 'cf-pcard__body' }, h('h3', { class: 'cf-pcard__name', text: 'Start with a blank page' }),
                h('p', { class: 'cf-pcard__reason', text: 'Your answers are kept, and you add everything yourself.' }),
                h('p', { class: 'lt-meta', text: `${f.label}${f.size_label ? `, ${f.size_label}` : ''}` }),
                h('button', { type: 'button', class: 'lt-btn lt-btn--secondary', 'data-cf-blank': true, disabled: ui.createState.busy, onclick: () => create(null) }, 'Start blank')));
    }

    function setCreate(patch) {
        ui.createState = { ...ui.createState, ...patch };
        const status = document.querySelector('[data-cf-create-status]');
        if (status) {
            clear(status);
            if (ui.createState.message) status.append(h('div', { class: `lt-status lt-status--${ui.createState.kind === 'neutral' ? 'neutral' : ui.createState.kind}`, role: ui.createState.kind === 'bad' ? 'alert' : 'status' }, icon(ui.createState.kind === 'bad' ? 'alert' : 'info'), h('p', { class: 'lt-status__text', text: ui.createState.message })));
        }
        for (const b of document.querySelectorAll('[data-cf-use], [data-cf-blank]')) { b.disabled = ui.createState.busy; if (ui.createState.busy && b.dataset.cfPressed === '1') b.setAttribute('aria-busy', 'true'); else b.removeAttribute('aria-busy'); }
    }

    async function ensureLogoKit() {
        const a = machine.getState().answers;
        if (a.colours !== 'logo') return { ok: true };
        if (ui.logoKit && ui.logoKit.logo === a.logo_asset_id) return { ok: true };
        ui.logoKit = null;
        if (!(ui.palette && ui.palette.state === 'ready' && ui.paletteFor === a.logo_asset_id)) { ui.paletteFor = null; await loadPalette(a.logo_asset_id); }
        const ready = ui.palette && ui.palette.state === 'ready' ? ui.palette : null;
        if (!ready) return { ok: false, message: 'We could not read colours from your logo, so these designs keep their own colours. Go back to change that.' };
        const roles = ready.roles || {};
        const colors = [];
        const seen = new Set();
        for (const role of ['primary', 'secondary', 'accent', 'neutral']) {
            const hex = roles[role];
            if (hex && !seen.has(hex.toLowerCase())) { seen.add(hex.toLowerCase()); colors.push({ hex, role }); }
        }
        const name = (machine.getState().answers.business_name || 'My business').slice(0, 60);
        const res = await api.post(boot.urls.kits, { name: `${name} logo colours`, colors: colors.map((c) => ({ hex: c.hex, role: c.role })) });
        if (!res.ok) return { ok: false, message: `We could not save your logo colours. ${res.message}` };
        ui.logoKit = { id: res.data.id, logo: machine.getState().answers.logo_asset_id };
        storage.set(kitKey, JSON.stringify(ui.logoKit));
        return { ok: true };
    }

    async function create(slug) {
        if (ui.createState.busy) return;
        const pressed = document.querySelector(slug ? `[data-cf-use="${CSS.escape(slug)}"]` : '[data-cf-blank]');
        if (pressed) pressed.dataset.cfPressed = '1';
        setCreate({ busy: true, message: 'Making your design...', kind: 'neutral' });
        const made = await ensureLogoKit();
        if (!made.ok) { if (pressed) pressed.dataset.cfPressed = ''; setCreate({ busy: false, message: made.message, kind: 'bad' }); return; }
        const res = await api.post(boot.urls.from_brief, { brief: briefFor(false), template: slug });
        if (res.ok && res.data && res.data.project) {
            machine.forget();
            setCreate({ busy: true, message: 'Done. Opening your design...', kind: 'ok' });
            window.location.assign(res.data.project.url);
            return;
        }
        if (pressed) pressed.dataset.cfPressed = '';
        if (res.status === 422 && res.errors && Object.keys(res.errors).length) {
            setCreate({ busy: false, message: '', kind: 'neutral' });
            if (res.errors.template && Object.keys(res.errors).length === 1) { setCreate({ busy: false, message: res.errors.template, kind: 'bad' }); return; }
            machine.applyServerErrors(res.errors);
            renderStep();
            paintErrors(true);
            return;
        }
        setCreate({ busy: false, message: `We could not make your design. ${res.message}`, kind: 'bad' });
    }

    // ---- go ----------------------------------------------------------------------------------------------------
    renderStep();
    paintStrip();
    recommend.flush();
}

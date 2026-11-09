// The Start screen: preview of the best starting design on each goal card, the size picker (search and custom size),
// and the "describe your idea" box when the assistant is switched on.
import '../../../css/pages/start.css';
import { createApi } from './lib/api.js';
import { composePrompt, interpretCompose, MIN_PROMPT } from './lib/compose.js';
import { clear, h, readBoot } from './lib/dom.js';
import { createPreviewer, createRenderQueue } from './lib/preview.js';
import { drawInto, loadIcons, whenVisible } from './lib/preview-ui.js';
import { customName, formatNumber, fromPx, parseSize, UNITS, validateSize } from './lib/units.js';

const boot = readBoot('start-boot');
const api = createApi();

// ---- busy buttons: a submit that creates a project must not be pressed twice -------------------------------------
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.cfNoBusy !== undefined) return;
    if (form.dataset.cfSubmitted === '1') { e.preventDefault(); return; }
    form.dataset.cfSubmitted = '1';
    const button = e.submitter || form.querySelector('[type="submit"]');
    if (button) button.setAttribute('aria-busy', 'true');
    // a back-forward restore keeps the page alive: let the form work again
    window.addEventListener('pageshow', () => { form.dataset.cfSubmitted = ''; if (button) button.removeAttribute('aria-busy'); }, { once: true });
});

// ---- size picker: search ----------------------------------------------------------------------------------------
function initSearch() {
    const input = document.querySelector('[data-cf-size-search]');
    const list = document.querySelector('[data-cf-size-list]');
    if (!input || !list) return;
    const none = list.querySelector('[data-cf-size-none]');
    const rows = Array.from(list.querySelectorAll('[data-format]'));
    const groups = Array.from(list.querySelectorAll('[data-group]'));
    input.addEventListener('input', () => {
        const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;
        for (const row of rows) {
            const hay = row.dataset.search || '';
            const hit = terms.every((t) => hay.includes(t));
            row.hidden = !hit;
            if (hit) shown += 1;
        }
        for (const g of groups) g.hidden = !g.querySelector('[data-format]:not([hidden])');
        if (none) none.hidden = shown !== 0;
    });
    // Enter in the search box opens the single remaining match, so a pro can type "a4" and press Enter.
    input.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const visible = rows.filter((r) => !r.hidden);
        if (visible.length === 1) { e.preventDefault(); visible[0].querySelector('button').click(); }
    });
}

// ---- size picker: custom size -----------------------------------------------------------------------------------
function initCustom() {
    const form = document.querySelector('[data-cf-custom]');
    if (!form) return;
    const w = form.querySelector('[data-cf-w]');
    const hh = form.querySelector('[data-cf-h]');
    const unit = form.querySelector('[data-cf-unit]');
    const readout = form.querySelector('[data-cf-readout]');
    const frame = form.querySelector('[data-cf-frame]');
    const submit = form.querySelector('[data-cf-custom-submit]');
    let lastUnit = unit.value;

    function update() {
        const result = validateSize({ width: w.value, height: hh.value, unit: unit.value }, boot.limits);
        w.setAttribute('aria-invalid', result.errors.width ? 'true' : 'false');
        hh.setAttribute('aria-invalid', result.errors.height ? 'true' : 'false');
        readout.classList.toggle('is-bad', !result.ok);
        if (result.ok) {
            const { width, height } = result.px;
            readout.textContent = `${width} x ${height} px at 72 dpi.${result.notes.length ? ` ${result.notes.join(' ')}` : ''}`;
            frame.style.setProperty('--w', String(width));
            frame.style.setProperty('--h', String(height));
            form.querySelector('[data-cf-px-w]').value = String(width);
            form.querySelector('[data-cf-px-h]').value = String(height);
            form.querySelector('[data-cf-name]').value = customName(w.value, hh.value, unit.value);
        } else {
            readout.textContent = [result.errors.width, result.errors.height, result.errors.unit].filter(Boolean).join(' ');
        }
        submit.disabled = !result.ok;
        return result;
    }

    // Changing the unit keeps the same physical size: 210 mm becomes 21 cm, not 210 cm.
    unit.addEventListener('change', () => {
        const from = lastUnit;
        const to = unit.value;
        for (const input of [w, hh]) {
            const n = parseSize(input.value);
            if (Number.isFinite(n) && UNITS.includes(from) && UNITS.includes(to)) {
                const px = from === 'px' ? n : n * (from === 'mm' ? 72 / 25.4 : from === 'cm' ? 720 / 25.4 : 72);
                input.value = formatNumber(fromPx(px, to));
            }
        }
        lastUnit = to;
        update();
    });
    for (const input of [w, hh]) input.addEventListener('input', update);
    form.querySelector('[data-cf-swap]').addEventListener('click', () => { const t = w.value; w.value = hh.value; hh.value = t; update(); });
    form.addEventListener('submit', (e) => { if (!update().ok) { e.preventDefault(); e.stopImmediatePropagation(); (w.getAttribute('aria-invalid') === 'true' ? w : hh).focus(); } }, true);

    // A custom preset from a link (`?format=logo`) fills the fields.
    const preset = boot.preset_custom;
    if (preset) { w.value = String(preset.width); hh.value = String(preset.height); unit.value = preset.unit; lastUnit = preset.unit; }
    update();
}

// ---- goal cards: the most popular starting design for each goal ------------------------------------------------
async function initGoalPreviews() {
    const cards = Array.from(document.querySelectorAll('.cf-goal[data-purpose]')).filter((c) => c.dataset.purpose);
    if (!cards.length) return;
    let previewer = null;
    const queue = createRenderQueue();
    for (const card of cards) {
        const frame = card.querySelector('.cf-goal__frame');
        whenVisible(card, async () => {
            const res = await api.get(`${boot.urls.templates}?${new URLSearchParams({ purpose: card.dataset.purpose, per_page: '1', sort: 'popular', include: 'first_page' })}`);
            const t = res.ok && res.data && res.data.data && res.data.data[0];
            if (!t || !t.preview) return; // nothing to show: the neutral frame stays, which is honest
            try {
                if (!previewer) previewer = createPreviewer({ manifest: boot.fonts || [], icons: await loadIcons() });
                await drawInto(frame, previewer, queue, { ...t, pages: [t.preview] }, 0);
            } catch (e) { /* the neutral frame stays */ }
        });
    }
}

// ---- the assistant box -----------------------------------------------------------------------------------------
function initDescribe() {
    const root = document.querySelector('[data-cf-describe]');
    if (!root || !boot.assistant) return;
    root.hidden = false;
    const form = root.querySelector('form');
    const prompt = root.querySelector('textarea');
    const out = root.querySelector('[data-cf-describe-out]');
    const button = form.querySelector('[type="submit"]');
    let pending = null;
    let asked = [];

    function say(kind, text) {
        clear(out);
        if (!text) return;
        out.append(h('div', { class: `lt-status lt-status--${kind}`, role: kind === 'bad' ? 'alert' : 'status' }, h('p', { class: 'lt-status__text', text })));
    }

    function questions(list) {
        clear(out);
        const box = h('form', { class: 'cf-describe__questions', novalidate: true });
        box.append(h('p', { class: 'lt-label', text: 'A few quick questions so the first layout fits.' }));
        for (const q of list) {
            const id = `dq-${q.id}`;
            box.append(h('div', { class: 'lt-field' }, h('label', { class: 'lt-label', for: id, text: q.text }), h('input', { id, class: 'lt-input', type: 'text', maxlength: '200', dataset: { qid: q.id, qtext: q.text } })));
        }
        box.append(h('div', { class: 'lt-form__actions' }, h('button', { type: 'submit', class: 'lt-btn', text: 'Continue' })));
        box.addEventListener('submit', (e) => {
            e.preventDefault();
            asked = Array.from(box.querySelectorAll('input')).filter((i) => i.value.trim()).map((i) => ({ text: i.dataset.qtext, value: i.value }));
            send();
        });
        out.append(box);
        box.querySelector('input').focus();
    }

    async function send() {
        if (pending) return;
        const text = prompt.value.trim();
        if (text.length < MIN_PROMPT) { say('warn', 'Write a few words about what you want to make.'); prompt.focus(); return; }
        pending = new AbortController();
        const timer = setTimeout(() => pending && pending.abort(), 90000);
        button.setAttribute('aria-busy', 'true');
        button.disabled = true;
        say('neutral', 'Making a first layout. This can take up to a minute.');
        const res = await api.post(boot.urls.compose, { prompt: composePrompt(text, asked) }, { signal: pending.signal });
        clearTimeout(timer);
        pending = null;
        button.removeAttribute('aria-busy');
        button.disabled = false;
        const outcome = interpretCompose(res);
        if (outcome.kind === 'open') { say('ok', 'Done. Opening your design.'); window.location.assign(outcome.url); return; }
        if (outcome.kind === 'questions') { questions(outcome.questions); return; }
        if (outcome.kind === 'aborted') { say('bad', 'That took too long. Try a shorter description, or pick a goal below.'); return; }
        if (outcome.kind === 'unavailable') { say('warn', `${outcome.message} You can still pick a goal below.`); return; }
        say('bad', `${outcome.message} You can also pick a goal below.`);
    }

    form.addEventListener('submit', (e) => { e.preventDefault(); asked = []; send(); });
}

if (boot) {
    initSearch();
    initCustom();
    initGoalPreviews();
    initDescribe();
}

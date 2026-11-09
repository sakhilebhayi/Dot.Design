// The guided brief as a state machine: which question is showing, what has been answered, what was skipped, what is wrong.
// No DOM in here. The page script renders whatever this says and calls these methods; persistence goes through a storage
// object (sessionStorage in the browser) that is allowed to fail.
import { checkDetail, checkText, cleanText } from './brief-validate.js';

export const MESSAGES = {
    business_name: 'Add your business name. We put it on your design for you.',
};

const SCHEMA_VERSION = 1;
const CONTACT_KEYS = ['phone', 'whatsapp', 'email', 'website', 'address', 'social_handle'];
const ANSWER_KEYS = ['what', 'business_name', 'logo_asset_id', 'style', 'format_key', 'colours'];

function blankAnswers() {
    return { what: '', business_name: '', details: {}, logo_asset_id: null, style: null, format_key: null, colours: 'own' };
}

/**
 * @param {object} opts
 * @param {Array} opts.steps          flow steps (without the goal step) as the server sends them
 * @param {string} opts.goal          goal key
 * @param {object} [opts.previous]    last brief the person wrote ({business_name, details}) used to start the name filled in
 * @param {string|null} [opts.presetFormat]  a format chosen before the wizard
 * @param {object|null} [opts.storage]  {get,set,remove}; may fail silently
 * @param {string} [opts.storageKey]
 */
export function createBriefMachine({ steps, goal, previous = {}, presetFormat = null, storage = null, storageKey = null } = {}) {
    const list = [...steps.filter((s) => s.key !== 'goal'), { key: 'choose', essential: false, skippable: false, title: 'Choose a starting point', help: 'Pick a design to start from, or begin with a blank page.' }];
    const key = storageKey || `dot-design-brief:${goal}`;
    const listeners = new Set();
    const fieldSpecs = Object.fromEntries(((list.find((s) => s.key === 'details') || {}).fields || []).map((f) => [f.key, f]));

    let state = {
        index: 0,
        reached: 0,
        answers: blankAnswers(),
        skipped: [],
        errors: {},
        prefilled: [],
    };

    // A size chosen before the wizard, and the last brief's name, are starting values the person can change.
    if (presetFormat) state.answers.format_key = presetFormat;
    if (previous && typeof previous.business_name === 'string' && previous.business_name.trim() !== '') {
        state.answers.business_name = cleanText(previous.business_name);
        state.prefilled = ['business_name'];
    }
    // Contact details rarely change between designs, so they start filled too (and say so).
    if (previous && previous.details && typeof previous.details === 'object') {
        for (const k of CONTACT_KEYS) {
            if (fieldSpecs[k] && typeof previous.details[k] === 'string' && cleanText(previous.details[k]) !== '' && checkDetail(fieldSpecs[k], previous.details[k]) === null) {
                state.answers.details[k] = cleanText(previous.details[k]);
                state.prefilled = [...state.prefilled, `details.${k}`];
            }
        }
    }
    restore();

    function emit() {
        persist();
        for (const fn of [...listeners]) fn(state);
    }

    function set(patch) {
        state = { ...state, ...patch };
        emit();
    }

    function stepIndex(stepKey) {
        return list.findIndex((s) => s.key === stepKey);
    }

    function hasAnswer(stepKey) {
        const a = state.answers;
        switch (stepKey) {
            case 'what': return cleanText(a.what) !== '';
            case 'business_name': return cleanText(a.business_name) !== '';
            case 'details': return Object.values(a.details).some((v) => cleanText(v, true) !== '');
            case 'logo': return a.logo_asset_id !== null;
            case 'style': return a.style !== null;
            case 'where': return a.format_key !== null;
            default: return false;
        }
    }

    function clearStep(stepKey) {
        const a = { ...state.answers };
        if (stepKey === 'what') a.what = '';
        if (stepKey === 'business_name') a.business_name = '';
        if (stepKey === 'details') a.details = {};
        if (stepKey === 'logo') { a.logo_asset_id = null; if (a.colours === 'logo') a.colours = 'own'; }
        if (stepKey === 'style') a.style = null;
        if (stepKey === 'where') a.format_key = presetFormat || null;
        return a;
    }

    /** Errors for one step, keyed like the server's (details.phone). Empty object when the step is fine. */
    function validateStep(stepKey) {
        const errors = {};
        const a = state.answers;
        if (stepKey === 'business_name') {
            if (cleanText(a.business_name) === '') errors.business_name = MESSAGES.business_name;
            else {
                const bad = checkText(a.business_name, { max: 60, label: 'Your business name' });
                if (bad) errors.business_name = bad;
            }
        }
        if (stepKey === 'what') {
            const bad = checkText(a.what, { max: 120, label: 'This answer' });
            if (bad) errors.what = bad;
        }
        if (stepKey === 'details') {
            for (const [k, v] of Object.entries(a.details)) {
                const spec = fieldSpecs[k];
                if (!spec) continue;
                const bad = checkDetail(spec, v);
                if (bad) errors[`details.${k}`] = bad;
            }
        }
        return errors;
    }

    const api = {
        steps: list,
        goal,
        getState: () => state,
        subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
        current: () => list[state.index],
        total: () => list.length,
        isLast: () => state.index === list.length - 1,
        stepStatus(stepKey) {
            if (state.skipped.includes(stepKey)) return 'skipped';
            return hasAnswer(stepKey) ? 'answered' : 'todo';
        },

        setAnswer(field, value) {
            if (!ANSWER_KEYS.includes(field)) throw new Error(`Unknown answer ${field}`);
            const answers = { ...state.answers, [field]: value };
            const errors = { ...state.errors };
            delete errors[field];
            const owner = field === 'logo_asset_id' ? 'logo' : field === 'format_key' ? 'where' : field === 'colours' ? null : field;
            set({
                answers,
                errors,
                skipped: owner ? state.skipped.filter((k) => k !== owner) : state.skipped,
                prefilled: field === 'business_name' ? state.prefilled.filter((k) => k !== 'business_name') : state.prefilled,
            });
        },

        setDetail(field, value) {
            if (!fieldSpecs[field]) throw new Error(`Unknown detail ${field}`);
            const details = { ...state.answers.details, [field]: value };
            const errors = { ...state.errors };
            delete errors[`details.${field}`];
            set({ answers: { ...state.answers, details }, errors, skipped: state.skipped.filter((k) => k !== 'details'), prefilled: state.prefilled.filter((k) => k !== `details.${field}`) });
        },

        /** Check one detail field now (on blur). */
        checkDetailNow(field) {
            const spec = fieldSpecs[field];
            if (!spec) return null;
            const bad = checkDetail(spec, state.answers.details[field] ?? '');
            const errors = { ...state.errors };
            if (bad) errors[`details.${field}`] = bad; else delete errors[`details.${field}`];
            set({ errors });
            return bad;
        },

        canContinue() {
            const step = list[state.index];
            if (!step) return false;
            if (step.key === 'choose') return false;
            if (step.essential && !hasAnswer(step.key)) return false;
            return Object.keys(validateStep(step.key)).length === 0;
        },

        /** Move forward. A blocked step stays put and carries its errors. Returns {ok, errors}. */
        next() {
            const step = list[state.index];
            const errors = validateStep(step.key);
            if (Object.keys(errors).length) { set({ errors: { ...state.errors, ...errors } }); return { ok: false, errors }; }
            let skipped = state.skipped;
            if (!step.essential && step.key !== 'choose' && !hasAnswer(step.key) && !skipped.includes(step.key)) skipped = [...skipped, step.key];
            const index = Math.min(list.length - 1, state.index + 1);
            set({ index, reached: Math.max(state.reached, index), skipped });
            return { ok: true, errors: {} };
        },

        /** "Skip, I will add this later": only for optional steps. Clears anything typed on the step. */
        skip() {
            const step = list[state.index];
            if (!step || step.essential || step.key === 'choose') return { ok: false };
            const index = Math.min(list.length - 1, state.index + 1);
            const errors = Object.fromEntries(Object.entries(state.errors).filter(([k]) => k !== step.key && !k.startsWith(`${step.key}.`)));
            set({
                answers: clearStep(step.key),
                skipped: state.skipped.includes(step.key) ? state.skipped : [...state.skipped, step.key],
                errors,
                index,
                reached: Math.max(state.reached, index),
            });
            return { ok: true };
        },

        back() {
            if (state.index === 0) return false;
            set({ index: state.index - 1 });
            return true;
        },

        /** Jump to a step already reached (the step list). */
        goTo(stepKey) {
            const i = typeof stepKey === 'number' ? stepKey : stepIndex(stepKey);
            if (i < 0 || i > state.reached) return false;
            set({ index: i });
            return true;
        },

        /** The essential steps are done, so a project can be made. */
        isReady() {
            return list.filter((s) => s.essential).every((s) => hasAnswer(s.key));
        },

        /** The brief the server takes (docs/R45_BUSINESS.md, "The brief object"). Empty answers are left out. */
        toBrief({ kitId = null, lenient = false } = {}) {
            const a = state.answers;
            const brief = { goal };
            // lenient (live suggestions): an answer that does not pass the checks yet is left out instead of failing the request
            const what = cleanText(a.what);
            if (what && !(lenient && checkText(what, { max: 120, label: 'x' }))) brief.what = what;
            const name = cleanText(a.business_name);
            if (name && !(lenient && checkText(name, { max: 60, label: 'x' }))) brief.business_name = name;
            const details = {};
            for (const [k, v] of Object.entries(a.details)) {
                const t = cleanText(v, fieldSpecs[k]?.type === 'multiline');
                if (t && !(lenient && fieldSpecs[k] && checkDetail(fieldSpecs[k], t))) details[k] = t;
            }
            if (Object.keys(details).length) brief.details = details;
            if (a.logo_asset_id !== null) brief.logo_asset_id = a.logo_asset_id;
            if (a.style) brief.style = a.style;
            if (a.format_key) brief.format_key = a.format_key;
            if (kitId !== null) { brief.brand_kit_id = kitId; brief.apply_brand = a.colours !== 'own'; } else brief.apply_brand = false;
            const skipped = state.skipped.filter((k) => k !== 'choose' && !hasAnswer(k));
            if (skipped.length) brief.skipped = skipped;
            return brief;
        },

        /** Server errors (keyed by brief field) put back on the step they belong to. Returns the step key that now shows. */
        applyServerErrors(errors) {
            const owner = (field) => {
                if (field.startsWith('details')) return 'details';
                if (field === 'logo_asset_id' || field.startsWith('photos')) return 'logo';
                if (field === 'format_key') return 'where';
                if (field === 'brand_kit_id') return 'logo';
                return list.some((s) => s.key === field) ? field : null;
            };
            let first = null;
            for (const field of Object.keys(errors)) {
                const o = owner(field);
                if (o !== null && (first === null || stepIndex(o) < stepIndex(first))) first = o;
            }
            set({ errors: { ...state.errors, ...errors }, index: first !== null ? stepIndex(first) : state.index });
            return first;
        },

        clearErrors() { set({ errors: {} }); },

        snapshot() {
            return { v: SCHEMA_VERSION, goal, index: state.index, reached: state.reached, answers: state.answers, skipped: state.skipped, prefilled: state.prefilled };
        },

        reset() {
            state = { index: 0, reached: 0, answers: blankAnswers(), skipped: [], errors: {}, prefilled: [] };
            if (presetFormat) state.answers.format_key = presetFormat;
            if (storage) storage.remove(key);
            for (const fn of [...listeners]) fn(state);
        },

        /** Forget the saved answers (after the project was made). */
        forget() { if (storage) storage.remove(key); },
    };

    function persist() {
        if (!storage) return;
        storage.set(key, JSON.stringify(api.snapshot()));
    }

    function restore() {
        if (!storage) return;
        let raw = null;
        try { raw = JSON.parse(storage.get(key) || 'null'); } catch (e) { raw = null; }
        if (!raw || raw.v !== SCHEMA_VERSION || raw.goal !== goal || typeof raw.answers !== 'object' || raw.answers === null) return;

        const answers = blankAnswers();
        for (const k of ['what', 'business_name']) if (typeof raw.answers[k] === 'string') answers[k] = raw.answers[k];
        if (raw.answers.details && typeof raw.answers.details === 'object') {
            for (const [k, v] of Object.entries(raw.answers.details)) if (fieldSpecs[k] && typeof v === 'string') answers.details[k] = v;
        }
        if (Number.isInteger(raw.answers.logo_asset_id) && raw.answers.logo_asset_id > 0) answers.logo_asset_id = raw.answers.logo_asset_id;
        if (typeof raw.answers.style === 'string') answers.style = raw.answers.style;
        if (typeof raw.answers.format_key === 'string') answers.format_key = raw.answers.format_key;
        if (['own', 'kit', 'logo'].includes(raw.answers.colours)) answers.colours = raw.answers.colours;
        if (presetFormat && !raw.answers.format_key) answers.format_key = presetFormat;

        const known = new Set(list.map((s) => s.key));
        const reached = Math.min(list.length - 1, Math.max(0, Number.isInteger(raw.reached) ? raw.reached : 0));
        state = {
            ...state,
            answers,
            skipped: Array.isArray(raw.skipped) ? raw.skipped.filter((k) => known.has(k)) : [],
            prefilled: Array.isArray(raw.prefilled) ? raw.prefilled.filter((k) => k === 'business_name' || (typeof k === 'string' && k.startsWith('details.') && fieldSpecs[k.slice(8)])) : [],
            reached,
            index: Math.min(reached, Math.max(0, Number.isInteger(raw.index) ? raw.index : 0)),
        };
    }

    return api;
}

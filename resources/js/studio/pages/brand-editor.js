/*
 * Brand kit editor. One page, sections, an explicit Save. Nothing is sent until the person presses Save; leaving with unsaved
 * changes asks first. A save writes the kit, then the logo and picture changes, then reloads the kit from the server so what is on
 * screen is what is stored. Saving a kit never changes a design: designs that used it get a "Brand updated" notice instead.
 */
import { api, append, clear, confirmDialog, el, firstError, fontManager, icon, readBoot, toast } from './brand-core.js';
import { SINGLE_ASSET_ROLES, assetOps, emptyKit, fingerprint, fromServer, isDirty, toPayload } from './brand-kit-state.js';
import { mountImagery, mountContact, mountName, mountVoice } from './brand-ed-basics.js';
import { mountPreview } from './brand-ed-preview.js';
import { mountFonts, mountStyles } from './brand-ed-type.js';
import { mountColors, mountLogo } from './brand-ed-visual.js';

const boot = readBoot('brand-boot');
const root = document.getElementById('brand-editor');
if (!root) throw new Error('The brand editor page is missing its root element.');

fontManager(boot.fonts || []);

const listeners = new Map();
const assetRows = (kit) => (kit && kit.assets ? kit.assets : []).filter((a) => a.asset).map((a) => ({ id: a.id, role: a.role, design_asset_id: a.asset.id, label: a.label || '', asset: a.asset }));

const ctx = {
    boot,
    canEdit: !!boot.canEdit,
    state: fromServer(boot.kit),
    assets: assetRows(boot.kit),
    on(event, fn) { if (!listeners.has(event)) listeners.set(event, []); listeners.get(event).push(fn); },
    emit(event) { for (const fn of listeners.get(event) || []) fn(); },
    change() { refreshBar(); ctx.emit('change'); },
    setSingleAsset(role, asset) {
        ctx.assets = ctx.assets.filter((a) => a.role !== role);
        ctx.assets.push({ id: null, role, design_asset_id: asset.id, label: '', asset });
        ctx.emit('assets'); ctx.change();
    },
    addAsset(role, asset) {
        if (ctx.assets.some((a) => a.role === role && a.design_asset_id === asset.id)) { toast('That picture is already there.', 'warn'); return; }
        ctx.assets.push({ id: null, role, design_asset_id: asset.id, label: '', asset });
        ctx.emit('assets'); ctx.change();
    },
    removeAsset(entry) {
        ctx.assets = ctx.assets.filter((a) => a !== entry);
        ctx.emit('assets'); ctx.change();
    },
};

let kitId = boot.kit ? boot.kit.id : null;
let initialAssets = ctx.assets.map((a) => ({ ...a }));
let savedFp = boot.kit ? fingerprint(ctx.state, ctx.assets) : fingerprint(emptyKit(), []);
let saving = false;
let failed = false;

// ---- save bar --------------------------------------------------------------------------------------------------------

const bar = root.querySelector('[data-savebar]');
const saveBtn = root.querySelector('[data-save]');
const discardBtn = root.querySelector('[data-discard]');
const barText = root.querySelector('[data-save-text]');
const summary = root.querySelector('[data-error-summary]');

function dirty() { return isDirty(savedFp, ctx.state, ctx.assets); }

function refreshBar() {
    const d = dirty();
    const isNew = kitId === null;
    let state = 'clean';
    let text = isNew ? 'Not saved yet' : 'All changes saved';
    if (saving) { state = 'saving'; text = 'Saving'; }
    else if (failed && d) { state = 'failed'; text = 'Not saved. See what needs fixing below.'; }
    else if (d) { state = 'dirty'; text = isNew ? 'Not saved yet. Press Create kit to keep it.' : 'You have unsaved changes'; }
    bar.setAttribute('data-state', state);
    barText.textContent = text;
    if (saveBtn) saveBtn.disabled = saving || (!d && !isNew) || !ctx.canEdit;
    if (saveBtn) { if (saving) saveBtn.setAttribute('aria-busy', 'true'); else saveBtn.removeAttribute('aria-busy'); }
    discardBtn.hidden = !d || saving || !ctx.canEdit;
}

// ---- server errors ---------------------------------------------------------------------------------------------------

function clearErrors() {
    root.querySelectorAll('[data-err-for]').forEach((n) => { n.hidden = true; n.textContent = ''; });
    root.querySelectorAll('[aria-invalid]').forEach((n) => n.removeAttribute('aria-invalid'));
    summary.hidden = true;
    clear(summary);
}

function showErrors(errors, headline) {
    clearErrors();
    const lines = [];
    let firstInput = null;
    for (const [path, msgs] of Object.entries(errors || {})) {
        const message = Array.isArray(msgs) ? msgs[0] : String(msgs);
        const slot = root.querySelector(`[data-err-for="${CSS.escape(path)}"]`);
        const input = root.querySelector(`[data-path="${CSS.escape(path)}"]`);
        if (slot) { slot.textContent = message; slot.hidden = false; }
        if (input) { input.setAttribute('aria-invalid', 'true'); firstInput = firstInput || input; }
        lines.push(message);
    }
    summary.hidden = false;
    append(summary, el('div', { class: 'lt-alert lt-alert--bad', role: 'alert' }, [icon('alert'), el('div', {}, [el('p', { text: headline }), lines.length ? el('ul', {}, lines.slice(0, 6).map((l) => el('li', { text: l }))) : null])]));
    (firstInput || summary).scrollIntoView({ block: 'center' });
    if (firstInput) firstInput.focus();
}

// ---- save ------------------------------------------------------------------------------------------------------------

async function save() {
    if (saving || !ctx.canEdit) return;
    clearErrors();
    if (ctx.state.name.trim() === '') {
        showErrors({ name: ['Give your brand kit a name, for example the name of your business.'] }, 'The kit was not saved.');
        failed = true; refreshBar();
        return;
    }
    saving = true; failed = false; refreshBar();

    const creating = kitId === null;
    const res = await api(creating ? 'POST' : 'PUT', creating ? boot.urls.kits : `${boot.urls.kits}/${kitId}`, toPayload(ctx.state));
    if (!res.ok) {
        saving = false; failed = true; refreshBar();
        if (res.status === 422) showErrors(res.errors, 'The kit was not saved. Fix this and try again.');
        else toast(res.message, 'bad');
        return;
    }

    kitId = res.data.id;
    const changed = creating ? true : !!res.data.changed;

    // pictures: attach and detach after the kit exists
    const ops = assetOps(initialAssets, ctx.assets);
    const problems = [];
    for (const a of ops.attach) {
        const r = await api('POST', `${boot.urls.kits}/${kitId}/assets`, { design_asset_id: a.design_asset_id, role: a.role, label: a.label || null });
        if (!r.ok) problems.push(`${a.asset.name}: ${firstError(r.errors) || r.message}`);
    }
    for (const a of ops.detach) {
        if (a.id === null) continue;
        const r = await api('DELETE', `${boot.urls.kits}/${kitId}/assets/${a.id}`);
        if (!r.ok) problems.push(`${a.asset ? a.asset.name : 'A picture'}: ${r.message}`);
    }

    // what the server now holds
    const fresh = await api('GET', `${boot.urls.kits}/${kitId}`);
    saving = false;
    if (fresh.ok) {
        const server = fresh.data;
        ctx.state = fromServer(server);
        ctx.assets = assetRows(server);
        initialAssets = ctx.assets.map((a) => ({ ...a }));
        savedFp = fingerprint(ctx.state, ctx.assets);
        failed = problems.length > 0;
        root.querySelector('.lt-pagehead .lt-h1').textContent = server.name;
        if (creating) history.replaceState(null, '', `${boot.urls.index}/${kitId}/edit`);
        ctx.emit('reset'); ctx.emit('assets');
        const using = server.projects_using || 0;
        toast(creating ? `${server.name} is saved.` : (changed || ops.attach.length || ops.detach.length)
            ? `Saved as version ${server.version}.${using ? ` ${using} ${using === 1 ? 'design' : 'designs'} that used it will show a "Brand updated" notice. Nothing in them changes until you apply it.` : ''}`
            : 'Nothing had changed.', 'ok');
    } else {
        failed = true;
    }
    if (problems.length) showErrors({ pictures: problems }, 'The kit was saved, but some pictures could not be added.');
    refreshBar();
}

async function discard() {
    const ok = await confirmDialog({ title: 'Discard your changes?', body: kitId === null ? 'The new kit will go back to its starting values.' : 'The kit goes back to what was last saved. Pictures you uploaded stay in your assets.', confirmLabel: 'Discard changes', cancelLabel: 'Keep editing', danger: true });
    if (!ok) return;
    // The simplest honest way back to "what is stored": load the page again from the server.
    savedFp = fingerprint(ctx.state, ctx.assets);
    window.location.reload();
}

// ---- leaving with unsaved changes ------------------------------------------------------------------------------------

window.addEventListener('beforeunload', (e) => {
    if (!ctx.canEdit || !dirty() || saving) return;
    e.preventDefault();
    e.returnValue = '';
});

document.addEventListener('click', async (e) => {
    const a = e.target.closest('a[href]');
    if (!a || !dirty() || !ctx.canEdit) return;
    if (a.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey || a.getAttribute('href').startsWith('#') || a.hasAttribute('download')) return;
    e.preventDefault();
    e.stopPropagation();
    const leave = await confirmDialog({ title: 'Leave without saving?', body: 'You have changes that are not saved. If you leave now they are lost.', confirmLabel: 'Leave and lose changes', cancelLabel: 'Stay and keep editing', danger: true });
    if (leave) { savedFp = fingerprint(ctx.state, ctx.assets); window.location.href = a.href; }
}, true);

if (saveBtn) saveBtn.addEventListener('click', save);
discardBtn.addEventListener('click', discard);
document.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key === 's') { e.preventDefault(); save(); } });

// ---- mount -----------------------------------------------------------------------------------------------------------

const mounts = { name: mountName, logo: mountLogo, colors: mountColors, fonts: mountFonts, styles: mountStyles, voice: mountVoice, contact: mountContact, imagery: mountImagery, preview: mountPreview };
for (const [name, fn] of Object.entries(mounts)) {
    const host = root.querySelector(`[data-section="${name}"]`);
    if (host) fn(host, ctx);
}
refreshBar();

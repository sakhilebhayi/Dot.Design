/*
 * Brand tab: the workspace brand kit as chips (colours, fonts, logos). Applying a
 * chip records the element's brand token, so a later brand update can be reviewed
 * and applied deliberately. Brand updates are never applied silently.
 */
import { applyColour, applyFont, colourPropFor } from '../actions.js';
import { h, icon } from '../dom.js';
import { swatch } from '../fields.js';
import { fontLabel } from '../quick.js';
import { emptyState } from './common.js';

export function brandStudioUrl(ctx) {
    for (const n of ['design.brand.index', 'brand.index', 'design.brand-kits.index']) if (ctx.config.urls[n]) return ctx.config.urls[n];
    return null;
}

export function mountBrand(el, ctx) {
    const body = h('div', { class: 'st-brandbody' });
    const notice = h('div', { class: 'st-notebox st-notebox--warn', hidden: true, attrs: { role: 'status' } });
    el.append(notice, body);
    let outdated = null;

    async function checkOutdated() {
        const kit = ctx.brand; if (!kit) { notice.hidden = true; return; }
        const r = await ctx.api.get(ctx.url('design.projects.brand.show'));
        if (!r.ok) return;
        outdated = r.data;
        const n = r.data.counts && r.data.counts.changes ? r.data.counts.changes : 0;
        notice.hidden = !(r.data.brand_outdated && r.data.linked && n > 0);
        if (!notice.hidden) notice.replaceChildren(icon('triangle-alert'), h('div', { class: 'st-notebox__body' }, h('strong', { text: 'Your brand was updated.' }), h('p', { text: `${n} change${n === 1 ? '' : 's'} can be applied to this design. Nothing changes until you say so.` }), h('button', { class: 'lt-btn lt-btn--sm', attrs: { type: 'button' }, text: 'Review changes', onClick: () => ctx.openDialog('brand-review', {}) })));
        ctx.emit('brand-outdated', r.data);
    }

    function paint() {
        const kit = ctx.brand;
        body.replaceChildren();
        if (!kit) {
            const url = brandStudioUrl(ctx);
            body.append(emptyState('No brand kit yet', 'A brand kit keeps your colours, fonts and logo in one place, so every design looks like you. Set one up once and it appears here.',
                url ? h('a', { class: 'lt-btn lt-btn--sm', href: url, target: '_blank', rel: 'noopener' }, 'Set up your brand', icon('external')) : h('p', { class: 'st-note', text: 'Brand Studio is in the main menu under Brand.' })));
            return;
        }
        const sel = () => ctx.selection();
        body.append(h('div', { class: 'st-brandhead' }, h('strong', { text: kit.name }), h('span', { class: 'st-note', text: `Version ${kit.version}` })));
        const colourChips = (kit.colors || []).map((c) => swatch(c.hex, { size: 'lg', label: `Use ${c.name || c.key} (${c.hex})`, onClick: () => {
            const s = sel();
            if (s.count && s.elements.some((e) => colourPropFor(e))) applyColour(ctx, s.elements.filter((e) => colourPropFor(e)).map((e) => e.id), c.hex, { token: 'brand.colors.' + c.key });
            else if (!s.count) ctx.run('brand.apply', { prop: 'background', token: 'brand.colors.' + c.key });
            else ctx.toast('Select text, a shape or an icon to colour it.', { kind: 'warn' });
        } }));
        body.append(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Colours' }), h('div', { class: 'st-colour__chips st-colour__chips--lg' }, ...colourChips), h('p', { class: 'st-note', text: 'Click a colour to use it on what is selected, or on the page background when nothing is.' })));
        const fontRows = ['heading', 'body', 'accent'].filter((s) => kit.fonts && kit.fonts[s]).map((slot) => {
            const key = kit.fonts[slot];
            const sample = h('span', { class: 'st-fontchip__sample', text: 'Aa' }); sample.style.fontFamily = ctx.studio.fonts.stackFor(key);
            return h('button', { class: 'st-fontchip', attrs: { type: 'button' }, onClick: () => {
                const s = sel(); const texts = s.elements.filter((e) => e.type === 'text');
                if (texts.length) applyFont(ctx, texts.map((e) => e.id), key, slot); else ctx.toast('Select some text first, then choose a font.', { kind: 'warn' });
            } }, sample, h('span', { class: 'st-fontchip__text' }, h('strong', { text: { heading: 'Heading font', body: 'Text font', accent: 'Accent font' }[slot] }), h('span', { text: fontLabel(ctx, key) })));
        });
        ctx.studio.fonts.ensureLoaded(Object.values(kit.fonts || {}).map((k) => ({ font: k, weight: 400, italic: false }))).catch(() => {});
        if (fontRows.length) body.append(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Fonts' }), ...fontRows));
        const logos = (kit.assets || []).filter((a) => a.asset);
        if (logos.length) {
            body.append(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Logos and pictures' }), h('div', { class: 'st-pics' }, ...logos.map((a) => h('button', { class: 'st-pic st-pic--logo', attrs: { type: 'button', 'aria-label': `Add ${a.label || a.role_label}` }, onClick: () => ctx.uploads.usePicture(a.asset) }, h('img', { class: 'st-pic__img', attrs: { src: a.asset.url, alt: '', loading: 'lazy' } }), h('span', { class: 'st-pic__cap', text: a.label || a.role_label }))))));
        } else body.append(h('p', { class: 'st-note', text: 'No logo in your brand kit yet. Add one in Brand Studio.' }));
        const url = brandStudioUrl(ctx);
        if (url) body.append(h('a', { class: 'lt-btn lt-btn--ghost lt-btn--sm', href: url, target: '_blank', rel: 'noopener' }, 'Open Brand Studio', icon('external')));
        if (!ctx.config.project.brand_kit_id && !ctx.readOnly) body.append(h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, onClick: async (e) => {
            const r = await ctx.api.put(ctx.url('design.projects.brand.link'), { kit_id: kit.id });
            if (r.ok) { ctx.config.project.brand_kit_id = kit.id; ctx.toast('This design now follows your brand. You will be told when the brand changes.'); paint(); } else ctx.toast(r.message, { kind: 'bad' });
            void e;
        } }, 'Let this design follow the brand'));
    }
    ctx.on('brand', paint);
    ctx.on('selection', () => {});
    paint(); checkOutdated();
    return { show: checkOutdated };
}

/** The review dialog: every change a brand update would make, chosen one by one. */
export function brandReviewDialog() {
    return {
        title: 'Review brand changes', size: 'lg',
        mount(el, props, ctx) {
            el.append(h('p', { class: 'st-note', text: 'Loading the changes' }));
            let items = []; let checked = new Set();
            const list = h('div', { class: 'st-reviewlist' });
            const apply = h('button', { class: 'lt-btn', attrs: { type: 'button' }, text: 'Apply selected changes' });
            const keep = h('button', { class: 'lt-btn lt-btn--secondary', attrs: { type: 'button' }, text: 'Keep my design as it is' });
            const foot = h('div', { class: 'lt-dialog__foot st-dialog__foot' }, keep, apply);
            async function load() {
                const r = await ctx.api.get(ctx.url('design.projects.brand.show'));
                if (!r.ok) { el.replaceChildren(h('p', { class: 'lt-error', text: r.message })); return; }
                items = r.data.items || []; checked = new Set(items.map((i) => i.key));
                if (!items.length) { el.replaceChildren(h('p', { text: 'Nothing needs to change. This design already matches your brand.' })); return; }
                paint();
            }
            const chip = (it) => (it.kind === 'color' ? swatch(it.current, { label: String(it.current) }) : h('span', { class: 'lt-tag', text: it.current }));
            const chip2 = (it) => (it.kind === 'color' ? swatch(it.proposed, { label: String(it.proposed) }) : h('span', { class: 'lt-tag', text: it.proposed }));
            function paint() {
                list.replaceChildren(...items.map((it) => {
                    const cb = h('input', { attrs: { type: 'checkbox', id: 'br-' + it.key, checked: checked.has(it.key) } }); cb.checked = checked.has(it.key);
                    cb.addEventListener('change', () => { if (cb.checked) checked.add(it.key); else checked.delete(it.key); apply.disabled = !checked.size; });
                    return h('label', { class: 'st-review', attrs: { for: 'br-' + it.key } }, cb, h('span', { class: 'st-review__what', text: `Page ${it.page_number}: ${it.element_name || it.element_type}, ${it.kind === 'color' ? 'colour' : 'font'}` }), chip(it), icon('arrow-right', 'lt-icon--sm'), chip2(it));
                }));
                el.replaceChildren(h('p', { class: 'st-note', text: 'Pick what to change. A restore point is saved first, so you can go back.' }), list, foot);
                apply.disabled = !checked.size;
            }
            apply.addEventListener('click', async () => {
                apply.setAttribute('aria-busy', 'true');
                const s = await ctx.studio.save.flush();
                if (s !== 'saved' && s !== 'idle') { apply.removeAttribute('aria-busy'); ctx.toast('Your latest changes are not saved yet. Fix saving, then try again.', { kind: 'warn' }); return; }
                const r = await ctx.api.post(ctx.url('design.projects.brand.apply'), { items: Array.from(checked) });
                apply.removeAttribute('aria-busy');
                if (!r.ok) { ctx.toast(r.message || 'The changes could not be applied.', { kind: 'bad' }); return; }
                ctx.toast('Brand changes applied. Reloading your design.');
                setTimeout(() => location.reload(), 600);
            });
            keep.addEventListener('click', async () => {
                const r = await ctx.api.post(ctx.url('design.projects.brand.dismiss'), {});
                if (r.ok) { ctx.toast('Kept as it is. We will not ask again for this update.'); ctx.closeDialog(); } else ctx.toast(r.message, { kind: 'bad' });
            });
            load();
            return null;
        },
    };
}

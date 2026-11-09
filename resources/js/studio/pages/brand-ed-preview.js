/*
 * "Preview on a design": three real library templates drawn with the unsaved kit applied. The kit is applied in memory only
 * (brand-apply.js mirrors the server's token rules); nothing is sent anywhere and no design is touched.
 */
import { api, clear, debounce, el, icon } from './brand-core.js';
import { applyKitToPages, hasTokens } from './brand-apply.js';
import { previewSurface } from './brand-preview.js';
import { button } from './brand-ed-ui.js';

/** Up to three templates that are linked to brand colours, preferring different shapes (square, tall, wide). Pure. */
export function pickPreviewTemplates(templates, count = 3) {
    const usable = templates.filter((t) => t.preview && hasTokens([t.preview]));
    const shapeOf = (t) => (t.width === t.height ? 'square' : (t.height > t.width ? 'tall' : 'wide'));
    const out = [];
    const shapes = new Set();
    for (const t of usable) { if (out.length < count && !shapes.has(shapeOf(t))) { out.push(t); shapes.add(shapeOf(t)); } }
    for (const t of usable) { if (out.length < count && !out.includes(t)) out.push(t); }
    return out;
}

export function mountPreview(host, ctx) {
    const list = el('div', { class: 'br-preview__list' });
    const note = el('p', { class: 'lt-small', role: 'status' });
    let surfaces = [];

    host.appendChild(el('div', { class: 'br-preview__head' }, [el('h2', { id: 'pv-title', class: 'lt-h3', text: 'Preview on a design' })]));
    host.appendChild(el('p', { class: 'lt-small', text: 'Real templates from the library, drawn with the kit as it is right now, saved or not. Only a preview: no design is changed.' }));
    host.appendChild(list);
    host.appendChild(note);

    const kitNow = () => ({ colors: ctx.state.colors, fonts: ctx.state.fonts });
    let templates = [];

    const paint = debounce(() => {
        const kit = kitNow();
        surfaces.forEach(({ surface, template }) => {
            const [page] = applyKitToPages([template.preview], kit);
            surface.show(page, `${template.name}, shown in your brand`);
        });
    }, 120);

    function skeleton() {
        clear(list);
        for (let i = 0; i < 3; i += 1) list.appendChild(el('div', { class: 'br-prev br-prev--skeleton' }, el('div', { class: 'br-prev__stage' }, el('div', { class: 'br-prev__frame lt-skeleton', style: { '--w': 4, '--h': 5 } }))));
    }

    async function load() {
        skeleton();
        note.textContent = '';
        const res = await api('GET', `${ctx.boot.urls.templates}?per_page=24&include=first_page&sort=popular`);
        clear(list);
        if (!res.ok) {
            note.textContent = res.message;
            list.appendChild(button('Try again', { icon: icon('refresh'), onclick: load }));
            return;
        }
        templates = pickPreviewTemplates(res.data.data || []);
        if (!templates.length) { note.textContent = 'No library template is linked to brand colours yet, so there is nothing to preview. Your kit still works wherever a template is.'; return; }
        surfaces = templates.map((template) => {
            const frame = el('div', { class: 'br-prev__frame', style: { '--w': template.width, '--h': template.height } });
            list.appendChild(el('figure', { class: 'br-prev' }, [el('div', { class: 'br-prev__stage' }, frame), el('figcaption', { class: 'br-prev__name', text: template.name })]));
            return { surface: previewSurface(frame, { manifest: ctx.boot.fonts }), template };
        });
        paint();
    }

    ctx.on('change', paint);
    ctx.on('reset', paint);
    load();
}

/*
 * Save to Dot.Files (R-45 collab). Only offered when the server says the Dot.Files integration is on
 * (config.flags.filesVault) AND the shell can build an export file for us.
 *
 * Optional dependency, feature-detected, documented in docs/R45_UI_COLLAB.md:
 *     ctx.exports.render({ format: 'png'|'jpg'|'pdf'|'svg'|'gif', scope: 'all'|'page-N' }) -> Promise<Blob>
 * It must produce the same file the Export dialog would download. If it is missing the menu item stays hidden: there is no
 * stand-in, because filing something other than what the person would download would be dishonest.
 *
 * Upload: multipart POST (file, format, scope, acknowledge) through ctx.api.postForm when the shell has it, else ctx.api.post
 * with a FormData body (the shell's api must pass FormData through untouched).
 */
import '../../../../css/studio-collab.css';
import { h, clear } from '../../collab/dom.js';
import { busyWhile, button, field, notice, select, skeleton } from '../../collab/ui.js';
import { collabUrls } from '../../collab/urls.js';
import { explain } from '../../collab/errors.js';

const FORMATS = [
    { value: 'png', label: 'PNG picture' },
    { value: 'jpg', label: 'JPG picture' },
    { value: 'pdf', label: 'PDF document' },
    { value: 'svg', label: 'SVG drawing' },
];

const exporter = (ctx) => (ctx.exports && typeof ctx.exports.render === 'function' ? ctx.exports : null);

export function register(ctx) {
    const flags = (ctx.config && ctx.config.flags) || {};
    if (!flags.filesVault) return;

    ctx.registerDialog('files.save', { title: 'Save to Dot.Files', size: 'md', mount: (el, props, c) => mountFiles(el, c || ctx) });
    ctx.registerTopbarAction({
        id: 'save-to-files',
        label: 'Save to Dot.Files',
        icon: 'upload',
        order: 90,
        modes: ['simple', 'pro'],
        visible: (c) => !!exporter(c || ctx),
        onClick: (c) => (c || ctx).openDialog('files.save'),
    });
}

/** Only an http(s) address is ever turned into a link. */
export function safeHref(url) {
    try { const u = new URL(url, window.location.href); return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : null; } catch (e) { return null; }
}

export function mountFiles(el, ctx) {
    const urls = collabUrls(ctx.config);
    let destroyed = false;
    const root = h('div', { class: 'cb-files' });
    el.append(root);

    if (!exporter(ctx)) {
        root.append(notice('This editor cannot build an export file here, so saving to Dot.Files is not available. You can still download the design and upload it in Dot.Files.', 'warn'));
        return { destroy() { clear(el); } };
    }
    root.append(skeleton(2));

    (async () => {
        const status = await ctx.api.get(urls.integrations);
        if (destroyed) return;
        clear(root);
        const files = status.ok && status.data && Array.isArray(status.data.integrations) ? status.data.integrations.find((i) => i.key === 'files') : null;
        if (files && files.status === 'unavailable') root.append(notice(files.reason, 'warn'));

        const format = select(FORMATS, { value: 'png' });
        const pageCount = (() => { try { return ctx.studio.getState().doc.pages.length; } catch (e) { return 1; } })();
        const pageIndex = (() => { try { const s = ctx.studio.getState(); return Math.max(0, s.doc.pages.findIndex((p) => p.id === s.activePageId)); } catch (e) { return 0; } })();
        const scope = select([{ value: 'all', label: pageCount > 1 ? `All ${pageCount} pages` : 'The design' }].concat(pageCount > 1 ? [{ value: `page-${pageIndex + 1}`, label: `Page ${pageIndex + 1} only` }] : []), { value: 'all' });
        const ack = h('input', { type: 'checkbox', id: 'cb-ack' });
        const ackRow = h('label', { class: 'cb-check', for: 'cb-ack' }, ack, h('span', { text: 'I understand that everyone in this business will see this file in Dot.Files.' }));
        const err = h('div', { class: 'cb-formerr' });
        const submit = button({ label: 'Save to Dot.Files', variant: 'primary', type: 'submit' });
        const form = h('form', { class: 'cb-form', novalidate: true },
            h('p', { class: 'cb-pinned', text: 'This files a copy in your business\'s Dot.Files. Whatever this design\'s own visibility is, everyone in the business can open the file there. Saving the same thing again adds a new version of the same file.' }),
            h('div', { class: 'cb-row' }, field('Format', format), field('What to save', scope)),
            ackRow, err,
            h('div', { class: 'cb-actions' }, submit, button({ label: 'Cancel', variant: 'ghost', onClick: () => ctx.closeDialog() })));
        root.append(form);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clear(err);
            if (!ack.checked) { err.append(notice('Tick the box to confirm who can see the file.', 'warn')); ack.focus(); return; }
            submit.setBusy(true, 'Preparing the file...');
            let blob;
            try {
                try { if (ctx.studio && ctx.studio.save && ctx.studio.save.flush) await ctx.studio.save.flush(); } catch (x) { /* export uses what is on screen */ }
                blob = await exporter(ctx).render({ format: format.value, scope: scope.value });
            } catch (x) {
                submit.setBusy(false);
                err.append(notice('Could not build the file. Try downloading the design from the Export dialog instead.', 'bad'));
                return;
            }
            if (!(blob instanceof Blob) || blob.size === 0) { submit.setBusy(false); err.append(notice('The export came out empty, so nothing was saved.', 'bad')); return; }
            const body = new FormData();
            body.append('file', blob, `design.${format.value}`);
            body.append('format', format.value);
            body.append('scope', scope.value);
            body.append('acknowledge', '1');
            submit.setBusy(true, 'Saving...');
            const post = ctx.api.postForm ? ctx.api.postForm.bind(ctx.api) : ctx.api.post.bind(ctx.api);
            const res = await post(urls.dotFiles, body);
            if (destroyed) return;
            submit.setBusy(false);
            if (res.ok && res.data && res.data.status === 'filed') {
                const link = safeHref(res.data.open_url);
                clear(root);
                root.append(h('div', { class: 'cb-done' },
                    h('h3', { class: 'cb-h', text: res.data.version > 1 ? `Saved as version ${res.data.version}` : 'Saved to Dot.Files' }),
                    h('p', { text: res.data.message || 'The file is in Dot.Files.' }),
                    h('div', { class: 'cb-actions' },
                        link ? h('a', { class: 'cb-btn cb-btn--secondary', href: link, target: '_blank', rel: 'noopener noreferrer', text: 'Open in Dot.Files' }) : null,
                        button({ label: 'Done', variant: 'primary', onClick: () => ctx.closeDialog() }))));
            } else {
                const msg = res.data && res.data.error ? { text: res.data.error, kind: 'bad' } : explain(res, { what: 'save the file to Dot.Files' });
                err.append(notice(msg.text, msg.kind));
            }
        });
    })();

    return { destroy() { destroyed = true; clear(el); } };
}

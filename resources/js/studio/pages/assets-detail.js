/*
 * The details drawer of one picture: what it is, where it is used, and the few things its uploader can change (name, folder, tags,
 * licence). There is deliberately no "copy link": files are private and open only through the app for people who may see them.
 */
import { api, append, clear, confirmDialog, el, firstError, formatBytes, icon, toast } from './brand-core.js';

const TAG_PATTERN = /^[\p{L}\p{N} _-]+$/u;

/** Add a tag: trimmed, 30 characters, letters and numbers, no repeats, 20 at most. Returns { list, reason }. Pure. */
export function addTag(list, raw) {
    const t = String(raw || '').trim().replace(/\s+/g, ' ');
    if (t === '') return { list, reason: null };
    if (t.length > 30) return { list, reason: 'A tag can be up to 30 characters.' };
    if (!TAG_PATTERN.test(t)) return { list, reason: 'Use letters, numbers, spaces and - _ in a tag.' };
    if (list.some((x) => x.toLowerCase() === t.toLowerCase())) return { list, reason: 'That tag is already there.' };
    if (list.length >= 20) return { list, reason: 'A picture can have up to 20 tags.' };
    return { list: [...list, t], reason: null, added: true };
}

export function openDetail({ asset, boot, state, onChanged, onDeleted }) {
    const dialog = document.getElementById('as-detail');
    const host = dialog.querySelector('[data-detail]');
    const canChange = !!(asset.can && asset.can.update);
    let tags = [...(asset.tags || [])];
    let usage = null;

    const close = () => { if (dialog.open) dialog.close(); };
    dialog.onclose = () => clear(host);
    dialog.onclick = (e) => { if (e.target.classList && e.target.classList.contains('as-drawer__wrap')) close(); };

    const nameInput = el('input', { class: 'lt-input', value: asset.name, maxlength: 120, 'aria-label': 'Name', disabled: !canChange });
    const folderInput = el('input', { class: 'lt-input', value: asset.folder || '', maxlength: 80, list: 'as-folder-options', 'aria-label': 'Folder', placeholder: 'No folder', disabled: !canChange, autocomplete: 'off' });
    const folderOptions = el('datalist', { id: 'as-folder-options' }, state.meta.folders.map((f) => el('option', { value: f.name })));
    const licence = el('select', { class: 'lt-select', 'aria-label': 'Licence', disabled: !canChange || asset.license === 'builtin' }, [
        el('option', { value: 'own', text: 'Mine: I have the right to use it' }),
        el('option', { value: 'brand', text: 'Workspace brand asset: everyone here may use it' }),
        asset.license === 'builtin' ? el('option', { value: 'builtin', text: 'Built in' }) : null,
    ]);
    licence.value = asset.license;
    const tagList = el('div', { class: 'as-tagedit' });
    const tagInput = el('input', { class: 'lt-input', placeholder: 'Add a tag', maxlength: 30, 'aria-label': 'Add a tag', disabled: !canChange, autocomplete: 'off' });
    const tagMsg = el('p', { class: 'lt-error', hidden: true });
    const formMsg = el('p', { class: 'lt-error', role: 'alert', hidden: true });
    const saveBtn = el('button', { type: 'button', class: 'lt-btn', text: 'Save changes', disabled: true });
    const usedBox = el('div', { class: 'as-used-list', 'aria-live': 'polite' }, el('p', { class: 'lt-small', text: 'Checking where it is used' }));

    const dirty = () => nameInput.value.trim() !== asset.name || (folderInput.value.trim() || '') !== (asset.folder || '') || licence.value !== asset.license || JSON.stringify(tags) !== JSON.stringify(asset.tags || []);
    const touch = () => { saveBtn.disabled = !canChange || !dirty(); formMsg.hidden = true; };

    function drawTags() {
        clear(tagList);
        for (const [i, t] of tags.entries()) tagList.appendChild(el('span', { class: 'br-word' }, [el('span', { text: t }), canChange ? el('button', { type: 'button', 'aria-label': `Remove tag ${t}`, onclick: () => { tags.splice(i, 1); drawTags(); touch(); } }, icon('x', 'sm')) : null]));
        if (!tags.length) tagList.appendChild(el('span', { class: 'lt-small', text: 'No tags' }));
    }
    function commitTag() {
        const r = addTag(tags, tagInput.value);
        if (r.reason) { tagMsg.textContent = r.reason; tagMsg.hidden = false; return; }
        tagMsg.hidden = true;
        if (r.added) { tags = r.list; tagInput.value = ''; drawTags(); touch(); }
    }
    tagInput.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); commitTag(); } });
    tagInput.addEventListener('blur', () => { if (tagInput.value.trim()) commitTag(); });
    [nameInput, folderInput].forEach((i) => i.addEventListener('input', touch));
    licence.addEventListener('change', touch);

    saveBtn.addEventListener('click', async () => {
        saveBtn.setAttribute('aria-busy', 'true');
        const res = await api('PUT', `${boot.urls.asset}/${asset.id}`, { name: nameInput.value.trim(), folder: folderInput.value.trim() || null, tags, license: licence.value === 'builtin' ? undefined : licence.value });
        saveBtn.removeAttribute('aria-busy');
        if (!res.ok) { formMsg.textContent = firstError(res.errors) || res.message; formMsg.hidden = false; return; }
        toast('Saved.', 'ok');
        close();
        onChanged();
    });

    async function remove() {
        if (usage && usage.in_use) {
            await confirmDialog({
                title: 'This picture is in use',
                body: el('div', { class: 'br-stack br-stack--tight' }, [
                    el('p', { text: `${asset.name} is placed on ${usage.designs.length ? usage.designs.map((d) => `${d.project} (page ${d.pages.join(', ')})`).join('; ') : 'a design you cannot see'}${usage.hidden && usage.designs.length ? ` and ${usage.hidden} more ${usage.hidden === 1 ? 'page' : 'pages'} you cannot see` : ''}.` }),
                    el('p', { text: 'Deleting it would leave a hole in the design, so it is kept. Remove it from the design first, then delete it.' }),
                ]),
                hideConfirm: true, cancelLabel: 'Close',
            });
            return;
        }
        const ok = await confirmDialog({ title: `Delete ${asset.name}?`, body: 'The picture is deleted for good and cannot be brought back. It is not used in any design.', confirmLabel: 'Delete picture', danger: true });
        if (!ok) return;
        const res = await api('DELETE', `${boot.urls.asset}/${asset.id}`);
        if (!res.ok) { toast(res.message, 'bad'); return; }
        toast(`${asset.name} was deleted.`, 'ok');
        close();
        onDeleted();
    }

    function field(label, input, extra) { return el('div', { class: 'lt-field' }, [el('label', { class: 'lt-label', text: label }), input, extra || null]); }

    clear(host);
    append(host, [
        el('div', { class: 'as-dr__head' }, [el('h2', { class: 'as-dr__title', id: 'as-detail-title', text: asset.name }), el('button', { type: 'button', class: 'lt-icon-btn', 'aria-label': 'Close details', onclick: close }, icon('x'))]),
        el('div', { class: 'as-dr__pic' }, el('img', { src: asset.url, alt: asset.name, width: asset.width, height: asset.height })),
        el('div', { class: 'as-dr__body' }, [
            el('dl', { class: 'as-facts' }, [
                el('dt', { text: 'Size' }), el('dd', { text: `${asset.width} x ${asset.height} pixels` }),
                el('dt', { text: 'File' }), el('dd', { text: `${asset.type}, ${formatBytes(asset.size)}` }),
                el('dt', { text: 'Added' }), el('dd', { text: asset.created_at ? new Date(asset.created_at).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : 'Unknown' }),
                el('dt', { text: 'Uploaded by' }), el('dd', { text: asset.owned ? 'You' : 'A teammate' }),
            ]),
            !canChange ? el('p', { class: 'lt-small', text: 'Only the person who uploaded this picture can rename, file or tag it.' }) : null,
            field('Name', nameInput),
            field('Folder', folderInput, el('p', { class: 'lt-hint', text: 'Type a new name to make a new folder.' })),
            field('Tags', el('div', { class: 'br-stack br-stack--tight' }, [tagList, canChange ? el('div', { class: 'br-row' }, [el('div', { style: { flex: '1 1 10rem' } }, tagInput), el('button', { type: 'button', class: 'lt-btn lt-btn--secondary lt-btn--sm', onclick: commitTag, text: 'Add' })]) : null, tagMsg])),
            field('Licence', licence, el('p', { class: 'lt-hint', text: 'Dot.Design does not check rights for you. Only use pictures you own or have permission to use.' })),
            el('div', { class: 'lt-field' }, [el('span', { class: 'lt-label', text: 'Where it is used' }), usedBox]),
            formMsg,
        ]),
        el('div', { class: 'as-dr__foot' }, [
            asset.can && asset.can.delete ? el('button', { type: 'button', class: 'lt-btn lt-btn--ghost grow', onclick: remove }, [icon('trash'), 'Delete']) : el('span', { class: 'grow' }),
            el('button', { type: 'button', class: 'lt-btn lt-btn--secondary', onclick: close, text: 'Close' }),
            canChange ? saveBtn : null,
        ]),
        folderOptions,
    ]);
    drawTags();

    if (!dialog.open) dialog.showModal();

    api('GET', `${boot.urls.asset}/${asset.id}/usage`).then((res) => {
        clear(usedBox);
        if (!res.ok) { usedBox.appendChild(el('p', { class: 'lt-small', text: 'We could not check where this is used. ' + res.message })); return; }
        usage = res.data;
        if (!usage.in_use) { usedBox.appendChild(el('p', { class: 'lt-small', text: 'Not used in any design yet.' })); return; }
        for (const d of usage.designs) usedBox.appendChild(el('p', {}, [el('a', { href: d.url, text: d.project }), ` (${d.pages.length === 1 ? 'page' : 'pages'} ${d.pages.join(', ')})`]));
        if (usage.hidden) usedBox.appendChild(el('p', { class: 'lt-meta', text: `And ${usage.hidden} more ${usage.hidden === 1 ? 'page' : 'pages'} in designs you cannot see.` }));
    });
}

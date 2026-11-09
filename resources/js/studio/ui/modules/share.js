/*
 * Share dialog (R-45 collab). Topbar "Share" opens one dialog with three parts:
 *   1. who can open the design inside Dot.Design (Only me / My workspace), with a plain explanation;
 *   2. the people who can open it now (read-only; membership is managed in workspace settings);
 *   3. share links: make one (view or comment, optional expiry, downloads), copy it once, see and turn off the others.
 * The link's address exists only in the response that creates it. It is held in memory until the person is done with it.
 */
import '../../../../css/studio-collab.css';
import { h, clear } from '../../collab/dom.js';
import { busyWhile, button, copyText, empty, field, input, notice, select, skeleton, tag } from '../../collab/ui.js';
import { can, collabUrls } from '../../collab/urls.js';
import { explain } from '../../collab/errors.js';
import { peopleStore } from '../../collab/people.js';
import { EXPIRY_CHOICES, allowsSentence, createLinkVault, describeShare, expiryDays } from '../../collab/share-state.js';
import { expiresIn, timeAgo } from '../../collab/format.js';

export function register(ctx) {
    if (!can(ctx.config, 'share')) return;

    ctx.registerDialog('share', {
        title: 'Share',
        size: 'md',
        mount(el, props, c) { return mountShare(el, c || ctx); },
    });

    ctx.registerTopbarAction({
        id: 'share',
        label: 'Share',
        icon: 'share',
        order: 80,
        modes: ['simple', 'pro'],
        visible: (c) => can((c || ctx).config, 'share'),
        onClick: (c) => (c || ctx).openDialog('share'),
    });
}

export function mountShare(el, ctx) {
    const urls = collabUrls(ctx.config);
    const people = peopleStore(ctx);
    const vault = createLinkVault();
    let destroyed = false;
    let shares = [];

    const root = h('div', { class: 'cb-share' });
    el.append(root);

    const accessBox = h('section', { class: 'cb-section', 'aria-labelledby': 'cb-share-access' });
    const peopleBox = h('section', { class: 'cb-section', 'aria-labelledby': 'cb-share-people' });
    const linkBox = h('section', { class: 'cb-section', 'aria-labelledby': 'cb-share-links' });
    root.append(accessBox, peopleBox, linkBox);

    // ------------------------------------------------------------------ 1 and 2: access and people
    function renderAccess(data) {
        clear(accessBox); clear(peopleBox);
        const team = data.workspace;
        const canChange = !!data.can_change_visibility;
        const current = data.visibility === 'team' ? 'team' : 'private';

        const group = h('div', { class: 'cb-choices', role: 'radiogroup', 'aria-labelledby': 'cb-share-access' });
        const options = [
            ['private', 'Only me', 'Only you can open this design. Anyone you send it to for review can open it too.'],
            ['team', team ? `Everyone in ${team.name}` : 'My workspace', 'Everyone in the workspace can open and comment on it. Owners, admins and editors can also change it.'],
        ];
        const status = h('p', { class: 'cb-hint', role: 'status', 'aria-live': 'polite' });
        for (const [value, label, text] of options) {
            const id = `cb-vis-${value}`;
            const radio = h('input', { type: 'radio', name: 'cb-visibility', id, value, checked: current === value, disabled: !canChange || (value === 'team' && !team) });
            radio.addEventListener('change', async () => {
                if (!radio.checked) return;
                status.textContent = 'Saving...';
                const res = await ctx.api.put(urls.project, { visibility: value });
                if (destroyed) return;
                if (res.ok) {
                    status.textContent = 'Saved.';
                    people.invalidate();
                    const fresh = await people.load(true);
                    if (!destroyed && fresh.ok) renderAccess(fresh.data);
                } else {
                    const e = explain(res, { what: 'change who can open this design' });
                    status.textContent = e.text;
                    radio.checked = false;
                    const back = group.querySelector(`input[value="${current}"]`);
                    if (back) back.checked = true;
                }
            });
            group.append(h('label', { class: 'cb-choice', for: id }, radio, h('span', { class: 'cb-choice__body' }, h('span', { class: 'cb-choice__label', text: label }), h('span', { class: 'cb-choice__text', text }))));
        }
        accessBox.append(h('h3', { class: 'cb-h', id: 'cb-share-access', text: 'Who can open it in Dot.Design' }), group,
            canChange ? null : h('p', { class: 'cb-hint', text: 'Only the person who made this design, or a workspace owner or admin, can change this.' }), status);

        peopleBox.append(h('h3', { class: 'cb-h', id: 'cb-share-people', text: 'People with access' }));
        const withAccess = data.people.filter((p) => p.can_view);
        const list = h('ul', { class: 'cb-people' });
        for (const p of withAccess) {
            list.append(h('li', { class: 'cb-person' },
                h('span', { class: 'cb-person__name', text: p.is_you ? `${p.name} (you)` : p.name }),
                h('span', { class: 'cb-person__role', text: p.is_creator ? 'Made this design' : p.role_label })));
        }
        peopleBox.append(withAccess.length ? list : h('p', { class: 'cb-hint', text: 'Nobody else can open it yet.' }));
        peopleBox.append(h('p', { class: 'cb-hint' },
            'Link visitors are not listed here. ',
            team && team.url ? h('a', { class: 'cb-link', href: team.url, text: 'Manage who is in the workspace' }) : null));
    }

    async function loadPeople() {
        clear(accessBox); clear(peopleBox);
        accessBox.append(skeleton(2));
        const r = await people.load(true);
        if (destroyed) return;
        if (r.ok) { renderAccess(r.data); return; }
        clear(accessBox);
        const e = explain(r.res, { what: 'load who can open this design' });
        accessBox.append(notice(e.text, e.kind, { action: { label: 'Try again', onClick: loadPeople } }));
    }

    // ------------------------------------------------------------------ 3: links
    const form = buildForm();
    const resultBox = h('div', { class: 'cb-result', 'aria-live': 'polite' });
    const listBox = h('div', { class: 'cb-links' });
    linkBox.append(h('h3', { class: 'cb-h', id: 'cb-share-links', text: 'Share link' }), form.el, resultBox, listBox);

    function buildForm() {
        const permission = select([{ value: 'view', label: 'View only' }, { value: 'comment', label: 'View and comment' }], { value: 'view' });
        const expiry = select(EXPIRY_CHOICES.map((c) => ({ value: c.key, label: c.label })), { value: '30' });
        const downloads = h('input', { type: 'checkbox', id: 'cb-dl' });
        const says = h('p', { class: 'cb-says', 'aria-live': 'polite' });
        const sync = () => { says.textContent = allowsSentence(permission.value, downloads.checked); };
        permission.addEventListener('change', sync);
        downloads.addEventListener('change', sync);
        sync();
        const submit = button({ label: 'Create link', variant: 'primary', type: 'submit' });
        const err = h('div', { class: 'cb-formerr' });
        const el = h('form', { class: 'cb-linkform', novalidate: true },
            h('div', { class: 'cb-row' },
                field('Anyone with the link can', permission),
                field('Link stops working', expiry)),
            h('label', { class: 'cb-check', for: 'cb-dl' }, downloads, h('span', { text: 'Allow downloads' })),
            says, err,
            h('div', { class: 'cb-actions' }, submit));
        el.addEventListener('submit', async (event) => {
            event.preventDefault();
            clear(err);
            const days = expiryDays(expiry.value);
            const body = { permission: permission.value, download_allowed: downloads.checked };
            if (days) body.expires_in_days = days;
            const res = await busyWhile(submit, ctx.api.post(urls.shares, body), 'Creating...');
            if (destroyed) return;
            if (res.ok && res.data && res.data.url) {
                vault.keep(res.data.url, res.data.share && res.data.share.id);
                showResult();
                loadShares();
            } else {
                const e = explain(res, { what: 'create the link' });
                err.append(notice(e.text, e.kind));
            }
        });
        return { el };
    }

    function showResult() {
        clear(resultBox);
        if (!vault.has) return;
        const url = vault.peek();
        const box = input({ type: 'text', readOnly: true, value: url, 'aria-label': 'Your new link', onfocus: (e) => e.target.select() });
        const copy = button({ label: 'Copy link', variant: 'primary', onClick: async () => {
            const ok = await copyText(url, box);
            ctx.toast(ok ? 'Link copied.' : 'Could not copy. Select the link and copy it by hand.', { kind: ok ? 'ok' : 'warn' });
        } });
        const done = button({ label: 'Done, I have copied it', variant: 'secondary', onClick: () => { vault.hide(); clear(resultBox); } });
        resultBox.append(h('div', { class: 'cb-oncebox', role: 'group', 'aria-label': 'Your new link' },
            h('p', { class: 'cb-oncebox__title', text: 'Your link is ready' }),
            h('p', { class: 'cb-oncebox__text', text: 'Copy it now. For security this is the only time you will see it. If you lose it, turn it off and make a new one.' }),
            h('div', { class: 'cb-copyrow' }, box, copy),
            h('div', { class: 'cb-actions' }, done)));
        box.focus(); box.select();
    }

    async function loadShares() {
        if (!listBox.firstChild) listBox.append(skeleton(2));
        const res = await ctx.api.get(urls.shares);
        if (destroyed) return;
        clear(listBox);
        if (!res.ok) {
            const e = explain(res, { what: 'load the links' });
            listBox.append(notice(e.text, e.kind, { action: { label: 'Try again', onClick: loadShares } }));
            return;
        }
        shares = (res.data && res.data.shares) || [];
        renderShares();
    }

    function renderShares() {
        clear(listBox);
        listBox.append(h('h4', { class: 'cb-h cb-h--sub', text: 'Links you have made' }));
        if (!shares.length) { listBox.append(empty('No links yet', 'A link lets someone outside your workspace look at this design without an account.')); return; }
        const rank = { active: 0, expired: 1, revoked: 2 };
        const rows = shares.map((s) => ({ s, d: describeShare(s) })).sort((a, b) => rank[a.d.state] - rank[b.d.state] || b.s.id - a.s.id);
        const ul = h('ul', { class: 'cb-linklist' });
        for (const { s, d } of rows) {
            const revoke = d.state === 'active' ? button({ label: 'Turn off', size: 'sm', variant: 'secondary', ariaLabel: `Turn off the ${d.permission.toLowerCase()} link made ${timeAgo(s.created_at)}`, onClick: () => revokeShare(s, revoke) }) : null;
            ul.append(h('li', { class: `cb-linkrow cb-linkrow--${d.state}` },
                h('div', { class: 'cb-linkrow__main' },
                    h('span', { class: 'cb-linkrow__title' }, d.permission, ' ', tag(d.stateLabel, d.state === 'active' ? 'ok' : 'neutral')),
                    h('span', { class: 'cb-linkrow__meta', text: [`Made ${timeAgo(s.created_at)}`, d.state === 'active' ? expiresIn(s.expires_at) : '', d.downloads, d.views, s.last_viewed_at ? `last opened ${timeAgo(s.last_viewed_at)}` : 'not opened yet'].filter(Boolean).join('. ') + '.' })),
                revoke));
        }
        listBox.append(ul);
    }

    async function revokeShare(share, btn) {
        const ok = await ctx.confirm({
            title: 'Turn off this link?',
            body: 'Anyone who has it will see "This link is not available" straight away. You can make a new one at any time.',
            confirmLabel: 'Turn off link',
            danger: true,
        });
        if (!ok || destroyed) return;
        const res = await busyWhile(btn, ctx.api.del(urls.share(share.id)), 'Turning off...');
        if (destroyed) return;
        if (res.ok) {
            if (vault.shareId === share.id) { vault.hide(); clear(resultBox); }
            ctx.toast('Link turned off.', { kind: 'ok' });
            loadShares();
        } else {
            const e = explain(res, { what: 'turn off the link' });
            ctx.toast(e.text, { kind: e.kind });
        }
    }

    loadPeople();
    loadShares();

    return {
        destroy() {
            // The new link is forgotten with the dialog; there is no way to get it back from the server.
            vault.hide();
            destroyed = true;
            clear(el);
        },
    };
}

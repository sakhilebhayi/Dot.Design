/*
 * Comment widgets shared by the left panel and the on-canvas popover: the composer (textarea, @mention picker, counter)
 * and one thread (comment, replies, resolve, edit, delete). Comment text is always set as text, never as HTML.
 */
import { h, uid } from './dom.js';
import { button, notice, tag } from './ui.js';
import { MAX_COMMENT_LENGTH, authorLabel, remaining, validateBody } from './comments-state.js';
import { activeMention, filterPeople, insertMention, mentionIds, splitMentions } from './mentions.js';
import { timeAgo, dateTime } from './format.js';
import { explain } from './errors.js';

/**
 * @param {{people:Array, label:string, placeholder?:string, submitLabel?:string, initial?:string, initialPicked?:Array,
 *          onSubmit:(payload:{body:string, mentions:number[]})=>Promise<{ok:boolean, res?:object}>, onCancel?:()=>void, rows?:number}} opts
 */
export function createComposer(opts) {
    const id = uid('cb-c');
    const picked = (opts.initialPicked || []).slice();
    const ta = h('textarea', {
        class: 'cb-input cb-textarea', id, rows: opts.rows || 3, value: opts.initial || '', placeholder: opts.placeholder || 'Write a comment. Type @ to mention a colleague.',
        'aria-label': opts.label, autocomplete: 'off', spellcheck: 'true', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': `${id}-list`,
    });
    const list = h('ul', { class: 'cb-mentions', id: `${id}-list`, role: 'listbox', 'aria-label': 'People you can mention', hidden: true });
    const counter = h('span', { class: 'cb-counter', 'aria-live': 'off' });
    const error = h('div', { class: 'cb-formerr' });
    const submit = button({ label: opts.submitLabel || 'Comment', variant: 'primary', type: 'submit', size: 'sm' });
    const cancel = opts.onCancel ? button({ label: 'Cancel', variant: 'ghost', size: 'sm', onClick: () => opts.onCancel() }) : null;
    const el = h('form', { class: 'cb-composer', novalidate: true },
        h('div', { class: 'cb-composer__box' }, ta, list),
        error,
        h('div', { class: 'cb-composer__foot' }, counter, h('span', { class: 'cb-composer__btns' }, cancel, submit)));

    let options = [];
    let enabled = 0; // the first `enabled` options can be chosen; the rest are people who cannot open this design yet
    let active = 0;
    let mention = null;

    const closeList = () => { list.hidden = true; ta.setAttribute('aria-expanded', 'false'); ta.removeAttribute('aria-activedescendant'); mention = null; options = []; };
    const paintList = () => {
        list.replaceChildren(...options.map((p, i) => {
            const off = i >= enabled;
            return h('li', {
                class: `cb-mention${i === active ? ' is-active' : ''}${off ? ' is-off' : ''}`, role: 'option', id: `${id}-o${i}`, 'aria-selected': i === active ? 'true' : 'false', 'aria-disabled': off ? 'true' : null,
                onmousedown: (e) => { e.preventDefault(); if (!off) choose(p); },
            }, h('span', { class: 'cb-mention__name', text: p.name }), h('span', { class: 'cb-mention__role', text: off ? 'Cannot open this design yet' : p.role_label }));
        }));
        list.hidden = options.length === 0;
        ta.setAttribute('aria-expanded', options.length ? 'true' : 'false');
        if (options.length) ta.setAttribute('aria-activedescendant', `${id}-o${active}`); else ta.removeAttribute('aria-activedescendant');
    };
    const choose = (person) => {
        if (!mention) return;
        const r = insertMention(ta.value, mention, person);
        ta.value = r.text;
        if (!picked.some((p) => p.id === person.id)) picked.push({ id: person.id, name: person.name });
        ta.setSelectionRange(r.caret, r.caret);
        closeList();
        ta.focus();
        update();
    };
    const detect = () => {
        mention = activeMention(ta.value, ta.selectionStart);
        if (!mention) { closeList(); return; }
        const found = filterPeople(opts.people, mention.query, 8);
        const can = found.filter((p) => p.can_view !== false);
        enabled = can.length;
        options = can.concat(found.filter((p) => p.can_view === false)).slice(0, 8);
        active = 0;
        paintList();
    };
    const update = () => {
        const left = remaining(ta.value);
        counter.textContent = left < 200 ? (left < 0 ? `${-left} too many characters` : `${left} characters left`) : '';
        counter.classList.toggle('is-over', left < 0);
    };
    ta.addEventListener('input', () => { update(); detect(); error.replaceChildren(); });
    ta.addEventListener('click', detect);
    ta.addEventListener('blur', () => setTimeout(closeList, 120));
    ta.addEventListener('keydown', (e) => {
        if (!list.hidden && options.length) {
            if (e.key === 'ArrowDown' && enabled) { e.preventDefault(); active = (active + 1) % enabled; paintList(); return; }
            if (e.key === 'ArrowUp' && enabled) { e.preventDefault(); active = (active - 1 + enabled) % enabled; paintList(); return; }
            if ((e.key === 'Enter' || e.key === 'Tab') && enabled) { e.preventDefault(); choose(options[active]); return; }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeList(); return; }
        }
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); el.requestSubmit(); }
        if (e.key === 'Escape' && opts.onCancel) { e.preventDefault(); opts.onCancel(); }
        e.stopPropagation(); // typing here must never reach the canvas shortcuts (delete, arrows, tool keys)
    });
    el.addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = validateBody(ta.value);
        error.replaceChildren();
        if (!v.ok) { error.append(notice(v.message, 'bad')); ta.focus(); return; }
        submit.setBusy(true, 'Sending...');
        const r = await opts.onSubmit({ body: v.body, mentions: mentionIds(v.body, picked) });
        submit.setBusy(false);
        if (r && r.ok) { ta.value = ''; picked.length = 0; update(); return; }
        if (r && r.res) { const x = explain(r.res, { what: 'send your comment' }); error.append(notice(x.text, x.kind)); }
        ta.focus();
    });
    update();
    return { el, focus: () => ta.focus(), get value() { return ta.value; }, set value(v) { ta.value = v; update(); } };
}

/** The text of a comment with its @mentions picked out, as plain text nodes and <span>s. */
export function bodyNodes(comment, peopleById) {
    const names = (comment.mentions || []).map((id) => peopleById.get(id)).filter(Boolean).map((p) => p.name);
    return splitMentions(comment.body, names).map((seg) => (seg.mention ? h('span', { class: 'cb-at', text: seg.text }) : seg.text));
}

/**
 * One thread. `env` gives the thread what it needs without importing the controller:
 *   {people, peopleById, canComment, number, active, onReply(thread, payload), onResolve(thread, bool), onEdit(comment, payload),
 *    onDelete(comment), onFocus(thread), pageLabel(thread)}
 */
export function threadCard(thread, env) {
    const a = authorLabel(thread);
    const card = h('article', {
        class: `cb-thread${thread.resolved ? ' is-resolved' : ''}${env.active ? ' is-active' : ''}`, 'data-thread': thread.id,
        'aria-label': `Comment ${env.number || ''} by ${a.name}${thread.resolved ? ', resolved' : ''}`,
    });

    const head = (c, isTop) => {
        const who = authorLabel(c);
        return h('header', { class: 'cb-comment__head' },
            isTop && env.number ? h('span', { class: 'cb-badge', 'aria-hidden': 'true', text: String(env.number) }) : null,
            h('span', { class: 'cb-comment__who', text: who.name }),
            who.guest ? tag('Guest', 'neutral') : null,
            h('time', { class: 'cb-comment__time', datetime: c.created_at, title: dateTime(c.created_at), text: timeAgo(c.created_at) }),
            null);
    };

    const renderComment = (c, isTop) => {
        const wrap = h('div', { class: 'cb-comment', 'data-comment': c.id });
        const show = () => {
            wrap.replaceChildren(head(c, isTop), h('p', { class: 'cb-comment__body' }, bodyNodes(c, env.peopleById)));
            const tools = h('div', { class: 'cb-comment__tools' });
            if (c.can && c.can.edit) tools.append(button({ label: 'Edit', size: 'sm', variant: 'ghost', ariaLabel: `Edit the comment by ${authorLabel(c).name}`, onClick: edit }));
            if (c.can && c.can.delete) tools.append(button({ label: 'Delete', size: 'sm', variant: 'ghost', ariaLabel: `Delete the comment by ${authorLabel(c).name}`, onClick: () => env.onDelete(c) }));
            if (tools.children.length) wrap.append(tools);
        };
        const edit = () => {
            const picked = (c.mentions || []).map((id) => env.peopleById.get(id)).filter(Boolean).map((p) => ({ id: p.id, name: p.name }));
            const composer = createComposer({
                people: env.people, label: 'Edit your comment', submitLabel: 'Save', initial: c.body, initialPicked: picked,
                onSubmit: (payload) => env.onEdit(c, payload), onCancel: show,
            });
            wrap.replaceChildren(head(c, isTop), composer.el);
            composer.focus();
        };
        show();
        return wrap;
    };

    card.append(renderComment(thread, true));
    if (thread.replies && thread.replies.length) {
        card.append(h('div', { class: 'cb-replies' }, thread.replies.map((r) => renderComment(r, false))));
    }

    const actions = h('div', { class: 'cb-thread__actions' });
    if (env.pageLabel) actions.append(h('span', { class: 'cb-thread__where', text: env.pageLabel(thread) }));
    if (thread.can && thread.can.resolve) {
        actions.append(button({ label: thread.resolved ? 'Reopen' : 'Resolve', size: 'sm', variant: 'secondary', onClick: (e) => env.onResolve(thread, !thread.resolved, e.currentTarget) }));
    }
    if (env.canComment && !thread.resolved) {
        const replyBox = h('div', { class: 'cb-replybox', hidden: true });
        const toggle = button({ label: 'Reply', size: 'sm', variant: 'secondary', onClick: () => {
            replyBox.hidden = !replyBox.hidden;
            toggle.setAttribute('aria-expanded', String(!replyBox.hidden));
            if (!replyBox.hidden && !replyBox.firstChild) {
                const composer = createComposer({ people: env.people, label: `Reply to ${a.name}`, submitLabel: 'Reply', rows: 2, onSubmit: (p) => env.onReply(thread, p), onCancel: () => { replyBox.hidden = true; toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); } });
                replyBox.append(composer.el);
                composer.focus();
            } else if (!replyBox.hidden) replyBox.querySelector('textarea').focus();
        } });
        toggle.setAttribute('aria-expanded', 'false');
        actions.append(toggle);
        card.append(actions, replyBox);
    } else {
        card.append(actions);
    }
    if (env.onFocus) {
        card.addEventListener('click', (e) => { if (!e.target.closest('button, textarea, a, input')) env.onFocus(thread); });
    }
    return card;
}

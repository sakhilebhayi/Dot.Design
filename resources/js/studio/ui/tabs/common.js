import { h, icon, debounce } from '../dom.js';

export function searchBox({ label, placeholder, onInput, value = '' }) {
    const input = h('input', { class: 'lt-input st-search__input', attrs: { type: 'search', 'aria-label': label, placeholder, autocomplete: 'off', spellcheck: 'false' } });
    input.value = value;
    const fire = debounce(() => onInput(input.value.trim()), 220);
    input.addEventListener('input', fire);
    input.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter') { fire.cancel(); onInput(input.value.trim()); } });
    return { el: h('div', { class: 'lt-input-wrap st-search' }, icon('search', 'lt-icon'), input), input };
}

export function chipRow(items, current, onPick, label) {
    const row = h('div', { class: 'st-chips', attrs: { role: 'group', 'aria-label': label } });
    const btns = items.map(([value, text]) => {
        const b = h('button', { class: 'lt-chip', attrs: { type: 'button', 'aria-pressed': String(value === current) }, text, onClick: () => { btns.forEach((x) => x.setAttribute('aria-pressed', String(x === b))); onPick(value); } });
        return b;
    });
    row.append(...btns);
    return row;
}

export function skeletonGrid(n = 6, aspect = '4 / 5') {
    const g = h('div', { class: 'st-cards', attrs: { 'aria-hidden': 'true' } });
    for (let i = 0; i < n; i += 1) g.appendChild(h('div', { class: 'st-card st-card--skeleton' }, h('div', { class: 'lt-skeleton st-card__frame', vars: { a: aspect } })));
    return g;
}

export function errorNote(message, onRetry) {
    return h('div', { class: 'st-notebox st-notebox--bad', attrs: { role: 'alert' } }, icon('circle-alert'), h('div', {}, h('p', { text: message }), onRetry ? h('button', { class: 'lt-btn lt-btn--secondary lt-btn--sm', attrs: { type: 'button' }, text: 'Try again', onClick: onRetry }) : null));
}

export function emptyState(title, text, actions) {
    return h('div', { class: 'st-emptystate' }, icon('registration', 'lt-reg'), h('h3', { class: 'st-emptystate__title', text: title }), h('p', { class: 'st-emptystate__text', text }), actions || null);
}

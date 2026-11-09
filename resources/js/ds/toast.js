// Toasts. Server flash messages are rendered into [data-lt-flash] elements and shown here; the same text stays in the DOM for no-script visitors.
const ICONS = { ok: 'circle-check', bad: 'circle-alert', warn: 'triangle-alert', info: 'info' };
function region() { return document.getElementById('lt-toasts'); }

export function toast(message, kind = 'info', ms) {
    const host = region(); if (!host) return null;
    const el = document.createElement('div');
    el.className = 'lt-toast' + (kind !== 'info' ? ' lt-toast--' + kind : '');
    const icon = host.querySelector('template[data-icon="' + (ICONS[kind] || 'info') + '"]');
    if (icon) el.appendChild(icon.content.cloneNode(true));
    const body = document.createElement('div'); body.className = 'lt-toast__body'; body.textContent = message; el.appendChild(body);
    const close = document.createElement('button'); close.type = 'button'; close.setAttribute('aria-label', 'Dismiss message');
    const x = host.querySelector('template[data-icon="x"]'); if (x) close.appendChild(x.content.cloneNode(true)); else close.textContent = 'Close';
    close.addEventListener('click', () => el.remove()); el.appendChild(close);
    host.appendChild(el);
    const life = ms === undefined ? (kind === 'bad' ? 0 : 6000) : ms;
    if (life > 0) setTimeout(() => el.remove(), life);
    return el;
}
window.dotToast = toast;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lt-flash]').forEach((n) => { toast(n.getAttribute('data-message') || n.textContent.trim(), n.getAttribute('data-kind') || 'info'); n.remove(); });
});

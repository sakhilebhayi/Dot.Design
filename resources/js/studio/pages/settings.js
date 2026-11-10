/*
 * Studio preferences. Every control saves the moment it changes, through the settings endpoint, and says so. If a save fails the
 * control goes back to what is stored and the page says why: it never shows a setting as saved when it was not.
 */
import { api, firstError, readBoot } from './brand-core.js';

const boot = readBoot('settings-boot');
const root = document.getElementById('settings-page');
const status = root.querySelector('[data-pref-status]');
let stored = { ...boot.settings };

function say(text, bad = false) {
    status.textContent = text;
    status.style.color = bad ? 'var(--lt-bad)' : '';
}

function paint() {
    for (const node of root.querySelectorAll('[data-pref]')) {
        const key = node.getAttribute('data-pref');
        if (node.type === 'checkbox') node.checked = !!stored[key];
        else node.setAttribute('aria-pressed', String(stored[key] === node.getAttribute('data-value')));
    }
}

async function save(key, value, control) {
    control.disabled = true;
    say('Saving');
    const res = await api('PUT', boot.url, { [key]: value });
    control.disabled = false;
    if (!res.ok) {
        say(`Not saved. ${firstError(res.errors) || res.message}`, true);
        paint();
        return;
    }
    stored = { ...stored, ...res.data.settings };
    paint();
    say('Saved.');
}

root.addEventListener('change', (e) => {
    const box = e.target.closest('input[data-pref]');
    if (box) save(box.getAttribute('data-pref'), box.checked, box);
});

root.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-pref]');
    if (!btn || btn.getAttribute('aria-pressed') === 'true') return;
    save(btn.getAttribute('data-pref'), btn.getAttribute('data-value'), btn);
});

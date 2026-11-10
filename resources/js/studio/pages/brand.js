/*
 * Brand Studio list page: loads the fonts the kit cards use (only when a card scrolls into view), and the two kit actions
 * that are not a page of their own: make a kit the default and delete one. Everything else is a normal link.
 */
import { api, confirmDialog, fontManager, fontStack, loadFonts, readBoot, toast, whenVisible } from './brand-core.js';

const boot = readBoot('brand-boot');
fontManager(boot.fonts || []);

const FLASH = 'brand-flash';

function flashAfterReload(message, kind) {
    try { sessionStorage.setItem(FLASH, JSON.stringify({ message, kind })); } catch (e) { /* storage can be blocked: the page still reloads */ }
}

function showFlash() {
    try {
        const raw = sessionStorage.getItem(FLASH);
        if (!raw) return;
        sessionStorage.removeItem(FLASH);
        const { message, kind } = JSON.parse(raw);
        toast(message, kind);
    } catch (e) { /* ignore */ }
}

function applyFonts(card) {
    const targets = Array.from(card.querySelectorAll('[data-font]'));
    const keys = targets.map((n) => n.getAttribute('data-font'));
    const names = (card.querySelector('[data-font-names]') || { getAttribute: () => '' }).getAttribute('data-font-names') || '';
    loadFonts([...keys, ...names.split('|')], () => {
        for (const n of targets) n.style.fontFamily = fontStack(n.getAttribute('data-font'));
    });
}

document.querySelectorAll('[data-kit-id]').forEach((card) => whenVisible(card, () => applyFonts(card)));

document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-kit-action]');
    if (!btn) return;
    const card = btn.closest('[data-kit-id]');
    const id = card.getAttribute('data-kit-id');
    const name = card.getAttribute('data-kit-name');

    if (btn.getAttribute('data-kit-action') === 'default') {
        const res = await api('POST', `${boot.urls.kits}/${id}/default`);
        if (!res.ok) { toast(res.message, 'bad'); return; }
        flashAfterReload(`${name} is now your default kit.`, 'ok');
        location.reload();
        return;
    }

    const ok = await confirmDialog({
        title: `Delete ${name}?`,
        body: 'Designs that used this kit keep their colours and fonts. They just stop being linked to it. This cannot be undone.',
        confirmLabel: 'Delete kit',
        danger: true,
    });
    if (!ok) return;
    const res = await api('DELETE', `${boot.urls.kits}/${id}`);
    if (!res.ok) { toast(res.message, 'bad'); return; }
    flashAfterReload(`${name} was deleted.`, 'ok');
    location.reload();
});

showFlash();

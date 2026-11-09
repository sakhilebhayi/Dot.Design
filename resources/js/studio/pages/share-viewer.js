/*
 * The public share viewer (R-45 collab). Runs on /s/{token}: no account, no session, no cookie.
 *
 * The server draws every page into the HTML (that is the whole viewer without script). This script upgrades it: the
 * engine's read-only viewer (same renderer as the editor) with zoom and pan, a page strip, light/dark, and, when the link
 * allows comments, a form for a guest comment that can point at a spot on the design.
 *
 * What a visitor sees of comments: only their own, this visit. There is deliberately no endpoint that lists a design's
 * comments to a link holder, so the team's internal conversation is never exposed through a link.
 */
import { createViewer } from '../engine/viewer.js';
import { pageToScreen, screenToPage } from '../collab/pins.js';
import { remaining, validateBody } from '../collab/comments-state.js';

const $ = (sel, root = document) => root.querySelector(sel);

function readDocument() {
    try {
        const el = document.getElementById('dot-share-document');
        return el ? JSON.parse(el.textContent) : null;
    } catch (e) {
        return null;
    }
}

function readJsonAttr(el, name, fallback) {
    try { return JSON.parse(el.getAttribute(name) || ''); } catch (e) { return fallback; }
}

function setupTheme() {
    const btn = $('[data-sv-theme]');
    if (!btn) return;
    const root = document.documentElement;
    const dark = () => (root.getAttribute('data-theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark';
    const paint = () => {
        const d = dark();
        btn.setAttribute('aria-pressed', String(d));
        $('[data-sv-theme-label]', btn).textContent = d ? 'Light' : 'Dark';
        btn.setAttribute('aria-label', d ? 'Switch to light mode' : 'Switch to dark mode');
    };
    btn.addEventListener('click', () => {
        const next = dark() ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('dot-design-theme', next); } catch (e) { /* storage blocked: the choice lasts for this visit */ }
        paint();
    });
    paint();
}

function boot() {
    setupTheme();
    const doc = readDocument();
    const stage = $('[data-sv-stage]');
    if (!doc || !stage || !Array.isArray(doc.pages) || !doc.pages.length) return; // the server-drawn pages stay

    const body = document.body;
    const fonts = readJsonAttr(body, 'data-fonts', []);
    const assets = Object.values(doc.assets || {});
    const pages = doc.pages.map((p) => ({
        id: p.id, name: p.name, width: p.width, height: p.height, background: p.background, elements: p.elements, duration_ms: p.duration_ms, transition: p.transition,
    }));

    const interactive = $('[data-sv-interactive]');
    interactive.hidden = false;
    document.documentElement.classList.add('js');

    let viewer;
    try {
        viewer = createViewer({ root: stage, pages, assets, fonts: { manifest: fonts } });
    } catch (e) {
        interactive.hidden = true; // the server-drawn pages are still there
        document.documentElement.classList.remove('js');
        return;
    }
    const commentAside = $('[data-sv-comment]');
    if (commentAside) commentAside.hidden = false;

    // ------------------------------------------------------------------ controls
    const label = $('[data-sv-pagelabel]');
    const zoomLabel = $('[data-sv-zoomlabel]');
    const prev = $('[data-sv-prev]');
    const next = $('[data-sv-next]');
    const strip = $('[data-sv-strip]');
    const thumbs = [];

    const syncPage = () => {
        label.textContent = `Page ${viewer.index + 1} of ${viewer.count}`;
        prev.disabled = viewer.index === 0;
        next.disabled = viewer.index >= viewer.count - 1;
        thumbs.forEach((t, i) => { if (i === viewer.index) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current'); });
        const cur = thumbs[viewer.index];
        if (cur && strip) strip.scrollTo({ left: Math.max(0, cur.offsetLeft - strip.clientWidth / 2 + cur.clientWidth / 2), behavior: 'auto' }); // the strip only; never scroll the page
        renderPins();
        renderMine();
    };
    const syncZoom = () => { zoomLabel.textContent = `${Math.round(viewer.zoom * 100)}%`; };

    prev.addEventListener('click', () => viewer.prev());
    next.addEventListener('click', () => viewer.next());
    $('[data-sv-zoomin]').addEventListener('click', () => viewer.zoomIn());
    $('[data-sv-zoomout]').addEventListener('click', () => viewer.zoomOut());
    $('[data-sv-fit]').addEventListener('click', () => viewer.fit());
    stage.addEventListener('keydown', (e) => {
        if (e.target.closest('input, textarea')) return;
        if (e.key === 'ArrowRight' || e.key === 'PageDown') { viewer.next(); e.preventDefault(); }
        else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { viewer.prev(); e.preventDefault(); }
        else if (e.key === '+' || e.key === '=') { viewer.zoomIn(); e.preventDefault(); }
        else if (e.key === '-') { viewer.zoomOut(); e.preventDefault(); }
        else if (e.key === '0') { viewer.fit(); e.preventDefault(); }
    });

    // Page strip: the thumbnails are copies of the server-drawn pages, so pictures show without another request.
    if (strip) {
        $('[data-sv-fallback]').querySelectorAll('figure').forEach((fig, i) => {
            const svg = fig.querySelector('svg');
            if (!svg) return;
            const clone = svg.cloneNode(true);
            clone.removeAttribute('width'); clone.removeAttribute('height');
            clone.setAttribute('aria-hidden', 'true'); clone.setAttribute('focusable', 'false');
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'sv-thumb';
            b.setAttribute('aria-label', `Go to page ${i + 1}`);
            const frame = document.createElement('span');
            frame.className = 'sv-thumb__frame';
            frame.append(clone);
            const cap = document.createElement('span');
            cap.textContent = String(i + 1);
            b.append(frame, cap);
            b.addEventListener('click', () => viewer.setPage(i));
            strip.append(b);
            thumbs.push(b);
        });
    }

    // ------------------------------------------------------------------ the visitor's own pins
    const pinLayer = document.createElement('div');
    pinLayer.className = 'sv-pins';
    pinLayer.setAttribute('data-sv-pinlayer', '');
    const pinsEl = document.createElement('div');
    pinLayer.append(pinsEl);
    stage.append(pinLayer);
    const mine = []; // {n, page, x, y, name, body}
    let draft = null; // {page, x, y}
    let placing = false;

    const currentView = () => {
        const s = stage.firstElementChild; // the viewer's moving stage: its box on screen gives the view
        const page = pages[viewer.index];
        const r = s.getBoundingClientRect();
        const rr = stage.getBoundingClientRect();
        return { zoom: r.width / page.width, x: r.left - rr.left, y: r.top - rr.top };
    };

    function renderPins() {
        const view = currentView();
        const frag = document.createDocumentFragment();
        const add = (pin, extra) => {
            const s = pageToScreen(view, pin.x, pin.y);
            const el = document.createElement(extra ? 'span' : 'button');
            el.className = `sv-pin${extra ? ' sv-pin--draft' : ''}`;
            el.style.transform = `translate(${s.x}px, ${s.y}px)`;
            if (!extra) { el.type = 'button'; el.textContent = String(pin.n); el.setAttribute('aria-label', `Your comment ${pin.n}`); el.addEventListener('pointerdown', (e) => e.stopPropagation()); }
            else { el.textContent = '+'; el.setAttribute('aria-hidden', 'true'); }
            frag.append(el);
        };
        mine.filter((m) => m.page === viewer.index && m.x !== null).forEach((m) => add(m));
        if (draft && draft.page === viewer.index) add(draft, true);
        pinsEl.replaceChildren(frag);
    }
    let raf = 0;
    const schedule = () => { if (!raf) raf = requestAnimationFrame(() => { raf = 0; syncZoom(); renderPins(); }); };
    viewer.on('viewport', schedule);
    viewer.on('page', syncPage);
    window.addEventListener('resize', schedule);

    pinLayer.addEventListener('pointerdown', (e) => {
        if (!placing || e.target !== pinLayer) return;
        e.preventDefault(); e.stopPropagation();
        const rr = stage.getBoundingClientRect();
        const p = screenToPage(currentView(), e.clientX - rr.left, e.clientY - rr.top);
        const page = pages[viewer.index];
        if (p.x < 0 || p.y < 0 || p.x > page.width || p.y > page.height) { setState('That is outside the page. Click on the design.'); return; }
        draft = { page: viewer.index, x: Math.round(p.x * 10) / 10, y: Math.round(p.y * 10) / 10 };
        setPlacing(false);
        setState('Pinned to page ' + (viewer.index + 1) + '. Write your comment and send it.');
        renderPins();
    });

    // ------------------------------------------------------------------ comment form
    const form = $('[data-share-comment-form]');
    const mineBox = $('[data-sv-mine]');
    const mineList = $('[data-sv-minelist]');
    const pinBtn = $('[data-sv-pin]');
    const pinState = $('[data-sv-pinstate]');
    function setState(text) { if (pinState) pinState.textContent = text || ''; }
    function setPlacing(on) {
        placing = on;
        pinLayer.classList.toggle('is-placing', on);
        if (pinBtn) { pinBtn.setAttribute('aria-pressed', String(on)); pinBtn.textContent = on ? 'Click the design' : (draft ? 'Move the pin' : 'Point to a spot on the design'); }
        if (on) setState('Click the exact spot. Press Escape to cancel.');
    }
    function renderMine() {
        if (!mineBox) return;
        const here = mine.filter((m) => m.page === viewer.index);
        mineBox.hidden = here.length === 0;
        mineList.replaceChildren(...here.map((m) => { const li = document.createElement('li'); const b = document.createElement('b'); b.textContent = `${m.n}. ${m.name}`; li.append(b, document.createTextNode(m.body)); return li; }));
    }

    if (form) {
        const nameEl = form.elements.guest_name;
        const bodyEl = form.elements.body;
        const status = $('[data-share-comment-status]', form);
        const count = $('[data-sv-count]', form);
        const nameErr = $('#sv-name-err');
        const bodyErr = $('#sv-body-err');
        const submit = $('button[type="submit"]', form);
        const say = (text, kind) => { status.textContent = text || ''; status.className = `sv-status${kind ? ` is-${kind}` : ''}`; };
        const fieldErr = (el, errEl, text) => { errEl.textContent = text || ''; errEl.hidden = !text; if (text) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid'); };
        const updateCount = () => { const left = remaining(bodyEl.value); count.textContent = left < 300 ? (left < 0 ? `${-left} characters too many` : `${left} characters left`) : ''; count.classList.toggle('is-over', left < 0); };
        bodyEl.addEventListener('input', () => { updateCount(); fieldErr(bodyEl, bodyErr, ''); });
        nameEl.addEventListener('input', () => fieldErr(nameEl, nameErr, ''));
        updateCount();

        if (pinBtn) {
            pinBtn.addEventListener('click', () => setPlacing(!placing));
            window.addEventListener('keydown', (e) => { if (e.key === 'Escape' && placing) { setPlacing(false); setState(''); } });
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            say('');
            const name = nameEl.value.trim();
            const v = validateBody(bodyEl.value);
            let bad = false;
            if (!name) { fieldErr(nameEl, nameErr, 'Tell us your name so the owner knows who you are.'); bad = true; }
            if (!v.ok) { fieldErr(bodyEl, bodyErr, v.message); bad = true; }
            if (bad) { (!name ? nameEl : bodyEl).focus(); return; }
            submit.disabled = true; submit.setAttribute('aria-busy', 'true');
            say('Sending...');
            const page = pages[viewer.index];
            const payload = { guest_name: name, body: v.body, website: form.elements.website.value, page_id: page.id };
            if (draft && draft.page === viewer.index) { payload.x = draft.x; payload.y = draft.y; }
            try {
                const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(payload), credentials: 'omit', referrerPolicy: 'no-referrer' });
                let data = {};
                try { data = await response.json(); } catch (e) { data = {}; }
                if (response.ok) {
                    mine.push({ n: mine.length + 1, page: viewer.index, x: payload.x ?? null, y: payload.y ?? null, name, body: v.body });
                    bodyEl.value = ''; updateCount(); draft = null; setState(''); setPlacing(false);
                    say('Thank you. Your comment was sent to the people who made this design.', 'ok');
                    renderPins(); renderMine();
                } else if (response.status === 404) {
                    commentAside.replaceChildren(Object.assign(document.createElement('p'), { className: 'sv-hint', textContent: 'This link is no longer available, so your comment was not sent.' }));
                } else if (response.status === 422) {
                    const errs = (data && data.errors) || {};
                    if (errs.guest_name) fieldErr(nameEl, nameErr, errs.guest_name[0]);
                    if (errs.body) fieldErr(bodyEl, bodyErr, errs.body[0]);
                    if (errs.page_id || errs.x || errs.y) say('That spot could not be used. Clear the pin and try again.', 'bad');
                    else if (!errs.guest_name && !errs.body) say((data && data.message) || 'Your comment could not be sent. Check what you wrote.', 'bad');
                } else if (response.status === 429) {
                    say((data && data.message) || 'You are commenting too quickly. Wait a minute and try again.', 'bad');
                } else {
                    say('Your comment could not be sent because of a problem on our side. Try again in a moment.', 'bad');
                }
            } catch (e) {
                say('Your comment could not be sent. Check your connection and try again.', 'bad');
            } finally {
                submit.disabled = false; submit.removeAttribute('aria-busy');
            }
        });
    }

    syncPage();
    syncZoom();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

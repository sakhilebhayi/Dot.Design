// Landing hero: choosing a format swaps in a real library template drawn at that format. The first drawing is already in the page (so it
// shows without script); the others are small SVG files fetched once, when asked for. Without script the chips are plain links.
const table = document.querySelector('[data-lp-table]');
if (table) {
    const sheet = table.querySelector('[data-lp-sheet]');
    const art = table.querySelector('[data-lp-art]');
    const caption = table.querySelector('[data-lp-caption]');
    const colors = table.querySelector('[data-lp-colors]');
    const cta = document.querySelector('[data-lp-cta]');
    const ctaText = document.querySelector('[data-lp-cta-text]');
    const chips = Array.from(table.querySelectorAll('[data-lp-format]'));
    const cache = new Map();

    // A drawing is parsed as SVG and adopted, never injected as markup.
    const load = async (url) => {
        if (cache.has(url)) return cache.get(url);
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const doc = new DOMParser().parseFromString(await res.text(), 'image/svg+xml');
        const svg = doc.documentElement;
        if (!svg || svg.localName !== 'svg' || doc.querySelector('parsererror')) throw new Error('not an svg');
        cache.set(url, svg);
        return svg;
    };

    const setColors = (list) => {
        colors.replaceChildren(...list.map((hex) => { const li = document.createElement('li'); li.setAttribute('role', 'img'); li.setAttribute('aria-label', hex); li.style.setProperty('--c', hex); li.title = hex; return li; }));
    };

    let token = 0;
    const choose = async (chip) => {
        const mine = (token += 1);
        chips.forEach((c) => c.setAttribute('aria-current', String(c === chip)));
        if (cta) cta.setAttribute('href', chip.dataset.href);
        if (ctaText) ctaText.textContent = chip.dataset.name;
        let svg = null;
        if (chip.dataset.art) { try { svg = await load(chip.dataset.art); } catch (e) { svg = null; } }
        if (mine !== token) return;
        sheet.style.setProperty('--w', chip.dataset.w);
        sheet.style.setProperty('--h', chip.dataset.h);
        sheet.classList.toggle('lt-frame--blank', !svg);
        if (svg) { const node = document.importNode(svg, true); node.setAttribute('aria-hidden', 'true'); node.setAttribute('focusable', 'false'); art.replaceChildren(node); } else art.replaceChildren();
        art.setAttribute('aria-label', chip.dataset.artName ? `${chip.dataset.artName}, a library template` : chip.dataset.name);
        caption.replaceChildren();
        const b = document.createElement('b');
        b.textContent = chip.dataset.artName || chip.dataset.name;
        caption.append(b, chip.dataset.artName ? `, a ready-made template, drawn by the same code the editor uses at ${chip.dataset.size}.` : '');
        setColors(chip.dataset.colors ? chip.dataset.colors.split(' ') : []);
        sheet.classList.remove('is-swap'); void sheet.offsetWidth; sheet.classList.add('is-swap');
    };
    chips.forEach((chip) => chip.addEventListener('click', (e) => { e.preventDefault(); choose(chip); }));
}

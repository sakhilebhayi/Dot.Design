// Landing hero: choosing a format redraws the proof frame at that format's true proportions. Without script the chips are plain links.
const table = document.querySelector('[data-lp-table]');
if (table) {
    const sheet = table.querySelector('[data-lp-sheet]');
    const name = table.querySelector('[data-lp-name]');
    const size = table.querySelector('[data-lp-size]');
    const cta = document.querySelector('[data-lp-cta]');
    const ctaText = document.querySelector('[data-lp-cta-text]');
    const chips = Array.from(table.querySelectorAll('[data-lp-format]'));

    const choose = (chip) => {
        chips.forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
        sheet.style.setProperty('--w', chip.dataset.w);
        sheet.style.setProperty('--h', chip.dataset.h);
        name.textContent = chip.dataset.name;
        size.textContent = chip.dataset.size;
        if (cta) cta.setAttribute('href', chip.dataset.href);
        if (ctaText) ctaText.textContent = chip.dataset.name;
        sheet.classList.remove('is-swap'); void sheet.offsetWidth; sheet.classList.add('is-swap');
    };
    chips.forEach((chip) => chip.addEventListener('click', (e) => { e.preventDefault(); choose(chip); }));
}

/*
 * Text tab: heading, subheading and body presets sized to the page (in the brand
 * fonts when a kit exists), curated font pairings, and brand text styles.
 */
import { find } from '../../doc/tree.js';
import { activePage } from '../describe.js';
import { h, icon } from '../dom.js';
import { draggable, insert } from '../insert.js';
import { PAIRINGS, fontLabel } from '../quick.js';
import { textPreset } from '../stage.js';

export function mountText(el, ctx) {
    const st = ctx.studio;
    const fonts = ctx.config.fonts || [];
    const has = (k) => fonts.some((f) => f.key === k);
    const stack = (k) => st.fonts.stackFor(k);

    const presetBtn = (kind, label, sample, cls) => {
        const b = h('button', { class: ['st-preset', cls], attrs: { type: 'button', 'aria-label': 'Add ' + label.toLowerCase() }, onClick: () => insert(ctx, { kind: 'text', props: textPreset(ctx, kind) }) }, h('span', { class: 'st-preset__sample', text: sample }), h('span', { class: 'st-preset__label', text: label }));
        draggable(b, () => ({ kind: 'text', props: { ...textPreset(ctx, kind), name: label }, edit: false }));
        return b;
    };
    const paintFonts = () => {
        const kit = ctx.brand; const hf = kit && kit.fonts && kit.fonts.heading; const bf = kit && kit.fonts && kit.fonts.body;
        for (const [sel, key] of [['.st-preset--h', hf], ['.st-preset--s', bf], ['.st-preset--b', bf]]) { const n = el.querySelector(sel + ' .st-preset__sample'); if (n) n.style.fontFamily = stack(key || 'inter'); }
        const ids = [hf, bf].filter(Boolean); if (ids.length) st.fonts.ensureLoaded(ids.map((k) => ({ font: k, weight: 400, italic: false })).concat(ids.map((k) => ({ font: k, weight: 700, italic: false })))).catch(() => {});
    };
    const presets = h('div', { class: 'st-presets' },
        presetBtn('heading', 'Heading', 'Add a heading', 'st-preset--h'),
        presetBtn('subheading', 'Subheading', 'Add a subheading', 'st-preset--s'),
        presetBtn('body', 'Body text', 'Add a little bit of body text', 'st-preset--b'));
    const brandNote = h('p', { class: 'st-note' });
    const paintNote = () => { const kit = ctx.brand; brandNote.textContent = kit && kit.fonts && kit.fonts.heading ? `Using the fonts from ${kit.name}: ${fontLabel(ctx, kit.fonts.heading)} for headings and ${fontLabel(ctx, kit.fonts.body)} for text.` : 'No brand fonts yet, so these use Inter. Pick a pairing below, or set up your brand.'; };
    paintNote(); paintFonts();
    ctx.on('brand', () => { paintNote(); paintFonts(); });

    async function addPair(hk, bk) {
        // measure with the real glyphs, so the body text lands under the heading's final height
        try { await st.fonts.ensureLoaded([{ font: hk, weight: 700, italic: false }, { font: bk, weight: 400, italic: false }]); } catch (e) { /* the fallback stack still measures */ }
        const page = activePage(st.getState());
        const hp = textPreset(ctx, 'heading'); const bp = textPreset(ctx, 'body');
        const hr = ctx.run('element.add', { type: 'text', ...hp, font: hk, tokens: undefined, fontWeight: 700, width: Math.round(page.width * 0.8), x: Math.round(page.width * 0.1), y: Math.round(page.height * 0.28), autoFit: 'grow', text: 'A heading with presence' });
        if (!hr.ok) return;
        const hid = hr.result.id;
        const hit = find(activePage(st.getState()).elements, hid);
        const y = Math.round((hit ? hit.element.y + hit.element.height : page.height * 0.4) + page.height * 0.03);
        ctx.run('element.add', { type: 'text', ...bp, font: bk, tokens: undefined, width: Math.round(page.width * 0.8), x: Math.round(page.width * 0.1), y, autoFit: 'grow', text: 'Support it with a few clear lines of body text that are easy to read.' });
    }

    const pairs = h('div', { class: 'st-pairs' });
    for (const [hk, bk, name] of PAIRINGS) {
        if (!has(hk) || !has(bk)) continue;
        const a = h('span', { class: 'st-pair__h', text: fontLabel(ctx, hk) }); a.style.fontFamily = stack(hk);
        const b = h('span', { class: 'st-pair__b', text: fontLabel(ctx, bk) + ' for text' }); b.style.fontFamily = stack(bk);
        pairs.appendChild(h('button', { class: 'st-pair', attrs: { type: 'button', 'aria-label': `Add the pairing ${name}: ${fontLabel(ctx, hk)} with ${fontLabel(ctx, bk)}` }, onClick: () => addPair(hk, bk) }, a, b, h('span', { class: 'st-pair__name', text: name })));
    }
    st.fonts.ensureLoaded(PAIRINGS.flatMap(([a, b]) => [a, b]).filter(has).map((k) => ({ font: k, weight: 400, italic: false }))).catch(() => {});

    const addBox = h('button', { class: 'lt-btn lt-btn--secondary lt-btn--block', attrs: { type: 'button' }, onClick: () => insert(ctx, { kind: 'text', props: { ...textPreset(ctx, 'body'), text: 'Type here', name: 'Text' } }) }, icon('text'), 'Add a text box');

    const styles = () => (ctx.brand && ctx.brand.type_styles) || [];
    const styleBox = h('section', { class: 'st-group' });
    const paintStyles = () => {
        const list = styles();
        styleBox.hidden = !list.length;
        styleBox.replaceChildren(h('h3', { class: 'st-group__title', text: 'Your brand text styles' }), ...list.map((s) => h('button', { class: 'st-preset', attrs: { type: 'button' }, onClick: () => {
            const kit = ctx.brand; const font = kit.fonts[s.font];
            const base = textPreset(ctx, s.font === 'heading' ? 'heading' : 'body');
            insert(ctx, { kind: 'text', props: { ...base, name: s.label || s.key, text: s.label || 'Text', ...(font ? { font, tokens: { ...(base.tokens || {}), font: 'brand.fonts.' + s.font } } : {}), ...(s.size ? { fontSize: s.size } : {}), ...(s.weight ? { fontWeight: s.weight } : {}), ...(s.line_height ? { lineHeight: s.line_height } : {}), ...(['upper', 'lower', 'title'].includes(s.case) ? { textCase: s.case } : {}) } });
        } }, h('span', { class: 'st-preset__sample', text: s.label || s.key }), h('span', { class: 'st-preset__label', text: `${s.size || ''} px` }))));
    };
    paintStyles(); ctx.on('brand', paintStyles);

    el.append(h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Add text' }), presets, addBox, brandNote),
        h('section', { class: 'st-group' }, h('h3', { class: 'st-group__title', text: 'Font pairings' }), h('p', { class: 'st-note', text: 'A heading font and a text font that look good together. Click to add both.' }), pairs), styleBox);
}

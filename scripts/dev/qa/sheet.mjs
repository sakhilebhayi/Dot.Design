// Dev-only contact sheet drawn by the REAL engine renderer. Query: ?data=<file in /data>&h=<page height px>&placeholders=0|1|preview
//   &only=a,b (slugs) &page=2 (only that page index, 1-based) &w=<max width>
import './harness.mjs';

const q = new URLSearchParams(location.search);
const data = await (await fetch('/data/' + (q.get('data') || 'templates.json'))).json();
const H = Number(q.get('h') || 520);
const MAXW = Number(q.get('w') || 760);
const only = (q.get('only') || '').split(',').filter(Boolean);
const pageOnly = Number(q.get('page') || 0);
const placeholders = q.get('placeholders') !== '0' && q.get('placeholders') !== 'preview';
const placeholderStyle = q.get('placeholders') === 'preview' ? 'preview' : undefined;
const grid = document.getElementById('grid');
const list = data.filter((t) => !only.length || only.includes(t.slug) || only.some((o) => t.slug.includes(o)));
await window.QA.ensure(list.flatMap((t) => t.pages));
for (const tpl of list) {
    tpl.pages.forEach((page, i) => {
        if (pageOnly && pageOnly !== i + 1) return;
        const fig = document.createElement('figure');
        const w = page.width ?? tpl.width; const h = page.height ?? tpl.height;
        const scale = Math.min(H / h, MAXW / w);
        const cap = document.createElement('figcaption');
        cap.textContent = `${tpl.slug}${tpl.pages.length > 1 ? ` p${i + 1}` : ''}${tpl.label ? ' ' + tpl.label : ''}`;
        cap.style.setProperty('--cap', Math.round(w * scale) + 'px');
        fig.append(cap);
        const holder = document.createElement('div');
        holder.innerHTML = window.QA.svg(page, { width: tpl.width, height: tpl.height }, { placeholders, placeholderStyle });
        const svg = holder.firstElementChild;
        svg.setAttribute('width', Math.round(w * scale)); svg.setAttribute('height', Math.round(h * scale));
        svg.dataset.slug = tpl.slug;
        fig.append(svg);
        grid.append(fig);
    });
}
document.title = 'READY ' + grid.children.length;
window.SHEET_READY = true;

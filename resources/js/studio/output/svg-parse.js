/*
 * A small XML reader for the SVG our own renderer writes (well formed, no DTD, no CDATA, no namespaces beyond xml: and xlink:).
 * It is not a general XML parser and refuses what it does not understand instead of guessing.
 *
 *   parseSvg(text) -> {tag, attrs: {name: value}, children: [node | string]}
 */
const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };

export function decodeEntities(s) {
    return String(s).replace(/&(#x[0-9a-fA-F]+|#\d+|[a-z]+);/g, (all, e) => {
        if (e[0] === '#') { const cp = e[1] === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10); return Number.isFinite(cp) && cp > 0 && cp <= 0x10ffff ? String.fromCodePoint(cp) : ''; }
        return ENTITIES[e] !== undefined ? ENTITIES[e] : all;
    });
}

const ATTR = /([A-Za-z_:][A-Za-z0-9_:.-]*)\s*=\s*"([^"]*)"/g;

export function parseSvg(text) {
    const src = String(text);
    if (/<!DOCTYPE|<!ENTITY|<!\[CDATA\[/i.test(src)) throw new Error('The SVG uses markup the PDF writer does not read.');
    const root = { tag: '#root', attrs: {}, children: [] };
    const stack = [root];
    let i = 0;
    while (i < src.length) {
        const lt = src.indexOf('<', i);
        if (lt < 0) break;
        if (lt > i) {
            const t = src.slice(i, lt);
            if (t.length) stack[stack.length - 1].children.push(decodeEntities(t));
        }
        if (src.startsWith('<?', lt)) { i = src.indexOf('?>', lt) + 2; continue; }
        if (src.startsWith('<!--', lt)) { i = src.indexOf('-->', lt) + 3; continue; }
        const gt = src.indexOf('>', lt);
        if (gt < 0) throw new Error('Unterminated tag.');
        const inner = src.slice(lt + 1, gt);
        if (inner[0] === '/') {
            stack.pop();
            if (!stack.length) throw new Error('Unbalanced tags.');
        } else {
            const selfClose = inner.endsWith('/');
            const body = selfClose ? inner.slice(0, -1) : inner;
            const m = /^([A-Za-z][A-Za-z0-9:_-]*)/.exec(body);
            if (!m) throw new Error('Bad tag.');
            const attrs = {};
            ATTR.lastIndex = 0;
            let a;
            const rest = body.slice(m[0].length);
            while ((a = ATTR.exec(rest))) attrs[a[1]] = decodeEntities(a[2]);
            const node = { tag: m[1], attrs, children: [] };
            stack[stack.length - 1].children.push(node);
            if (!selfClose) stack.push(node);
        }
        i = gt + 1;
    }
    if (stack.length !== 1) throw new Error('Unbalanced tags.');
    const svg = root.children.find((c) => typeof c !== 'string' && c.tag === 'svg');
    if (!svg) throw new Error('No <svg> element.');
    return svg;
}

export function walk(node, fn) {
    if (typeof node === 'string') return;
    fn(node);
    for (const c of node.children) walk(c, fn);
}

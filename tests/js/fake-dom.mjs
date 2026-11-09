// A tiny DOM just big enough for the live renderer, with mutation counters so tests can
// prove that only changed elements are touched.
export class FakeNode {
    constructor(tag, doc) {
        this.tag = tag; this.doc = doc; this.attrs = new Map(); this.childNodes = []; this.parentNode = null;
        this.style = { cssText: '' }; this.ops = 0; this.nodeType = 1;
    }
    setAttribute(k, v) { this.ops += 1; this.attrs.set(k, String(v)); }
    setAttributeNS(ns, k, v) { this.ops += 1; this.attrs.set(k, String(v)); }
    getAttribute(k) { return this.attrs.has(k) ? this.attrs.get(k) : null; }
    hasAttribute(k) { return this.attrs.has(k); }
    removeAttribute(k) { if (this.attrs.has(k)) { this.ops += 1; this.attrs.delete(k); } }
    removeAttributeNS(ns, k) { this.removeAttribute('xml:' + k); }
    get firstChild() { return this.childNodes[0] || null; }
    appendChild(n) { this.ops += 1; if (n.parentNode) n.parentNode.removeChild(n); n.parentNode = this; this.childNodes.push(n); return n; }
    insertBefore(n, ref) {
        this.ops += 1;
        if (n.parentNode) n.parentNode.removeChild(n);
        n.parentNode = this;
        const i = ref ? this.childNodes.indexOf(ref) : -1;
        if (i < 0) this.childNodes.push(n); else this.childNodes.splice(i, 0, n);
        return n;
    }
    removeChild(n) { this.ops += 1; const i = this.childNodes.indexOf(n); if (i >= 0) this.childNodes.splice(i, 1); n.parentNode = null; return n; }
    set textContent(t) { this.childNodes = [{ nodeType: 3, text: String(t), parentNode: this }]; }
    get textContent() { return this.childNodes.map((c) => (c.nodeType === 3 ? c.text : c.textContent)).join(''); }
    find(pred) { if (pred(this)) return this; for (const c of this.childNodes) { if (c.find) { const r = c.find(pred); if (r) return r; } } return null; }
    all(pred, out = []) { if (pred(this)) out.push(this); for (const c of this.childNodes) if (c.all) c.all(pred, out); return out; }
    totalOps() { return this.ops + this.childNodes.reduce((s, c) => s + (c.totalOps ? c.totalOps() : 0), 0); }
}

export class FakeDocument {
    createElementNS(ns, tag) { return new FakeNode(tag, this); }
    createElement(tag) { return new FakeNode(tag, this); }
    createTextNode(t) { return { nodeType: 3, text: String(t), parentNode: null }; }
}

export function fakeSvg() {
    const doc = new FakeDocument();
    const svg = new FakeNode('svg', doc);
    svg.ownerDocument = doc;
    return { doc, svg };
}

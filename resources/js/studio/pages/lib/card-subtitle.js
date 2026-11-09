// The small line under a template card: kind and size without saying the same words twice.
// "Instagram post" + "Instagram post (portrait)" reads "Instagram post, portrait"; "Menu" + "Menu (A4)" reads "Menu, A4".
export function cardSubtitle(kind, format) {
    const k = (kind || '').trim();
    const f = (format || '').trim();
    if (!k) return f;
    if (!f) return k;
    if (f.toLowerCase() === k.toLowerCase()) return k;
    if (f.toLowerCase().startsWith(k.toLowerCase())) {
        let rest = f.slice(k.length).trim();
        const m = rest.match(/^\((.*)\)$/);
        if (m) rest = m[1].trim();
        return rest ? `${k}, ${rest}` : k;
    }
    return `${k}, ${f}`;
}

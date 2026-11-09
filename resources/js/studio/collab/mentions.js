/*
 * @mentions: finding the word being typed, filtering people, and turning a body of text plus the people picked into the
 * ids the server wants. Names only; no address is ever involved. A mention in a saved comment is rendered from the text
 * itself (split into plain and mention segments), so no markup is ever produced.
 */

/** The "@par" being typed just before the caret, or null. Mentions start at a word boundary and may not span a newline. */
export function activeMention(text, caret) {
    const before = String(text).slice(0, caret);
    const at = before.lastIndexOf('@');
    if (at < 0) return null;
    if (at > 0 && !/\s/.test(before[at - 1])) return null;
    const query = before.slice(at + 1);
    if (/[\n@]/.test(query) || query.length > 40) return null;
    return { start: at, query };
}

/** People whose name contains every typed word, names starting with the query first. Never returns more than `limit`. */
export function filterPeople(people, query, limit = 6) {
    const words = String(query).toLowerCase().split(/\s+/).filter(Boolean);
    const scored = [];
    for (const p of people) {
        const name = String(p.name || '').toLowerCase();
        if (words.every((w) => name.includes(w))) {
            scored.push({ p, rank: words.length && name.startsWith(words[0]) ? 0 : 1 });
        }
    }
    scored.sort((a, b) => a.rank - b.rank || String(a.p.name).localeCompare(String(b.p.name)));
    return scored.slice(0, limit).map((s) => s.p);
}

/** Replace the "@query" being typed with "@Name " and report the new caret position. */
export function insertMention(text, mention, person) {
    const caret = mention.start + 1 + mention.query.length;
    const insert = `@${person.name} `;
    return { text: text.slice(0, mention.start) + insert + text.slice(caret), caret: mention.start + insert.length };
}

/** The ids of picked people whose "@Name" is still in the text (deleting the words un-mentions them). */
export function mentionIds(text, picked) {
    const out = [];
    for (const p of picked) {
        if (text.includes(`@${p.name}`) && !out.includes(p.id)) out.push(p.id);
    }
    return out;
}

/** Split a saved body into [{text}, {text, mention:true}] using the names of the people it mentions. */
export function splitMentions(body, names) {
    const text = String(body);
    const hits = [];
    for (const name of names) {
        if (!name) continue;
        const needle = `@${name}`;
        let from = 0;
        for (;;) {
            const i = text.indexOf(needle, from);
            if (i < 0) break;
            hits.push([i, i + needle.length]);
            from = i + needle.length;
        }
    }
    hits.sort((a, b) => a[0] - b[0] || b[1] - a[1]);
    const out = [];
    let cursor = 0;
    for (const [s, e] of hits) {
        if (s < cursor) continue;
        if (s > cursor) out.push({ text: text.slice(cursor, s) });
        out.push({ text: text.slice(s, e), mention: true });
        cursor = e;
    }
    if (cursor < text.length) out.push({ text: text.slice(cursor) });
    return out.length ? out : [{ text }];
}

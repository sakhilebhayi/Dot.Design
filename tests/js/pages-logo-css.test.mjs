import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

// A light and a dark logo variant sit side by side in the markup; CSS must show exactly one per theme. A later, more specific rule once
// set `display: block` on both images and showed them together, so only vocab.css may decide display on them.
const root = new URL('../../resources/css/', import.meta.url).pathname;
const files = ['ds', 'pages'].flatMap((d) => readdirSync(join(root, d)).filter((f) => f.endsWith('.css')).map((f) => [f, readFileSync(join(root, d, f), 'utf8')]));

test('only vocab.css sets display on a logo variant', () => {
    for (const [name, css] of files) {
        if (name === 'vocab.css') continue;
        for (const rule of css.split('}')) {
            const [sel, body = ''] = rule.split('{');
            if (/\.lt-logo(__light|__dark)?\b[^,]*\bimg\b|\.lt-logo__(light|dark)/.test(sel) && /\bdisplay\s*:/.test(body)) assert.fail(`${name}: ${sel.trim()} sets display`);
        }
    }
});

test('vocab.css hides the dark variant by default and swaps them for the dark theme and the system setting', () => {
    const vocab = files.find(([n]) => n === 'vocab.css')[1];
    assert.match(vocab, /\.lt-logo \.lt-logo__dark \{ display: none; \}/);
    assert.match(vocab, /:root\[data-theme="dark"\] \.lt-logo \.lt-logo__light \{ display: none; \}/);
    assert.match(vocab, /:root\[data-theme="dark"\] \.lt-logo \.lt-logo__dark \{ display: block; \}/);
    assert.match(vocab, /prefers-color-scheme: dark[\s\S]*\.lt-logo__light \{ display: none; \}[\s\S]*\.lt-logo__dark \{ display: block; \}/);
});

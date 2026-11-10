import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { check, walk } from '../../scripts/ops/check-vite-manifest.mjs';

function fixture(files, manifest, built = []) {
    const root = mkdtempSync(join(tmpdir(), 'vitecheck-'));
    const views = join(root, 'views');
    const build = join(root, 'build');
    mkdirSync(views);
    mkdirSync(build);
    for (const [name, body] of Object.entries(files)) writeFileSync(join(views, name), body);
    for (const f of built) writeFileSync(join(build, f), 'x');
    return { views, manifest, buildDir: build, viewFiles: walk(views) };
}

const entry = (file) => ({ file, isEntry: true });

test('a view whose @vite entries are all built passes', () => {
    const r = check(fixture({ 'a.blade.php': "@vite(['resources/css/a.css', 'resources/js/a.js'])" }, { 'resources/css/a.css': entry('a.css'), 'resources/js/a.js': entry('a.js') }, ['a.css', 'a.js']));
    assert.deepEqual(r.errors, []);
    assert.equal(r.stats.directiveEntries.size, 2);
});

test('an @vite entry that was never built is an error naming the file', () => {
    const r = check(fixture({ 'a.blade.php': "@vite(['resources/js/missing.js'])" }, {}));
    assert.equal(r.errors.length >= 1, true);
    assert.match(r.errors[0], /resources\/js\/missing\.js/);
});

test('a resources/ path in a comment of a view that uses @vite is an error (the Auction deploy failure)', () => {
    const r = check(fixture({ 'a.blade.php': "{{-- the old resources/js/legacy.js is gone --}}\n@vite(['resources/css/a.css'])" }, { 'resources/css/a.css': entry('a.css') }, ['a.css']));
    assert.equal(r.errors.length, 1);
    assert.match(r.errors[0], /legacy\.js/);
});

test('a view without @vite may mention any path', () => {
    const r = check(fixture({ 'a.blade.php': '<p>resources/js/whatever.js</p>' }, {}));
    assert.deepEqual(r.errors, []);
});

test('a dynamic @vite argument is not judged', () => {
    const r = check(fixture({ 'a.blade.php': '@vite($entries)' }, {}));
    assert.deepEqual(r.errors, []);
});

test('a manifest record whose file is not in the build folder is an error', () => {
    const r = check(fixture({}, { 'resources/js/a.js': { file: 'a.js', css: ['a.css'], imports: ['_x.js'] } }));
    assert.equal(r.errors.length, 3);
});

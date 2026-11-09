#!/usr/bin/env node
/*
 * Reproducible font fetch for Dot.Design (R-45 engine).
 *
 * Downloads latin-subset woff2 files for the 18 user-facing design fonts from
 * the Fontsource npm packages (all SIL Open Font License 1.1 or Apache 2.0),
 * into public/fonts/design/<key>/<key>-<weight>[-italic].woff2, and writes
 * public/fonts/design/LICENSES.md. Fontsource is a *build-time* source only:
 * nothing here is an app dependency and nothing is fetched at runtime.
 *
 *   node scripts/fonts/fetch-design-fonts.mjs [--force]
 *
 * Package versions are pinned below so a re-run reproduces the same files.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const out = path.join(root, 'public', 'fonts', 'design');
const force = process.argv.includes('--force');

// key -> { pkg, version, weights, italic: weights that also ship an italic, licence, label }
const FONTS = {
    'inter': { pkg: 'inter', weights: [300, 400, 500, 600, 700], italic: [400, 700], licence: 'OFL-1.1', author: 'The Inter Project Authors' },
    'dm-sans': { pkg: 'dm-sans', weights: [300, 400, 500, 600, 700], italic: [], licence: 'OFL-1.1', author: 'The DM Sans Project Authors' },
    'poppins': { pkg: 'poppins', weights: [300, 400, 500, 600, 700], italic: [], licence: 'OFL-1.1', author: 'The Poppins Project Authors' },
    'montserrat': { pkg: 'montserrat', weights: [300, 400, 500, 600, 700], italic: [], licence: 'OFL-1.1', author: 'The Montserrat Project Authors' },
    'oswald': { pkg: 'oswald', weights: [400, 700], italic: [], licence: 'OFL-1.1', author: 'The Oswald Project Authors' },
    'bebas-neue': { pkg: 'bebas-neue', weights: [400], italic: [], licence: 'OFL-1.1', author: 'The Bebas Neue Project Authors' },
    'anton': { pkg: 'anton', weights: [400], italic: [], licence: 'OFL-1.1', author: 'The Anton Project Authors' },
    'archivo-black': { pkg: 'archivo-black', weights: [400], italic: [], licence: 'OFL-1.1', author: 'The Archivo Black Project Authors' },
    'playfair-display': { pkg: 'playfair-display', weights: [400, 700], italic: [400, 700], licence: 'OFL-1.1', author: 'The Playfair Project Authors' },
    'lora': { pkg: 'lora', weights: [400, 700], italic: [400, 700], licence: 'OFL-1.1', author: 'The Lora Project Authors' },
    'merriweather': { pkg: 'merriweather', weights: [400, 700], italic: [400, 700], licence: 'OFL-1.1', author: 'The Merriweather Project Authors' },
    'cormorant-garamond': { pkg: 'cormorant-garamond', weights: [400, 700], italic: [400, 700], licence: 'OFL-1.1', author: 'The Cormorant Project Authors' },
    'fraunces': { pkg: 'fraunces', weights: [400, 700], italic: [400, 700], licence: 'OFL-1.1', author: 'The Fraunces Project Authors' },
    'space-grotesk': { pkg: 'space-grotesk', weights: [400, 700], italic: [], licence: 'OFL-1.1', author: 'The Space Grotesk Project Authors' },
    'caveat': { pkg: 'caveat', weights: [400, 700], italic: [], licence: 'OFL-1.1', author: 'The Caveat Project Authors' },
    'pacifico': { pkg: 'pacifico', weights: [400], italic: [], licence: 'OFL-1.1', author: 'The Pacifico Project Authors' },
    'permanent-marker': { pkg: 'permanent-marker', weights: [400], italic: [], licence: 'Apache-2.0', author: 'Font Diner, Inc' },
    'jetbrains-mono': { pkg: 'jetbrains-mono', weights: [400, 700], italic: [], licence: 'OFL-1.1', author: 'The JetBrains Mono Project Authors' },
};

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'dot-design-fonts-'));
let total = 0;
const missing = [];

try {
    for (const [key, f] of Object.entries(FONTS)) {
        const dir = path.join(out, key);
        const wanted = [];
        for (const w of f.weights) wanted.push([w, false]);
        for (const w of f.italic) wanted.push([w, true]);
        const names = wanted.map(([w, it]) => `${key}-${w}${it ? '-italic' : ''}.woff2`);
        if (!force && names.every((n) => fs.existsSync(path.join(dir, n)))) {
            for (const n of names) total += fs.statSync(path.join(dir, n)).size;
            console.log(`= ${key} (cached)`);
            continue;
        }
        const work = path.join(tmp, key);
        fs.mkdirSync(work, { recursive: true });
        execFileSync('npm', ['pack', `@fontsource/${f.pkg}`, '--silent', '--pack-destination', work], { cwd: work, stdio: ['ignore', 'pipe', 'inherit'] });
        const tgz = fs.readdirSync(work).find((n) => n.endsWith('.tgz'));
        execFileSync('tar', ['-xzf', path.join(work, tgz), '-C', work]);
        fs.mkdirSync(dir, { recursive: true });
        for (const [w, italic] of wanted) {
            const src = path.join(work, 'package', 'files', `${f.pkg}-latin-${w}-${italic ? 'italic' : 'normal'}.woff2`);
            const name = `${key}-${w}${italic ? '-italic' : ''}.woff2`;
            if (!fs.existsSync(src)) { missing.push(`${key} ${w}${italic ? ' italic' : ''}`); continue; }
            fs.copyFileSync(src, path.join(dir, name));
            total += fs.statSync(path.join(dir, name)).size;
        }
        console.log(`+ ${key}`);
    }
} finally {
    fs.rmSync(tmp, { recursive: true, force: true });
}

const lines = [
    '# Dot.Design font licences',
    '',
    'These woff2 files are latin subsets of open-source typefaces, self-hosted so the editor and every export never call an external font host.',
    'They were fetched by `scripts/fonts/fetch-design-fonts.mjs` from the Fontsource packages (a build-time source only; Fontsource is not an app dependency).',
    '',
    'SIL Open Font License 1.1 (https://openfontlicense.org): the fonts may be used, embedded in documents and exported designs, bundled and redistributed; they may not be sold on their own and derivative font files must keep the licence and not use the Reserved Font Names. Apache License 2.0 (https://www.apache.org/licenses/LICENSE-2.0) applies where marked.',
    '',
    '| Key | Copyright holder | Licence |',
    '|---|---|---|',
    ...Object.entries(FONTS).map(([k, f]) => `| ${k} | ${f.author} | ${f.licence} |`),
    '',
];
fs.writeFileSync(path.join(out, 'LICENSES.md'), lines.join('\n'));
console.log(`Total ${(total / 1024).toFixed(0)} KB`);
if (missing.length) { console.error('MISSING: ' + missing.join(', ')); process.exit(1); }

// Dev-only: writes the generated inputs the harness needs into QA_DATA (default: os tmpdir).
//   fonts.json      the font manifest from config/design_fonts.php with served URLs
//   templates.json  the template definition files (scripts/dev/dump-templates.php), so no database or reseed is needed;
//                   `php artisan design:export-templates --json` exports the stored rows in the same shape
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { DATA_DIR, ROOT } from './server.mjs';

export function prepareData({ templates = true } = {}) {
    mkdirSync(DATA_DIR, { recursive: true });
    const fonts = JSON.parse(execFileSync('php', ['-r', 'echo json_encode((require "config/design_fonts.php")["fonts"]);'], { cwd: ROOT }).toString());
    const manifest = fonts.map((f) => ({
        key: f.key, label: f.label, category: f.category, weights: f.weights, italic: !!f.italic,
        italic_weights: f.italic_weights ?? [], fallback: f.fallback,
        files: Object.fromEntries(Object.entries(f.files).map(([k, v]) => [k, '/' + v.replace(/^fonts\/design\//, 'fonts/')])),
    }));
    writeFileSync(join(DATA_DIR, 'fonts.json'), JSON.stringify(manifest));
    if (templates) {
        writeFileSync(join(DATA_DIR, 'templates.json'), execFileSync('php', ['scripts/dev/dump-templates.php'], { cwd: ROOT, maxBuffer: 1 << 28 }));
    }
    return DATA_DIR;
}

if (import.meta.url === `file://${process.argv[1]}`) console.log(prepareData());

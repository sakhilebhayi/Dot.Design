// Dev-only: loads template definitions (from the PHP definition files, no database needed) and the format table.
import { execFileSync } from 'node:child_process';
import { ROOT } from './server.mjs';

export function loadTemplates(filter = '') {
    const json = execFileSync('php', ['scripts/dev/dump-templates.php', ...(filter ? [filter] : [])], { cwd: ROOT, maxBuffer: 1 << 28 }).toString();
    return JSON.parse(json);
}
export function loadFormats() {
    return JSON.parse(execFileSync('php', ['scripts/dev/dump-formats.php'], { cwd: ROOT }).toString());
}

/** Runs the real PHP TemplateInstantiator (scripts/dev/instantiate.php). */
export function instantiate({ kit = 'none', brief = 'full', filter = '' } = {}) {
    const args = ['scripts/dev/instantiate.php', `--kit=${kit}`, `--brief=${brief}`, ...(filter ? [filter] : [])];
    return JSON.parse(execFileSync('php', args, { cwd: ROOT, maxBuffer: 1 << 29 }).toString());
}

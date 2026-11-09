// Orchestrator for the end-to-end suite (docs/R45_QA.md). No process is left behind: servers are started in their own
// process group and the whole group is killed on exit, on error and on SIGINT/SIGTERM.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, readdirSync, existsSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';
import { startAnthropicStub } from './lib/anthropic-stub.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2);
const headed = args.includes('--headed');
const skipBuild = args.includes('--skip-build') || process.env.E2E_SKIP_BUILD === '1';
const keep = args.includes('--keep');
const filter = args.find((a) => !a.startsWith('--')) || '';

const children = [];
let work = null;
let stub = null;
let cleaned = false;

function cleanup() {
    if (cleaned) return;
    cleaned = true;
    for (const c of children) {
        try { process.kill(-c.pid, 'SIGTERM'); } catch { /* already gone */ }
    }
    try { stub?.close(); } catch { /* ignore */ }
    if (work && !keep) {
        try { rmSync(work, { recursive: true, force: true }); } catch { /* ignore */ }
    }
}
process.on('exit', cleanup);
for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) process.on(sig, () => { cleanup(); process.exit(130); });
process.on('uncaughtException', (e) => { console.error(e); cleanup(); process.exit(1); });

function freePort(preferred) {
    return new Promise((res) => {
        const s = net.createServer();
        s.once('error', () => { const t = net.createServer(); t.listen(0, '127.0.0.1', () => { const p = t.address().port; t.close(() => res(p)); }); });
        s.listen(preferred, '127.0.0.1', () => s.close(() => res(preferred)));
    });
}

function php(argv, env, opts = {}) {
    const r = spawnSync('php', argv, { cwd: root, env, encoding: 'utf8', ...opts });
    if (r.status !== 0) throw new Error(`php ${argv.join(' ')} failed:\n${r.stdout}\n${r.stderr}`);
    return r.stdout;
}

async function waitFor(url, ms = 30000) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        try { const r = await fetch(url); if (r.status < 500) return; } catch { /* not yet */ }
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error(`Timed out waiting for ${url}`);
}

function startServer(port, env, label) {
    const child = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
        cwd: root, env: { ...env, PHP_CLI_SERVER_WORKERS: '1' }, detached: true, stdio: ['ignore', 'pipe', 'pipe'],
    });
    child.stdout.on('data', () => {});
    child.stderr.on('data', (d) => { if (process.env.E2E_VERBOSE) process.stderr.write(`[${label}] ${d}`); });
    children.push(child);
    return child;
}

async function main() {
    if (!skipBuild) {
        console.log('Building assets ...');
        const r = spawnSync('npm', ['run', 'build'], { cwd: root, encoding: 'utf8' });
        if (r.status !== 0) { console.error(r.stdout, r.stderr); process.exit(1); }
    } else if (!existsSync(join(root, 'public/build/manifest.json'))) {
        console.error('public/build is missing; run without --skip-build'); process.exit(1);
    }

    work = mkdtempSync(join(process.env.E2E_TMP || tmpdir(), 'dot-design-e2e-'));
    const storage = join(work, 'storage');
    for (const d of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) mkdirSync(join(storage, d), { recursive: true });
    const db = join(work, 'e2e.sqlite');
    writeFileSync(db, '');
    const out = join(root, 'tests/e2e/out');
    mkdirSync(out, { recursive: true });

    const port = await freePort(Number(process.env.E2E_PORT || 8485));
    const aiPort = await freePort(port + 1);
    stub = await startAnthropicStub();

    const base = {
        ...process.env,
        APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${port}`,
        DB_CONNECTION: 'sqlite', DB_DATABASE: db, DB_FOREIGN_KEYS: 'true',
        SESSION_DRIVER: 'file', SESSION_LIFETIME: '120', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'single',
        LARAVEL_STORAGE_PATH: storage, FILESYSTEM_DISK: 'local',
        PHP_INI_SCAN_DIR: `:${join(root, 'tests/e2e/support/ini')}`,
        MAIL_MAILER: 'log', BROADCAST_CONNECTION: 'log', LOG_LEVEL: 'debug',
        ANTHROPIC_API_KEY: '', DESIGN_AI_ENABLED: 'true', AI_IMAGE_DRIVER: 'mock',
        DESIGN_FILES_ENABLED: 'false',
    };
    console.log('Migrating and seeding ...');
    php(['artisan', 'migrate', '--force', '--no-interaction'], base);
    php(['artisan', 'db:seed', '--class=DesignTemplateSeeder', '--force', '--no-interaction'], base);

    startServer(port, base, 'main');
    // The same app and database with the assistant switched on, pointed at a local stub of the Messages API.
    startServer(aiPort, {
        ...base, APP_URL: `http://127.0.0.1:${aiPort}`, ANTHROPIC_API_KEY: 'e2e-stub-key-not-real',
        DESIGN_AI_BASE_URL: `http://127.0.0.1:${stub.port}`, DESIGN_AI_RETRIES: '0', DESIGN_AI_THINKING: 'omit', DESIGN_AI_EFFORT: '',
    }, 'ai');
    await waitFor(`http://127.0.0.1:${port}/up`);
    await waitFor(`http://127.0.0.1:${aiPort}/up`);

    if (args.includes('--serve')) {
        console.log(`SERVING main=http://127.0.0.1:${port} ai=http://127.0.0.1:${aiPort} stub=${stub.port} db=${db}`);
        await new Promise(() => {});
    }
    const specDir = join(root, 'tests/e2e/specs');
    const specs = readdirSync(specDir).filter((f) => f.endsWith('.spec.mjs') && f.includes(filter)).sort();
    if (!specs.length) { console.error('No specs match'); process.exit(1); }

    const env = {
        ...base,
        E2E_BASE_URL: `http://127.0.0.1:${port}`, E2E_AI_URL: `http://127.0.0.1:${aiPort}`, E2E_STUB_URL: `http://127.0.0.1:${stub.port}`,
        E2E_STUB_CONTROL: `http://127.0.0.1:${stub.port}/__control`,
        E2E_DB: db, E2E_OUT: out, E2E_ROOT: root, E2E_HEADED: headed ? '1' : '',
    };
    let failed = 0;
    for (const spec of specs) {
        console.log(`\n=== ${spec}`);
        const r = spawnSync(process.execPath, ['--test', '--test-concurrency=1', '--test-timeout=240000', join(specDir, spec)], { cwd: root, env, stdio: 'inherit' });
        if (r.status !== 0) failed++;
    }
    console.log(failed ? `\n${failed} spec file(s) failed` : '\nAll specs passed');
    cleanup();
    process.exit(failed ? 1 : 0);
}

main().catch((e) => { console.error(e); cleanup(); process.exit(1); });

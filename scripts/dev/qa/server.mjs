// Dev-only static server for the QA harness. Serves the real engine modules, the self-hosted fonts and the
// generated data, so headless Chrome renders exactly what the editor renders. Nothing here ships.
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { dirname, join, normalize, resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
export const DATA_DIR = process.env.QA_DATA || join(tmpdir(), 'dot-design-qa');
const MOUNTS = {
    '/studio/': join(ROOT, 'resources/js/studio/'),
    '/fonts/': join(ROOT, 'public/fonts/design/'),
    '/qa/': join(ROOT, 'scripts/dev/qa/'),
    '/data/': DATA_DIR + '/',
    '/design/': join(ROOT, 'resources/design/'),
};
const TYPES = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.html': 'text/html; charset=utf-8', '.json': 'application/json', '.woff2': 'font/woff2', '.png': 'image/png', '.css': 'text/css', '.svg': 'image/svg+xml' };

export function serve(port = 0) {
    const server = createServer(async (req, res) => {
        try {
            const url = new URL(req.url, 'http://x');
            const mount = Object.keys(MOUNTS).find((m) => url.pathname.startsWith(m));
            if (!mount) { res.writeHead(404); res.end('not found'); return; }
            const file = normalize(join(MOUNTS[mount], decodeURIComponent(url.pathname.slice(mount.length))));
            if (!file.startsWith(MOUNTS[mount])) { res.writeHead(403); res.end(); return; }
            await stat(file);
            res.writeHead(200, { 'content-type': TYPES[extname(file)] || 'application/octet-stream', 'cache-control': 'no-store' });
            res.end(await readFile(file));
        } catch (e) { res.writeHead(404); res.end('not found'); }
    });
    return new Promise((res) => server.listen(port, '127.0.0.1', () => res({ server, port: server.address().port, close: () => server.close() })));
}

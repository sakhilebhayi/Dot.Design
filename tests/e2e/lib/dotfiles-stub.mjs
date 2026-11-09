// A local stand-in for Dot.Files' platform API: POST /api/platform/files/{store,update}. It checks the HMAC signature the
// way the real service does and keeps what it was sent so a spec can decode the stored file. GET /__control lists them.
import http from 'node:http';
import crypto from 'node:crypto';

export const FILES_SECRET = 'e2e-files-secret-not-real';

export function startDotFilesStub() {
    const stored = [];
    const server = http.createServer((req, res) => {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            const raw = Buffer.concat(chunks).toString('utf8');
            const json = (code, body) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(body)); };
            if (req.url === '/__control') return json(200, { stored: stored.map((s) => ({ ...s, content_b64: undefined, bytes: Buffer.from(s.content_b64 || '', 'base64').length, head: Buffer.from(s.content_b64 || '', 'base64').subarray(0, 16).toString('hex'), content: s.content_b64 })) });
            const m = req.url.match(/^\/api\/platform\/files\/(store|update)$/);
            if (!m || req.method !== 'POST') return json(404, { error: 'not found' });
            const ts = req.headers['x-dot-timestamp'];
            const expected = 'sha256=' + crypto.createHmac('sha256', FILES_SECRET).update(`${ts}.${raw}`).digest('hex');
            if (req.headers['x-dot-platform'] !== 'design' || req.headers['x-dot-signature'] !== expected) return json(401, { error: 'bad signature' });
            const body = JSON.parse(raw);
            const uuid = body.uuid || crypto.randomUUID();
            stored.push({ endpoint: m[1], uuid, team_uuid: body.team_uuid, name: body.name, folder: body.folder, content_b64: body.content_b64 });
            return json(200, { uuid, version: stored.filter((s) => s.uuid === uuid).length, open_url: `http://127.0.0.1:${server.address().port}/open/${uuid}` });
        });
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ port: server.address().port, close: () => server.close() })));
}

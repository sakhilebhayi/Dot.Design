// A local stand-in for POST /v1/messages (Anthropic Messages API). It never contacts the internet and knows no real key.
// Control (from a spec): POST /__control {"queue":[{"tool":"submit_design_changes","input":{...}} | {"status":500}]} queues answers,
// GET /__control returns the requests seen so far, DELETE /__control resets both.
import http from 'node:http';

export function startAnthropicStub() {
    const state = { queue: [], seen: [] };
    const server = http.createServer((req, res) => {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            const raw = Buffer.concat(chunks).toString('utf8');
            const json = (code, body) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(body)); };
            if (req.url === '/__control') {
                if (req.method === 'POST') { const b = JSON.parse(raw || '{}'); state.queue.push(...(b.queue || [])); return json(200, { queued: state.queue.length }); }
                if (req.method === 'DELETE') { state.queue = []; state.seen = []; return json(200, {}); }
                return json(200, { queue: state.queue.length, seen: state.seen });
            }
            if (req.url !== '/v1/messages' || req.method !== 'POST') return json(404, { error: { type: 'not_found_error' } });
            let body = {};
            try { body = JSON.parse(raw); } catch { /* ignore */ }
            state.seen.push({ headers: { key: req.headers['x-api-key'] ? 'present' : 'missing', version: req.headers['anthropic-version'] }, tool: body.tools?.[0]?.name, user: body.messages?.[0]?.content });
            const next = state.queue.shift();
            if (!next) return json(500, { error: { type: 'api_error', message: 'stub queue empty' } });
            if (next.status) return json(next.status, { error: { type: 'api_error' } });
            return json(200, {
                id: 'msg_stub', type: 'message', role: 'assistant', stop_reason: 'tool_use',
                content: [{ type: 'tool_use', id: 'tu_stub', name: next.tool || body.tools?.[0]?.name, input: next.input }],
                usage: { input_tokens: 700, output_tokens: 90 },
            });
        });
    });
    return new Promise((resolve) => {
        server.listen(0, '127.0.0.1', () => resolve({ port: server.address().port, close: () => server.close() }));
    });
}

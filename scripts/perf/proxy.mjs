// Production-like front for `php artisan serve` (the dev server neither compresses nor sets cache headers).
// It does what the cPanel Apache host is asked to do in public/.htaccess: gzip/brotli text, immutable cache for hashed
// assets and fonts. Flags: --listen=8497 --target=8487 --compress=br|gzip|none --immutable=1|0
import http from 'node:http';
import zlib from 'node:zlib';

const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=')[1] ?? d;
const listen = +arg('listen', 8497); const target = +arg('target', 8487); const compress = arg('compress', 'br'); const immutable = arg('immutable', '1') === '1';
const TEXT = /^(text\/|application\/(javascript|json|xml|manifest\+json)|image\/svg\+xml|font\/(ttf|otf))/;

http.createServer((req, res) => {
    const up = http.request({ host: '127.0.0.1', port: target, path: req.url, method: req.method, headers: { ...req.headers, 'accept-encoding': 'identity' } }, (r) => {
        const chunks = [];
        r.on('data', (d) => chunks.push(d));
        r.on('end', () => {
            let body = Buffer.concat(chunks);
            const headers = { ...r.headers };
            const type = String(headers['content-type'] || '');
            const accept = String(req.headers['accept-encoding'] || '');
            if (immutable && /^\/(build|fonts)\//.test(req.url.split('?')[0]) && r.statusCode === 200) headers['cache-control'] = 'public, max-age=31536000, immutable';
            if (compress !== 'none' && TEXT.test(type) && body.length > 1024 && r.statusCode === 200) {
                if (compress === 'br' && /\bbr\b/.test(accept)) { body = zlib.brotliCompressSync(body, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 5 } }); headers['content-encoding'] = 'br'; }
                else if (/\bgzip\b/.test(accept)) { body = zlib.gzipSync(body, { level: 6 }); headers['content-encoding'] = 'gzip'; }
                headers.vary = 'Accept-Encoding';
            }
            delete headers['transfer-encoding'];
            headers['content-length'] = body.length;
            res.writeHead(r.statusCode, headers); res.end(body);
        });
    });
    up.on('error', () => { res.writeHead(502); res.end(); });
    req.pipe(up);
}).listen(listen, '127.0.0.1', () => console.log(`proxy :${listen} -> :${target} compress=${compress} immutable=${immutable}`));

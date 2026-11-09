// Idempotent dev-data seed for the a11y sweep: one rich design, one blank design, a brand kit, an asset, a comment,
// a version, a share link. Writes ids to storage/a11y/fixture.json (git-ignored). Dev database only.
import { mkdirSync, writeFileSync, existsSync, readFileSync } from 'node:fs';
import { launch, login, apiCall } from './lib.mjs';

const base = process.env.BASE || 'http://127.0.0.1:8490';
const out = new URL('../../../storage/a11y/fixture.json', import.meta.url).pathname;

export async function seed(c) {
    if (existsSync(out)) {
        const f = JSON.parse(readFileSync(out, 'utf8'));
        const ok = await apiCall(c, 'GET', `/design/projects/${f.rich}/document`);
        if (ok.status === 200) { await addLong(c, f); return f; }
    }
    const f = {};
    const kit = await apiCall(c, 'POST', '/design/brand/kits', {
        name: 'Thandi Bakery', colors: [
            { name: 'Flour', hex: '#F5E6C8', role: 'primary' }, { name: 'Crust', hex: '#8a4b08', role: 'secondary' },
            { name: 'Berry', hex: '#b0123f', role: 'accent' }, { name: 'Ink', hex: '#222222', role: 'neutral' }],
        fonts: { heading: 'playfair-display', body: 'inter', accent: 'caveat' }, tagline: 'Fresh every morning',
        website: 'www.thandis.co.za', contacts: { phone: '021 555 0123', email: 'hello@thandis.co.za' },
        voice: { tone: 'Warm and friendly', keywords: ['fresh', 'family'], avoid: ['cheap'] }, guidelines: 'Always leave space around the logo.',
    });
    f.kit = kit.data?.id;
    const long = 'Thandi\'s Bakery and Pie Shop of the Western Cape Winelands';
    const rich = await apiCall(c, 'POST', '/design/projects/from-brief', {
        template: 'flyer-a4-corporate', brief: { goal: 'announce_sale', business_name: long, what: 'Winter pie special', details: { offer: '2 for 1', phone: '021 555 0123' }, style: 'bold', format_key: 'a4-portrait' },
    });
    f.rich = rich.data?.project?.id;
    const multi = await apiCall(c, 'POST', '/design/projects/from-brief', { template: 'brand-guidelines-clean', brief: { goal: 'document_report', business_name: 'Zulu Plumbing', format_key: 'presentation' } });
    f.multi = multi.data?.project?.id;
    const blank = await apiCall(c, 'POST', '/design/projects/from-brief', { brief: { goal: 'stationery', business_name: 'Zulu Plumbing', format_key: 'business-card-za' } });
    f.blank = blank.data?.project?.id;
    f.rich && await apiCall(c, 'POST', `/design/projects/${f.rich}/comments`, { body: 'Can the heading be a little bigger?' });
    f.rich && await apiCall(c, 'POST', `/design/projects/${f.rich}/versions`, { name: 'First draft' });
    const share = f.rich && await apiCall(c, 'POST', `/design/projects/${f.rich}/shares`, { permission: 'comment', expires_in_days: 7, download_allowed: true });
    f.share = share?.data;
    // a small PNG asset made in the page
    const asset = await c.eval(`(async () => {
        const cv = document.createElement('canvas'); cv.width = 320; cv.height = 240; const g = cv.getContext('2d');
        g.fillStyle = '#c0392b'; g.fillRect(0, 0, 320, 240); g.fillStyle = '#f5e6c8'; g.beginPath(); g.arc(160, 120, 70, 0, 7); g.fill();
        const blob = await new Promise((r) => cv.toBlob(r, 'image/png'));
        const fd = new FormData(); fd.append('file', blob, 'pie-photo.png'); fd.append('project_id', '${f.rich}');
        const token = document.querySelector('meta[name=csrf-token]')?.content;
        const res = await fetch('/design/assets', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, body: fd });
        return { status: res.status, body: (await res.text()).slice(0, 300) };
    })()`);
    f.asset = asset;
    await addLong(c, f);
    return f;
}

/** Long-content stress data: a 70-character kit name, a 300-character guideline, an unbroken 80-character business name. */
async function addLong(c, f) {
    if (f.long) return;
    const kit = await apiCall(c, 'POST', '/design/brand/kits', {
        name: 'Bhekizizwe Mnguni Construction and Civil Engineering Holdings (Pty) Ltd',
        colors: [{ name: 'A very long colour name for testing', hex: '#2a4d69', role: 'primary' }, { name: 'Sand', hex: '#e8d8b8', role: 'secondary' }, { name: 'Rust', hex: '#b5532a', role: 'accent' }, { name: 'Charcoal', hex: '#222222', role: 'neutral' }],
        fonts: { heading: 'oswald', body: 'inter', accent: 'caveat' }, tagline: 'Building the Western Cape since 1987, one careful foundation at a time, for families and for firms',
        website: 'www.bhekizizwe-mnguni-construction-and-civil-engineering.co.za', contacts: { phone: '021 555 0123', email: 'enquiries@bhekizizwe-mnguni-construction.co.za' },
        voice: { tone: 'Plain, steady and proud of the work', keywords: ['solid', 'on time', 'honest'], avoid: ['cheap'] },
        guidelines: 'Always leave clear space equal to the height of the letter B around the logo. '.repeat(4).trim(),
    });
    const proj = await apiCall(c, 'POST', '/design/projects/from-brief', { brief: { goal: 'advertise_business', business_name: 'Supercalifragilistic-Plumbing-and-Electrical-Johannesburg1', format_key: 'a4-portrait' } });
    f.long = { kit: kit.data?.id, project: proj.data?.project?.id };
    mkdirSync(new URL('../../../storage/a11y/', import.meta.url).pathname, { recursive: true });
    writeFileSync(out, JSON.stringify(f, null, 2));
}

if (import.meta.url === `file://${process.argv[1]}`) {
    const c = await launch();
    try { await login(c, base); console.log(JSON.stringify(await seed(c), null, 2)); } finally { c.close(); }
}

/* Plain-language time and size formatting. Pure functions so they can be tested without a browser. */

const MIN = 60 * 1000;
const HOUR = 60 * MIN;
const DAY = 24 * HOUR;

export function timeAgo(iso, now = Date.now()) {
    const then = typeof iso === 'number' ? iso : Date.parse(iso || '');
    if (!Number.isFinite(then)) return '';
    const diff = Math.max(0, now - then);
    if (diff < MIN) return 'just now';
    if (diff < HOUR) { const m = Math.floor(diff / MIN); return `${m} ${m === 1 ? 'minute' : 'minutes'} ago`; }
    if (diff < DAY) { const hrs = Math.floor(diff / HOUR); return `${hrs} ${hrs === 1 ? 'hour' : 'hours'} ago`; }
    if (diff < 7 * DAY) { const d = Math.floor(diff / DAY); return d === 1 ? 'yesterday' : `${d} days ago`; }
    return shortDate(then);
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function shortDate(value) {
    const d = new Date(typeof value === 'number' ? value : Date.parse(value || ''));
    if (Number.isNaN(d.getTime())) return '';
    return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}

export function dateTime(value) {
    const d = new Date(typeof value === 'number' ? value : Date.parse(value || ''));
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}, ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function formatBytes(n) {
    const v = Number(n);
    if (!Number.isFinite(v) || v < 0) return '';
    if (v < 1024) return `${v} B`;
    if (v < 1024 * 1024) return `${(v / 1024).toFixed(v < 10240 ? 1 : 0)} KB`;
    return `${(v / (1024 * 1024)).toFixed(1)} MB`;
}

/** "1 comment", "3 comments". */
export function plural(n, one, many = `${one}s`) {
    return `${n} ${n === 1 ? one : many}`;
}

/** When a link stops working, in words: "in 6 days", "tomorrow", "expired". */
export function expiresIn(iso, now = Date.now()) {
    if (!iso) return 'Never expires';
    const at = Date.parse(iso);
    if (!Number.isFinite(at)) return '';
    const diff = at - now;
    if (diff <= 0) return 'Expired';
    if (diff < HOUR) return 'Expires within the hour';
    if (diff < DAY) { const hrs = Math.round(diff / HOUR); return `Expires in ${hrs} ${hrs === 1 ? 'hour' : 'hours'}`; }
    const d = Math.round(diff / DAY);
    return d === 1 ? 'Expires tomorrow' : `Expires in ${d} days`;
}

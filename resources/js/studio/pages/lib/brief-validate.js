// The same checks the server makes on a brief (App\Services\Design\BriefValidator), with the same plain wording, so a
// mistake is caught on the step where it was made. The server stays the authority: it checks everything again.

/** Same clean-up as the server: no control or direction-override characters, one space between words. */
export function cleanText(value, multiline = false) {
    let s = String(value ?? '');
    s = s.normalize('NFC');
    s = s.replace(/[\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF\u00AD]/g, '');
    s = s.replace(/[\u0000-\u0008\u000B-\u001F\u007F-\u009F]/g, '');
    if (multiline) {
        s = s.replace(/[ \t]+/g, ' ').replace(/\s*\n\s*/g, '\n').replace(/\n{3,}/g, '\n\n');
    } else {
        s = s.replace(/\s+/g, ' ');
    }
    return s.trim();
}

export function looksLikeMarkup(s) {
    return /<\s*[/!?a-zA-Z]|&#|&lt;|&gt;|\{\{|\}\}|\$\{/.test(s);
}

/** @returns {string|null} a message, or null when the value is fine (an empty value is fine: the field is optional) */
export function checkText(value, { max, label, multiline = false }) {
    const clean = cleanText(value, multiline);
    if (clean === '') return null;
    if (looksLikeMarkup(clean)) return `${label} has characters that look like code. Use plain words, without angle brackets.`;
    if ([...clean].length > max) return `${label} is too long. Keep it under ${max} characters.`;
    return null;
}

export function checkDetail(spec, value) {
    const base = checkText(value, { max: spec.max, label: spec.label, multiline: spec.type === 'multiline' });
    if (base) return base;
    const text = cleanText(value, spec.type === 'multiline');
    if (text === '') return null;
    switch (spec.type) {
        case 'phone': {
            const digits = text.replace(/\D/g, '');
            if (!/^\+?[0-9 ()\-./]{7,24}$/.test(text) || digits.length < 7 || digits.length > 15) return `${spec.label} should only use numbers, spaces, + and dashes, for example 021 555 0123.`;
            return null;
        }
        case 'email':
            return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(text) ? null : 'That email address does not look right. It should look like name@business.co.za.';
        case 'url':
            return /^(https?:\/\/)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(:\d{1,5})?(\/[^\s<>"'\\]*)?$/i.test(text) ? null : 'That web address does not look right. It should look like www.yourbusiness.co.za.';
        case 'handle':
            return /^@?[A-Za-z0-9._]{1,30}$/.test(text) ? null : 'A handle can only use letters, numbers, dots and underscores, for example @yourbusiness.';
        default:
            return null;
    }
}

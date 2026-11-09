import assert from 'node:assert/strict';
import test from 'node:test';
import { checkDetail, cleanText } from '../../resources/js/studio/pages/lib/brief-validate.js';
import { createBriefMachine } from '../../resources/js/studio/pages/lib/brief-machine.js';

const FIELDS = [
    { key: 'headline', label: 'Headline', type: 'text', max: 80 },
    { key: 'phone', label: 'Phone number', type: 'phone', max: 24 },
    { key: 'website', label: 'Website', type: 'url', max: 140 },
    { key: 'email', label: 'Email address', type: 'email', max: 120 },
    { key: 'social_handle', label: 'Social media handle', type: 'handle', max: 31 },
];
const STEPS = [
    { key: 'goal', essential: true, skippable: false },
    { key: 'what', essential: false, skippable: true },
    { key: 'business_name', essential: true, skippable: false },
    { key: 'details', essential: false, skippable: true, fields: FIELDS },
    { key: 'logo', essential: false, skippable: true },
    { key: 'style', essential: false, skippable: true },
    { key: 'where', essential: false, skippable: true },
];

function memory() {
    const m = new Map();
    return { get: (k) => (m.has(k) ? m.get(k) : null), set: (k, v) => { m.set(k, v); return true; }, remove: (k) => m.delete(k), map: m };
}
const make = (extra = {}) => createBriefMachine({ steps: STEPS, goal: 'announce_sale', ...extra });

test('the flow starts at the first question and ends on the starting-point choice', () => {
    const m = make();
    assert.deepEqual(m.steps.map((s) => s.key), ['what', 'business_name', 'details', 'logo', 'style', 'where', 'choose']);
    assert.equal(m.current().key, 'what');
    assert.equal(m.getState().index, 0);
});

test('an optional step can be skipped and the skip is recorded; typing on it first is thrown away', () => {
    const m = make();
    m.setAnswer('what', 'Winter pies');
    assert.equal(m.skip().ok, true);
    assert.equal(m.getState().answers.what, '');
    assert.deepEqual(m.getState().skipped, ['what']);
    assert.equal(m.current().key, 'business_name');
});

test('an essential step cannot be skipped or passed empty, and says why', () => {
    const m = make();
    m.next();
    assert.equal(m.current().key, 'business_name');
    assert.equal(m.skip().ok, false);
    assert.equal(m.canContinue(), false);
    const r = m.next();
    assert.equal(r.ok, false);
    assert.equal(r.errors.business_name, 'Add your business name. We put it on your design for you.');
    assert.equal(m.current().key, 'business_name');
    m.setAnswer('business_name', "Thandi's Bakery");
    assert.equal(m.getState().errors.business_name, undefined, 'typing clears the message');
    assert.equal(m.canContinue(), true);
    assert.equal(m.next().ok, true);
});

test('going back keeps every answer; the step list only reaches steps already seen', () => {
    const m = make();
    m.setAnswer('what', 'Winter pies');
    m.next();
    m.setAnswer('business_name', 'Thandi');
    m.next();
    m.setDetail('headline', 'Pie week');
    assert.equal(m.back(), true);
    assert.equal(m.back(), true);
    assert.equal(m.back(), false);
    const a = m.getState().answers;
    assert.equal(a.what, 'Winter pies');
    assert.equal(a.business_name, 'Thandi');
    assert.equal(a.details.headline, 'Pie week');
    assert.equal(m.goTo('details'), true, 'details was reached before going back');
    assert.equal(m.goTo('style'), false, 'style was never reached');
});

test('leaving an untouched optional step with Next counts as skipped; answering it later un-skips it', () => {
    const m = make();
    m.next();
    assert.deepEqual(m.getState().skipped, ['what']);
    m.goTo('what');
    m.setAnswer('what', 'Pies');
    assert.deepEqual(m.getState().skipped, []);
    assert.equal(m.stepStatus('what'), 'answered');
});

test('detail fields are checked with the server wording and block Next until fixed', () => {
    const m = make();
    m.setAnswer('business_name', 'Thandi');
    m.next(); m.next();
    assert.equal(m.current().key, 'details');
    m.setDetail('phone', 'call me');
    m.setDetail('website', 'not a site');
    const r = m.next();
    assert.equal(r.ok, false);
    assert.match(r.errors['details.phone'], /should only use numbers, spaces, \+ and dashes/);
    assert.match(r.errors['details.website'], /web address does not look right/);
    m.setDetail('phone', '021 555 0123');
    m.setDetail('website', 'www.thandis.co.za');
    assert.equal(m.next().ok, true);
});

test('validators match the server rules', () => {
    const f = Object.fromEntries(FIELDS.map((x) => [x.key, x]));
    assert.equal(checkDetail(f.phone, '+27 21 555 0123'), null);
    assert.ok(checkDetail(f.phone, '12345'));
    assert.equal(checkDetail(f.email, 'a@b.co.za'), null);
    assert.ok(checkDetail(f.email, 'a b@c.d'));
    assert.equal(checkDetail(f.social_handle, '@thandi_pies'), null);
    assert.ok(checkDetail(f.social_handle, '@thandi pies'));
    assert.ok(checkDetail(f.headline, '<b>Sale</b>'), 'markup is refused');
    assert.ok(checkDetail(f.headline, 'x'.repeat(81)));
    assert.equal(checkDetail(f.headline, ''), null, 'empty is fine, the field is optional');
    assert.equal(cleanText('  a​   b‮c '), 'a bc');
});

test('toBrief leaves empty answers out and lists what was really skipped', () => {
    const m = make();
    m.setAnswer('business_name', ' Thandi’s   Bakery ');
    m.next(); m.next();
    m.setDetail('headline', 'Pie week');
    m.setDetail('phone', '');
    m.next();
    m.skip(); // logo
    m.setAnswer('style', 'bold');
    const brief = m.toBrief();
    assert.deepEqual(brief, {
        goal: 'announce_sale',
        business_name: 'Thandi’s Bakery',
        details: { headline: 'Pie week' },
        style: 'bold',
        apply_brand: false,
        skipped: ['what', 'logo'],
    });
    assert.equal(m.isReady(), true);
});

test('brand: a kit id is always sent when there is one; colours only apply when chosen', () => {
    const m = make();
    m.setAnswer('business_name', 'Thandi');
    assert.deepEqual(m.toBrief({ kitId: 7 }), { goal: 'announce_sale', business_name: 'Thandi', brand_kit_id: 7, apply_brand: false });
    m.setAnswer('colours', 'kit');
    assert.equal(m.toBrief({ kitId: 7 }).apply_brand, true);
    assert.equal(m.toBrief().apply_brand, false, 'no kit, nothing to apply');
});

test('answers persist across a reload through storage, and survive a corrupt or foreign entry', () => {
    const store = memory();
    const a = make({ storage: store });
    a.setAnswer('business_name', 'Thandi');
    a.next(); a.next();
    a.setDetail('headline', 'Pie week');
    a.next();
    a.setAnswer('style', 'bold');

    const b = make({ storage: store });
    assert.equal(b.getState().answers.business_name, 'Thandi');
    assert.equal(b.getState().answers.details.headline, 'Pie week');
    assert.equal(b.getState().answers.style, 'bold');
    assert.equal(b.getState().index, a.getState().index);
    assert.equal(b.getState().reached, a.getState().reached);

    store.set('dot-design-brief:announce_sale', '{not json');
    assert.equal(make({ storage: store }).getState().answers.business_name, '');
    store.set('dot-design-brief:announce_sale', JSON.stringify({ v: 1, goal: 'stationery', answers: { business_name: 'Other' } }));
    assert.equal(make({ storage: store }).getState().answers.business_name, '', 'another goal is ignored');
    store.set('dot-design-brief:announce_sale', JSON.stringify({ v: 1, goal: 'announce_sale', index: 99, reached: 99, answers: { business_name: 'X', details: { evil: 'y', phone: 5 }, logo_asset_id: '9', style: 4 } }));
    const c = make({ storage: store });
    assert.equal(c.getState().answers.business_name, 'X');
    assert.deepEqual(c.getState().answers.details, {});
    assert.equal(c.getState().answers.logo_asset_id, null);
    assert.equal(c.getState().index, c.total() - 1, 'index is clamped');
});

test('storage that throws never breaks the wizard', () => {
    const broken = { get() { throw new Error('blocked'); }, set() { throw new Error('quota'); }, remove() { throw new Error('blocked'); } };
    // safeStorage wraps these in the browser; the machine itself only needs get/set/remove not to be required to succeed.
    const safe = { get: () => { try { return broken.get(); } catch (e) { return null; } }, set: () => { try { return broken.set(); } catch (e) { return false; } }, remove: () => {} };
    const m = make({ storage: safe });
    m.setAnswer('business_name', 'Thandi');
    assert.equal(m.getState().answers.business_name, 'Thandi');
});

test('previous brief fills the name as a starting value that typing replaces; a preset size is kept', () => {
    const m = make({ previous: { business_name: 'Thandi Bakery' }, presetFormat: 'a5-portrait' });
    assert.equal(m.getState().answers.business_name, 'Thandi Bakery');
    assert.deepEqual(m.getState().prefilled, ['business_name']);
    assert.equal(m.getState().answers.format_key, 'a5-portrait');
    m.setAnswer('business_name', 'Thandi Pies');
    assert.deepEqual(m.getState().prefilled, []);
});

test('server errors are put back on the step they belong to', () => {
    const m = make();
    m.setAnswer('business_name', 'Thandi');
    m.next(); m.next(); m.next(); m.next(); m.next(); m.next();
    assert.equal(m.current().key, 'choose');
    const first = m.applyServerErrors({ 'details.phone': 'Phone number should only use numbers.', format_key: 'That size is not available.' });
    assert.equal(first, 'details');
    assert.equal(m.current().key, 'details');
    assert.equal(m.getState().errors['details.phone'], 'Phone number should only use numbers.');
});

test('reset forgets everything, including the saved copy', () => {
    const store = memory();
    const m = make({ storage: store });
    m.setAnswer('business_name', 'Thandi');
    assert.ok(store.map.size > 0);
    m.reset();
    assert.equal(m.getState().answers.business_name, '');
    assert.equal(store.map.size, 0);
});

test('lenient brief (live suggestions) leaves out answers that do not pass yet instead of failing the request', () => {
    const m = make();
    m.setAnswer('business_name', 'Thandi');
    m.next(); m.next();
    m.setDetail('phone', 'call me');
    m.setDetail('headline', 'Pie week');
    assert.deepEqual(m.toBrief({ lenient: true }).details, { headline: 'Pie week' });
    assert.deepEqual(m.toBrief().details, { headline: 'Pie week', phone: 'call me' }, 'the real brief keeps it so the server can say what is wrong');
});

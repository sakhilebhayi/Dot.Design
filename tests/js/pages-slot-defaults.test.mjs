import test from 'node:test';
import assert from 'node:assert/strict';
import { initialSlotValues } from '../../resources/js/studio/pages/lib/slot-defaults.js';

const slots = [
    { key: 'business_name', type: 'text' }, { key: 'headline', type: 'text' }, { key: 'website', type: 'text' },
    { key: 'phone', type: 'text' }, { key: 'offer', type: 'text' }, { key: 'logo', type: 'image' },
];
const previous = { goal: 'sale', business_name: 'Karoo Kitchen', details: { headline: 'Flash sale', offer: '20% off', phone: '021 555 0123' } };

test('a menu template does not open with the headline from an earlier sale brief', () => {
    const v = initialSlotValues({ slots, previous, kit: null, goal: 'menu' });
    assert.equal(v.headline, '');
    assert.equal(v.offer, '');
    assert.equal(v.business_name, 'Karoo Kitchen');
    assert.equal(v.phone, '021 555 0123', 'contact details are business facts and carry over');
    assert.equal('logo' in v, false, 'picture slots are not text answers');
});
test('the same goal carries its own words over', () => {
    const v = initialSlotValues({ slots, previous, goal: 'sale' });
    assert.equal(v.headline, 'Flash sale');
    assert.equal(v.offer, '20% off');
});
test('the kit fills tagline, website and contacts when the brief has nothing', () => {
    const v = initialSlotValues({ slots, previous: {}, kit: { website: 'www.karoo.co.za', contacts: { phone: '011 000 0000' } }, goal: 'menu' });
    assert.equal(v.website, 'www.karoo.co.za');
    assert.equal(v.phone, '011 000 0000');
    assert.equal(v.headline, '');
});
test('no saved brief, no kit, no goal: everything empty so the template keeps its sample words', () => {
    assert.deepEqual(initialSlotValues({ slots }), { business_name: '', headline: '', website: '', phone: '', offer: '' });
});

// What a template's form starts with. Business facts (name, contact details, and the kit's tagline and website) carry over from
// the last guided brief and the brand kit. Words that belong to one message (headline, offer, price, date, ...) carry over only when
// the last brief was for the same goal as this template, so a menu never opens with "Flash sale" from an earlier sale brief.
// A spot left empty keeps the template's own sample words, which is the template's own default.
export const BUSINESS_FACT_KEYS = ['business_name', 'phone', 'whatsapp', 'email', 'website', 'address', 'social_handle'];

export function initialSlotValues({ slots = [], previous = {}, kit = null, goal = null } = {}) {
    const prev = previous || {};
    const details = prev.details && typeof prev.details === 'object' ? prev.details : {};
    const sameGoal = Boolean(goal) && prev.goal === goal;
    const fromKit = { tagline: kit && kit.tagline, website: kit && kit.website, ...(kit && kit.contacts ? kit.contacts : {}) };
    const values = {};
    for (const s of slots) {
        if (s.type !== 'text') continue;
        if (s.key === 'business_name') { values[s.key] = prev.business_name || ''; continue; }
        const saved = typeof details[s.key] === 'string' ? details[s.key] : '';
        const fact = BUSINESS_FACT_KEYS.includes(s.key);
        values[s.key] = (fact || sameGoal ? saved : '') || fromKit[s.key] || '';
    }
    return values;
}

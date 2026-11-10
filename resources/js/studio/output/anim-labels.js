// Plain-language names for the animation presets (UI strings: sentence case, no dashes). Keys are the schema enums.
export const IN_LABELS = {
    none: 'No entrance', fade: 'Fade in', rise: 'Rise up', drop: 'Drop down', 'slide-left': 'Slide in from the left', 'slide-right': 'Slide in from the right',
    'zoom-in': 'Zoom in', pop: 'Pop', 'wipe-left': 'Wipe in from the left', 'wipe-up': 'Wipe in upward', type: 'Type out', 'blur-in': 'Come into focus',
};
export const OUT_LABELS = {
    none: 'No exit', fade: 'Fade out', sink: 'Sink down', 'slide-left': 'Slide out to the left', 'slide-right': 'Slide out to the right', 'zoom-out': 'Zoom out', 'wipe-left': 'Wipe out to the left',
};
export const LOOP_LABELS = { none: 'Stays still', pulse: 'Pulse', float: 'Float', spin: 'Spin', wiggle: 'Wiggle' };
export const EASING_LABELS = { linear: 'Steady', 'ease-out': 'Slows at the end', 'ease-in-out': 'Smooth start and end', spring: 'Springy' };
export const TRANSITION_LABELS = { none: 'Cut', fade: 'Fade', slide: 'Slide', zoom: 'Zoom' };

export const ROLE_NAMES = { headline: 'Headline', subhead: 'Subheading', body: 'Text', cta: 'Button', logo: 'Logo', photo: 'Picture', price: 'Price', badge: 'Badge', contact: 'Contact line', decor: 'Decoration' };

/** A short human name for an element: its own name, else its text, else its role, else its type. */
export function elementLabel(el) {
    if (el.name && String(el.name).trim()) return String(el.name).trim();
    if (el.type === 'text' && el.text) { const t = String(el.text).replace(/\s+/g, ' ').trim(); return t.length > 22 ? `${t.slice(0, 21)}...` : t; }
    if (ROLE_NAMES[el.role]) return ROLE_NAMES[el.role];
    return { text: 'Text', shape: 'Shape', image: 'Picture', icon: 'Icon', group: 'Group' }[el.type] || 'Element';
}

/** The sentence used in the "Animate this design" proposal. */
export function describeProposal(el, anim) {
    const p = anim.in && anim.in.preset;
    return `${elementLabel(el)}: ${IN_LABELS[p] ? IN_LABELS[p].toLowerCase() : p}`;
}

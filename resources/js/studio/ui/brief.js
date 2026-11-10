/*
 * What the editor sends to the template "fill" endpoint so that bound slots
 * (business name, phone, logo...) are filled again from the project's own brief
 * and its brand kit. Pure; unit tested.
 */
export function buildBrief(project, kit) {
    const base = project && project.brief && typeof project.brief === 'object' && !Array.isArray(project.brief) ? { ...project.brief } : {};
    if (kit && kit.id) {
        base.brand_kit_id = kit.id;
        base.apply_brand = true;
        if (!base.business_name && kit.name) base.business_name = kit.name;
    } else {
        delete base.brand_kit_id;
        base.apply_brand = false;
    }
    return base;
}

/** A one-sentence plain summary of what a fill did: what came from the brief and what is still sample text. */
export function summariseFill(result) {
    const left = (result.left || []).filter((x) => x.reason === 'no_value').map((x) => x.name || x.slot);
    const parts = [];
    const filled = (result.filled || []).length;
    if (filled) parts.push(`Filled ${filled} spot${filled === 1 ? '' : 's'} from your brief and brand.`);
    if (left.length) parts.push(`Still sample text: ${[...new Set(left)].slice(0, 5).join(', ')}.`);
    return parts.join(' ');
}

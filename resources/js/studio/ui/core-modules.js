/*
 * The core panels, registered through the same registry the other modules use.
 * They are not special: remove one and the shell still works.
 */
import { registerInspector } from './inspector/index.js';
import { shortcutSheet } from './shortcuts-install.js';
import { brandReviewDialog, mountBrand } from './tabs/brand.js';
import { mountBackground } from './tabs/background.js';
import { mountElements } from './tabs/elements.js';
import { mountTemplates } from './tabs/templates.js';
import { mountText } from './tabs/text.js';
import { mountUploads } from './tabs/uploads.js';

export function registerCore(ctx) {
    const edit = (ctx2) => !ctx2.readOnly;
    const tab = (id, label, icon, order, mount) => ctx.registerLeftTab({ id, label, icon, order, mount, visible: edit });
    tab('templates', 'Templates', 'template', 10, mountTemplates);
    tab('elements', 'Elements', 'shape', 20, mountElements);
    tab('text', 'Text', 'text', 30, mountText);
    tab('uploads', 'Uploads', 'images', 40, mountUploads);
    tab('brand', 'Brand', 'swatch', 50, mountBrand);
    tab('background', 'Background', 'artboard', 60, mountBackground);
    registerInspector(ctx);
    ctx.registerDialog('brand-review', brandReviewDialog());
    ctx.registerDialog('shortcuts', shortcutSheet(ctx));
}

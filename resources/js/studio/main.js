/*
 * Entry of the Dot.Design editor. Reads the boot JSON the page rendered, starts
 * the engine, builds the context and the shell, loads the feature modules and
 * hands over. Everything the engine exports stays available from here.
 */
import './ui/dom.js';
import { createStudio } from './engine/studio.js';
import { boot as bootEditor } from './ui/boot.js';

export { createStudio, EVENTS } from './engine/studio.js';
export { createViewer } from './engine/viewer.js';
export { createCore } from './engine/core.js';
export { COMMAND_NAMES } from './engine/commands.js';
export { renderPageToSvgString } from './engine/render.js';
export { createFetchSaveAdapter, SAVE_STATES } from './engine/autosave.js';
export { SCHEMA, LIMITS, defaults, normaliseElement, normalisePage, validate, migrateElement } from './doc/schema.js';
export { parsePath } from './doc/path.js';

void createStudio;

if (typeof document !== 'undefined') {
    const run = () => { const el = document.getElementById('studio-boot'); if (el && document.getElementById('studio-app')) bootEditor(el); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
}

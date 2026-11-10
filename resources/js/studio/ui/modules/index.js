import { stubModules } from './deferred.js';

// The feature modules are separate chunks (ui/modules/deferred.js): the editor entry holds only their stubs, and each real
// module loads in idle time or on first use. The real modules are unchanged: assistant, export, animate, resize, share,
// comments, versions, review, files (each still exports `register(ctx)`).
export const modules = stubModules();

export { warmModules } from './deferred.js';

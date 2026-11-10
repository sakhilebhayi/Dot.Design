/*
 * The "Save a copy to Dot.Files" entry shared by the Download and Share dialogs. It is offered only when the server says the
 * integration is on (config.flags.filesVault) AND the Download module has provided ctx.exports.render. The dialog itself lives in
 * files.js, a lazy chunk: opening it first makes sure that chunk is loaded.
 */
export function filesEntryAvailable(ctx) {
    const flags = (ctx.config && ctx.config.flags) || {};
    return !!(flags.filesVault && ctx.exports && typeof ctx.exports.render === 'function');
}

export function openFilesDialog(ctx) {
    const loader = ctx.__moduleLoader;
    const ready = loader && typeof loader.ensure === 'function' ? loader.ensure('files') : Promise.resolve();
    ready.then(() => ctx.openDialog('files.save')).catch(() => ctx.toast('Dot.Files could not load. Check your connection and try again.', { kind: 'bad' }));
}

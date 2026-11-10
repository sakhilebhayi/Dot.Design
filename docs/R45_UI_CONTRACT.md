# R-45 wave 2: editor UI module contract

The editor shell (owner: **editor-ui** agent) boots the engine and exposes a small registry. Every feature panel is a module in `resources/js/studio/ui/modules/<name>.js` that exports `register(ctx)`. `resources/js/studio/ui/modules/index.js` imports them all (stubs exist so every branch builds; each owner replaces only their own file). Modules must not import each other; they talk through `ctx`.

```
ctx = {
  studio,            // engine (docs/R45_ENGINE.md): store, exec, undo/redo, select, on, renderPageToSvg
  config,            // boot JSON: project {id,name,width,height,unit,format_key,status,visibility,revision per page}, pages, assets, brand, fonts,
                     // formats, limits, urls {name: string}, permissions {view,edit,comment,share,export,review,approve,delete}, flags {ai,filesVault,notify}, user {id,name}
  api,               // {get,post,put,patch,del}(url, body?) -> Promise<{ok,status,data,message,errors}>; adds CSRF + JSON headers; never throws on HTTP errors
  toast(message, {kind:'ok'|'warn'|'bad', action?:{label,onClick}}),
  confirm({title, body, confirmLabel, danger}) -> Promise<boolean>,
  mode,              // 'simple' | 'pro' (getter) ; ctx.on('mode', fn)
  on(event, fn),     // 'mode' | 'selection' | 'page' | 'save-state' | 'dialog'
  registerLeftTab({id, label, icon, order, modes: ['simple','pro'], mount(el, ctx) -> {destroy()}}),
  registerTopbarAction({id, label, icon, order, modes, onClick(ctx) | menu: [{label, onClick}], visible?(ctx)}),
  registerInspectorSection({id, label, order, modes, when(selection, ctx) -> bool, mount(el, ctx) -> {update?(), destroy()}}),
  registerDialog(id, {title, size:'sm'|'md'|'lg', mount(el, props, ctx) -> {destroy()}}),
  openDialog(id, props), closeDialog(),
  registerContextAction({id, label, icon, when(selection, ctx), run(ctx)}),
  registerShortcut({keys:'mod+shift+e', label, run(ctx)}),
}
```
Rules: UI strings in Simple mode use the plain-language labels from the architecture doc; every control has an accessible name and a visible focus ring from `--lt-focus`; all colours/spacing from `--lt-*` tokens (docs/R45_DS.md) and the `--studio-*` canvas chrome properties; no inline `<script>`, no external hosts; modules never call `fetch` directly (use `ctx.api`); every network failure surfaces through `toast` or an inline state, never silently; destructive actions use `ctx.confirm`.
Module files and owners: `assistant.js` (ai), `export.js` `animate.js` `resize.js` (output), `share.js` `comments.js` `versions.js` `review.js` `files.js` (collab). Core panels (select/inspect/layers/pages/assets/elements/text/background/brand/templates swap/mode toggle/preview) are inside editor-ui's own files.

Implementation notes (editor-ui): the shell, the extensions beyond this contract (`primary`, `visible`, `render`, `bare`, shortcut `group`, ctx helpers and events) and a module recipe are in `docs/R45_UI_EDITOR.md` and D-703. `registerContextAction` and `registerShortcut` run only for editors; a viewer has no left tabs, so collab modules that serve viewers register topbar actions and dialogs.

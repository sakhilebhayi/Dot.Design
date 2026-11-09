# R-45 editor workspace (editor-ui)

The studio itself: a full-bleed workspace over the engine (`docs/R45_ENGINE.md`), with two modes over one document. **Simple** (default) shows one panel that holds only what applies to the selection, in plain words. **Pro** shows every property, layers, rulers, guides and a grid. Switching changes what is shown, never the data. Decisions: `D-700` to `D-713` in `docs/R45_DECISIONS.md`. Skills record: `docs/R45_SKILLS.md`. Screens: `docs/R45_screens/editor/`.

## Where things are

| Path | What |
|---|---|
| `app/Http/Controllers/Studio/EditorController.php`, `routes/studio/editorui.php` | The page (`design.projects.show`) and `design.studio.elements` (the built-in elements file, loaded lazily). |
| `app/Services/Design/EditorBoot.php` | Builds the boot JSON. |
| `resources/views/layouts/studio.blade.php`, `design/projects/show.blade.php` | Full-viewport layout (skip link, theme script, `@vite`), and the page: `#studio-app` plus `script#studio-boot`. No inline script. |
| `resources/css/studio.css` | All the editor's styling, on the `--lt-*` tokens and the `--studio-*` canvas properties. The only inline styles are dynamic geometry written through the CSSOM (custom properties such as `--x`, `--fx`, `--p`). |
| `resources/js/studio/main.js`, `ui/boot.js` | Reads the boot block, starts the engine, builds ctx and the shell, registers the core panels, then the modules in `ui/modules/index.js`. |
| `resources/js/studio/ui/` | The shell and every core panel (below). `ui/modules/*.js` belong to the other wave-2 agents. |
| `tests/js/ui-*.test.mjs`, `tests/Feature/StudioEditorPageTest.php` | 47 JS and 11 PHP tests for the pure parts and the page. |

## Boot JSON (`script#studio-boot`)

`project` (id, name, size, unit, status, visibility, `format_key`, `brief`, `brand_kit_id`, `brand_version`, review), `pages` (the document, v1 upgraded on read, with `revision`), `assets` (index by id), `permissions` (`role`, `capabilities`, `can{view,create,edit,comment,export,share_link,request_review,approve,delete,...}`), `limits`, `brand` (the project's linked kit, else the workspace default; `null` when none), `fonts` (entries of `config/design_fonts.php` with `files` as URLs), `icons`, `uiIcons`, `formats`, `settings` (mode, snap, grid, rulers, onboarded), `flags {ai, filesVault, notify}`, `urls` (every named `design.*` route as a path with `{project}` filled, other placeholders left, plus `dashboard` and `login`), `user`. Hex-escaped (`JSON_HEX_*`), so a design that says `</script>` stays text (tested).

## Architecture of `resources/js/studio/ui/`

```
boot.js           wires everything; engine errors become toasts; screen reader announcements of changes
context.js        createContext(): the ctx every panel codes against (registry, api, assets, brand, settings, mode, run, url, can ...)
registry.js       pure: tabs, topbar actions, inspector sections, dialogs, context actions, shortcuts
api.js            fetch wrapper: CSRF + JSON headers, never throws, {ok,status,data,message,errors}, 401/419/redirect -> session hook
adapter.js        the engine's save adapter over the studio API (create = POST then PUT, reorder, delete)
shell.js          grid skeleton, left rail and panel, right panel (Simple host, Pro tabs Design/Layers), sheets on compact
topbar.js         back, rename, honest save chip, undo/redo, zoom, Simple/Pro, preview, module slots, More (phones)
stage.js          crop marks, grid, rulers and guide dragging, empty state, drop targets, read-only pan and zoom
floating.js       selection toolbar (Simple: full; Pro: short; phone: docked strip), `pickPicture`
contextmenu.js    right click / long press: core actions + registerContextAction
panels/simple.js  the contextual Simple panel     panels/layers.js  Layers (tree, rename, hide, lock, drag reorder)
inspector/        Pro sections registered through the same registry (page, arrange, align, text, fill, icon, picture, outline, appearance, layer)
tabs/             templates, elements, text, uploads, brand (+ review dialog), background
pagestrip.js      real thumbnails, add / duplicate / delete (confirm) / reorder (drag or menu)
preview.js        the design as a viewer sees it (the export renderer, no placeholders)
reposition.js     move a picture inside its frame (focal point) by dragging
uploads.js        upload with progress (XHR), validation in plain words, master asset index, place or replace
reliability.js    crash recovery, failed save banner, conflict, offline, expired session
thumbnail.js      debounced thumbnail after a confirmed save
shortcuts*.js     key matching (pure), the router, the shortcut sheet
fields.js, popover.js, overlays.js, dom.js, quick.js, actions.js, insert.js, describe.js, units.js, ruler.js
```

Data flow: the engine owns the document. Panels read `studio.getState()` (immutable, compare by reference) and write through `ctx.run(name, args, {key})` (= `studio.exec`); every change is one undoable command. Numeric fields pass `key: 'inspector:<prop>:<ids>'`, so a scrub or a burst is one undo step. Refusals (locked, limits) reach the person through the engine's `error` event as one short toast.

## How a module plugs in

`resources/js/studio/ui/modules/<name>.js` exports `register(ctx)`. Nothing else is imported; everything is on `ctx` (contract: `docs/R45_UI_CONTRACT.md`, extensions: D-703). The shell works with all stubs empty (checked), and a module that throws while registering is logged and skipped.

```js
export function register(ctx) {
    ctx.registerTopbarAction({ id: 'share', label: 'Share', icon: 'share', order: 90, primary: true,
        visible: (c) => c.can('share_link'), onClick: (c) => c.openDialog('share') });
    ctx.registerDialog('share', { title: 'Share this design', size: 'md',
        mount(el, props, c) { /* build into el; return {destroy()} */ } });
    ctx.registerLeftTab({ id: 'versions', label: 'History', icon: 'history', order: 120, modes: ['simple', 'pro'],
        mount(el, c) { /* return {show?(), destroy?()} */ } });
    ctx.registerInspectorSection({ id: 'animation', label: 'Animation', order: 80, modes: ['pro'],
        when: (sel) => sel.count === 1, mount(el, c) { return { update(sel) {} }; } });
    ctx.registerContextAction({ id: 'comment', label: 'Add a comment', when: (sel) => sel.count > 0, run: (c) => {} });
    ctx.registerShortcut({ keys: 'mod+shift+e', label: 'Export', group: 'File', run: (c) => c.openDialog('export') });
}
```

Rules that bite: use `ctx.api` (never `fetch`) and `ctx.url('design.<route name>', {param})`; every control gets an accessible name; confirm destructive actions with `ctx.confirm({title, body, confirmLabel, danger})`; show a network failure with `ctx.toast(message, {kind: 'bad'})` or inline; plain sentence-case words, no em or en dashes. `ctx.selection()` returns `{ids, count, el, elements, kind, locked, emptyPicture, page, ...}`. Left tabs stay mounted once opened (`show()` is called again when reopened). A tab or action may pass `visible(ctx)`; viewers have no left tabs, so comment and approve belong in topbar actions or dialogs.

## Simple and Pro

> The Simple panel was regrouped by intent in the polish pass (main action, Size, Colour, Font, Style, an Arrange icon row, "Make it move" folded away) and the top bar was reordered (D-1900 to D-1903, `docs/R45_POLISH_EDITOR.md`). The lists below say what is available, not where it sits.

Simple, on a selection (plain words): Edit text; Smaller and Bigger; Colour (brand chips, colours on this page, more); Font (curated list with specimens, brand fonts first); Bold, Italic, alignment; Make it transparent; Rounded corners; Replace picture, How it fits (Fill the space, Whole picture), Move the picture inside its frame, Zoom; Move forward, Move back; Duplicate; Lock; Delete. Several items: Line up, Spread evenly, Make same size, Group together. Nothing selected: Background colour, Add a heading, a picture, a shape or icon. An empty picture spot reads "Add a picture" and opens Uploads with a "Pick a picture for this spot" banner; picking fills that spot.

Pro: position and size with units (px, mm, in, remembered; typing "12mm" works; shift 10x, alt 0.1x while scrubbing the field's grip), rotation, flip, keep-shape lock; align (to selection or page), distribute, match size; typography (font, weight limited to what the font has, size, line height, letter spacing, case, align, vertical align, italic, underline, strike, box behaviour, colour, brand text styles); fill (none, colour, gradient with up to 6 stops, kind, angle), corner roundness, sides, points; outline (colour, width, dash); opacity, blend, shadow; picture (fit, zoom, focus, cut to a shape, corners, border, 7 filters); layer (name, lock, hide, group, ungroup, order). Layers panel. Rulers, guides (drag from a ruler), grid and snapping, saved through the settings endpoint. Custom colours are saved per browser (`localStorage`), not on the server.

## Keyboard map

Engine (unchanged): arrows nudge 1, Shift+arrows 10; Delete; Cmd/Ctrl+D duplicate, +G group, +Shift+G ungroup, +A all, +Z undo, +Shift+Z or +Y redo, +C/X/V, +Shift+V in place; `[` `]` back and forward, Cmd+`[` `]` back-most and front; Enter edits text or opens a group; Escape leaves text, group, then selection; Tab and Shift+Tab cycle elements when the canvas is focused; Space+drag pans; Shift keeps an axis or the ratio, Alt turns snapping off (resize from the centre).
Editor: `?` this sheet (also in the top bar), Shift+1 fit, Shift+0 100%, `+` `-` zoom, `P` preview (Escape leaves; arrows change page), PageUp and PageDown change page, Cmd/Ctrl+S save now, `T` heading, `R` rectangle, `O` circle, `L` line, Alt+M switch Simple and Pro, Shift+R rulers, Shift+G grid, Shift+S snapping. Typing in a field never triggers a shortcut. The skip link goes to the canvas; every control is a real button, input or select with a name and the `--lt-focus` ring.

## Mobile and tablet

> Superseded in part by the polish pass (`docs/R45_POLISH_EDITOR.md`, D-1907): the sheet model now starts below 768 px (and for a phone on its side), the sheets have three snap points, and 768 to 1024 is a docked layout with the inspector as a drawer. The numbers below describe the first build.

Below 60rem (D-708) the three columns become a stack: top bar (back, name, save icon, undo, redo, More), the stage, a bottom sheet (the left panel for a tab, or the Edit panel for the selection; swipe down on its header or press the x to close; the page strip hides while a sheet is open so the page keeps room), the page strip as a swipe row, and the tab bar as a bottom row. A selection shows a floating strip docked above the bottom bars: Edit text or Replace picture or Add a picture or Group, then Colour, Font, Layer (a sheet with the four order moves), Duplicate, Delete, More (opens the Edit sheet). Pinch zoom and two-finger pan are the engine's, long press opens the context menu, and every target is 44 px or more (measured: strip 44, tab bar 55, sheet buttons and swatches 44, page thumbnails 44 wide). Under 48rem the top bar folds Simple/Pro, preview, zoom and module actions into More.

## Reliability

| Situation | What the person sees | What really happens |
|---|---|---|
| Normal | Chip: No changes yet, Unsaved changes, Saving, Saved (time on hover) | The wording follows the engine's seven states; "Saved" only after the engine says so. |
| Offline | Chip "Offline, kept on this device" and a banner | Edits stay in the crash journal; saving resumes when the browser is online. |
| Failed save (5xx, network) | Chip "Not saved. Retry" and a banner with the server's message and "Try again" | The engine retries by itself (1 s doubling to 30 s); the work is kept on this device. |
| Conflict (409) | Banner and a dialog with two plain choices | Saving is paused. "Load the latest" discards mine and reloads. "Save mine over theirs" first saves their version as a restore point, then overwrites. |
| Session ended (401, 419, login redirect) | Banner "You are signed out" and a dialog | The document stays open. Open sign-in in a new tab; the dialog notices (every 4 s and when the tab regains focus), then saving resumes. |
| Crash or closed tab with unsaved work | Dialog "We found work that was not saved" with the pages and times | Restore (one undo step), Discard (confirmed), or Decide later. If the server has a newer version the dialog warns and the button reads "Restore my changes anyway". |
| Leaving | Browser prompt only while something is unsaved | A deliberate leave or reload asks once (D-709). |

## Acceptance (done in a real browser, by me)

Driven with Playwright (an isolated Chromium) at 1440x900, 820x1180, 768x1024 and 390x844; screens in `docs/R45_screens/editor/`. The guided start flow had not landed, so projects were made with the business `POST design/projects/from-brief` endpoint (the call the start flow will make).

- **(a) Beginner** (Simple): from-brief with the flyer template, a brief and the workspace brand kit; replaced the headline by double click and typing, replaced the small business-name text through the panel's Edit text; added a picture to the empty spot (select it, Add a picture, upload through the file picker, click the picture); recoloured the headline with a brand chip; Preview; waited for Saved; closed, reopened and compared the document (identical). `a1` to `a8`.
- **(b) Pro**: blank square design; added a rectangle, an icon, a heading and a picture through the panels; resized (620) and rotated (-8) through the inspector; changed font, size, letter spacing and case; selected all, grouped and ungrouped (Cmd+G); three circles aligned in the middle and spread across (exactly 330 px apart); dragged a guide from the ruler and dragged a circle near it (landed exactly on 203, the guide); 36 further edits, then undo and redo through the whole history (56 steps, document identical both ways); reload identical. `b1` to `b6`.
- **(c) Phone**: all of the above layout at 390: no horizontal scroll at 390, 768 and 1440, targets measured, sheets, strip, swipe row, preview. `c1` to `c8`.
- **(d) Network**: offline mid-edit then back (saved by itself); a server returning 500 twice (failed banner, retry, saved); saves blocked and the tab closed (recovery dialog, restore, saved; discard, journal cleared); the same page saved from another window (conflict dialog, Load the latest shows their version); every save answering 401 (signed-out dialog, then "signed in" restores saving). `d1` to `d6`.
- **(e) Stress**: 200 elements (shapes with rotation and shadows, plus text) all selected and dragged by mouse (80 moves): frames 16.7 ms at p50, p95 and max (60 fps, none dropped), the engine's handler 0.31 ms average, 4 ms worst; saving the 76 KB page took 0.5 s. `e1`.
- Also clicked through: templates (browse, detail, Use for a new page with a 3-page template, Replace this page with the restore point and undo), elements (drag and tap), text pairings, uploads (drop a file on the page, paste an image, delete refused with where it is used, delete), brand (chips, review dialog applying 9 of 10 changes, empty state), background (gradient presets), layers (rename, drag, hide, lock), pages (add, duplicate, move, delete with confirm, drag reorder, reload keeps the order), rulers, grid, guides, snap, context menu, shortcut sheet, reposition, preview, read-only viewer, dark theme, the first-run tip (dismissed once, remembered).
- Time from navigation to ready: 70 to 100 ms on this machine for a 4-page, 28-element design (not measured on a throttled phone profile).

## Known limits (honest)

- No contrast warning when a person picks a text colour that disappears into what is behind it (D-713).
- Guides are added by dragging from a ruler; to move or remove one use the Page section (not dragged back).
- Template replace takes the first page of a multi-page template; a template with another size keeps its own page size (the project's other pages do not change).
- Layers can be reordered inside their group by dragging; moving a layer into another group means group and ungroup.
- A viewer's workspace has no left panels; comment and approve come from the collab modules.
- Custom colours and the chosen unit are kept per browser.
- Engine gaps found: no `allowUnload`/`confirmUnload` switch (worked around, D-709); crash-journal entries for a page's temporary id (`new_*`) are not removed once the page gets its server id (harmless and bounded by the journal's caps); `history` events are not sent when a drag commits, so the top bar refreshes undo and redo after pointer and key events instead.
- Fonts: Latin subset only (engine limit).

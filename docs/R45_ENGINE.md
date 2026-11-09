# Dot.Design Studio engine (R-45)

Headless-first editing engine in `resources/js/studio/{doc,engine}/`. Pure ES modules, no framework, no runtime dependencies. The UI codes against the public API below only. Contract owner: engine agent. Decisions: `docs/R45_DECISIONS.md` D-200..D-213.

```js
import { createStudio, createViewer, createFetchSaveAdapter } from '.../studio/main.js';
const studio = createStudio({ root, document, assets, brand, fonts, formats, limits, saveAdapter, prefs, projectKey, icons });
```

## createStudio options

| option | shape |
|---|---|
| `root` | element the editor mounts in (it gets position/overflow/touch-action; give it a size) |
| `document` | `{ id?, format: {width, height}, pages: [{id, name, width, height, background, duration_ms, transition, guides, elements, revision}] }` v1 or v2 (migrated on read) |
| `assets` | `[{id, url, width, height, name}]` (url must be same-origin, `/`, `https:`, `blob:` or an image `data:`; anything else is dropped) |
| `brand` | `{colors: [{key, hex}], fonts: {heading, body, accent}}` |
| `fonts` | `{manifest: [...], baseUrl?}` or the manifest array: entries of `config/design_fonts.php` with `files` as URLs |
| `icons` | contents of `resources/design/icons.json` (optional; 12 built-ins) |
| `saveAdapter` | `{savePage({pageId, revision, payload}), createPage?, deletePage?, reorderPages?}` each `Promise<{status, body}>`; `createFetchSaveAdapter({urls:{save(id), create(), remove(id), reorder()}, csrf})` builds one for the existing PUT route |
| `prefs` | `{snap: true, grid: 0, wheel: 'scroll'|'zoom', tool: 'select'|'hand', showGuides}` |
| `projectKey` | namespaces the crash journal (default: `document.id`) |

## API

`studio.exec(name, args, {key?, live?})` returns `{ok:true, result?, noop?}` or `{ok:false, error:{code, message}}`. Never throws. `key` coalesces bursts within 700 ms into one undo step.
`studio.undo() / redo() / canUndo() / canRedo() / history()` (`{canUndo, canRedo, undoLabel, redoLabel}`).
`studio.select(ids, {group}) / selectAll() / clearSelection() / enterGroup(id|null) / setActivePage(id)`.
`studio.getState()` / `studio.store.getState()/subscribe(fn)` : `{doc:{format,pages}, activePageId, selection, group, editingId}` (immutable; compare by reference). `studio.activePage()`.
`studio.on(event, fn)` returns an unsubscribe function.
`studio.renderPageToSvg(pageIndex, {forExport=true, scale, fontCss, idPrefix})` sync string; `renderPageToSvgAsync(i, {embedFonts})` waits for fonts and can embed them as data URIs. The same renderer draws the canvas, thumbnails and exports (placeholders for empty picture slots are excluded when `forExport`).
`studio.viewport`: `fit() fill() actual() setZoom(z, anchor?) zoomIn() zoomOut() panBy(dx,dy) zoom view screenToPage() pageToScreen()` (zoom 5%..800%).
`studio.save`: `state`, `info {message, serverRevision, retryAt}`, `hasUnsaved()`, `flush()`, `retry()`, `resolveReload()`, `resolveOverwrite()`.
`studio.recovery`: `list()` (`[{pageId, revision, serverRevision, stale, at, page}]`), `apply(pageIds, {allowStale})`, `discard(pageIds)`.
`studio.clipboard`: `copy() cut() paste({inPlace})` (async; for buttons). Keyboard copy/cut/paste work through real clipboard events.
`studio.beginTextEdit(id, {selectAll}) / endTextEdit(commit=true)`; `setInputEnabled(false)` while a dialog is open; `setPrefs()`, `setBrand()`, `setAssets()`; `fonts` (manager: `ensureLoaded`, `usedSpecs`, `embedCss`, `list`); `perf` counters; `destroy()`.

### Events
`change {state, metas, system}` (doc changed; `system:true` for revision/reflow bookkeeping) · `selection {ids, group}` · `page {activePageId, pageIds}` · `save-state {state, info}` · `history` · `viewport {zoom,x,y}` · `error {code, message, warning?}` (limits, locked, font/recovery warnings) · `contextmenu {x, y, ids, source: 'pointer'|'long-press'}` · `activate {id, type}` (double-click an image/shape/icon) · `text-edit {phase: start|input|end, id}` · `font` · `recoverable {pages}` · `paste-files {files}` · `ready {issues}`. Store notifications are batched per frame (120 ms timer fallback in hidden tabs).

## Save states
Exactly `idle | pending | saving | saved | failed | conflict | offline`. `saved` only after a 2xx whose JSON carries an integer `revision` (a 2xx without it is `failed`). Debounce 800 ms, changed pages only, compare-and-swap, single flight, retry 1 s doubling to 30 s for 5xx/429/network, no retry for 401/419/403/422 (`retry()` or the next edit tries again). 409 puts the engine in `conflict` and stops all saving until `resolveReload()` (discard + reload) or `resolveOverwrite()` (adopt the server revision from the 409 body, save mine). `beforeunload` is blocked only while unsaved; `sendBeacon` is never used; a hidden tab flushes.

## exec commands (args)
All take an optional `pageId` (default: active page). `ids` may be `id`.
- `element.add {type|element, ...props, parentId?, index?, select?}` centres when x/y are omitted; text grows per autoFit. `{type:'text'|'shape'(kind)|'image'|'icon'}`.
- `element.remove {ids}` · `element.update {ids, changes, perElement?}` (schema-validated; id/type/children ignored; group width/height ignored) · `element.move {ids, dx, dy | x, y | positions:{id:{x,y}}}` · `element.resize {id, width?, height?, x?, y?, keepRatio?, anchor?:'topleft', scaleFont?}` (groups scale their contents) · `element.transform {boxes:{id:{x,y,width,height,rotation,fontSize,scaleFont}}}` (low level, used by drags) · `element.rotate {ids, rotation | delta, unit?}`
- `element.reorder {ids, to:'forward'|'back'|'front'|'back-most'}` · `element.duplicate {ids, offset?, inPlace?}` · `element.group {ids}` / `element.ungroup {ids}` (depth <= 3) · `element.lock|hide {ids, value?}` · `element.rename {id, name}`
- `text.edit {id, text}` (use `key:'text:<id>'` to coalesce)
- `page.add {index?, page?, after?}` · `page.remove {id}` · `page.duplicate {id}` · `page.reorder {id, to}` · `page.update {id, changes:{name,width,height,background,duration_ms,transition,guides}}`
- `align.left|center|right|top|middle|bottom {ids, to?:'selection'|'page'}` (one element aligns to the page) · `distribute.horizontal|vertical {ids}` (>= 3) · `size.match {ids, dimension:'width'|'height'|'both', to?:'first'|'largest'|'smallest'}`
- `paste {payload|elements, inPlace?, offset?}` (validates; foreign/hostile payloads are refused) · `brand.apply {ids, prop:'color'|'fill'|'stroke_color'|'shadow_color'|'font'|'background', token}` or `{update:true, brand?, pageIds?}` (re-resolve recorded tokens, one undo step)
- `doc.replacePages {pages, selection?, activePageId?}` (AI/template apply: sanitised like any input, ONE undo step) · `doc.reflow` (system, no undo)
Error codes: `unknown-command bad-type bad-arg no-page no-element no-group no-token bad-token bad-clipboard empty locked mixed-parents depth limit too-large too-few last-page`.

## Keyboard
Arrows nudge 1 (shift 10, one undo per burst) · Delete/Backspace remove · cmd/ctrl+D duplicate · +G group · +Shift+G ungroup · +A select all · +Z undo · +Shift+Z / +Y redo · `[` `]` back/forward · cmd+`[` `]` back-most/front · Enter edits text (or enters a group) · Esc leaves text, group, then clears selection (and cancels a drag) · Tab cycles elements when the canvas is focused · Space+drag or middle-drag pans · shift while dragging locks the axis; alt disables snapping (and alt-drag duplicates); on resize shift = ratio, alt = from centre; rotation snaps to 15 degrees with shift. cmd/ctrl+C/X/V use clipboard events; cmd+shift+V pastes in place.

## Pointer and touch
Mouse: drag, marquee, handles (8 + rotate), double-click edits text / enters a group, right-click emits `contextmenu`. Touch: tap selects, drag moves, one finger on empty canvas pans, two fingers pan and zoom, double-tap edits, long-press (520 ms) emits `contextmenu`; handle hit areas are 44 px. Elements inside `[data-studio-ignore]` do not start gestures.

## Extension points for the UI
- Style the chrome with CSS variables on the root: `--studio-select`, `--studio-guide`, `--studio-hover`. Page surround, shadow and background are the UI's (`.studio-root`, `.studio-stage`, `.studio-page`, `.studio-overlay`).
- Inspector/layers read `getState()`; every control calls `exec`. A numeric field should pass `{key:'inspector:<prop>:<id>'}` so scrubbing is one undo step.
- Image replacement: listen for `activate`/`paste-files`, upload, call `studio.setAssets([...])` then `exec('element.update', {ids, changes:{asset_id}})`.
- Brand: `setBrand()` then `brand.apply`; accepting a brand update is `brand.apply {update:true}`.
- Text editing from a toolbar: `exec('element.add', {type:'text', ...})` then `beginTextEdit(id)`.

## Known limits (honest)
Latin-subset fonts only (other scripts use the fallback stack). Textarea wrapping while editing and SVG layout can differ by a glyph on exotic fonts; the committed layout is the truth. Shadow/blend of a group are dropped on ungroup; groups flattened past depth 3 lose intermediate rotation. Text stroke is centred. Flipped groups refit correctly but rotate-handle angles for children of flipped parents are measured in page space. Real-time co-editing, background removal and video are out of scope (architecture section 8). The live canvas is an SVG: very large pages with hundreds of filters (shadows, image filters) cost more than plain shapes; measured drag cost with 201 elements selected is about 0.3 ms per pointer move.
## Tests
`node --test "tests/js/*.test.mjs"` (324 tests: schema, migration, commands + inverses, history coalescing, transactions, transform math incl. rotated multi-select, snapping, align/distribute, groups, text layout, render snapshots, hostile text/URL/path inputs, live renderer touch counts, autosave state machine with fake timers/fetch, recovery with throwing storage, fonts, viewport, a seeded random-sequence property test) and `php artisan test --compact tests/Unit/DesignFontsConfigTest.php`.

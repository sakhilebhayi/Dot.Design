# R-45 output: export, animation and smart resize

Owner: output agent. Decisions: `docs/R45_DECISIONS.md` D-1000..D-1013. Skills record: `docs/R45_SKILLS.md` (last section). Screens: `docs/R45_screens/output/`.

## What was built

| Area | Files |
|---|---|
| Pure modules (no DOM, node-tested) | `resources/js/studio/output/`: `bytes.js` `zip.js` `filenames.js` `export-pdf.js` `gif.js` `animate.js` `anim-edit.js` `anim-labels.js` `resize.js` `presets.js` |
| Browser modules (injected deps, node-tested with stubs) | `export-svg.js` `export-raster.js` `export-jobs.js` `webm.js` `animate-apply.js` `playback.js` `ui-kit.js` |
| UI (R45_UI_CONTRACT) | `resources/js/studio/ui/modules/export.js` (topbar action "Download", dialog `export`, shortcut mod+shift+e), `animate.js` (left tab `animate`, inspector section `animation`), `resize.js` (topbar action "Other sizes", dialog `resize`); styles `resources/css/studio-output.css` (imported by the modules, `--lt-*` tokens only) |
| Backend | `POST design/projects/{project}/copies` (`app/Http/Controllers/Studio/DesignCopyController.php`, `routes/studio/output.php`); the export audit endpoint is backend's, wiring tested in `tests/Feature/StudioCopiesTest.php` |
| Tests | `tests/js/output/*.test.mjs` (77), fixtures `tests/js/output/fixtures/`, `tests/Feature/StudioCopiesTest.php` (13) |

## 1. Export

**Pipeline** (D-1000): `studio.renderPageToSvgAsync(i, {embedFonts: true})` (waits for the page's fonts and embeds the woff2 files as data URIs) -> pictures fetched and inlined as data URIs (sniffed by bytes, SVG pictures refused) -> optional removal of the page background -> `<img>` -> canvas -> `toBlob`. PDF, GIF, WebM and ZIP are assembled from those pictures by our own writers. No external host, no `eval`, no `Function`, no `innerHTML`.

**Dialog.** One-click presets by the project's format kind (Simple wording): "Download for Instagram" (PNG at 1x), "Download a smaller file" (JPG 85%), "Download as PDF"; print formats lead with "Download for printing" (PDF, 300 dpi), animated designs add "Download as animated GIF" and, when the browser can record, "Download as video". "More options" (open by default in Pro): file type tabs (PNG, JPG, PDF, SVG, GIF, Video; GIF and Video only when something is animated), pages (all, this page, choose), size (1x to 4x chips that can be combined, exact width, print 150/300/600 dpi), JPG quality slider with a live size estimate, see-through PNG, fonts in the SVG, GIF/video smoothness and size, a preview drawn by the real renderer at the true proportions (checkerboard when see-through), real facts, and an explicit **Prepare file** step with progress and Cancel that ends in the real size and **Download**. More than one file (pages x sizes) is one store-only ZIP. File names are `<design>-<page>-<w>x<h>.<ext>` (`filenames.js`; sanitised, reserved names and path tricks neutralised). After the download starts the format, pages, scale and bytes are posted to the backend audit endpoint; a failure there is ignored (D-1012). Errors say what failed and what to try (`ExportError{code, message, hint}`): picture could not be loaded, picture type not exportable, page could not be drawn, browser could not make an image that large, empty file, cancelled.

**Honest labels.** PDF: "Pages are saved as high-resolution pictures. The text in a PDF made here is not selectable and cannot be searched." Video when unsupported: the reason and a "Use GIF instead" button (no dead button). GIF: an "about X (estimate)" from real frames, and a warning above 8 MB.

## 2. Animation

`animate.js` is a pure `evaluate(page, tMs) -> Map<elementId, {opacity, translateX, translateY, scale, rotate, clip?, blur?, textProgress?}>` implementing every preset in the schema enums (a test fails if the lists drift from `document-schema.json`): in: fade, rise, drop, slide-left/right, zoom-in, pop (overshoot), wipe-left/up, type (character reveal; wipe on non-text), blur-in; out: fade, sink, slide-left/right, zoom-out, wipe-left; loop: pulse, float, spin, wiggle; easings linear, ease-out, ease-in-out, spring. Timing semantics are in D-1004 (`out.delay_ms` = how long before the page end the exit has finished). Deterministic: same input, same output, asserted frame by frame.

Applying it: `animate-apply.js` has one set of frame maths (animation about the element centre composed with the element's own matrix) with two carriers: `applyAnimationToSvg` (string, used by GIF/WebM frames) and `createDomPlayer` (live canvas, D-1005). `playback.js` drives the preview on wall-clock time.

UI: the **Animate** tab (Animate this design, Play/Pause, Back to editing, Repeat, scrub, page length, "Then the next page" transition, tracks with start/length bars that can be dragged or moved with the arrow keys (shift = 250 ms), Stagger all) and an **Animation** inspector section (Comes in, Goes out, Keeps moving, each with its settings and a Preview button). "Animate this design" is a proposal (D-1007), one undo step. `prefers-reduced-motion` only affects the editor's own transitions in the CSS; playing a design is something the user asks for.

**Performance** (headless Chrome 157, 60 animated elements: 20 text, 20 shapes with shadows, 20 pictures, entrances, pulse loops and exits, 4 s page, 5 s measured): JS per frame 0.49 ms average (max 1 ms), 0 frames over 33 ms of JS work, frame interval 20.1 ms average (p95 17.6 ms), about 50 fps effective while painting. The 30 fps target holds with large headroom; the limit is painting, not scripting. Command: `window.__h.perf60()` in the throwaway harness (not committed).

## 3. Smart resize

`resize.js`: `resizeDocument(pages, fromFormat, toFormat, {measure})` returns pages; `resizeDocumentWithReport` also returns the per-element report (`moved`, `scaled`, `re-wrapped`, `re-cropped`, `needs your check` with a reason) plus scale factors and whether type shrank. The algorithm is D-1010. The dialog "Make this for another place" lists the aspect family and closest shapes first, then every group of use; several targets at once; one rendered preview per target with a plain summary ("23 elements placed, 2 text re-wrapped, 1 picture re-cropped") and the list of elements that need a look; **Create copies** posts one request per target and then offers "Open the new design" links; per-target failures are shown where they happened.

**Quality check.** 13 pairs from the 58 library templates are fixtures (dumps in `tests/js/output/fixtures/`), measured with the library's real per-character advances; the tests assert: no element lost or invented, ids unique, text never taller than its box, nothing outside the page unless flagged, the source never modified (deep-frozen input), pages, names and durations kept, background re-covered, groups scaled as one piece with their children, pictures re-cropped with their focal point, same-family resize near-lossless, extreme downsizing flagged rather than hidden. The results were also looked at in the browser (screenshots `resize-gal-a/b/c.jpg`) and the heuristics were changed until they looked right; what that process fixed: panels and their text moving apart, drop-shadow rectangles drifting from their photos, text running into a side-by-side neighbour, footers whose text left their band, accent bars losing their headline, orphan words in headlines, phone numbers wrapping.

| Template (format) | To | Elements | Re-wrapped | Needs a look | Layout | Type scale | Time |
|---|---|---|---|---|---|---|---|
| announcement-clean (Instagram post square) | Instagram story | 11 | 0 | 0 | one column | 1.2 | 2.8 ms |
| ig-post-portrait-bold (Instagram portrait) | Facebook post | 8 | 0 | 0 | 2 columns | 0.5 | 1.3 ms |
| flyer-a4-corporate (A4) | A4 landscape | 23 | 1 | 0 | one column | 0.88 | 2.5 ms |
| service-ad-corporate (A4) | DL portrait | 18 | 0 | 0 | one column | 0.58 | 0.7 ms |
| business-card-clean | Business card (SA) | 17 | 0 | 0 | one column | 0.97 | 0.4 ms |
| ig-story-bold (story) | Instagram post square | 10 | 1 | 0 | one column | 0.62 | 0.4 ms |
| facebook-post-clean | Instagram portrait | 9 | 0 | 0 | one column | 1.39 | 0.3 ms |
| sale-ad-clean (A4) | A5 portrait | 22 | 1 | 0 | one column | 0.66 | 0.8 ms |
| youtube-thumbnail-bold | Instagram post square | 8 | 1 | 1 | one column | 1.11 | 0.1 ms |
| linkedin-banner-clean | Instagram post square | 14 | 1 | 1 | one column | 1.35 | 0.4 ms |
| menu-a4-elegant | Facebook post | 7 | 0 | 0 | 2 columns | 0.85 | 0.7 ms |
| ig-post-square-playful | TikTok cover | 10 | 0 | 0 | one column | 1.17 | 0.2 ms |
| flyer-a4-corporate (extreme, on purpose) | Business card 85 x 55 | 23 | 1 | 20 | one column | 0.12 | 22 ms |

The last row is the honesty test: a full A4 flyer cannot be a business card; 20 of 23 elements are flagged ("does not fit in this format") instead of pretending.

## 4. Backend

`POST design/projects/{project}/copies` body `{format_key, name?, pages: [{name?, background?, duration_ms?, transition?, elements}]}` -> 201 `{status: 'created', id, name, format_key, url}` (D-1011). 404 for a project the caller cannot see, 403 without the create capability, 422 for an unknown format, no pages, more than 60 pages, a hostile element, or a picture the caller may not use in the source project, 413 over 6 MB, 429 over 30 per minute, 401 for a guest. Tests: the original is untouched (updated_at and revision unchanged), the page size is the format's, visibility/brief/brand link carry over, the copy lands in the caller's workspace, the role matrix (owner, admin, editor create; a viewer with a personal workspace is placed there; a viewer with none gets 403), cross-tenant refusal, sanitising, the picture rule, the throttle and body ceiling on the route (inline `throttle:30,1`, route-cache safe), and the export audit payloads the dialog posts (png, jpg, svg, pdf, gif, webm; page ids; scale 0.1 to 8).

## 5. Verification

Node: `node --test "tests/js/*.test.mjs" "tests/js/output/*.test.mjs"` = 401 tests, all green (324 engine + 77 output). PHP: `php artisan test --compact` = see the report (all green, 7 skipped as before).

Real downloads in headless Chrome 157 (own CDP driver, throwaway harness page with a fake `ctx`, real engine, 2-page 1080 x 1080 design with a picture, 4 s of animation; every file was written by the browser's download mechanism and then inspected from disk):

| Check | Result | File and size |
|---|---|---|
| PNG at 2x of a 1080 x 1080 page | decoded size exactly 2160 x 2160; not blank (content differs from the background); audit posted `{format: png, pages: [101], scale: 2, bytes: 324457}` | `spring-sale-front-2160x2160.png`, 324 457 bytes |
| JPG 1x | real JPEG, 1080 x 1080; the estimate shown before preparing was "about 116 KB", the real file 98 KB | `spring-sale-front-1080x1080.jpg`, 100 773 bytes |
| PDF, 2 pages, 2x | structural parse passes (xref offsets, trailer Size, page tree Count, per-page MediaBox 810 x 810 pt for a 1080 px page, image streams 2160 x 2160, stream lengths exact, JPEG markers); title "Spring sale"; **opened in Chrome's PDF viewer**: title bar "Spring sale", 2 pages, rendered with fonts and picture | `spring-sale-1080x1080.pdf`, 452 895 bytes |
| SVG | self-contained (no script, handler, foreignObject or external href; audited), text stays `<text>`, fonts and picture embedded; re-rendered in an `<img>` at 1080 x 1080 | `spring-sale-front-1080x1080.svg`, 88 577 characters |
| GIF, 10 per second, 480 px cap | decoded by the independent decoder: 480 x 480, 40 frames (10 fps x 4 s), every delay 10 cs, loop count 0 (forever), frame 0 differs from frame 20 (the animation is in the file); the estimate before preparing was a 60-frame (15 fps) projection of about 339 KB | `spring-sale-480x480.gif`, 257 423 bytes |
| WebM | EBML header, plays in a `<video>` (480 x 480, time advancing); duration header absent (Chrome) | `spring-sale-480x480.webm`, 224 161 bytes |
| Batch ZIP, 2 pages x 1x and 2x | 4 PNG entries named `spring-sale-front-1x-1080x1080.png`, `...-2x-2160x2160.png`, `spring-sale-back-...`, each decoded to its size; `unzip -t`: no errors | `spring-sale-4-files.zip`, 854 KB |
| See-through PNG | corner pixel alpha 0 (page background left out) | `spring-sale-front-1080x1080.png`, 139 KB |
| 20 000 px exact width | reduced to 10 000 x 10 000 with the message "This browser cannot make an image of 20000 by 20000 pixels, so it was made 10000 by 10000 instead", a real 3.1 MB file | `spring-sale-front-10000x10000.png`, 3 243 085 bytes |
| Audit endpoint failing (500) | download still produced | `spring-sale-front-1080x1080.png` |
| Simple mode, one click "Download for Instagram" | PNG at 1x saved | `spring-sale-front-1080x1080.png` |

23 of 23 checks in the driver script passed on the final run (`scratchpad/cdp-output/verify-export.mjs`, log `verify-export-4.log`).

Also checked by hand in the browser: every state of the three dialogs and the Animate tab at 1440 and 390, light and dark (`docs/R45_screens/output/`); Simple mode one-click download; Pro mode Prepare, progress, Cancel and Download; keyboard: tabs, chips, checkboxes, range, arrow-key timeline nudging; timeline drag with a real mouse is one undo step; the animate proposal, apply, undo and redo; stagger; inspector edits; leaving a preview restores every element exactly (attributes compared with the renderer's own).

## 6. Limitations (honest)

- **Browser canvas limits.** Chrome and Edge allow about 16 384 pixels per side and 268 M pixels per canvas, Firefox about 32 767 per side and 472 M, iOS Safari about 16.7 M pixels in total. We cap at 100 M pixels (10 000 x 10 000) on desktop and 16.7 M on iOS, probe the real canvas and reduce with a message; 600 dpi A3 and larger will be reduced on most devices. Large exports are memory heavy.
- **PDF.** Superseded by the pmod update below: the default is now a vector PDF with real text; the raster PDF remains as "PDF (as pictures)". Colour is still sRGB, no CMYK.
- **WebM support matrix.** Verified here only in Chrome 157 (vp9 or vp8 through MediaRecorder). Expected to work in current Chrome, Edge and Firefox; Safari records MP4/other containers on most versions, so `isTypeSupported('video/webm')` is false there and the Video tab says so and offers GIF (not tested on Safari or Firefox in this environment). **No MP4.** The tab must stay in front while recording (a hidden tab's timers slow to 1 Hz; the recording stops with a clear message rather than producing slow motion). Chrome writes WebM without a duration header, so the file plays but may not show a length.
- **GIF.** 256 colours (median cut, no dithering by default) so gradients band; limited by frame count (600 frames cap warning) and memory (360 MB of frame data, the picture is reduced to fit). The estimate is a projection from sampled frames and can be 10% off.
- **Fonts.** Only the 18 shipped latin-subset families embed; other scripts fall back. SVG with embedded fonts is large (each used weight is a whole woff2 file); the toggle drops them.
- **Pictures.** Must be same-origin or blob/data (the engine already drops other hosts). A picture that fails to load aborts that export with a specific message instead of leaving a hole.
- **Animation.** Only top-level elements animate; page transitions appear in exports only (D-1008); "type" reveals characters by count (centre and right aligned text keep their alignment because the hidden part stays in layout); filters, blend modes and shadows are honoured but a 60-element page full of shadows costs paint time.
- **Resize.** Deterministic heuristics, not design intelligence: it does not know a picture's subject (focal point stays as set), it does not reorder content, it will not drop elements, and a design that changes shape by 3x or more (story to banner, A4 to card) is flagged as needing your check, honestly. Rotated elements are scaled as pieces and not re-wrapped. Bleed and crop marks are not re-derived for print targets (the Download dialog has them, see the pmod update). The preview draws icons from the built-in set when the boot JSON does not carry the full icon library.
- **Not mine, not built.** "Save a copy to Dot.Files" (collab owns it), MP4, vector PDF, server-side export.

## 7. Seams other owners should know

- `ctx.config.urls.exports` and `ctx.config.urls.copies` are used when present; otherwise `/design/projects/{id}/exports` and `/design/projects/{id}/copies` (route names `design.projects.exports.store` and `design.projects.copies.store`).
- `ctx.config.project` needs `id, name, unit, format_key, width, height`; `ctx.config.formats` is read as a flat list, `{formats}` or grouped (`groups[].formats`); `ctx.config.assets` as a list or an id map; `ctx.config.permissions.export === false` hides Download, `.create === false` hides Other sizes.
- Icon names passed to `registerTopbarAction`/`registerLeftTab`: `download`, `resize`, `play`. The shell should map them or ignore them.
- Dialog ids: `export`, `resize`. Left tab id `animate`. Inspector section id `animation`. Shortcut mod+shift+e.
- The engine exposes no animation-override hook and no `measure`/`icons`; `animate-apply.js` reads the renderer's `[data-layer=elements] > g[data-id]` frames and the resize dialog builds its own canvas measurer from `studio.fonts`. A `renderer.setOverrides()` and `studio.measure` would remove both couplings.
- The contract has no bottom dock, so the timeline lives in the left tab; it can move to a dock without changes to `playback.js`.
- `package.json` `test:js` only globs `tests/js/*.test.mjs`; add `tests/js/output/*.test.mjs` to it when merging.

## 8. pmod update (2026-11-09): vector PDF, print readiness, Animate tab

Full account, evidence and limits: `docs/R45_POLISH_MODULES.md`. Decisions D-2000 to D-2011.

- **Vector PDF** (`vector-pdf.js`, `pdf-font.js`, `pdf-objects.js`, `svg-parse.js`, `export-vector.js`): real text from embedded TrueType (`public/fonts/design/**/*.ttfz`, made by `scripts/fonts/woff2-to-ttf.mjs`), PDF paths, shadings, picture clips and alpha. Per page: anything it cannot reproduce (shadows, picture filters, blend modes, gradient outlines, letters a font lacks, fonts that are not ours) becomes a picture page with a message. Run `node scripts/fonts/woff2-to-ttf.mjs` after adding or changing a font file (a PHP test fails if a `.ttfz` is missing).
- **Print readiness** (`print-boxes.js`): bleed 0 / 3 / 5 mm, crop marks, TrimBox / BleedBox / CropBox, RGB note, a message for elements that end exactly on the page edge. Print designs are written at 1 unit = 1 pt.
- **Dialog**: custom controls (`ui/modules/_controls.js`), PDF kind chooser, "Ready for the printer" section, preview that shows the bleed and marks, "Save a copy to Dot.Files" when enabled.
- **Animate tab**: timeline first (`ui/modules/animate.js`), locale-safe numbers (`output/number-format.js`).
- **Limits that remain**: no `calt`, no CMYK, fonts embedded whole, Chrome's viewer and Apple's PDFKit are the only viewers used.

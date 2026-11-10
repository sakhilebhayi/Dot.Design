# R-45 polish: the feature modules (pmod agent)

Owner: pmod. Branch `feat/r45-pmod`. Decisions `D-2000` to `D-2011` in `docs/R45_DECISIONS.md`. Screens before and after: `docs/R45_screens/polish-modules/` (`before-*` are the outside jury's shots, `after-*` were taken in Chrome from this branch). Skill record: `docs/R45_SKILLS.md` (last section).

Scope: jury items 3 (review bar and pins), 5 (native controls, number formats, Animate), 9 (assistant previews) and 15 (animation and PDF), for `ui/modules/*.js`, `output/`, `collab/`, `assist/` and the three module stylesheets.

## What changed, by jury finding

| Jury finding | What was done | Where |
|---|---|---|
| 21a/21b/21d: the review bar overlays the artboard | The bar is a strip of its own at the top of the canvas area. The stage starts below it (the bar writes its height to `--cb-bar-h` on `.st-stagewrap`, the stylesheet moves the stage and the rulers by that much). Compact: status chip, who asked, a "Version sent" link, then the actions. On a phone it wraps above the page. | `ui/modules/review.js`, `studio-collab.css` |
| 20/21a: pins sit on the words | A pin is a small anchor dot on the design, a numbered marker beside it and a leader line. For an element the marker goes just outside the nearest edge of the element's box; markers push each other apart (and away from their own element); hover or focus shows a one-line preview; opening a thread outlines the element it is about; a "Hide comments" button on the canvas and a switch in the panel hide them all; the hit area is 44 px (touch) although the marker is 22 px. | `collab/pins.js` (pure placement), `ui/modules/comments.js`, `studio-collab.css` |
| 16, 19, 21e: native select, check box, radio | `ui/modules/_controls.js`: `select`, `combobox` (people, with avatars and search), `switchControl` (visible track, On/Off), `checkbox`, `radioCards`, `segmented`, `numberField`, `slider`. Built to the `--lt-*` tokens, keyboard operable, accessible names, focus rings, reduced motion, forced colours, 44 px targets on touch. Every native `<select>`, bare check box, radio and `type=number` in the modules was replaced. The ds had no JS-buildable select or switch and pedit has not created `ui/controls/`, so the helpers are mine. | `_controls.js`, `studio-controls.css` |
| 16: "5,0" next to "0.0 / 5.0 s" | The cause was the browser's own `<input type=number>`, which prints the reader's decimal mark. No number field in the modules is a native one any more; `output/number-format.js` (`fmtFixed`, `fmtSeconds`, `parseDecimal`) always writes a dot and reads either mark. Tested with a comma-decimal locale (Chrome `de-DE`, Node with `toLocaleString` replaced). | `number-format.js`, `numberField` |
| 16/16b: Animate looks like one button and a slider | The tab opens into the timeline: one row per element, bars you drag or move with the arrow keys, a ruler whose labels thin out so they never touch, a playhead, scrub, Play/Pause, Back to editing, Repeat, a status line (not playing / playing / paused at 1.5 s). "Animate this design" is one button above it. Selecting a row shows that element's Comes in / Goes out / Keeps moving presets under the timeline. The Add rows are small dashed buttons, not text. | `ui/modules/animate.js`, `studio-output.css` |
| 15/15b/15c: PDF is raster; no bleed, no crop marks | See "PDF" below. | `output/vector-pdf.js` and friends |
| 18c/18d: assistant preview | One large preview (Before / After) fitted by its own aspect ratio on the stage colour, changed elements outlined, "Outline what changes" switch, a sentence saying how many are outlined, the plain-language list below. | `assist/panel.js`, `assist/preview-model.js` |
| 18c/18d: "Save to Dot.Files" changes the top bar | No top bar action any more. The entry is "Save a copy to Dot.Files" inside the Download dialog and the Share dialog, shown only when `flags.filesVault` is on and the export handle exists. The top bar is the same with the integration on or off. | `files.js`, `_files-entry.js` |
| 19, 21e: people lists and pickers | The reviewer picker is a searchable combobox with avatars; the visibility choice is radio cards; the people list shows avatars. | `review.js`, `share.js` |

## PDF: what is verified

"PDF (text stays text)" is the default; "PDF (as pictures)" is the old path, kept for effects a PDF cannot hold.

How it is built: the editor's one renderer writes the page SVG; `vector-pdf.js` reads that SVG and writes PDF operators: embedded TrueType (CID, Identity-H, `/W` widths, ToUnicode), paths, axial and radial shadings (a luminosity soft mask when a stop is see-through), pictures (JPEG passes through, the rest is decoded with an alpha mask), picture masks as clips, opacity as graphics state or a transparency group. A page that uses a shadow, a picture filter, a blend mode, a gradient outline, a font that is not ours or a letter the font lacks is saved as a picture instead, and the dialog says which page and why. One bad page never loses the others.

Fonts: the shipped fonts are woff2 (Brotli plus the transformed glyf table). `scripts/fonts/woff2-to-ttf.mjs` (Node, `node:zlib` for Brotli, no dependency) decodes each into a plain TrueType file and writes `<name>.ttfz` (zlib) next to the woff2: 55 files, 1.6 MB in all (budget 3 MB). It keeps `cmap head hhea hmtx maxp name OS/2 post glyf loca cvt fpgm prep gasp GSUB GPOS GDEF`. Checked: all 55 `.ttfz` load in Chrome (its sanitiser accepts them) and draw pixel for pixel like the woff2 (kerning and ligatures off in both, 55 of 55; with them on the GSUB and GPOS tables travel with the file).

Verification actually run:
- Independent reader in the tests (`tests/js/output/pdf-reader.mjs`, shares no code with the writer): cross-reference table, every object offset, every stream length, Flate decode, page tree, page boxes, ToUnicode maps, text extracted from the page content. It reads back the exact strings typed in the design.
- Apple PDFKit (a second parser, via a Swift script) extracted the same text from a downloaded file and found "Karoo" with its search (1 hit).
- Rendered: macOS Quick Look (PDFKit) and Chrome's own PDF viewer (headless Chrome 157 opened the file) show the page correctly with the embedded fonts, kerning, letter spacing, the rounded rectangles, a picture masked to an ellipse with its alpha, and the crop marks outside the bleed.
- In the browser, by hand and in `tests/e2e/specs/11-modules-polish.spec.mjs`: a download from the real dialog, parsed, text real, no image object in the file, TrimBox/BleedBox/MediaBox at the right distances, eight crop marks; a page with a shadow comes back as a picture with the message "Page 1 is saved as a picture because it has a shadow".
- Widths: the shaped width of 5 strings in each of the 55 fonts against the browser's own `measureText` (275 measurements): 257 within 0.3 percent, worst 1.97 percent (two script fonts, Pacifico and Permanent Marker, which use contextual alternates the shaper does not apply).

What is not done, honestly:
- No contextual alternates (`calt`), no mark positioning, no complex scripts. A letter the font lacks (an emoji, a Cyrillic letter) sends the page to the picture path.
- Colour is RGB; there is no CMYK conversion and no ICC profile (the dialog says so).
- Bleed: the page background and anything that runs past the page edge continue into the bleed. A picture or block that ends exactly on the edge is not stretched; the dialog names how many elements do that and says to drag them past the edge.
- Not tried: Firefox's and Safari's viewers, Acrobat, or a print shop's preflight. Chrome's viewer and Apple's PDFKit are the only viewers used.
- Text selection was checked by two parsers and the search of PDFKit, not by dragging a selection in a viewer.
- Fonts are embedded whole (about 20 to 60 KB each compressed), not subset; a design with four fonts is roughly 150 KB larger than the same page without text.
- Reading order in the PDF is the layer order, not a computed reading order.

A defect found on the way, fixed: a print design (format kind "print") is stored in 72 dpi units with `unit: px`, and the PDF writer treated those as screen pixels (0.75 pt each), so an A4 design came out 446 x 632 pt. A print design is now written at 1 unit = 1 pt (A4 is 595 x 842 pt). The raster PDF had the same bug.

## Print readiness

Bleed (none, 3 mm, 5 mm) and crop marks for PNG, JPG and PDF of print formats. Marks start 1 mm outside the bleed and are 5 mm long, 0.25 pt black. PDF pages carry TrimBox, BleedBox and CropBox. The preview in the dialog shows the bleed and the marks. A note says exports are RGB and CMYK conversion is not provided.

## Number formatting audit

Searched `resources/js` and `resources/views` for `toLocale*`, `Intl.`, `type=number`, `valueAsNumber`.
- Fixed in the modules: every number field in export (exact width), animate (page length, delays, lengths), and the time read-outs. `toFixed` itself is locale-free and was never the cause.
- Dates and clock times (`reliability.js`, `topbar.js`, `assets-detail.js`) use the locale on purpose (they are dates, not decimals).
- Left for the owners of those files, named: `resources/js/studio/pages/brand-ed-type.js:151` (type scale `type=number`), `resources/views/design/start/index.blade.php:203,205` (custom width and height `type=number`), `resources/js/studio/pages/lib/units.js:72` (`toLocaleString('en-ZA')` writes a fixed locale, fine). The inspector (`ui/inspector`, `ui/fields.js`) has no native number input.

## Tests

- JS: 727 before, 737 after (new: `number-format`, `print-boxes`, `vector-pdf` (9), pin placement (6); changed: the Dot.Files registration test, the export job tests).
- PHP: 1,573 tests pass (7 skipped), one new (every woff2 has a TrueType `.ttfz` beside it).
- E2E: new `11-modules-polish.spec.mjs` (locale, keyboard controls, vector PDF, print boxes, PNG bleed, shadow fallback, review bar above the page, pins not overlapping and not on the words, outline, hide all, phone width). Specs 01, 02, 03, 04, 05, 08, 09 and 10 pass here; 02, 03, 04 and 08 were edited (see decisions D-2011). 06 has one failure, a view-only member expected to get 201 where the server answers 403 (a security or backend rule, not touched by this branch).

## Seams and notes for other owners

- The bar uses `.st-stagewrap` (pedit's shell) and the classes `.st-stage`, `.st-ruler--h/v`, `.st-ruler__corner`; if the shell changes, `studio-collab.css` (the `data-cb-bar` rules) must follow. A cleaner seam would be a `--st-top-offset` variable in `studio.css`.
- `ctx.__moduleLoader.ensure('files')` is used so the lazy Dot.Files chunk is loaded before its dialog opens (`_files-entry.js`).
- The Brand update review page (jury 25) lives in `resources/js/studio/pages/brand-review.js` (ppages). The assistant preview fix here (stage-coloured letterbox, outlines) is the pattern to copy.
- `window.__studio` is only present when `localStorage['dot-design-debug']` is `1` (the test harness sets it).

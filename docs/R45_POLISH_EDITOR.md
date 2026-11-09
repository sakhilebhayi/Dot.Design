# Editor polish after the outside jury (pedit)

Scope: the editor shell and inspector, from the jury findings 2 (editor side), 6, 7, 8, 11 (the Background value), 14 (editor) and 15 (discoverability). Decisions `D-1900` to `D-1912` in `docs/R45_DECISIONS.md`. Skills record: `docs/R45_SKILLS.md`. Screens: `docs/R45_screens/polish-editor/` (`before-*` and `after-*`; the `before-jury-*` files are the jury's own screenshots). Tests: `tests/js/ui-polish.test.mjs` (14 tests) and `tests/e2e/specs/11-editor-polish.spec.mjs`. The code is under `resources/js/studio/ui/` and `resources/css/studio.css`; one backend fix (item 9, below).

## What changed

| # | Finding | What it is now | Where |
|---|---|---|---|
| 1 | Simple inspector: about 900 px of equal weight buttons, "Text / Text: ..." twice, a mode switch you cannot read | The panel reads in order of intent: the one main action (Edit text, Replace picture, Group together), then Size, Colour, Font and Style as compact controls, then Arrange as five icon buttons with delete set apart in the danger colour, and "Make it move" folded shut. The title says "Text" and the line under it is the words in quotes. The text panel is about 620 px tall at 1440x900 and needs no scrolling (checked by the new E2E). Each icon has an accessible name and a tooltip, and the row's caption shows the name as visible text on hover and keyboard focus. The Simple/Pro switch fills the chosen side with a check; the panel's foot says what Pro adds ("gradients, shadows, layers, exact sizes, rulers and guides") with a Switch to Pro link. | `panels/simple.js`, `panels/parts.js`, `shell.js`, `topbar.js` |
| 2 | The floating toolbar covers the label it belongs to | A pure placement function (`float-place.js`) tries above, below, right and left (above and below also at either end of the frame), keeps clear of the frame, its handles and the rotate handle, stays inside the stage, and prefers the spot that covers least of the other elements. It hides while a pointer is down and follows zoom and pan. The Simple bar lost its size buttons (they are in the panel), so it is narrow enough to slide past a neighbour. Checked in a browser with a 120x60 box at each corner, a 20x20 box, boxes on the top and left edge and a page-sized box: always inside the stage, never over the selection. | `float-place.js`, `floating.js` |
| 3 | Text overflowing its box, 384 px boxes for two lines | Investigated, see the next section. Added Fit box to text and a text extent guide. No engine change. | `text-fit.js`, `measure.js`, `stage.js`, `panels/simple.js` |
| 4 | Opens with Templates in the way and a bare Page panel; the tip covers the page | A design with content opens on the page with all panels shut; an empty design opens on Templates. The last tab a person opened or closed is remembered per user. The first-run tip is a strip in its own row above the page (the stage starts below it), measured clear of the page on a story design. | `shell.js` (`init`), `coach.js` |
| 5 | Eleven equal controls in the top bar | Download is the one filled button, Share is outlined, Preview, Other sizes and Review are icon buttons with tooltips that show the shortcut, the rest sits under More. Undo, redo, zoom and the mode switch stay. "Other sizes" is the label everywhere I own. On a tablet the zoom buttons and the icon group fold away so Share and Download stay on screen; on a phone Download stays and the rest is under More. | `topbar.js` (`ACTION_TIER`) |
| 6 | Layers: no eye or lock, developer names | Eye and lock are always drawn (dim at rest, strong when set). Names are made from role, type and text: "Headline", "Subheading", "Label", "Shape behind subheading", "Frame behind picture", "Offset shape behind picture", "Empty picture". A name a person typed wins, and nothing is written to the document. Inline rename (double click, F2), group fold, drag with a drop line (the dragged row dims), and Alt with the arrows reorder from the keyboard. | `describe.js` (`friendlyName`), `panels/layers.js` |
| 7 | Elements: an unsorted wall of 100 glyphs | Search and kind chips first (All, Shapes, Icons, Badges, Frames, Lines and arrows, Speech bubbles, Price tags, Patterns, Backgrounds, Colours), then sections with a header and a count, a Recently used row (per user, 8 entries), and a size peek pinned at the foot on hover and focus: a miniature page with the item at the size it will be added, and the size in pixels and as a share of the page width. | `tabs/elements.js`, `recents.js`, `insert.js` (`addSize`) |
| 8 | Phone and tablet | See "Layouts" below. | `shell.js`, `dom.js`, `studio.css` |
| 9 | Pro looks thin; Page background shows #111111 on a green page | Every Pro group folds (remembered), and a row of jump chips over the Design tab names every group that applies (Position, Align, Text, Effects, ...). "Appearance" is now "Effects". The background bug is fixed, see below. | `shell.js`, `inspector/index.js` |
| 10 | Neutral chrome | Warm yellow for two moments only (the confirmed-save tick, the first saved design), a bleed and safe area overlay in Pro, motion on the toolbar, the tick and the panel slides, all off under reduced motion. | `topbar.js`, `coach.js`, `stage.js`, `studio.css` |

### Text boxes: what the jury saw, and why it is not an engine bug

Reproduced with the same template (Video thumbnail) and the same words ("Slow-cooked lamb"). The headline is `autoFit: shrink`, so the engine picks the largest size whose lines fit the box: the longest word then ends flush against the box edge. The template puts that edge 20 px from the photo, so it looks like a collision, and it is the specified behaviour. The 384 px box is the template's: it was drawn for three lines ("STOP / OVERPAYING / FOR ADS" at 128 px) and now holds two. The renderer, the measurer and the text layout agree with each other (the text never leaves its box). So nothing was changed in the engine; what was missing was a way to tell the box from the text and to fix it:

- **Fit box to text** (Simple panel), offered only when the box is clearly taller than its text (more than 12% empty) or too small for it, never for `grow` boxes (they already follow their text). It sets the height to the text's real height at the size the engine draws it, and keeps the top, middle or bottom of the text still according to its vertical alignment, also when the box is rotated. One undo step. On the test headline the box went from 384 to 163 px with the text unmoved.
- **Text extent guide**: Pro draws a dashed outline of where the words really are inside the box; Simple hatches the unused part of a box that is 1.5 times or more taller than its text.
- Not done: selection handles that hug the text. The handles act on the box, so a resize drag would then change something other than the thing it touches.

### The Page background value (jury 16b)

Cause: a template that follows a brand colour draws a full-page "Background colour" shape (only elements can carry brand tokens). Applying the brand recolours the shape and leaves `page.background` at the template palette (#111111) underneath, so the inspector was reading a real value that was simply hidden. Fixed at the source (`TemplateInstantiator::syncBackdrop`, with a Feature test) and in the editor for designs that already exist: `effectiveBackground(page)` reads the backdrop's fill, and `setPageColour` writes the backdrop and the page together as one undo step. The Simple panel also shows the page's own colour as a swatch when it is none of the offered ones.

### Layouts

| Width | Layout |
|---|---|
| 1024 and wider | Docked: rail, left panel, canvas, inspector (as before, with the top bar reordered). |
| 768 to 1024 (taller than 480) | Tablet: the rail and the left panel are docked, the canvas is centred in what is left, and the inspector is a drawer on the stage's right edge. A tab on that edge folds it (Hide the edit panel); a new selection brings it back unless the person folded it for that selection. |
| Below 768 | Phone: panels are bottom sheets with three snap points. The grip is a button that steps through half, full and peek, and a swipe moves one step. At half the canvas keeps 46.7% of the screen at 390x844 and 48% at 430x932 (the brief asks for 45%). The tab bar scrolls, labels are whole (two lines allowed), the open tab is scrolled into view and a fade shows where there is more. The selection strip steps aside while a sheet is open. |
| Short and not wide (844x390) | A phone on its side: the panel is a column on the right, the rail is an icon column on the left, the canvas keeps the middle, the floating bar (not the docked strip) is used. |

No horizontal scroll at 390, 430, 768, 1024 or 844 wide (measured).

## Before and after

| | Before | After |
|---|---|---|
| Simple, text selected | `before-jury-08-editor-simple-selected-desktop-light.jpg` | `after-02-simple-text.png` |
| Fit box to text | `before-jury-08-...` (384 px box) | `after-04-fit-box.png` |
| Opening state | `before-01-open.png`, `before-jury-09-...` | `after-01b-open-simple.png`, `after-08-first-run-tall.png`, `after-10-empty-design.png` |
| Layers | `before-jury-10b-editor-pro-layers-desktop-light.jpg` | `after-06-layers.png` |
| Elements | `before-jury-13-editor-elements-tab-desktop-light.jpg` | `after-07-elements.png`, `after-07b-elements-peek.png` |
| Pro inspector | `before-jury-10-editor-pro-inspector-desktop-light.jpg`, `before-jury-16b-...` | `after-02-simple-text.png` after switching, jump chips in `after-06-layers.png` |
| Phone | `before-jury-08-editor-simple-selected-phone-light.jpg` | `after-390x844-b-selected.png`, `-c-tab.png`, `-d-editsheet.png`, `-e-full.png`, `-f-peek.png`, the 430 set |
| Tablet | `before-jury-T1-...`, `before-jury-T2-...` | `after-768x1024-*.png`, `after-1024x768-*.png` |
| Landscape phone | none | `after-844x390-*.png` |
| Toolbar at the edges | `before-jury-08-...` | `after-11-toolbar-*.png` (eight placements) |
| Dark | none | `after-09-dark-simple.png`, `after-09b-dark-arrange-caption.png` |

## What I clicked through (a real Chrome, my own profile)

Opening a design with content (panels shut, page selected), an empty design (Templates open), first run (tip strip, "Got it", dismissal on first click); Simple with a text, a picture, an empty picture and a shape selected; every icon in the Arrange row by hover and by keyboard focus; Fit box to text, then undo; Smaller and Bigger; the font and colour pickers; the mode switch by click and by arrow keys; Pro with the jump chips, folding a group and reopening it; Layers (names, eye and lock, rename); Elements (search, chips, hover peek, add a circle, Recently used); the Bleed and safe area toggle; selection on the eight edge placements above; undo and redo; saving and the yellow tick and the first-design toast; dark theme; 1440x900, 1024x768, 768x1024, 430x932, 390x844 (touch, 2x) and 844x390; the sheet grip at all three snap points; the tablet drawer folding and returning; every button, input and select in six tabs in both modes with and without a selection has an accessible name (scripted check: none unnamed).

## Tests

- JS: `tests/js/ui-polish.test.mjs` (14 tests): friendly names, the panel subtitle, the effective background, toolbar placement (above, below, at corners, around a neighbour, sliding past a label, the stage edges), neighbours, text extent, Fit box to text for top, middle and bottom aligned text and for shrink boxes, when the action is offered, recents (order, cap, cleaning, blocked storage) and the size peek's sizes.
- PHP: `InstantiatorTest::test_the_page_background_follows_a_recoloured_full_page_backdrop`.
- E2E: new `11-editor-polish.spec.mjs` (opening state, the mode switch, the text panel fitting, the toolbar off the neighbours, Fit box to text and its undo, the Arrange captions, Pro chips and folds, the real page colour, friendly layers, Elements, phone sheets and snap points, the tablet drawer, landscape). Changed existing specs where a selector legitimately moved: `09-responsive` (the first drawn group title; an empty "Recently used" section is hidden).

## Still weak (honest)

- The Simple panel on a phone shows Edit text, Size and the start of Colour at the half snap; the rest needs a swipe up to the full snap. That is the price of the 45% canvas rule.
- The yellow is used twice. Approval (review module, pmod's area) can use `{warm: true}` on a toast and `.st-toast--warm`; I did not touch their files.
- Handles do not hug text (see above), and Pro's inner guide is a dashed line, not a second handle set.
- The tablet top bar shows only the zoom percentage, not the zoom buttons (the menu and the keyboard still zoom).
- Friendly layer names for template helper shapes depend on the template author's naming ("Subheading block" becomes "Shape behind subheading"). A template with other names shows them as written.
- Recently used and the first-design milestone are per browser, not per account (the settings endpoint has no keys for them).
- Pro's text has no gradient fill and no per-word styling: the engine has none, so there is nothing for the UI to expose.
- The 45% canvas rule is measured with the sheet at half; at full the sheet takes about 78% of the screen by design.
- Existing failures on the branch point, not touched by this work: `06-reliability` (a view-only member's `copies` call answers 403, not 201), `07-route-sweep` (inline-style CSP errors on `/teams/create`, `/teams/{id}`, `/user/profile`), and `08-integration-seams` (Dot.Files: the export module had not loaded when the spec checked, which depends on idle time).

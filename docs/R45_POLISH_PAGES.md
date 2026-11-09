# R-45 pages polish (ppages agent)

Driven by the outside design jury (`jury-findings.md`, items 1, 4, 9, 10, 12 cards and copy, 13, 14). Decisions D-2100 to D-2110 in `docs/R45_DECISIONS.md`; skills record in `docs/R45_SKILLS.md`; design system notes in `docs/R45_DS.md` and `DESIGN.md` (lint clean). Screens (before are the jury's own shots, after are from this branch): `docs/R45_screens/polish-pages/`.

## What changed, by jury item

| Jury item | Before | After | Where |
|---|---|---|---|
| 1 Switch with no track (06, 06b) | a lone grey circle: `base.css` forced every checkbox to 1rem by 1rem and beat the switch's size | a 44 by 24 track with a thumb and a tick, off and on, hover, focus ring, disabled, forced colours; checkbox and radio drawn by the system too | `ds/controls.css`, `create-shared.css`, `settings/index.blade.php`, `templates.js` |
| 1 Wrong default (Headline "Flash sale" on a menu) | the last guided brief filled every slot | only business facts carry over; message words carry over only for the same goal | `pages/lib/slot-defaults.js` (tested) |
| 4 Blank "Pick up where you left off" | blank hatched frames | the same tile as Home with the real saved thumbnail, neutral frame only when none | `ds/design-tile.blade.php`, `StartController` |
| 4 Review inbox overlap (27) | thumbnails overlapped, no padding | fixed slot, padding, truncation, chips, phone stack | `pages/reviews.css` |
| 9 Brand Studio (23, 25) | clipped logo, "Dm Sans", counts disagree, white bands, no visible difference | logo whole on a checker, "DM Sans", list and review count the same design changes, true aspect previews, changed parts outlined | `brand.css`, `brand-updates.js`, `BrandStudioController`, `FontManifest` |
| 10 Auth, errors, viewer (28, 29, 30) | white logo plate in dark, duplicate Go home, 24 chips, bare "Dark" box, no phone wordmark | white-lettering logo on the stage, one action and three places, labelled theme button, wordmark on phone, page shadow, footer | `ds/logo`, `errors/*`, `share.blade.php`, `share.css` |
| 12 Cards and copy (05) | "Instagram post, Instagram post (portrait)", large gaps | "Instagram post, portrait", five columns that fill | `lib/card-subtitle.js`, `templates.css` |
| 13 Landing (31b) | white rectangle, empty hatched frame | real library templates drawn by the editor's renderer at the picked size, with their own colours; a real before and after of one template and an example brand kit | `welcome.blade.php`, `landing.css`, `ds/landing.js`, `scripts/landing/*`, `public/landing/art` |
| 14 Identity | grey, white, black plus a logo | the logo yellow as a signal in three places (primary focus, confirmations, first-time messages), proof-sheet marks carried to the message, the landing swatch strip and the brand before and after | `ds/signal.css`, `Milestones`, DESIGN.md |

Also: the mail header no longer loads Laravel's logo from laravel.com.

## How the landing artwork is made (real, not drawn)
`node scripts/landing/build-art.mjs` (dev only; re-run after the library, the engine or the example kit changes). It runs `scripts/landing/instantiate.php` (the real `TemplateInstantiator` on the template files, with and without an example kit) and draws each page with the real `renderPageToSvgString` through the QA harness in headless Chrome, writing small SVG files, a manifest and the @font-face rules. The page inlines the first drawing, fetches the others on a click, and falls back to a neutral frame if a file is missing. Eight hero templates (one per start format), one brand template, three for the close.

## First-time moments
First design, first downloaded file, first approval. Read from real rows; `design_milestones` only remembers "shown". Home and Start show the first one not yet seen; the page script reports it seen after 2.5 s in view, "Got it" closes it, and without script the button is a form post. Crop marks close in around it once and a seal settles; with reduced motion it is simply there.

## Verification
- PHP: 1,583 tests (1,572 before, 11 new in `tests/Feature/PagesPolishTest.php`); three existing tests changed with the behaviour (`EcosystemErrorPagesTest`, `DesignSystemShellTest` excludes drawings from page-copy checks, `StudioBrandUiTest` asserts the new list wording) and the generated docs were regenerated. JS: 569 (563 before plus 6 in `pages-card-subtitle` and `pages-slot-defaults`). Bundle budget: app.js 3.2 KB gzip of 10.
- Real browser (headless Chrome through the QA playwright-core, a seeded demo world with a brand kit, nine designs with thumbnails saved by the real editor, two reviews, a brand update, two share links): every page at 1440, 768 and 390, light and dark, no horizontal overflow; the switch in light, dark and emulated forced colours; the landing hero chips, the brand before and after, and the close; login in dark; the viewer on a phone; the milestone shown, reported, and gone on reload (and reset).
- Numeric contrast of every new pair (DESIGN.md): ink on signal 11.1, ink on signal-wash 15.9 and 13.1 in dark, ink-3 on signal-wash 5.2 and 5.4.
- Impeccable detector: see `docs/R45_SKILLS.md`.

## Not done, on purpose
- The editor-side flourishes (first export and first approval inside the editor shell and the export dialog) belong to other agents; the messages wait on Home and Start.
- Template previews that still show leftover sample copy or empty "Add a picture" boxes (jury 12) are template content, not page code.
- The landing hero on a phone shows the headline first and the drawing below it (the drawing is the next thing seen when scrolling a little).

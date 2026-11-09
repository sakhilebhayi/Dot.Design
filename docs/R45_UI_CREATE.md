# R-45 create flow: Start screen, guided brief, template browser (createflow agent)

Branch `feat/r45-createflow`. Decisions `D-800` to `D-810` in `docs/R45_DECISIONS.md`. Skills record: `docs/R45_SKILLS.md`. Screens: `docs/R45_screens/createflow/` (1440 light and dark, 390, empty, error, every wizard step).

## What a person can do
- **Beginner**: Start screen, pick the closest goal (each card shows the most popular real starting design for that goal), answer a few skippable questions, pick a design that already carries their name, details, logo and colours, land in the editor. Skipping everything is 6 clicks (goal, Skip, name + Enter, Skip x4, Use this design). Measured with three goals in a real browser (see "Verified").
- **Professional**: "Open a blank canvas" (header) or a size chip (one click), or More sizes: search over all 43 catalogue sizes grouped by use (type "a4" and press Enter when one match is left), or Custom size with px, mm, cm and in, live pixel conversion, a paper-size note, a swap button and limits.
- **From a template**: the Templates destination (nav, Home empty states) with tabs, filters, search, sort and a detail dialog that fills the template with their own words before the project is made.

## Routes and files
| Route | Controller | View | Script |
|---|---|---|---|
| `GET design/new` `design.start` (`?format=`) | `Studio\StartController@index` | `design/start/index` | `pages/start.js` |
| `GET design/new/brief?goal=` `design.start.brief` (`&format=`) | `StartController@brief` | `design/start/brief` | `pages/brief.js` |
| `GET design/templates` `design.templates.index`, `GET design/templates/{slug}` `design.templates.show` | `Studio\TemplateBrowserController` (delegates to `TemplateController` for JSON) | `design/templates/index` | `pages/templates.js` |
| `GET design/projects/create` `design.projects.create` | redirect to `design.start`, query kept | none | none |
| `POST design/projects` `design.projects.store` | `DesignProjectController@store` (+ optional `format_key`) | none | none |

JS lives in `resources/js/studio/pages/` (`lib/` holds the pure, tested modules), CSS in `resources/css/pages/` (`start.css`, `templates.css`, shared `create-shared.css`), Vite entries `start`, `brief`, `templates`. PHP helper: `App\Support\Ui\StartScreen` (format resolution, assistant flag, size limits, previous brief, font manifest).

Pages hand their script one `<script type="application/json">` block (encoded with `Js::encode`, so `</script>` and quotes in a person's own text cannot break out; a test proves it). No inline script, no external host (a test checks both).

## Start screen
Goal cards read the plain wording from `config/design_brief_flow.php`. Previews are the engine renderer drawing the best template per purpose (one not already shown), lazily when the card scrolls into view; a goal with no template keeps a neutral frame. The size chips and the picker are plain forms, so a blank canvas opens without script. Recent designs are the signed-in person's (model scope). The assistant box exists only when enabled (D-808).

Custom size: the form converts to whole px (72 dpi, A4 = 595 x 842), limits 16 to 10000 px per side with messages in the unit in use, and posts the existing fields (`unit=px`). Changing unit keeps the physical size.

## Guided brief
State machine in `lib/brief-machine.js` (no DOM, 18 tests): steps from the flow config plus a final "choose" step; essential steps (business name) cannot be skipped and say why; optional steps have "Skip, I will add this later" (typed text on a skipped step is thrown away on purpose); Back keeps every answer; the step list jumps only to steps already reached; answers persist in `sessionStorage` (validated on read, survives corrupt or foreign entries, never throws); server errors go back to the step they belong to. Validation mirrors `BriefValidator` word for word (`lib/brief-validate.js`).

Steps: what, business name (needed), details (fields per goal, type-aware inputs, help and example), logo (pick from uploads or upload with progress, cancel and failure with retry; palette via `design/brand/palette`; colour choice: design's own, brand kit, or logo colours), look (seven styles, each shown by the best real template in that style), size (the goal's usual sizes drawn at true proportion, plus every other size in a select), choose.

The live strip "Starting points for you" re-asks `brief/recommend` (debounced, lenient brief) and shows each template with the server's plain reason. The last step shows 3 to 6 recommended templates filled with the person's own answers through the `fill` dry run (so a multi-page one shows "3 pages"), plus "Start with a blank page" at the chosen or default size. Creating posts `projects/from-brief`; the brief is stored on the project by the existing service. After success the saved answers are forgotten.

## Template browser
Tabs (All, the five categories with counts from `facets`, From your workspace with Team and Only me), search (350 ms debounce, shareable address), selects for kind of business, purpose, look, platform, size and goal, sort, active-filter chips with remove and Clear all, 24 per page with "Show more" (also loads when it scrolls into view), skeletons, empty states (filtered, empty group, empty workspace), an error state with retry. Cards are real buttons with full labels; multi-page cards flip pages on hover, focus and touch (the full template loads on first flip). The address always reflects the state (`?category=social&style=bold`, `?open=<slug>` for the dialog), back and forward work.

Detail dialog (native `<dialog>`): large preview, all pages as thumbnails, facts (size, pages, where it fits), what can change (slot labels), "Make it yours" form (business name needed; one field per text slot with its help and example; values start from the last brief and the default brand kit), live preview through `fill` (650 ms debounce, failures keep the old preview and say so), "Fill with my brand colours" switch (only when a kit exists, default off), "Use this template" posts `from-brief` with the template slug. Pictures are added in the editor (stated).

## Contract notes for other owners
- JSON callers of `design.templates.index` and `design.templates.show` must send `Accept: application/json` (D-800).
- `design/assistant/compose` (ai agent): request `{prompt, format_key?}`; this UI sends only `prompt` today. Responses understood: `{status:'ok', project_url}` (same-site only), `{status:'questions', questions:[{id,text}]}`, `{status:'unavailable', message}`.
- Editor-ui: a new project may arrive with `format_key`, `template_id` and a `brief` stored; the project is always px at 72 dpi.
- Business: see D-807 (a colours-only apply would make "Use my logo colours" cleaner) and D-810.

## Verified (real browser, isolated Playwright Chromium session, dev server on 8480)
Clicked through: Start at 1440 light and dark and 390; size picker (open, search "a4", custom 210 x 297 mm -> 595 x 842 px "This is A4 paper", Open canvas -> project 595 x 842 px); a size chip (Instagram story, one click -> 1080 x 1920 project with `format_key`); the old `projects/create?format=poster` redirect and the preset panel; three goals end to end with stored-project checks in the database: sale (all answers, logo upload with progress, palette, template -> brief with details, style, size, `logo_asset_id`; project pages and filled text correct), advertise my business (skipped everything, 6 clicks, template), stationery with details (2-page template, brief and skipped list stored), menu (blank at the goal's default size, 0 elements, brief stored); wizard validation (phone, essential name), reload mid-wizard (same step, logo still selected), logo colours (kit created, brand colours in the previews); templates grid, category tab, search, empty and offline error states, workspace tab, filters panel at 390, detail dialog light, dark and 390 bottom sheet, multi-page flip and thumbnails, Escape returns focus to the card; keyboard only (Tab to a goal card with a visible focus ring, Enter, type, Enter through steps, focus lands on the first field of each step). No horizontal scroll at 390.

Tests: PHP `tests/Feature/CreateFlowTest.php` (24), JS `tests/js/createflow-*.test.mjs` (34: unit conversion, wizard state machine, filter and query building, preview cache and queue, preview SVG, API wrapper, compose contract with a fake). Existing tests changed because behaviour legitimately changed: `DesignSystemShellTest` (create form became the Start screen; added brief and templates pages to the render sweep) and `ResponsiveShellTest` (same).

## Not built, on purpose
- No colours-only brand apply (needs the business layer, D-807).
- "Save my design as a template" has no button here (the endpoint exists; the editor owns that surface). The workspace tab says so in its empty state.
- No stock pictures, no fake thumbnails: goals or styles without a template keep a neutral frame.
- Pictures (photos) are not asked in the wizard beyond the logo; the brief supports `photos` but a gallery picker would repeat the logo step's work and was left for a later pass.
- The size picker offers no print bleed or dpi choice (stated in the dialog; print export multiplies later).

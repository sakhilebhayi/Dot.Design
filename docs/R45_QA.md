# R-45 workflow QA: the end-to-end suite and what it found

Owner: the qa agent (wave 3). Branch `feat/r45-qa`. Decisions `D-1300` to `D-1307` in `docs/R45_DECISIONS.md`.

Every feature of R-45 was built and tested by its own agent in isolation. This suite drives the **integrated** product in a real Chrome the way a beginner, a professional, a business team and a hostile or unlucky visitor would, and asserts on real outcomes: database rows, the decoded bytes of downloaded files, the live document in the engine, and the DOM. A button being present is never an assertion.

## Running it

```
npm run test:e2e                 # = tests/e2e/run.sh   (all specs)
tests/e2e/run.sh 06              # specs whose file name contains "06"
tests/e2e/run.sh --headed        # watch Chrome
tests/e2e/run.sh --skip-build    # reuse public/build
tests/e2e/run.sh --serve --keep  # leave the throwaway app running to poke at it (Ctrl-C ends it)
```

Needs: PHP 8.3+, Node 22.5+ (the specs read the SQLite file with the built-in `node:sqlite`), `npm install` (adds `playwright-core` 1.65.0, dev only, Apache-2.0, D-1301) and Google Chrome installed (`channel: 'chrome'`; no browser is downloaded). About 8 minutes for everything on a laptop.

`run.mjs` builds the assets, creates a throwaway SQLite file and storage folder in the OS temp directory, migrates, seeds the 58 templates and the design-system tables, starts two copies of the app (assistant off on the first port, assistant on against local stubs on the second), runs each spec in its own node process, and always tears everything down (servers run in their own process groups; exit, error, Ctrl-C and SIGTERM all kill them). Specs write screenshots, downloads and JSON reports to `tests/e2e/out/` (git-ignored). `E2E_VERBOSE=1` shows server stderr.

| Path | What |
|---|---|
| `tests/e2e/run.mjs`, `run.sh` | Orchestrator. |
| `tests/e2e/lib/harness.mjs` | Env, test users, sign in once per user, Chrome, console/request logging, read-only SQL, `world()` (direct DB setup). |
| `tests/e2e/lib/editor.mjs`, `flows.mjs`, `files.mjs`, `png.mjs` | Editor helpers (engine state, save waits, export dialog), reusable UI flows, independent readers for PNG, JPEG, PDF, GIF, WebM and SVG, a PNG encoder/decoder. |
| `tests/e2e/lib/anthropic-stub.mjs`, `dotfiles-stub.mjs` | Local stand-ins for the Messages API and for Dot.Files (it verifies the HMAC signature). Nothing here reaches the internet. |
| `tests/e2e/support/world.php`, `ini/limits.ini` | Users, workspaces and projects set up directly (refuses any database that is not the throwaway one); production-like upload limits. |
| `tests/e2e/specs/NN-*.spec.mjs` | The workflows below. |

## Workflows

All 25 tests pass on this branch (last full run from a clean checkout state: 10 spec files, 25 tests, 0 failures, about 9 minutes).

| # | Workflow | What is automated (real clicks, real files) | Result | Found and fixed |
|---|---|---|---|---|
| 1 | Beginner (`01`) | Brand Studio kit from an uploaded logo (colours read from the picture) -> Start -> goal -> guided brief (name, headline, phone, logo, "use my brand kit", look, size) -> pick a Facebook post design -> editor opens with the name and phone filled -> replace the headline by double-click and typing -> picture slot filled by upload -> a brand chip changes the colour (token recorded) -> Preview -> wait for "Saved" -> back to Designs and reopen -> document equals what was edited and equals the server copy -> Download PNG: decoded, exact page size, the uploaded picture's pixels sit in the slot (teal, magenta, yellow mark) | pass | uploads in "Pick a picture" mode did not fill the spot (`d6b2e03`); a long headline ran off the page and over the picture (`ba93eb4`) |
| 2 | Professional (`02`) | Custom 900x600 canvas -> Pro mode -> rectangle, circle, star, icon, heading, picture -> position, resize, rotate with the inspector fields -> typography (font, weight, spacing, size) -> align and spread three elements -> layer order -> group and ungroup -> a real mouse drag shows a snapping guide and snaps to x = 700 -> 30 undos and 30 redos by keyboard -> save, reload, compare -> export PNG 2x (1800x1200, pixels), JPG (SOF size, decoded in a fresh Chrome), PDF (1 page, MediaBox 675x450 pt, embedded JPEG), SVG (parses, renders at 900x600, no external reference, live text) -> audit rows (format, scale, bytes) | pass | typing Width or Height moved X and Y (`bbf4c09`) |
| 3 | Business (`03`) | Owner makes a kit and a branded design (kit linked, tokens on elements) -> private until shared (member gets 404) -> share with the workspace -> editor comments on the headline (anchored to the element) -> owner sees it -> owner requests review (snapshot pinned) -> reviewer approves (confirmation step) -> project "approved" -> owner edits: review goes stale, project back to draft, banner shown -> export -> outsider from another team gets 404 on every URL of the project (14 pages and endpoints plus its picture and brand-asset files) and on four write attempts -> reviewer (viewer role) has no edit tools and gets 403 on save and delete | pass | none |
| 4 | Animation and resize (`04`) | Animate this design (proposal, then "Add these") and a hand-picked entrance on the picture -> Play: the canvas frames and playhead really change, and the design is whole again after leaving -> GIF (>1 frame, loops, square) -> WebM (valid EBML and cluster, plays back in a video element) -> "Other sizes": Instagram story and YouTube thumbnail become two new projects with the right format key, page size, headline text, every element inside the page; the original untouched | pass | none |
| 5 | Assistant (`05`) | Off: honest "switched off" state, no ask box, all three model endpoints say `unavailable`, no model call recorded; an automatic fix is reviewed (nothing changes), applied (readable colour), undone with one step. On (stub of the Messages API, fake key): flags and status agree; an improve proposal changes nothing until Apply, then applies as one undo step with a safety copy; Dismiss is recorded; a provider failure says so and changes nothing; "Suitable for Instagram" opens Resize with the size chosen and makes no model call; compose from the Start screen makes a new private draft with the business name verbatim and leaves the open design alone | pass | Resize ignored the size the assistant suggested (`d7f589e`); `flags.ai` disagreed with the assistant status when `DESIGN_AI_ENABLED=false` (`a64eba7`) |
| 6 | Reliability (`06`) | Wrong type and oversized files refused in plain words, a file lying about its type refused by the server, a 7 MB picture uploads and lands; edit while offline, come back: nothing lost, chip tells the truth; unsaved work survives in the crash journal; second tab: conflict dialog, both resolutions ("Save mine over theirs" with confirmation, "Load the latest"); expired session: signed-out banner, edit kept in the editor, sign in again in another tab and the edit reaches the server by itself; 300 elements open and drag as a whole (median frame 16.7 ms, p95 16.7 ms, open 163 ms, `out/perf-300-elements.json`); 13 edit endpoints and the thumbnail PUT return 403 to a view-only member and change nothing, while commenting works | pass | none (the 2 to 8 MB upload failure was the dev server's 2 MB default, now set in the harness; README already states the 8 MB rule) |
| 7 | Every route (`07`) | All 63 GET URLs of `route:list` (ids substituted, vendor and signed routes excluded) as owner, admin, editor, reviewer and outsider: no 500, exactly one `h1` on every HTML page (404 pages too), no horizontal overflow at 1440 (and 390 for owner and reviewer), no console error, no request to any host but the app, no mixed content; the public share viewer signed out: 200, no cookie, `noindex`, `no-store`, bad and unknown tokens give the same 404 page with one `h1` | pass | the editor page had no `h1` (`85c0876`) |
| 8 | Seams (`08`) | `ctx.exports` and Save to Dot.Files (button only when the flag is on; the stub verifies the signature and the stored file decodes to the 800x600 PNG of the design with its dark headline; a second save is an `update`, one link row); share viewer Download (absent in the markup without "Allow downloads"; with it the PNG is the design and the PDF has one page and the right MediaBox, still no cookie); version history save, change, restore, editor shows the restored text, safety copy kept; thumbnails stored about 5 s after a save and visible on Home and Designs; Home has no AI or mock block | pass | share viewer had no Download (`c81875f`); `ctx.exports` missing so Save to Dot.Files could never appear (`c81875f`); dashboard mock-AI block (`85c0876`) |
| 9 | Phones and tablets (`09`) | 390x844 and 768x1024: Start, Templates, Brand, Assets, Review, Home, Designs (one `h1`, no sideways scroll, screenshots); the editor on a phone with touch: bottom sheet inside the screen with the design visible above it, tap selects, docked toolbar fits, an edit saves, top bar targets at least 40 px, the status chip is thumb-sized when it is a button; tablet editor; dark mode on six screens (dark background, readable text and headings) | pass | save chip was 31x32 px while it was the retry button (`65f1bd1`) |
| 10 | Dead controls (`10`) | Every enabled button in the right panel and the selection toolbar, Simple and Pro, nothing / text / shape / icon / two shapes selected (navigation, destructive and rail-tab buttons are skipped on purpose): each must change the document, selection, a dialog, a toggle, the DOM or send a request | pass, 0 controls without an effect | none |

Seams listed in the wave-2 reports, checked:

| Seam | State |
|---|---|
| Boot JSON `flags.ai` equals `AssistantStatus::enabled()` | was wrong, fixed (`a64eba7`), PHP test over all four key / switch combinations |
| Dashboard has no AI-generation count or mock block | was still there, removed (`85c0876`); `DashboardTest`, `DesignHonestyTest` (one existing test changed: it asserted the mock block) |
| `ctx.exports` for the Files save item | did not exist, built (`c81875f`); verified end to end with a signed Dot.Files stub |
| Resize dialog opens from "Suitable for Instagram" | opened without the size, fixed (`d7f589e`) |
| `ctx.reloadDocument()` after a version restore | **not provided by the shell**; Restore falls back to a full page reload (the module's documented fallback). Verified working; recorded below |
| Share viewer Download only when allowed, and working | absent, built (`c81875f`, D-1304): the export module loads on the public page on first press |
| Thumbnails on Home and Designs after a save | works (real `img`, natural size read back) |

## Bugs found and fixed (each has a test in the right place)

| Bug | Fix | Test |
|---|---|---|
| Uploading from the Uploads panel while it says "Pick a picture for this spot" filed the picture but left the spot empty | `ui/tabs/uploads.js` fills the spot with the first uploaded picture (`d6b2e03`) | `01-beginner` |
| Inspector Width and Height resized about the centre, so X and Y changed while typing | `anchor: 'topleft'` in `ui/inspector/index.js` (`bbf4c09`) | `02-professional` |
| Assistant "Suitable for Instagram" opened Resize with nothing chosen | `ui/modules/resize.js` reads `props.format_key` (`d7f589e`) | `05-assistant` |
| `flags.ai` true when a key exists even with `DESIGN_AI_ENABLED=false` | `EditorBoot` uses `AssistantStatus::enabled()` (`a64eba7`) | `StudioEditorPageTest` |
| Editor page without any `h1` | one hidden `h1` with the design's name outside the mount (`85c0876`) | `StudioEditorPageTest`, `07-route-sweep` |
| Dashboard still carried the "Image requests" mock-AI block and its queries | removed from the view and route (`85c0876`) | `DashboardTest`, `DesignHonestyTest` |
| Share viewer: "Allow downloads" had no effect | buttons, export on demand, viewer API (`c81875f`) | `08-integration-seams`, `07-route-sweep` (layout at 390) |
| "Save to Dot.Files" could never appear | `ctx.exports.render` (`c81875f`) | `08-integration-seams` |
| Template slot text longer than the sample overflowed the page and its neighbours (the wizard's own example sentence did this) | slot text longer than the sample gets "Shrink text to fit" (`ba93eb4`) | `InstantiatorTest`; visible in `01` |
| Save chip 31x32 px while it is the retry and resolve button | 44 px minimum while enabled (`65f1bd1`) | `09-responsive` |

## Found and NOT fixed

| Item | Severity | Why not |
|---|---|---|
| Choosing a size the library has no design for (for example "Announce a sale" with Instagram post, square: no sale design exists in that size) shows designs of other sizes; "Use this design" creates the design's own size. Each card states its size, and "Other sizes" in the editor resizes. | medium (UX) | A library gap, not a code defect. A real fix is either a square sale template or resizing the chosen template to the chosen size inside "from brief" (a product decision; the output agent's resize is client side). |
| A viewer-role member can copy a design into their OWN workspace through "Other sizes" (`POST projects/{p}/copies`), although export is denied to that role | low | D-1011 as written (the copy is theirs, never the company's); they can already read every page. Owner decision needed. |
| `ctx.reloadDocument()` is not provided; Restore reloads the page | low | Works and is covered. A true in-place reload needs an engine call that replaces pages without an undo step. |
| Uploads of 2 to 8 MB fail on a server whose PHP limits are below 8 MB, with "The upload failed. The file may be larger than the server allows." | low | Honest message; the README states the 8 MB requirement; production must set it (the harness does). |
| The wizard's style and size buttons have very long accessible names (they include the text of the preview picture) | low (accessibility) | Needs a design decision on what a screen reader should hear. |
| Touch targets under 44 px on phones: template-card page arrows (32), "See all ready-made designs" (17 high), Assets folder chips (36) | low | Listed in `out/responsive-phone.json`. |
| Template cards show the kind twice ("Instagram post, Instagram post (portrait)", "Business card, Business card (85 x 55 mm)") | low (copy) | Copy decision for the library owner. |
| "Suggested for this design" in Other sizes offers the design's own size | low | The suggestion list is size-family based. |

## What this suite does NOT prove

* **Chromium only.** Chrome 157 (headless). Firefox and Safari are installed on this machine but `playwright-core` cannot drive stock builds of either, and enabling Safari's remote automation is a system setting, so neither was run. Nothing here says they work.
* The Anthropic API and Dot.Files are local stubs: the request and answer shapes are the app's own and the documented ones, never a real call. The first production call of each is still a by-hand check.
* Mail and Dot.Notify delivery are not exercised.
* The dead-control audit detects "nothing observable happened" (document, selection, dialog, toggle, DOM, request). A control that changes the wrong thing passes it; the workflow specs check the right thing for the controls they use.
* The perf numbers are from one fast laptop with software rendering off; use them as a regression baseline, not as a promise for a phone.
* Dark mode is checked for background, text and heading contrast and overflow on six screens, not pixel by pixel.
* The harness never touches production or a development database (`world.php` refuses any other path), and no spec calls a real external service.

## Counts

PHP 1,417 -> 1,421 tests (4 added: `flags.ai` over four combinations, one `h1`, shrink-to-fit slots, dashboard without an AI block; 1 existing test rewritten, `DesignHonestyTest::test_dashboard_does_not_show_mock_provider_activity_at_all`). JS 615 -> 615 (no change; the new behaviour is covered by the browser specs). End to end: 0 -> 25 tests in 10 files.

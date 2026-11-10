# R-45 performance, scale and PostgreSQL parity (perf agent)

Everything here was measured, not guessed. Decisions: `docs/R45_DECISIONS.md` D-1500 to D-1514. Tools live in `scripts/perf/` and `tests/Perf/` and are listed at the end so any number can be reproduced.

Production is PostgreSQL on a shared cPanel host (no Node at runtime, cron-driven queue). Tests use SQLite; `phpunit.pgsql.xml` runs the same suite on PostgreSQL.

## Method

- Real Chrome (headless, own instance, driven through the DevTools protocol by `scripts/perf/cdp.mjs`), against `php artisan serve` behind `scripts/perf/proxy.mjs`, which does what the host is asked to do in `public/.htaccess` (the dev server neither compresses nor sets cache headers).
- Two profiles. **desktop**: unthrottled. **mid phone**: 390 px viewport, 4x CPU slowdown, 1.6 Mbps down / 750 Kbps up / 150 ms RTT (Lighthouse "slow 4G").
- "Before" = trunk at the start of this task (gzip, no cache headers, one 465 KB entry chunk). "After" = this branch (brotli, immutable cache headers, lazy modules). Medians of 5 runs, caches cleared for "cold", kept for "warm".
- Server: `database/seeders/PerfSeeder.php` (50 users in 10 workspaces, 2,000 projects, 19,867 pages from the real template pages, 5,000 assets, 500 comments, 20,000 activity rows, 5 projects with 200 versions each; 79 MB) on a throwaway PostgreSQL 16 database; every endpoint called as a real user through the HTTP kernel with the query log on (`tests/Perf/ServerProfileTest.php`).

## 1. Front end

### Editor time to ready (boot JSON parsed, engine started, first interactive page)

| design | profile | cache | before | after |
|---|---|---|---|---|
| empty page | mid phone | cold | 1,607 ms | **1,142 ms** |
| empty page | mid phone | warm | 1,584 ms | **423 ms** |
| 5 pages x 60 elements | mid phone | cold | 1,711 ms | **1,239 ms** |
| 5 pages x 60 elements | mid phone | warm | 1,701 ms | **510 ms** |
| 300 elements on a page | mid phone | cold | 1,789 ms | **1,320 ms** |
| 300 elements on a page | mid phone | warm | 1,809 ms | **588 ms** |
| the three above | desktop | cold | 76 / 89 / 105 ms | 77 / 71 / 89 ms |

What moved it:

1. **The editor entry was 465 KB (154 KB gzip) because every feature module was in it.** Assistant, download, animation, resize, share, comments, history, review and Dot.Files are now separate chunks behind stubs (`ui/modules/deferred.js`, D-1502). Entry: 465 KB to **270 KB** (154 to **88 KB** gzip, 80 KB brotli). The stubs register the same tab, button, dialog and shortcut as the real module (same id, label, order, modes: a test pins this), so nothing appears late or moves; the real module loads in idle time (data-bearing ones at +0.5 s, the rest between 2.5 and 5.5 s) or on first use, whichever comes first, and replaces its stub. Verified in Chrome: all 9 chunks arrive in idle time, clicking Download before that loads the real dialog, no console errors.
2. **Cache headers.** Nothing was cacheable: a repeat visit re-downloaded the same 175 KB of script. `public/.htaccess` now sends `Cache-Control: public, max-age=31536000, immutable` for hashed build files and fonts, brotli (else gzip) for text, and a 1-day cache for the small unhashed images. Verified against a real Apache 2.4 with `AllowOverride All`. Warm editor load: about 1,600 to about 450 ms (-73%).
3. **Livewire (83 KB with Alpine) was loaded on every page** by the app layout, as a blocking script that made `templates.js`/`start.js` wait for it. It is only used on the Jetstream account and team pages, which get it injected automatically (verified on `/user/profile` and `/teams/create`: `Livewire` and `Alpine` present; absent on Start, Templates, Designs).

### Bundles (gzip, entry plus its static imports; `scripts/perf/bundle-budget.mjs` fails CI over the budget)

| entry | before | after | budget |
|---|---|---|---|
| editor `studio/main.js` | about 178 KB | **108 KB** | 115 KB |
| `pages/templates.js` | 31 KB | 31 KB | 45 KB |
| `pages/start.js` | 28 KB | 28 KB | 45 KB |
| `app.js` (every page) | 2.4 KB | 2.4 KB | 10 KB |

Start, Templates, Brand and Assets were already their own entries and do not load the editor. They share one 19 KB renderer chunk (the editor's own renderer draws the template previews). The icon set (14 KB gzip) is its own chunk and is now requested in step with the list instead of after it. CSS: app 14 KB, studio 14 KB gzip. Not done, because it showed no gain: splitting the engine itself (it is needed at once), a service worker (the immutable headers already give the warm numbers).

### Template browser (24 cards, 1440 px desktop and a 390 px phone with 4 cards in view)

| | before | after |
|---|---|---|
| desktop: first preview / all 20 visible | 78 / 206 ms | **73 / 159 ms** |
| mid phone: first preview | 2,604 ms | **1,795 ms** |
| mid phone: all 4 visible previews | 3,764 ms | **2,341 ms** |

Attribution, measured by switching one change off at a time on the phone profile: removing Livewire and starting the icon fetch early took the first preview to 1,732 ms and all four to 2,704 ms; preparing fonts outside the queue, gated, then took all four to 2,341 ms (it does not help the first card, which has nothing to overlap with).

Why it took seconds on a slow link: (a) `drawInto` loaded each card's fonts **inside** the one-at-a-time render queue, so N previews cost N network round trips in a row (about 600 ms each on the phone profile); (b) Livewire sat in front of the page script (item 3 above); (c) the icon set was requested only when the first card was visible. Fixes (D-1503): fonts are prepared outside the queue (`previewer.prepare`), through a first-in-first-out gate of 3 cards at a time (all 24 at once only makes the first card wait for the last one's bytes), the queue then renders from loaded fonts; icons start with the page. There are no long tasks on the main thread. The SVG strings are cached in memory per `slug@version:page` (already the case); a sessionStorage cache was rejected: cached markup shown before its fonts load would draw in the fallback font at positions measured for the real one. The Start screen asks for one template list per visible goal card (6 small parallel requests, all done in 640 ms on the phone profile): a batched endpoint was not justified.

### Landing page and fonts

The only picture on the landing page is the 32 px brand mark, drawn from a 35 KB, 1,181 px logo. It now uses a 4.8 KB 180 px WebP (`public/images/logo-180.webp`); the auth side image is lazy. Fonts already use `font-display: swap`; the UI body font is preloaded; preloading the display font too was tried and rejected (41 KB competing with the entry chunk on the phone profile). A design's own fonts load only for the fonts it uses (the engine's `usedSpecs`).

### What the host must do

`public/.htaccess` carries the rules, each guarded by `IfModule`. If the response headers of `/build/assets/*.js` lack `Content-Encoding` or the one-year `Cache-Control`, ask the host to enable `mod_deflate` (and `mod_brotli`), `mod_headers`, `mod_expires`, or set the same in cPanel "Optimize Website". The site must be served over HTTPS for brotli.

## 2. Editor runtime (300 elements, scripted pointer and keyboard gestures, `scripts/perf/runtime.mjs`)

| gesture | desktop | 4x CPU |
|---|---|---|
| drag one element, 90 moves | 1 dropped frame of 192, p95 16.8 ms | 1 of 190, p95 16.7 ms |
| select all (246 elements) and drag | 0 dropped | 0 dropped |
| marquee across the page | 0 dropped | 2 of 116 |
| 30 zoom wheel ticks | 0 dropped | 0 dropped |
| 60 arrow nudges of the whole selection | 0 dropped | 0 dropped |
| undo 20 steps, group 246 elements | 0 dropped, 17 ms of tasks | 0 dropped |

60 fps holds on the phone profile; there was no hot spot to fix. The page strip redraws its thumbnails 320 ms after the last change and in idle time, so it never competes with a drag.

**Memory** (`scripts/perf/leak.mjs`, 10 minutes, 2,200 cycles, 41,800 operations: add, move, duplicate, group, undo, redo, page add, duplicate, remove, zoom, text edit, open and close the Download dialog and a panel; garbage collected before each sample): heap 4.1 MB at the start, **flat at 6.3 MB from minute 2 to minute 9.5**, 5.6 MB at the end; DOM nodes 5,825 to 6,220 (the document's own growth), event listeners 738 to 725; save state `saved`, 0 errors. No leak found, so nothing to fix. The whole changed page is sent per save (12 to 40 KB for typical pages, 475 KB at the 1 MB limit) after an 800 ms debounce, one request at a time; gzip request bodies were not added (PHP and Apache here cannot read them without a custom middleware, and the cost is 80 ms at 4 Mbps up).

**Regression guard** (`tests/js/perf-*.test.mjs`): operation counts, not wall clock: the stubs equal the real registrations; font loading never takes a place in the render queue and overlaps; the gate keeps its limit and order; the entry never statically imports a feature module. The engine's own tests keep the touch-count and drag-cost budgets of the live renderer (D-200..D-213).

## 3. Server (PostgreSQL 16, the seeded data above, `tests/Perf/ServerProfileTest.php`)

| endpoint | queries | db ms | wall ms |
|---|---|---|---|
| designs list (page 1 of 48; was an unbounded `get()`: 275 KB, 55 ms) | 6 | 6.6 | **30** |
| editor boot, 21 pages (HTML 286 KB) | 24 | 3.1 | 22 |
| `document` JSON, 21 pages | 13 | 1.2 | 10 |
| template list 24 with first-page previews | 3 | 3.0 | 13 |
| template facets (was every row read in PHP) | 2 | 0.9 | 1.6 |
| asset library list (5,000 assets, 4,000 visible pages) | 16 | 105 | 157 |
| asset usage for one asset | 17 | 104 | 203 (was 262: one full scan fewer) |
| comments, shares, reviews, grants, people, activity, versions (200), version diff | 5 to 25 | under 1.5 | 1 to 4 |
| **save, 300 elements (36 KB)** | 15 | 3.5 | **16 ms** (second save 8 ms) |
| **save, about 1 MB page (476 KB)** | 12 | 4.0 | **13 ms** |

Findings and fixes (D-1504..D-1508): the designs list is paginated and filtered in SQL and reads only the card columns; template facets are counted by the database; `Schema::hasColumn` ran 5 to 12 pg_catalog queries per page (a process-wide memo of positive answers, `App\Support\Db\Columns`); the usage endpoint scanned the visible pages twice. **Known limit, not fixed:** the asset library and usage scan every page the person can see, because the elements are a JSON blob with no asset index; cost is about 25 ms per 1,000 visible pages (so about 10,000 pages before it is felt). A DB-side prefilter was tried (`elements::text ~ ...`): it was slower, because detoasting the JSON is the cost. The fix when measured need appears is a small `design_asset_usage` table written by the page save path; it was left out because every writer of pages (save, restore, brand apply, templates, AI apply) would have to keep it exact, and a stale "in use" flag gates asset deletion. N+1: none found in the list endpoints (`StudioPerformanceTest` already pins constant query counts); `people` repeats a 0.2 ms membership lookup 7 times, left alone (1.4 ms).

- **Sanitiser**: part of the 16 ms above; far under the 50 ms line, no change.
- **Versions**: an automatic snapshot costs 34 ms (5 pages, 30 elements, once per 10 minutes). With 200 versions (150 automatic, 50 named) the next snapshot pruned automatic ones to the newest 50 and kept all 50 named. About 60 KB per version.
- **Table sizes** (79 MB database): `design_canvases` 42 MB for 19,867 pages, `design_versions` 20 MB for 1,002, `design_activity` 5 MB for 20,000.
- **`design:seed-templates`**: 58 templates in 0.23 s including application boot.
- **Thumbnails** already send `ETag`, `Cache-Control: private, max-age=3600` and honour 304. Asset files: the same, streamed; pictures also answer `?size=preview` (section 5).
- **Queue** (cron `queue:work --stop-when-empty --max-time=50`): two queued units exist, the analytics snapshot and the notification listener; each makes one HTTP call with a 5 s timeout, 3 tries with backoff, so an attempt is under 10 s against the 50 s window.
- **Concurrency on PostgreSQL** (`scripts/perf/race.php`): 8 processes saving the same revision: exactly 1 saved, 7 conflicts; 8 processes appending pages: all succeeded, numbers unique.

## 4. PostgreSQL parity

`phpunit.pgsql.xml` mirrors `phpunit.xml` for PostgreSQL; `bin/test-pgsql.sh` creates `design_perf_<random>` on the local socket, runs `php artisan test -c phpunit.pgsql.xml`, and always drops it (it never touches another database). Result: **1,429 tests, 1,422 passed, 7 skipped (the same seven the SQLite run skips: features switched off), 0 failures, 27 s.** No divergence needed fixing: the code already uses `lower(...) like` with an explicit escape, `whereJsonContains`, query-builder JSON writes, and no SQLite-only SQL. Also checked on a populated PostgreSQL database: the migrations rolled back across all 17 wave-1 migrations and re-run over 2,000 projects, 20,000 pages and 5,000 assets (backfills of the personal team ran in 50 and 75 ms; the unique page-number migration is a full read of page ids and numbers, fine to about 100,000 pages). CI: two new, clearly named jobs in `.github/workflows/ci.yml`: `pgsql-tests` (postgres:16 service container) and `js` (`npm ci`, `npm run test:js`, `npm run build`, bundle budget). The deploy agent's blocks are untouched.

## 5. Image pipeline

**Real memory model** (peak resident memory of a decode and re-encode of a 24 MP image, `scripts/perf/image-bench.php`): JPEG 192 MB (2x the decoded size), PNG 485 MB (3x plus the input: libpng keeps a second copy of every row while writing), WebP 452 MB (4.7x). The old guard assumed 1x, so on a host whose GD counts against `memory_limit` (the bundled GD of a cPanel PHP) a large PNG or WebP would have died on PHP's uncatchable memory fatal instead of being refused. `ImageSanitiser` now uses the measured multiples (`peakBytes`, D-1509) and refuses with the number of MB it needs (a test pins the arithmetic and a refusal).

**Honest limit on a 256 MB host:** about 26 MP as JPEG (6000 x 4000 passes), about 16 MP as PNG, about 11 MP as WebP; at 128 MB a 24 MP JPEG is refused cleanly ("needs about 199 MB"). The 40 MP cap stays as a policy ceiling for hosts with more memory; the guard states the real, host-dependent limit. Measured here (macOS GD is not counted against PHP's limit, so peak PHP memory reads low; the figures above are resident memory): a 6000 x 4000 JPEG of 7.1 MB uploads in 462 ms including the editing copy; a flat 8000 x 5000 (40 MP) PNG of 0.2 MB sanitises in 0.8 s. The 8 MB upload cap means a 40 MP file must be highly compressible to arrive at all. Not changed: re-encoding at JPEG quality 90 turned the 7.1 MB photo into 11.8 MB (the quality setting is the security agent's and the owner's call).

**Editing copy** (D-1510, shown worth it): the same photo, original 11.8 MB, took **59.5 s** to load on the phone profile; its 1600 px WebP copy (725 KB for a noisy test photo, typically 150 to 400 KB) takes **3.7 s**. Uploads wider than 1,840 px (`design.assets.preview_edge` 1600 plus 15%) get a WebP copy made from the pixels already in memory (+220 ms), stored beside the original (`<name>.preview.webp`, recorded in `meta.preview`), served as `...?size=preview` with its own ETag and `private` caching, deleted with the original. The editor canvas, the page strip, the page thumbnail upload, the Uploads panel, the asset library grid and the brand pickers draw the copy; **exports always use the original**, and the canvas switches to the original above 150% zoom and back (checked in Chrome: copy at fit, original at 200%, copy again). Existing pictures have none until `php artisan design:backfill-previews --limit=200` is run (safe to repeat; guarded by the same memory model); until then they behave exactly as before. The copy is not counted in the storage quota (3 to 6% of the original); the public share viewer still loads originals (a signed route owned by the collab agent).

## 6. Export (browser, through the real Download dialog, `scripts/perf/export-bench.mjs`; 10-page design, every element animated)

| job | desktop | 4x CPU | longest main-thread task | heap |
|---|---|---|---|---|
| PNG 4x of a 1080 x 1350 page (4320 x 5400, 805 KB) | 148 ms | 461 ms | 79 ms | +2 MB |
| ZIP of 20 PNG files (10 pages, 1x and 2x, 4.2 MB) | 621 ms | 1.9 s | none over 50 ms | +26 MB |
| PDF, 10 pages (1.2 MB) | 251 ms | 662 ms | none over 50 ms | steady |
| GIF, 3 s at 15 fps, one page (114 KB) | 125 ms | 138 ms | none over 50 ms | steady |

Nothing blocks the main thread for 100 ms (worst: one 79 to 85 ms task on the phone profile), and the progress line and frames stay live (worst frame gap 100 ms on the phone profile). A Worker would add code for no measured gain, so none was built.

## Left undone on purpose

An asset-usage table (section 3); gzip request bodies; a batched Start-screen preview endpoint; originals-only changes to the public share viewer; bundled-GD memory measurements (this machine's GD is not counted against `memory_limit`, so the memory model comes from resident memory and is conservative); HTTP/2 and CDN behaviour of the real host (measured through a local proxy).

## Reproduce

- Server: `createdb design_perf_seed; DB_*=... php artisan migrate; php artisan design:seed-templates; php artisan db:seed --class=PerfSeeder; PERF_PROFILE=1 php artisan test -c phpunit.pgsql.xml tests/Perf/ServerProfileTest.php` (writes `storage/perf/server-profile.md`, untracked). `php scripts/perf/race.php 8`.
- PostgreSQL suite: `bin/test-pgsql.sh --compact`.
- Browser: `php artisan serve --port=8487`; `node scripts/perf/proxy.mjs --listen=8498 --compress=br --immutable=1`; `php scripts/perf/fixtures.php` (three designs); `node scripts/perf/editor-load.mjs --profile=mid --base=http://127.0.0.1:8498 ids.json`; `templates-load.mjs`, `runtime.mjs`, `leak.mjs`, `export-bench.mjs`, `waterfall.mjs`, `smoke-editor.mjs`, `photo-load.mjs` (after `php -d memory_limit=256M scripts/perf/photo-design.php`).
- Images: `php scripts/perf/make-images.php; php -d memory_limit=256M scripts/perf/image-bench.php /tmp/perf-img/photo-6000x4000.jpg`.

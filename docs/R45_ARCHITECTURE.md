# R-45 Dot.Design transformation: architecture contract

Owner brief 2026-11-09 ("Complete Product Transformation: The Ultimate Business Design & Creative Studio"). Standing order: build everything, design everything, no stops, deploy at the end. This file is the contract every build agent codes against. If reality forces a change, change this file in the same commit and log a decision in `docs/R45_DECISIONS.md`.

## 0. Ground truth (audit baseline, 2026-11-09)

Stack: Laravel 13, PHP 8.5 (host 8.3+), Jetstream teams, Livewire 3 (Alpine ships inside Livewire), Sanctum, Vite 8, Tailwind 3 in package.json (the signed-in layout loads Tailwind from a **CDN at runtime** and Google Fonts, which is why the CSP is report-only). Production PostgreSQL, tests SQLite in-memory. Manual deploy workflow (`gh workflow run deploy.yml --repo sakhilebhayi/Dot.Design --ref main -f confirm=deploy`), CI gate inside. Live at https://design.infodot.co.za. Baseline: 329 tests, 322 pass, 7 skipped.

What exists: user-scoped projects (`HasUserScope`), pages (`design_canvases` with `elements` JSON of text/rectangle/image, CAS `revision`), asset upload with GD re-encode and quota, a 695-line Alpine editor (`public/js/design-editor/`), server SVG and PNG exporters, a *mock* AI image driver (honest placeholder), a design-token / component catalog (global, operator-write), InfoDot SSO + team sync, Analytics daily snapshot job, CSP report-only, token drift scan. Not present: templates, brand kit, versions, comments, sharing, review, workspaces as tenancy, shapes beyond a rectangle, groups, alignment, snapping beyond basics, fonts beyond Inter, animation, PDF/JPG/GIF export, real AI, any beginner flow.

Visual state: dark pink-on-black generic dashboard (Syne + Inter, four metric tiles, rounded cards), a 43%-zoom canvas boxed in a card, everything inline-styled.

## 1. Product shape

Two experiences over ONE document model: **Simple mode** (default; plain language, guided, one contextual panel) and **Pro mode** (full panels). The mode is a per-user preference and a toggle in the editor; it only changes which panels and controls are shown, never the data.

Journeys: Start (home) -> pick what to make or describe it -> guided brief (progressive, skippable) -> starting point (template or blank or assistant draft) -> editor -> preview -> export / share / send for review.

## 2. Document model

`resources/design/document-schema.json` is the single source of truth (PHP validator and JS engine both read it). A project = format (width/height/unit) + ordered pages. A page = `design_canvases` row. All geometry is in page units (px at 72 dpi; print formats carry mm in the format catalog and convert to px at 300 dpi for export). Elements: `text | shape | image | icon | group`. v1 rows (text/rectangle/image) upgrade on read, idempotently, in both runtimes; nothing is rewritten until a real save.

Rules: server never trusts the client document (re-validate, clamp, strip unknown keys, enforce limits and asset ownership/permission); a page save is compare-and-swap on `revision` (409 + latest revision on conflict, never silent overwrite); "saved" is shown only after a 2xx from the server.

## 3. Data model (additive migrations; existing rows preserved)

Tenancy: **workspace = Jetstream Team** (InfoDot-synced). Every project/asset/brand kit/template/share row carries `team_id` (nullable only for legacy rows, backfilled from the owner's personal team) and `user_id`. Access = owner OR active member of the row's team with the capability the Jetstream role grants, AND `visibility` (`private` owner-only, `team` workspace) or an explicit share. `HasUserScope` is replaced on projects/assets by a workspace-aware scope; 404 (not 403) for rows the user cannot see; policies re-check as defence in depth. No `withoutGlobalScopes()` in controllers.

New or changed tables (owners in section 6):
- `design_projects`: + `team_id`, `visibility`, `format_key`, `status` (draft|in_review|approved|archived), `template_id`, `brief` json, `brand_kit_id`, `brand_version`, `thumbnail_asset_id` (nullable), `mode_hint`.
- `design_canvases`: + `name`, `width`, `height`, `background` json, `duration_ms`, `transition`, `guides` json. Keep `elements`, `revision`, `background_color` (kept in sync for v1 exporters).
- `design_versions`: project_id, user_id, kind (auto|manual|restore|approved), label, pages json snapshot, bytes, created_at. Auto snapshots at most every 10 minutes of editing and before any restore/AI-apply/brand-apply; retention 50 auto per project; manual and approved never pruned.
- `design_shares`: project_id, created_by, token_hash (sha256 of a 40-char token, shown once), permission (view|comment), expires_at, revoked_at, download_allowed bool, last_viewed_at, view_count.
- `design_comments`: project_id, page_id?, element_id?, x?, y?, user_id? or guest_name (share-link commenters), body (max 2000), parent_id?, resolved_at?, resolved_by?.
- `design_reviews`: project_id, version_id (snapshot being reviewed), requested_by, reviewer_id, status (pending|approved|changes_requested|cancelled|stale), note, decided_at. Separation of duties: a requester cannot approve their own request when the workspace has more than one member. Editing after approval marks the review `stale` and the project `draft` again.
- `design_activity`: team_id, project_id?, user_id?, action, subject_type, subject_id, meta json, created_at. Written for share/unshare, export, review request/decision, restore, delete, brand change, template publish, AI apply.
- `brand_kits`: team_id?, user_id, name, is_default, version int, colors json [{key,name,hex,role}], fonts json {heading,body,accent}, type_styles json, guidelines text, voice json {tone,keywords,avoid}, tagline, website, contacts json. `brand_assets`: brand_kit_id, design_asset_id, role (logo|logo-light|mark|image|pattern|background), label.
- `design_assets`: + `team_id`, `folder`, `tags` json, `license` (own|brand|builtin), `source`. Quota stays per user (and a team ceiling).
- `design_templates`: slug, name, description, source (curated|team|user), team_id?, status (draft|published|retired), category (marketing|social|identity|commercial|document), kind (poster|flyer|instagram-post|...), industries json, purposes json, styles json, platforms json, format_key, width, height, pages json (full v2 pages with `bind` slots), slots json (the bound keys + labels + defaults), popularity, version, thumbnail_svg text (server-rendered preview for the browser grid).
- `design_ai_proposals`: project_id?, user_id, mode (compose|improve|copy), prompt, status (proposed|applied|dismissed|failed), payload json, model, tokens_in, tokens_out, error.
- `design_exports`: project_id, user_id, format, pages, scale, bytes?, created_at (audit and Analytics counts; the file itself is produced in the browser and not stored unless the user saves it to Dot.Files).
- `design_user_settings`: user_id, mode (simple|pro), snap, grid, rulers, reduced_motion_pref, last_format_key, onboarded_at.

## 4. Editor architecture (browser)

Vanilla ES modules built by Vite (no framework; Alpine is not used inside the editor, so Livewire's Alpine start order cannot break it). Entry `resources/js/studio/main.js`, CSS `resources/css/studio.css`. The editor page boots from one JSON script tag the server renders (document, assets index, brand kit, fonts, formats, limits, urls).

```
resources/js/studio/
  doc/        schema.js (reads document-schema.json: defaults, normalise, validate, migrateElement), ids.js, geometry.js
  engine/     store.js (state + subscribe), commands.js (every mutation = command with inverse), history.js (undo/redo, coalescing),
              selection.js, transform.js (move/resize/rotate, shift/alt/center), snap.js (smart guides, grid, edges, spacing),
              align.js (align/distribute/match size), group.js, clipboard.js, text-layout.js (measure + wrap + autofit, shared by render and export),
              render.js (page -> SVG DOM; ONE renderer for screen, thumbnail and export), viewport.js (zoom/pan/fit, wheel, pinch),
              input.js (pointer, keyboard, touch, text editing overlay), autosave.js, recovery.js (localStorage journal), fonts.js
  output/     animate.js (evaluate(doc,page,tMs) -> per-element overrides), export-raster.js (PNG/JPG, scale, transparent),
              export-svg.js, export-pdf.js (multi-page, raster-in-PDF at chosen dpi, honest), gif.js, webm.js (MediaRecorder), resize.js (smart resize to a new format)
  ui/         shell, panels (simple + pro), inspector, layers, pages strip, export dialog, shortcuts, mobile sheet UI  (wave 2)
```

Engine public contract (stable; UI codes against it): `createStudio({root, document, assets, brand, fonts, formats, limits, saveAdapter, prefs}) -> studio` with `studio.store.getState()/subscribe(fn)`, `studio.exec(name, args)` (returns `{ok, ...}`; every document mutation goes through it), `studio.undo()/redo()`, `studio.select(ids)`, `studio.on(event, fn)` with events `change | selection | save-state | page | error`, `studio.renderPageToSvg(pageIndex, opts)`, `studio.destroy()`. Save states are exactly `idle | pending | saving | saved | failed | conflict | offline`; `saved` only after server confirmation.

Rendering: SVG, because export parity matters (what you see is the same renderer used for PNG/JPG/SVG/PDF/GIF). Text is wrapped by `text-layout.js` (canvas measureText with the real loaded font) into `<tspan>` lines; fonts are self-hosted woff2 (`public/fonts/design/`) and embedded as data URIs when exporting. No external font or script host at runtime anywhere in the app (the CDN Tailwind and Google Fonts links are removed; CSP becomes enforced with nonces where the app allows).

Performance budget: first interactive editor < 2.5 s on a mid phone profile for a 5-page, 60-element design; pointer drag at 60 fps for 300 elements (renderer updates only changed elements by id; no full re-render per pointer move); autosave payload is the changed page only; thumbnails render lazily and are cached by revision.

Mobile model: bottom sheet instead of side panels, large touch targets (>= 44 px), tap to select, two-finger pan/zoom, long-press for the context menu, a floating "Edit text / Replace picture / Colours / Layer" strip for the selection.

## 5. Services (PHP)

`app/Services/Design/`: `DocumentSchema` (loads the JSON), `DocumentSanitiser` (validate + normalise + migrate v1, clamps, strips), `PageStore` (CAS save, versions), `VersionService`, `TemplateInstantiator` (copy pages, new ids, bind slots from brief + brand), `BriefRecommender` (deterministic scoring of templates/formats from a brief), `BrandService` (apply/review updates, never silent), `ShareService`, `ReviewService`, `Assistant` (Anthropic Messages API; structured output validated by `DocumentSanitiser`; output is always a *proposal*), `ExportAudit`. Integrations in `app/Services/Integrations/` behind ports with config flags and `Http::fake` tests, only where a real contract exists (see `docs/R45_INTEGRATIONS.md`, written by the integrations agent from reading the other platforms' real code).

AI rules: no key = the assistant says so and the deterministic features (brief recommender, automatic-improve heuristics, format resize) still work; AI output is validated like any client input, never replaces existing work (it creates a proposal the user applies; applying takes a version snapshot first and is one undo step); text accuracy: user-supplied text (business name, prices, phone numbers) is substituted by code after generation, never trusted from the model; brand colours and fonts are passed in and enforced after.

## 6. File ownership (waves)

Wave 1 (parallel, worktrees off `feat/r45-design`):
- **ds**: `resources/css/ds/**`, `resources/css/app.css`, `resources/views/layouts/**`, `resources/views/components/ds/**`, `navigation-menu`, `dashboard.blade.php`, `welcome.blade.php`, auth views, error views, `DESIGN.md`, fonts for the UI (`public/fonts/ui/`), removal of CDN Tailwind/Google Fonts, logged-in shell, designs list, projects index. Tokens contract below.
- **engine**: `resources/js/studio/{doc,engine,output}/**`, `tests/js/**`, `public/fonts/design/**` + `resources/js/studio/engine/fonts.js` manifest source `config/design_fonts.php` (PHP owns the list; JS reads it from the boot JSON).
- **backend**: migrations for sections 3 (projects, canvases, versions, shares, comments, reviews, activity, user_settings, assets columns, workspace scope + policies), `app/Services/Design/{DocumentSchema,DocumentSanitiser,PageStore,VersionService,ShareService,ReviewService}`, `UpdateDesignCanvasRequest` replacement, controllers and `routes/studio/backend.php`, v1 migration, tenant-isolation tests, exporters kept working on v2.
- **business**: `config/design_formats.php`, brand kit + brand assets + templates + briefs tables/models/services/controllers/`routes/studio/business.php`, the template library (authored as real editable documents by a PHP builder DSL in `database/templates/`, seeded by `design:seed-templates`), `BriefRecommender`, `TemplateInstantiator`, `BrandService`.
- **integrations**: `docs/R45_INTEGRATIONS.md` ground truth + adapters in `app/Services/Integrations/**` + `routes/studio/integrations.php` + tests.

Wave 2 (after wave 1 merges): editor-ui (editor shell, simple/pro panels, inspector, layers, mobile), create-flow UI (start screen, guided brief, template browser, brand studio UI), output (export dialog, animation timeline UI, resize UI), AI (assistant service + panel), collab UI (share dialog, comments, review/approval, version history UI, notifications).
Wave 3: QA by workflow (beginner / pro / business / reliability), security, performance, accessibility, design jury, polish, deploy.

Shared files touched by many agents get **one-line additions only**: `routes/web.php` (the `require` lines for `routes/studio/*.php` already exist), `bootstrap/providers.php` (one provider per agent: `Studio{Name}ServiceProvider`), `app/Http/Controllers/AuditController`-style subject lists (append). Never register limiters, aliases, gates or bindings from route files (route:cache). Never touch another agent's owned directory; ask via the report instead. Decisions go to `docs/R45_DECISIONS.md` as `D-n` appended entries (merge conflicts there are resolved by keeping both).

## 7. Design system contract ("The Light Table")

Concept: a print studio's light table. The work surface is a neutral, colour-true stage; the chrome is quiet paper-and-ink; production marks (crop marks, registration targets, colour bars, swatch chips) are the structural vocabulary. The swatch chip is the unit of the UI (colours, fonts, templates and formats all present as chips on a ruled strip). Name, palette, type, radii and motion are decided by the ds agent through the installed design skills and written to `DESIGN.md`; this contract only fixes names so other agents can code against them before ds lands:

CSS custom properties (defined on `:root`, redefined for `[data-theme="dark"]`): `--lt-ground`, `--lt-panel`, `--lt-panel-sunk`, `--lt-stage` (canvas surround), `--lt-ink`, `--lt-ink-2`, `--lt-ink-3`, `--lt-rule`, `--lt-rule-strong`, `--lt-accent`, `--lt-accent-ink`, `--lt-ok`, `--lt-warn`, `--lt-bad`, `--lt-focus`, `--lt-radius-1/2/3`, `--lt-space-1..8`, `--lt-font-ui`, `--lt-font-display`, `--lt-font-mono`, `--lt-ease`, `--lt-dur-1/2/3`. Components: Blade `<x-ds.*>` (button, icon-button, chip, swatch, field, select, tabs, panel, empty, toast, menu, dialog, tooltip, kbd, status-line, progress, tag). Everything respects `prefers-reduced-motion`.

## 8. Honest limits decided up front

- Real-time multi-cursor co-editing: **not built** (shared cPanel cannot run a WebSocket server). Concurrent editing is CAS with a conflict UI, presence-free. Stated in the report.
- Background removal: **not built** (no genuine supported capability on this host); the control is absent, not disabled-pretending.
- Direct publishing to social networks: **not built** (no platform API credentials/permissions); export + download + share link only.
- Stock photo library: **not built** (licensing); the asset sources are the user's uploads, brand assets and the built-in vector shapes/icons/patterns we draw ourselves (MIT in this repo).
- Video export: WebM via the browser's MediaRecorder where supported and animated GIF; MP4 is not produced.
- PDF: raster pages at chosen dpi inside a PDF (vector PDF is not claimed).
- Server-side PNG/SVG exporters from v1 stay for the API; the editor exports in the browser.

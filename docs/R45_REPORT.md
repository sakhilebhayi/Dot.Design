# R-45 Dot.Design transformation: final report

Owner brief 2026-11-09 ("Complete Product Transformation: The Ultimate Business Design & Creative Studio"). This report separates what is **complete and verified**, what is **partial**, and what is **not built**. A feature is called complete only when a test or a browser run exercised it; an interface that merely exists is never counted. Detail per area lives in the `docs/R45_*.md` files named in each section.

## 1. Verdict

Dot.Design is now a working business design studio: guided and professional creation over one editable document model, 64 genuinely editable templates, a Brand Studio with opt-in updates, PNG/JPG/PDF (vector text)/SVG/GIF/WebM export, animation, smart resize, an honest assistant, workspace collaboration (share links, comments, review/approval, version history), security hardening and a deploy pipeline with preflight and smoke checks.

Independent estimate (two blind critics, screenshots only, before the polish wave): overall 6.3 and 5.8 out of 10, described as "honest, coherent, quiet and trustworthy; competent, not yet distinctive"; both listed concrete craft defects, which the polish wave then addressed (section 6). A second jury result is recorded in section 6 if it was run. These are estimates from screenshots, not measurements of market fit.

It is not a replacement for professional tools: Pro mode is a capable layout and typography editor, not a vector illustration suite (no pen tool, boolean operations, components or constraints). It is built for the business owner who needs a good result fast, with depth for a designer who wants control.

## 2. Initial audit (docs/R45_AUDIT.md)

Found on 2026-11-09: a dark pink generic dashboard; a 695-line Alpine editor with only text, rectangle and image elements, one font, single selection, no grouping or alignment, boxed into a card at 43% zoom; server PNG export that refuses text on the real host; no templates, brand kit, versions, comments, sharing or review; owner-only tenancy (Jetstream teams existed but did not gate designs); a mock AI image driver (honest, but a placeholder); CDN Tailwind and fonts blocking an enforced CSP. Preserved: SSO and team sync, the hardened upload pipeline, the compare-and-swap save protocol, the token catalogue and drift scan, the Analytics snapshot, security headers, and every existing project and asset (v1 elements upgrade on read).

## 3. Complete and verified

| Area | What exists | Evidence |
|---|---|---|
| Document model | Schema v2 in one JSON file read by PHP and JS: text, shape (rect, ellipse, triangle, star, polygon, line, arrow, path), image, icon, group; gradients, shadows, masks, filters, animation, brand tokens, alt text; v1 upgrade | `resources/design/document-schema.json`, sanitiser fuzz tests, engine tests |
| Editor engine | 33 undoable commands, selection/marquee, 8-handle transform, rotation, smart snapping, align/distribute, groups, clipboard, one SVG renderer for screen and export, text layout shared by both, viewport, touch, in-place text editing, autosave with 7 honest save states, crash recovery | `docs/R45_ENGINE.md`, property test (random commands then full undo), browser checks |
| Editor workspace | Simple and Pro modes over one document, plain-language Simple panel, Pro inspector (fill/gradient, stroke, shadow, opacity, blend, radius, image crop/mask/filters, typography, alignment, layers, guides, grid, rulers, bleed overlay), page strip with thumbnails, preview, shortcuts, mobile bottom-sheet and tablet docked layout | `docs/R45_UI_EDITOR.md`, `R45_POLISH_EDITOR.md`, E2E specs |
| Guided creation | Start screen, goal cards, progressive skippable brief, live recommendations with reasons, 42-format catalogue incl. print units, blank canvas picker | `docs/R45_UI_CREATE.md`, `R45_BUSINESS.md` |
| Templates | 64 editable templates, 7 styles, all brief categories (marketing, social, identity, commercial, documents), multi-page sets, one animated story; bound slots filled from the brief; real-font-metrics checks on sample, long and short copy; brand recolour legibility checks | `docs/R45_LIBRARY.md`, `R45_LIBQA.md`, `R45_POLISH_TEMPLATES.md` |
| Brand Studio | Kits (logo, colours with roles, fonts, text styles, voice, contacts), colours from a logo, contrast checker, workspace default, never-silent updates with per-design diff and selective apply (a version is saved first) | `docs/R45_UI_BRAND.md`, byte-for-byte never-silent tests |
| Assets | Upload with exact refusal reasons, folders, tags, search, usage, quota, 1600 px editing copy for large pictures | `docs/R45_UI_BRAND.md`, `R45_PERF.md` |
| Export | PNG/JPG at scale or exact size, print sizes at 150/300/600 dpi with bleed and crop marks, transparent PNG, SVG, PDF with selectable text (embedded TrueType, ToUnicode) or as pictures, GIF, WebM, batch ZIP, audit rows | decoded and independently parsed in E2E; PDF text extracted by PDFKit and an independent reader (`docs/R45_OUTPUT.md`) |
| Animation | Deterministic evaluator for every preset, per-element timeline, one-click "Animate this design" as an undoable proposal, GIF/WebM export | evaluator tests, E2E spec 04 |
| Smart resize | Re-layout by role (not a stretch) into several formats at once, each a new project, with a per-element report | fixtures and E2E spec 04 |
| Assistant | Off without a key (honest). Automatic fixes always work (contrast, headline emphasis, overflow, margins). With a key: compose from a description (picks and fills a library template, user facts substituted by code), improve proposals (restricted validated ops, never silent, one undo step), marketing copy that drops any number or link the user did not supply | `docs/R45_AI.md`, tests against a Messages API stub |
| Collaboration | Workspace visibility, share links (hashed token, expiry, view/comment, download toggle, revoke), anchored comments with replies and mentions, review request/approve/changes with separation of duties and stale-after-edit, version history with restore (snapshot first), review inbox, admin activity log | `docs/R45_UI_COLLAB.md`, E2E spec 03 with 6 users |
| Tenancy and security | Workspace-aware access, capability matrix by role, router-wide authorisation test, enforced nonce CSP, upload and document fuzzing, share-link hardening, data download and retention commands, account-deletion rules | `docs/R45_SECURITY.md`, `R45_PRIVACY.md`, 104 security tests |
| Accessibility | axe sweep 64 states x 3 viewports x 2 themes, keyboard walkthroughs, forced colours, reduced motion, 44 px targets, alt text, design-contents list for screen readers | `docs/R45_A11Y.md` |
| Performance | Editor entry 88 KB gzip (lazy feature modules) at first measurement, later 122 KB after reviewed polish (budget 130); 60 fps with 246 elements at 4x CPU; flat heap over a 41,800-operation session; 16 ms save at 300 elements; PostgreSQL parity | `docs/R45_PERF.md` |
| Delivery | CI jobs (tests, JS, signed-in smoke, PostgreSQL), `design:preflight`, backup and restore-drill scripts, docroot static sync, extended smoke checks, runbook | `docs/R45_OPERATIONS.md`, `deployment.md` |

## 4. Partial, with the missing part named

- **PDF text**: vector with real text, but a page that uses shadows, picture filters, blend modes or a missing glyph is saved as a picture (the dialog names the page). No CMYK, no ICC profiles, fonts embedded whole.
- **Animation**: top-level elements only; page transitions appear in exported video/GIF only; WebM works in Chrome (tab must stay in front), no MP4.
- **Assistant**: compose selects from the 64-template library and fills it; it does not invent free-form layouts. The request shape for the configured model was verified against a stub, never against the live API.
- **Brand updates on existing designs** do not yet re-check text readability after recolouring (the instantiation path does).
- **Layers and text frames**: handles act on the box, not the text extent (a "Fit box to text" action and an extent guide were added).
- **Accessibility**: no real screen reader pass (VoiceOver, NVDA, TalkBack) and no real-device checks; exported PDFs carry no alt text.
- **Browsers**: Chrome only for automated runs; Firefox and Safari not exercised.
- **Template variety**: about half the library is still left-aligned type over a block; weakest are noted in `R45_POLISH_TEMPLATES.md`.

## 5. Not built, by decision (docs/R45_ARCHITECTURE.md section 8)

Real-time multi-cursor co-editing (a shared cPanel host cannot run a WebSocket server: concurrency is a revision check with a conflict dialog); background removal (no genuine supported capability); publishing to social networks (no credentials or API contract); a stock photo library (licensing); MP4 export; vector illustration tools (pen, boolean ops); components and constraints; CMYK. None of these has a control in the interface.

## 6. UI and UX

New identity "The Light Table" (docs/R45_DS.md, DESIGN.md): colour-true neutral stage, crop marks, ruler rules and mono dimension labels, swatch chips, warm signal yellow from the logo for focus and success moments; Bricolage Grotesque and Atkinson Hyperlegible Next self-hosted, 18 user-facing design fonts self-hosted, no external hosts at runtime; light, dark and system themes; first-design, first-download and first-approval messages once per user.

Outside jury 1 (two blind critics, 92 screenshots): 6.3 and 5.8. Convergent defects fixed afterwards: invisible switch track, wrong template prefill, text box sizing and overflow, review bar over the artboard, pins over text, blank thumbnails, review-inbox overlap, native selects, "5,0" number format, long Simple inspector, toolbar covering labels, ambiguous mode toggle, crowded top bar, truncated phone rail, tablet using the phone layout, layers without eye/lock, unsorted icon grid, clipped kit logo, double logo on login, empty landing hero (now real templates drawn by the editor's renderer), raster-only PDF (now vector text), empty "Add a picture" boxes (now designed placeholders in previews). Jury 2 result: see the end of this section if recorded.

## 7. Design and motion skills

Actually invoked and applied (Skill tool, with their checks): `impeccable` (context, craft floor, detector: 0 anti-patterns after fixes), `design-taste-frontend` (app-screen run), `hallmark` (landing), `design-md` (DESIGN.md lints clean), `awesome-design-md` and `open-design` (reference only; the open-design content clone is not installed). Not used because unavailable: `ui-ux-pro-max` and `emil-design-eng` are dangling empty symlinks in this environment. `gsap-*` were deliberately not used (animation is a pure evaluator; UI motion is CSS). `dataviz`, `react-bits`, `scroll-world`, `canvas-ui` were not relevant. Per-agent records: docs/R45_SKILLS.md.

## 8. Backend and data

Additive migrations on the existing tables plus: `design_versions`, `design_shares`, `design_comments`, `design_reviews`, `design_activity`, `design_user_settings`, `design_templates`, `brand_kits`, `brand_assets`, `design_ai_proposals`, `design_exports`, `design_file_links`, `design_asset_folders`, `design_milestones`. Workspace = Jetstream team; 404 for invisible rows, 403 for forbidden; about 180 routes. Migrations were exercised on PostgreSQL 16 with 2,000 projects (backfill correct, idempotent, rollback clean). Services: `DocumentSanitiser`, `VersionService`, `TemplateInstantiator`, `BriefRecommender`, `BrandService`, `Assistant`. Details: docs/R45_BACKEND.md, DATABASE.md, API.md (generated from the router).

## 9. AI status

Off unless `ANTHROPIC_API_KEY` is set. Capabilities and guardrails in section 3. What is sent to the provider (the user's prompt, brand voice and library catalogue entries, or the instruction plus a summary of the page) is stated in docs/R45_PRIVACY.md. No image generation is offered. Dot.Agents, Dot.Brain and Dot.Memory are not integrated because none exposes a supported API for this use.

## 10. Dot ecosystem

Real and in use: InfoDot (SSO redeem, team sync; membership webhook built, off until configured). Built but OFF by default (need the sibling platform's owner step first, listed in docs/R45_INTEGRATIONS.md): Dot.Notify (review, comment notifications, queued, never blocks), Dot.Files (save an export; Files must list `design` in its platform secrets), InfoDot membership removal. Existing: daily aggregate snapshot to Dot.Analytics (Analytics' catalogue accepts only projects and assets for design; richer metrics need Analytics to add them). No contract found, therefore nothing built: Tasks, Projects, Agents, Brain, Memory, Emall, Pulse, HR, Engage, Forms, Press.

## 11. Security and privacy

See docs/R45_SECURITY.md (findings by severity with tests) and R45_PRIVACY.md (POPIA technical record, not legal advice). Notable fixes: reviewers could export or copy designs (now require the export capability); report-only CSP replaced by an enforced nonce policy (Livewire pages get `unsafe-eval` only where Livewire ships); account deletion no longer removes shared workspace designs or orphans files; removed members lose access to kits and templates; link probing and sign-in throttles; data download and retention commands. Not verified: real Anthropic calls, behaviour on the host's web server limits, backups on the host.

## 12. Tests

Final trunk before deployment: PHP 1,669 tests (1,662 pass, 7 skipped, same 7 since baseline of 329), JS 784, end-to-end 12 spec files driving real Chrome (beginner, professional, business, animation and resize, assistant on and off, reliability, every-route sweep as five roles, integration seams, dead-control audit, polish checks), axe sweep 384 states, signed-in production-like smoke (about 90 checks), PostgreSQL suite. Pint and static route/config/view/event caching pass.

## 13. Outstanding production risks

1. Host limits unverified: `upload_max_filesize` 8 MB and `post_max_size` at least 26 MB, GD memory for large photos (about 26 MP as JPEG on a 256 MB host), Apache modules for compression and cache headers.
2. Real-world performance on a throttled phone was measured with emulation, not devices.
3. The assistant against the real model API has not been exercised.
4. Backups are scripts only; the owner must add the cron line (marker `# design-backup`).
5. The privacy policy page does not yet name the AI provider transfer (docs/R45_PRIVACY.md section 10).
6. GitGuardian reports a false positive on a made-up test password in a security test.

## 14. Recommended next

Real screen-reader and device testing; a Firefox and Safari pass; vector illustration tools and components; a real animation easing editor; stock imagery through a licensed provider; Analytics metric names for Design; background removal only if a supported service appears; MP4 via a server worker if the host ever allows; more template variety and seasonal packs; brand-update readability check on existing designs.

## 15. Deployment

See the section appended after the production deploy (or the PR description).

---
title: Dot.Design — Platform Wiki
version: 0.3.1
status: active
owners: [Design Platform Lead]
platform-id: dot-design
last-review: 2026-08-01
---

# Dot.Design

Purpose: this is Dot.Design's own knowledge home — owned and maintained by the Dot.Design team. It describes what this platform actually is, what it stores, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-design.md)

---

## 1. What Dot.Design Is

Dot.Design is the ecosystem's **visual creation platform** — a canvas-first design editor paired with generative AI, aimed at producing on-brand graphics, social posts, and marketing collateral without requiring professional design skill. Think "Canva for the Dot ecosystem," not a component library: users open a project, drop text/shapes/images/icons onto a canvas, optionally ask AI to generate imagery or suggest a starting layout, and export the result.

**As of 0.3.0, this repository holds two coexisting domains, not one.** Alongside the canvas/AI creation tool described above, an MVP scaffold for the second domain Dot.Brain's `platforms/dot-design.md` describes — the design-token/component-library system — now also lives here (see §3.1). The two domains are intentionally separate: different tables, different models, no shared foreign keys, no shared tenancy rule. This does not yet resolve the framing question in §1.1 (is this one product or two, and should the token/component responsibility live here at all) — it just means both framings now have real code, which should make that conversation easier to have.

**Status:** early but real. The repository is a working Laravel 12 application — auth, teams, ecosystem SSO, and the core data model are scaffolded and migrated. The canvas editor UI itself (the Livewire components that actually render drag-and-drop editing) has not been built yet; the backend and domain schema exist ahead of the interactive front end. The token/component-library domain is an even earlier MVP scaffold: data model, basic CRUD, and seed data exist; no event emission, no cross-platform distribution API, no comprehension telemetry. Treat the architecture sections below as implemented; treat anything under §6 (Roadmap) as not yet built.

### 1.1 A note on framing vs. Dot.Brain's ingested view

Dot.Brain's `platforms/dot-design.md` currently describes Dot.Design as *"the enterprise design system: the shared component library, design tokens, and rendering surfaces every platform's UI is built from — including the Brain's own human-facing surfaces."* That is a different product from what is actually implemented here. The live repository is a content-creation tool for end users (canvas editor + AI image generation + brand kits + exports), not a token/component registry that other platforms' UIs consume.

This wiki describes the platform as it is coded today. The design-token/component-library concept Dot.Brain describes does not yet exist in this codebase — no token schema, no component certification pipeline, no `design.token.breaking_change` event emitter. Reconciling the two framings (are these two different responsibilities that both belong to Dot.Design, a naming collision, or should the enterprise-design-system role move to a different platform?) is the single most important open question for this platform — see §7.

## 2. Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.4 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared across ecosystem) |
| Realtime | Laravel Reverb |
| Auth | Laravel Sanctum + Jetstream (teams, 2FA) |
| AI | Multi-provider: Anthropic, OpenAI, Stability, Replicate (`AiGenerationLog.provider`) |
| Storage | AWS S3 / local (Flysystem) |
| Search | Laravel Scout · Meilisearch (planned) |
| Queue | Redis · Laravel Horizon (planned) |

## 3. Domain Entities

The schema (`database/migrations/2026_06_27_000001_create_design_tables.php`) is the source of truth. Four tables, all live:

| Entity | Table | Natural key | Notes |
|---|---|---|---|
| Design project | `design_projects` | id | Canvas project with `name`, `type` (graphic/social/print/etc.), `width` × `height`, `unit` — belongs to a `User` |
| Design canvas | `design_canvases` | id (per `design_project_id` + `page_number`) | One or more pages per project; `elements` is a JSON blob of positioned canvas objects, plus `background_color` / `background_image` |
| Design asset | `design_assets` | id | Uploaded/generated files — `type`, `file_path`, `mime_type`, `file_size`, freeform `meta` JSON |
| AI generation log | `ai_generation_logs` | id | One row per AI request — `prompt`, `result_url`, `provider`, `tokens_used`, optionally tied to a `design_project_id` |

Relationships: `DesignProject hasMany DesignCanvas` (ordered by `page_number`), `DesignProject hasMany AiGenerationLog`, `DesignAsset` and `AiGenerationLog` both `belongsTo User`. Teams come from Jetstream (`Team`, `TeamInvitation`, `Membership`) — multi-tenant by team, not yet enforced at the design-entity level (design projects are keyed to `user_id`, not `team_id`, today).

### 3.1 Design-token / component-library domain (MVP scaffold, added 0.3.0)

A second, deliberately separate domain — the schema is `database/migrations/2026_08_01_000001_create_design_system_tables.php`. Four tables, all live:

| Entity | Table | Natural key | Notes |
|---|---|---|---|
| Token set | `token_sets` | id | A versioned group of tokens that version together (e.g. "Core Palette"), carries its own `version` integer |
| Design token | `design_tokens` | id (per `token_set_id` + `slug`) | Color/type/spacing/motion; `belongsTo` a `TokenSet`, own `version` integer |
| Component | `components` | id | Reusable UI component definition; `is_brain_surface` boolean flags Brain-surface components (Why block, confidence badge, intent label) as a category, not separate certification infrastructure — no comprehension gate is implemented |
| Token consumption record | `token_consumption_records` | id (per `token_set_id` + `platform_id`) | Which ecosystem platform consumes which token set, pinned to which version — a plain tracking table, seeded with all ~21 `brain.platforms.md` registry platforms pinned to version 0 as placeholders |

Tenancy decision: these four tables carry **no** `user_id` or `team_id` — they are global/shared, unlike §3's per-user canvas-tool tables. Rationale: a design system is the same catalog every platform (and every team) consumes; per-tenant tokens would defeat the point of a shared system. Basic CRUD is plain resource controllers under `App\Http\Controllers\{TokenSetController,DesignTokenController,ComponentController,TokenConsumptionRecordController}`, routed under `/design-system/*` (see `routes/web.php`) behind the same `auth:sanctum` group as the dashboard — matching this repo's existing convention (no Livewire components exist anywhere in the codebase yet, so plain controllers were chosen over introducing a new pattern). `database/seeders/DesignSystemSeeder.php` seeds a starter color palette, spacing scale, and type scale, plus three example components (Button, Card, Confidence Badge).

**Explicitly out of scope for this MVP pass** (matches Dot.Brain's platform doc §2-3 minus what would require live infrastructure): no `ComprehensionObservation` table or telemetry pipeline (needs real telemetry infra that doesn't exist — tracked as a roadmap item, not stubbed as empty tables), no domain events (`design.token.breaking_change` etc. remain unimplemented, same as §5), no Knowledge Pack publishing integration, no cross-platform distribution API — other platforms cannot actually fetch tokens from this repo yet, only Dot.Design's own data model tracks who's *supposed* to be consuming what.

## 4. Ecosystem SSO

Dot.Design authenticates via a shared-token handoff rather than its own login flow for ecosystem users: `App\Http\Controllers\Auth\EcosystemAuthController::handle()` accepts a Sanctum personal-access token minted elsewhere in the ecosystem, checks it carries the `ecosystem:read` ability and isn't expired, logs the underlying user in, deletes the one-time token, and redirects to the dashboard. This is the pattern other Dot platforms should expect when linking users into Dot.Design without a separate signup.

## 5. Events We Emit

No domain events are wired up yet — `AppServiceProvider` is empty and there is no `EventServiceProvider`. The docs in `docs/CODE-ARCHITECTURE.md` and `docs/AI-INTEGRATION.md` specify the intended event set, which is the nearest thing to a contract today:

| Planned event | Trigger |
|---|---|
| `DesignProjectCreated` | New project created |
| `DesignProjectDeleted` | Project deleted |
| `DesignCanvasSaved` | Canvas elements persisted |
| `DesignExported` | Export to PNG/JPEG/SVG/PDF completed |
| `AiGenerationRequested` / `AiGenerationCompleted` / `AiGenerationFailed` | AI image generation lifecycle |
| `AssetUploaded` / `AssetDeleted` | Asset library changes |

None of these are implemented as Laravel event classes yet — they exist only as the architecture doc's proposed shape. Until they're wired, "events we emit" for Dot.Design is effectively empty; treat the table above as the plan, not the present.

## 6. Roadmap

Ordered roughly by what blocks what:

- [ ] Build the actual canvas editor Livewire components (`CanvasEditor`, `LayerPanel`, `PropertiesPanel`, `ToolBar` — currently just a proposed tree in `docs/CODE-ARCHITECTURE.md`)
- [ ] Wire up the AI generation pipeline end-to-end: driver abstraction over the four providers, queued generation jobs, Reverb broadcast back to the editor (`docs/AI-INTEGRATION.md` has the full design)
- [ ] Introduce the domain event classes listed in §5 and an `EventServiceProvider`
- [ ] Add `FormRequest` validation classes for project/canvas/asset/AI endpoints (currently no validation layer)
- [ ] Decide team-scoping: design entities are keyed to `user_id` today; Jetstream teams exist but aren't yet the tenancy boundary for projects/assets
- [ ] Export pipeline (PNG/JPEG/SVG/PDF) — referenced in README as a feature, not present in code yet
- [ ] Brand kit and template library models — mentioned in README's domain-model list but have no migrations or models yet
- [ ] Resolve the framing question in §1.1 with Dot.Brain before building anything resembling a token/component-certification system
- [ ] Token/component domain (§3.1): comprehension-observation telemetry — needs real instrumentation (aggregate reads with n ≥ 50 floors per Dot.Brain's contract) before any table or model is worth scaffolding; deliberately skipped in the 0.3.0 MVP pass
- [ ] Token/component domain (§3.1): domain events (`design.token.breaking_change`, `design.component.certified`, `design.consumption.drift_detected`), the comprehension gate/certification pipeline, and any actual cross-platform token-distribution API — none exist yet, only the local data model and CRUD

## 7. Connecting to Dot.Brain

Dot.Design does not yet publish Knowledge Packs — there is no publishing pipeline, signing key, or manifest in this repository. When that work starts, it should follow the shape Dot.Brain's ingestion side already expects (see [`platforms/dot-design.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-design.md) for the manifest example, payload types, and event mapping it's prepared to receive) — but the payload content described there (token-consumption records, comprehension telemetry, component certification) describes a product this repository doesn't build. Before wiring up publishing, the two framings in §1.1 need to be reconciled: either Dot.Design grows a second, genuinely separate responsibility as the ecosystem's design-token/component system (in which case that needs its own schema and roadmap here), or Dot.Brain's platform doc needs to be corrected to describe the canvas/AI-creation tool that actually exists.

In the meantime, the concrete, low-risk starting point is publishing operational `observation` packs about what's actually running: project/canvas/asset counts, AI generation volume and provider mix (all already queryable off the four tables in §3), without inventing telemetry for features that don't exist.

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.3.1 | 2026-08-01 | Design Platform Lead | First full UI/branding/tests/docs pass on top of the 0.3.0 scaffold. Real logo wired into favicons, sidebar brand mark, and auth-card logo (replacing the generic Jetstream placeholder mark). Token-set and component views rebuilt with card layouts, real color/spacing/type swatches per token, and proper empty states (previously plain index/show tables). Added a class-based light/dark theme toggle to the main app shell (previously permanently dark, no toggle). Added a Design System sidebar nav section (token sets / components were previously unreachable from navigation except by direct URL). Added Feature tests for dashboard load and token-set/component index+show. Fixed `composer.json`/`package.json` `name` fields (were still the generic `laravel/laravel` template default) and removed a stray leftover `public/dot_design.png` (duplicate of the now-wired `public/images/logo.png`) and an empty placeholder `favicon.ico`. Verified the §3.1 token/component tables remain global/unscoped and confirmed there is no missing ownership check to fix on the canvas-tool side, since no per-ID project/canvas/asset controllers exist yet (only the scoped dashboard closure) — that gap tracked in the Roadmap, not introduced or found here. Does not touch the §1.1 framing question. |
| 0.3.0 | 2026-08-01 | Design Platform Lead | Landed the design-token/component-library MVP scaffold (§3.1) alongside the existing canvas/AI tool: `token_sets`, `design_tokens`, `components`, `token_consumption_records` tables and models, plain-controller CRUD under `/design-system/*`, and a seeder with a starter palette/spacing/type scale plus three example components. Global/shared tenancy (no `user_id`/`team_id`), matching the reasoning that a design system is one shared catalog, not per-tenant data. Comprehension-observation telemetry, domain events, and any cross-platform distribution mechanism explicitly deferred — see updated Roadmap. Does not resolve the §1.1 framing question; both framings now have real code in this repo. |
| 0.2.0 | 2026-08-01 | Design Platform Lead | Initial wiki, derived from the actual Laravel codebase (models, migrations, routes, docs). Flagged the framing mismatch with Dot.Brain's `platforms/dot-design.md` (enterprise design system vs. implemented canvas/AI creation tool) as the top open question. |

## Open Questions

- Framing reconciliation with Dot.Brain (§1.1, §7): is Dot.Design one product or two? The 0.3.0 scaffold makes this more concrete but does not settle it — should the token/component domain stay in this repo long-term, or does it belong in a separate platform? Owner: Design Platform Lead → Dot.Brain steward.
- Tenancy: should design projects/canvases/assets move from `user_id` to `team_id` scoping now that Jetstream teams exist, before more code is built on top of the current shape? (Note: this question does not apply to the new §3.1 tables, which are deliberately global/shared by design.)
- AI provider cost/abuse controls: `AiGenerationLog` records four possible providers but there's no rate limiting or moderation implemented yet (both are speced in `docs/AI-INTEGRATION.md` §5/§8) — needed before any public AI generation endpoint ships.
- Token/component domain (§3.1): who actually owns writes to `token_sets`/`components` once other platforms are meant to consume them — is this an internal admin-only surface, or does it need its own auth/permission model before any platform relies on it?

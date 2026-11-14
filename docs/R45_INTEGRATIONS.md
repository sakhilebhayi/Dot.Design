# R-45 integrations: what Dot.Design really connects to

Written 2026-11-09 by the integrations agent. The owner's rule: Dot.Design integrates with another Dot platform only through a contract that exists in that platform's own code. Nothing here is assumed because a platform exists, invented, or simulated. Every verdict below comes from reading the sibling repository.

## How this was checked, and its limits

- Read-only. No sibling repository was edited, nothing was run against a production host. Sources: the local clones under `~/Dot/<Platform>` (several are stale or on feature branches), newer clones from earlier sessions, and read-only `gh api` reads of each repository's default branch on GitHub. Where a local clone lagged GitHub, GitHub's default branch won.
- "Deployed" means the repository's deploy workflow last succeeded on the default branch (checked with `gh run list`). It does NOT mean the host's `.env` holds the integration secrets; that cannot be seen from here.
- Dot.Analytics deploys from branch `feature/ecosystem-sso`, not `main` (its last run on 2026-11-07 failed; the one before, 2026-11-03, succeeded). File references for Analytics are at commit `5fe37ff` (a commit not on any local branch, found as an object in the local clone) and the same files on `feature/ecosystem-sso` on GitHub.
- A prior read-only investigation of the same platforms exists in the session scratchpad (`pulse-integrations.md`, 2026-11-07). Its findings were re-checked against GitHub today where they matter here.
- Verdict words: **REAL and implemented** (contract verified, adapter built, tests mirror the contract); **REAL but needs owner secret or setup**; **NO CONTRACT**. Every implemented integration is OFF by default.

## Summary

| Platform | Verdict | What Dot.Design does with it |
|---|---|---|
| InfoDot sign-in and company sync | REAL, already built (before R-45) | Sign-in via redeem; companies and roles synced on each sign-in |
| InfoDot membership webhook | REAL and implemented, needs owner setup | Removed members lose access at once instead of at next sign-in |
| Dot.Notify | REAL and implemented, needs owner secret and setup | Emails workspace members about shares, review requests and decisions, comments |
| Dot.Files | REAL and implemented, needs a change in Dot.Files plus owner secret | Files an export in the business's Dot.Files; a re-export becomes a new version |
| Dot.Analytics | REAL, already built (before R-45); cannot be extended | Two daily numbers (projects, assets). More metrics need Analytics to add them |
| Dot.Tasks | NO CONTRACT | nothing |
| Dot.Projects | NO CONTRACT | nothing |
| Dot.Agents | NO CONTRACT usable by Design | nothing |
| Dot.Brain | NO CONTRACT (not a service) | nothing |
| Dot.Memory | NO CONTRACT for Design | nothing |
| Dot.Emall, Dot.Pulse, Dot.HR, Dot.Engage, Dot.Forms | NO CONTRACT | nothing |
| Dot.Press | NO CONTRACT usable by Design (per-person tokens) | nothing |
| InfoDot resource registry | NO CONTRACT for Design (authority list excludes it) | nothing |

The settings page `GET /design/integrations` and the JSON `GET /design/integrations/status` show exactly this, live, with a plain-language reason per platform and no control that cannot work.

## 1. InfoDot (identity, redeem, team sync, membership, registry)

**Already built (not part of this work):** `app/Services/InfoDot/IdentityClient.php` posts the handoff token to InfoDot's redeem endpoint with `Authorization: Bearer INFODOT_SERVICE_SECRET` and `{token, platform}`; `app/Actions/Ecosystem/SyncTeamsFromInfoDot.php` applies the returned companies and roles; `EcosystemAuthController` handles `POST /auth/ecosystem`. Tests: `InfoDotRedeemTest`, `InfoDotTeamSyncTest`, `EcosystemAuthTest`, `EcosystemSsoPostTest`. InfoDot side: `routes/api.php` `POST /api/ecosystem/redeem` (`EcosystemRedeemController`, throttle `ecosystem-redeem`), per-platform secret `ECOSYSTEM_SECRET_<KEY>`. Response: `{user{id,name,email,email_verified}, teams[{id,uuid,name,personal,role,infodot_role}], current_team_id, current_team_uuid}` (InfoDot main; see also `pulse-integrations.md`).

**Membership webhook (InfoDot to Design), implemented here.** InfoDot `app/Support/MembershipWebhook.php` (`send()`) POSTs `{event, team_uuid, email?, role?}` to `{platform url}/api/ecosystem/membership` for each platform in `config/ecosystem.php` `membership_platforms` (env `ECOSYSTEM_MEMBERSHIP_PLATFORMS`, default `files,docs`), signed `X-InfoDot-Signature: sha256=HMAC-SHA256("{timestamp}.{raw body}", ECOSYSTEM_SECRET_<KEY>)` with `X-InfoDot-Timestamp`. Events: `member.removed`, `member.role_changed`, `team.deleted`. Dot.Files is the reference receiver (`app/Http/Controllers/Api/EcosystemMembershipController.php`, `ApplyMembershipChange.php`, `Services/InfoDot/SignedRequest.php`). Design's receiver: `app/Http/Controllers/Integrations/MembershipWebhookController.php`, `app/Services/Integrations/InfoDot/{SignedMembershipRequest,ApplyMembershipChange}.php`, route `POST /api/ecosystem/membership` (loaded by `StudioIntegrationsServiceProvider` under the `api` middleware, so it survives `route:cache`). Same semantics as Dot.Files: the local owner is never detached; unknown teams and people are ignored; every refusal is the same `401 {"error":"invalid_request"}`; the secret is the existing `INFODOT_SERVICE_SECRET`. Design adds one thing Files does not: it dispatches `App\Events\EcosystemMemberRemoved` after detaching, so the backend can end what that person granted (share links, pending reviews). Nothing is revoked by the integration itself.
- Verdict: **REAL and implemented, needs owner setup.** InfoDot sends nothing to Design until `design` is added to InfoDot's `ECOSYSTEM_MEMBERSHIP_PLATFORMS` and InfoDot knows Design's URL (`DOT_DESIGN_URL`, whose default in InfoDot's registry may be stale). Until then sign-in sync (the backstop) is the only path, which is also what the status page says.
- Tests: `tests/Feature/Integrations/MembershipWebhookTest.php` (signature, replay window, swapped body, off switch, owner kept).

**Resource registry (`POST /api/ecosystem/resources`).** InfoDot `app/Http/Controllers/Api/ResourceRegistryController.php`, `private const AUTHORITY = ['files' => ['file'], 'docs' => ['document']]`. A platform not in that map is refused 403. Design is not in it. Verdict: **NO CONTRACT.** Needed from InfoDot: an authority entry `design => ['design']` and a decision on what InfoDot would open for a design (the stable path would be `/design/projects/{id}`).

**Business verification / profile data:** InfoDot exposes none through any API (see `pulse-integrations.md`, section "InfoDot business verification"). Design therefore shows no "verified business" badge and fetches no brand data from InfoDot.

## 2. Dot.Notify

**Contract.** `POST /api/ingest/events` (Dot.Notify `routes/api.php`, `IngestEventController`, line references at `notify-ops` clone commit `9f0f5ca`; GitHub main is the same for this file). No session; the caller signs.
- Signature: `X-InfoDot-Timestamp` (unix seconds, within 300 s) and `X-InfoDot-Signature: sha256=hex(HMAC-SHA256("{timestamp}.{raw body}", secret))` (`app/Services/Notify/IngestSignature.php:28-30`). Secret per platform from env `NOTIFY_INGEST_SECRET_<KEY>` (`config/notify.php:22`); the key list is the platform registry, so `design` is accepted as soon as the env is set. Blank secret = platform cannot call; every authentication failure is the same 401.
- Body (`IngestEventController.php:56-63`): `platform` (<=40), `company_uuid` (uuid; Notify finds the team by `teams.infodot_team_uuid`, unknown = 404), `event` (`/^[A-Za-z0-9][A-Za-z0-9._:\-]*$/`, line 35), `recipient.email` or `recipient.user_email`, `data` (array), `idempotency_key` (required, <=120), optional `channel`, `sensitive[]`, `notification_type`. Body cap 64 KB; 120 per minute per platform+company (429 + `Retry-After`). Replies 202 new, 200 replay of the same (company, platform, key), 422 bad payload.
- It is not a free "send this message" API. `EventIngestor::route()` (line 120) looks up the company's own active `NotifyRule` for `trigger_event` = the event name; with none the event is stored as `no_rule` and nothing is sent (line 136). Rules are never created by the caller. Catalogued events (`config/notify_events.php`: tasks, hr, auction, ehail; Sheets is in open PR #31) have their `data` keys filtered and default templates a company switches on from its "Connected apps" page. **There is no `design` section in the catalogue.** For an uncatalogued event the company writes its own rule: `RuleManager` accepts any `trigger_event` as free text (`app/Livewire/Notify/RuleManager.php:119`) and pairs it with its own channel and template. Data of uncatalogued events is stored unfiltered, which is why Design sends only the keys below.
- Deployed: Notify's deploy succeeded 2026-11-01.
- Reference consumer: Dot.Tasks `app/Services/Notify/NotifyClient.php` (queued, 5 s timeout, breaker, idempotency key `event:subject:sha1(email):bucket`), Dot.HR the same.

**What Design sends** (`app/Services/Integrations/Notify/DesignNotifyEvents.php`):

| Event | Told | Data keys (nothing else) |
|---|---|---|
| `design.review.requested` | each reviewer | `design_name`, `requested_by`, `url` |
| `design.review.decided` | the requester | `design_name`, `decided_by`, `decision`, `url` |
| `design.comment.added` | the design's owner and anyone @-mentioned | `design_name`, `commented_by`, `url` |
| `design.shared` | the named workspace members the design was shared with | `design_name`, `shared_by`, `url` |

Never sent: comment text, review notes, other addresses, file names. The recipient address appears only in `recipient.email`. Only people who belong to the workspace the event happened in are addressed, never the actor; a workspace without an InfoDot company uuid (a personal workspace) sends nothing, because Notify files events under a company.

**Built.** `NotifyGateway` (`NotificationGateway` port) with a 5 s timeout, signature made at send time, breaker (5 transient failures pauses 120 s), last-outcome memory for honest status, no secret or address in any log line. `SendDesignNotifications` is a queued listener (`afterCommit`, 3 tries, backoff 60 s then 300 s) registered for the four events; it is not even queued while the integration is off; every error is caught inside it, so the user action cannot fail because of Notify. Retries re-send the batch safely because of the idempotency keys. Tests: `NotifyGatewayTest` (the request is validated against copies of Notify's own rules and signature), `DesignNotificationsTest`.
- Verdict: **REAL and implemented, needs owner secret and setup.** Owner steps: set `NOTIFY_INGEST_SECRET_DESIGN` in Notify and the same value as `NOTIFY_INGEST_SECRET` here, `NOTIFY_INGEST_URL=https://<notify host>/api/ingest/events`, `DESIGN_INTEGRATION_NOTIFY=true`; each business then creates a rule per `design.*` event in Notify. Ask of Notify: a `design` section in `config/notify_events.php` using the table above, so businesses can switch the events on with one click and `data` is filtered; and Notify's `DOT_DESIGN_URL` must be Design's real host or the `url` key is dropped once catalogued.

## 3. Dot.Files

**Contract.** Dot.Files `docs/programme/PLATFORM_FILE_API.md`, `routes/api.php:38-52`, `app/Http/Controllers/Api/PlatformFileController.php`, `app/Services/Ecosystem/PlatformRequest.php`. Six signed POST endpoints under `/api/platform/files/`: `store`, `update`, `fetch`, `meta`, `list`, `trash` (throttle 60-120 per minute). Headers `X-Dot-Platform`, `X-Dot-Timestamp`, `X-Dot-Signature: sha256=HMAC-SHA256("{timestamp}.{raw body}", secret)` (`PlatformRequest.php:32`, tolerance 300 s, line 18). Body always JSON including `team_uuid` (the company's InfoDot uuid). Authorisation is structural: the calling platform sees only files with `origin_platform` equal to itself, in the one company named (`PlatformFileController.php:202`), so Design can never read a person's own uploads or another platform's files. `store`: `{team_uuid, name, content_b64, mime?, folder?}`, extension allowlist `pdf csv txt doc docx xls xlsx ppt pptx jpg jpeg png gif webp svg zip tar gz mp4 mp3 mov avi` (line 32; **no json**), 25 MB decoded; the stored mime is sniffed from the bytes. `update` saves a new version with optional `base_version` and answers 409 `version_conflict` if the document moved on. Platform files appear in the business's Dot.Files workspace in a folder named after the platform (default the platform's display name), with an origin chip, and obey sharing, previews and trash. Per-platform secrets: `DOT_SERVICE_SECRET_<KEY>` listed in `config/ecosystem.php` `platform_secrets` (`:77`).
- Deployed: Dot.Files deploy succeeded 2026-10-31 (`a2899b1`), the API went live in #114, update/versions in #116.
- **Blocker on the Files side:** `platform_secrets` on Files' main lists agents, analytics, central, charts, docs, dopemine, engage, finance, forms, hr, memory, mines, press, projects, sheet. **`design` is not in it**, so Files answers 401 to Design today. Dot.Tasks has the same gap and a one-line fix (`feat/tasks-platform-secret`, commit `0a33b26`: `'tasks' => env('DOT_SERVICE_SECRET_TASKS')`); Design needs `'design' => env('DOT_SERVICE_SECRET_DESIGN')` in the same list. Files' registry copy also marks design `active => false` and the `design` URL as `.co.za`; neither blocks the API (only `platform_secrets` is read).
- Reference consumer: Dot.Tasks `app/Services/Files/FilesClient.php`.

**Built.** `FilesVault` (`FileVault` port) with `store` and `update`, 20 s timeout, breaker, plain-language errors per status, last-outcome memory. `ExportFiler` receives an export the browser produced (the architecture says exports are made in the browser and only stored if the person saves them to Dot.Files), checks the bytes are really the claimed format (PNG, JPG, PDF, GIF magic bytes; SVG must contain `<svg` and no script), then stores it, or, if that same (design, workspace, page or "all", format) was filed before, saves a **new version** (`design_file_links` table keeps only the uuid, version and link). The route is `POST design/projects/{project}/dot-files` (multipart: `file`, `format`, `scope` = `all` or `page-N`, `acknowledge`). It answers 404 while the integration is off, so the UI must offer the control only when `FileVault::enabled()` is true. The person must send `acknowledge=1`, because a file in Dot.Files is visible to **everyone in the business** whatever the design's own visibility is; the UI should say so next to the button. Authorised by the existing `view` policy on the project (somebody else's design is a 404), the workspace must be linked to an InfoDot company and the person must belong to it. Tests: `DotFilesTest`.
- Not built, by contract: project source JSON cannot be filed (Files accepts no `.json`; a `.zip` would need the engine to package it). Opening or fetching a filed file back is not offered (the `fetch` endpoint exists but returns only what Design itself filed; no feature needs it). Cross-platform reads, shares and webhooks are "not in v1" per Files.
- Verdict: **REAL and implemented, needs a Files change plus owner secret.** Owner steps: add `design` to Files' `platform_secrets` (PR in Dot.Files), set `DOT_SERVICE_SECRET_DESIGN` there and the same value as `FILES_PLATFORM_SECRET` here, `DESIGN_INTEGRATION_FILES=true`, `FILES_API_URL`. First save to Dot.Files marks the status "connected".

## 4. Dot.Analytics

**Contract.** `docs/metrics-contract.md` (commit `5fe37ff`, also on `feature/ecosystem-sso`), `routes/api.php:36` `POST /api/ingest/snapshots` (throttle `analytics-ingest-edge`). HMAC as above with `ANALYTICS_INGEST_SECRET_DESIGN`; body `{platform, company_uuid, taken_at, period:"day", metrics{}}`; 64 KB; 120 per minute. **The catalogue decides what is accepted, and unknown keys are silently dropped** (`dropped_keys` in the reply). `config/metrics.php:166-173`: `design` accepts exactly `projects` and `assets`, both counts.

**Already built (before R-45):** `app/Services/Analytics/{AnalyticsClient,MetricsCollector,SnapshotPublisher}.php`, job `SendAnalyticsSnapshot`, command `design:publish-metrics` (daily 00:10 UTC). Unchanged here.

**Why it was not extended.** The brief asked for designs created, exports by format, templates used and reviews approved. None of those names exists in Analytics' `design` catalogue, so sending them would be accepted with a 2xx and thrown away: a metric that looks wired but arrives nowhere. That is exactly the dishonesty the owner forbids, so nothing was added. Verdict: **NO CONTRACT for new metrics.** What Analytics would need to add to `config/metrics.php` (and `docs/metrics-contract.md`, which a test keeps in step) under `design`, all whole numbers, aggregate and PII-free, all computable from tables the backend adds (`design_exports`, `design_reviews`, `design_projects.template_id`): `designs_created_7d`, `exports_7d`, `exports_pdf_7d`, `exports_png_7d`, `templates_used_7d`, `reviews_approved_7d`. When Analytics adds them, extending `MetricsCollector::collect()` is a few lines plus tests.
- Status page: configured/not, breaker open or not. It cannot show whether the last day arrived, because Design keeps no record of it.

## 5. Dot.Tasks and Dot.Projects

Tasks `routes/api.php` (clone `337757c`, GitHub main) has one route: `GET /user` behind Sanctum. Projects: the same. Both take sign-in and the browser UI; neither has any server-to-server endpoint (no HMAC verifier exists in their `app/`, except Tasks' own outbound clients to Notify, Files and Analytics). Sanctum personal tokens are per person and would have to be created by hand and stored by Design. Verdict: **NO CONTRACT** for creating a design request or attaching a design link to a task or project. Needed from them: a signed `POST /api/platform/...` endpoint following the Dot.Files pattern (per-platform secret, `team_uuid`, scoped to what the caller created) that creates or links a task, plus a decision on what a "design request" is. Until then the only honest link is a plain URL a person pastes, which needs no code.

## 6. Dot.Agents, Dot.Brain, Dot.Memory

- **Dot.Agents** (GitHub main): `routes/api.php` has a signed webhook trigger `POST /api/hooks/{uuid}` (throttle 60/min) and a Sanctum API `v1/*` (`deployments/{id}/runs`, `runs/{run}`, `workflows/{id}/runs`, agents, skills). `RunController::store` requires `Gate::authorize('view', $deployment)` and `canInOrganization('agents.operate')` for the token's user and takes `input` (<=20000 chars); output is `taint: 1` ("words that arrive here came from outside the business"). Auth is a Bearer API key a person creates by hand per business, expires in 90 days; there is no app-to-app secret. A design-time use ("send my brief to a business agent") would need Design to hold that key for the business, and runs "need a person before they change anything". Design already has its own assistant on the Anthropic API (architecture section 5). Verdict: **NO CONTRACT** usable by Design. Needed: a platform-secret (HMAC) run endpoint scoped to a company and deployment, which Agents has not built.
- **Dot.Brain** has no web app and no routes (docs, ADRs and Node tooling run by GitHub workflows; the host does not resolve). Verdict: **NO CONTRACT.**
- **Dot.Memory** (GitHub main): `/api/intelligence/*`, `/api/ops/*`, `/api/guardian/health` use a Sanctum guardian service token; `/api/participation/*` and `/api/knowledge-recommendations/*` use HMAC with one secret per registered consumer (infodot, brain), keyed by InfoDot user uuid and opt-in people only. Design is not a consumer, and nothing Design holds is "user-approved context" Memory is built to receive. Verdict: **NO CONTRACT** for Design. Needed: a consumer registration and a Design-specific purpose in Memory, which would first need an owner decision about what, if anything, a design studio should remember about people.

## 7. Dot.Emall, Dot.Pulse, Dot.HR, Dot.Engage, Dot.Forms, Dot.Press

GitHub default branches, `routes/api.php`: Emall, Pulse, HR, Engage, Forms, Projects, Tasks, Sheet each have only `GET /user` (Sanctum). Emall's `POST /webhooks/paystack` is a payment-provider webhook; Engage's `signed` routes are for external contract signers; neither is a platform contract. Their web UIs take assets only through the browser.
- **Emall (product images, banners), Pulse (post images), HR (job ads, brand pack), Engage, Forms:** **NO CONTRACT.** Nothing can be pushed or pulled. A person downloads from Design and uploads there.
- **Deep links only (no code):** every platform's `EcosystemAuthController` honours a validated path `redirect`, and InfoDot's `GET /go/{platform}?redirect=/path` signs a person in and lands them there. A convention that works without any contract: `https://infodot.co.za/go/<platform>?redirect=<path on that platform>`. Design does not render these links, because the paths on the other platforms are theirs to keep stable and no feature here needs them.
- **Dot.Press** (GitHub main) has a rich Sanctum API including `POST /api/assets` (`throttle:press-upload`) and decks. Sanctum there is per person; for Design to upload a person's export to Press it would have to hold that person's personal access token, which is a stored third-party credential per user and not an ecosystem contract. Verdict: **NO CONTRACT** usable by Design. Needed: a signed platform endpoint like Files' (company, user email, asset) rather than personal tokens.

## Seams for the coordinator

New plain events (documented payloads) in `app/Events/`. Four are STUBS that the backend agent's classes of the same names must replace; the listener `App\Listeners\Integrations\SendDesignNotifications` and `App\Services\Integrations\Notify\NotificationPlanner` read only the public properties below. If the real classes differ, adjust the four small methods in `NotificationPlanner` (one place).

| Event | Public properties read |
|---|---|
| `DesignCommentAdded` | `int projectId`, `int commentId`, `int authorUserId`, `?int teamId`, `list<int> mentionedUserIds` |
| `DesignReviewRequested` | `int projectId`, `int reviewId`, `int requestedByUserId`, `list<int> reviewerUserIds`, `?int teamId` |
| `DesignReviewDecided` | `int projectId`, `int reviewId`, `int decidedByUserId`, `int requestedByUserId`, `string decision` (`approved` or `changes_requested`), `?int teamId` |
| `DesignShared` | `int projectId`, `int shareId`, `int sharedByUserId`, `list<int> recipientUserIds` (empty for link or whole-workspace shares), `?int teamId` |
| `EcosystemMemberRemoved` (mine, not a stub) | `int teamId`, `int userId`, `string reason`; dispatched by the InfoDot webhook after a detach |

`teamId` null falls back to the project's `team_id` attribute; if neither exists nothing is sent. `ExportFiler::teamFor()` also reads `team_id` from the project, falling back to the person's current team.

## Environment variables (all optional; every integration is off by default)

| Variable | Used by | Meaning |
|---|---|---|
| `DESIGN_INTEGRATION_NOTIFY` | Notify | `true` to switch on |
| `NOTIFY_INGEST_URL` | Notify | full URL ending `/api/ingest/events` |
| `NOTIFY_INGEST_SECRET` | Notify | equals `NOTIFY_INGEST_SECRET_DESIGN` in Dot.Notify |
| `NOTIFY_PLATFORM_KEY` | Notify | default `design` |
| `NOTIFY_INGEST_TIMEOUT` | Notify | seconds, default 5 |
| `DESIGN_INTEGRATION_FILES` | Files | `true` to switch on |
| `FILES_API_URL` | Files | default `https://files.infodot.co.za` |
| `FILES_PLATFORM_SECRET` | Files | equals `DOT_SERVICE_SECRET_DESIGN` in Dot.Files |
| `FILES_PLATFORM_KEY` | Files | default `design` |
| `FILES_FOLDER` | Files | folder name in the business's Dot.Files, default `Dot.Design` |
| `FILES_TIMEOUT` | Files | seconds, default 20 |
| `DESIGN_INTEGRATION_MEMBERSHIP` | InfoDot webhook | `true` to accept InfoDot's membership calls (uses `INFODOT_SERVICE_SECRET`) |
| `DESIGN_INTEGRATION_BREAKER_THRESHOLD` / `_COOLDOWN` | all | failures in a row before pausing (default 5), pause seconds (default 120) |

## Owner-only steps

1. **Dot.Files:** merge a change adding `'design' => env('DOT_SERVICE_SECRET_DESIGN')` to `platform_secrets`, set that secret on the Files host, set `FILES_PLATFORM_SECRET` (same value), `DESIGN_INTEGRATION_FILES=true` here.
2. **Dot.Notify:** set `NOTIFY_INGEST_SECRET_DESIGN` there, `NOTIFY_INGEST_SECRET` + `NOTIFY_INGEST_URL` + `DESIGN_INTEGRATION_NOTIFY=true` here; in Notify ask for a `design` catalogue section (optional; without it each business writes four rules by hand).
3. **InfoDot:** add `design` to `ECOSYSTEM_MEMBERSHIP_PLATFORMS`, make sure its registry URL for Design is the real host, then `DESIGN_INTEGRATION_MEMBERSHIP=true` here. The `INFODOT_SERVICE_SECRET` already used for sign-in is reused.
4. **Dot.Analytics:** nothing for what exists; to add metrics, a catalogue change in Analytics (names above).
5. A queue worker must run (the shared host's cron `queue:work --stop-when-empty`) for notifications to leave; without one they wait on the queue.
6. Link every workspace to its InfoDot company (SSO sync does it at sign-in, or `php artisan infodot:link-team {team} {uuid}`): Notify and Files address a business by `infodot_team_uuid`.

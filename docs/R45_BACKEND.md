# R-45 backend: document store, workspaces, history, sharing, review, audit

Owner: backend agent. Code: `app/Services/Design/**`, `app/Support/Design/**`, `app/Http/Controllers/Studio/**`, `routes/studio/{backend,public}.php`, migrations `2026_11_09_1000xx_*`. Contract this implements: `docs/R45_ARCHITECTURE.md` sections 2, 3, 5. Decisions: `docs/R45_DECISIONS.md` D-300..D-3xx.

## 1. What the old editor still uses
The v1 editor (`public/js/design-editor/`, `design.projects.show`) is untouched and keeps working:
- `PUT design/projects/{project}/canvases/{canvas}` (`design.projects.canvases.update`, `UpdateDesignCanvasRequest`): v1 payload (text / rectangle / image), v1 storage, same 409/422 bodies. It now goes through `PageStore::savePrepared()`, so it shares the revision counter, the automatic snapshots and the "approval goes stale" rule with the new API.
- `POST .../canvases` (add page), `DELETE design/projects/{project}` (HTML redirect, JSON when asked), asset upload/serve/delete, the SVG and PNG exporters.
- **Exports**: the new editor exports in the browser. `CanvasSvgExporter` / `CanvasPngExporter` remain for the API and legacy links; they tolerate v2 pages (rect and ellipse shapes, text with alignment, pictures, groups; icons, other shape kinds, hidden elements and empty picture slots are skipped; a gradient draws as its first stop). The SVG exporter takes an optional `Closure(int): ?string` to reference pictures by URL instead of embedding them (the share page uses it).

## 2. Data model (additive; existing rows preserved)
| Table | Notes |
|---|---|
| `design_projects` + `team_id` (FK, null on team delete), `visibility` (private default), `status` (draft default), `thumbnail_path`, `thumbnail_updated_at` | Backfilled: owner's personal team, visibility private. The business agent adds format/brief/template/brand columns separately (guarded). |
| `design_canvases` + `name`, `width`, `height` (null = inherit project), `background` json, `duration_ms` (5000), `transition`, `guides` json | `background_color` is kept in step with a solid v2 background for the v1 exporters. |
| `design_assets` + `team_id`, `folder`, `tags` json, `license` (own/brand/builtin), `source` | Quota stays per user. |
| `design_versions` | project, user, kind (auto/manual/restore/approved), label, `pages` json snapshot, bytes, created_at. |
| `design_shares` | `token_hash` (sha256, unique), permission (view/comment), expires_at, revoked_at, download_allowed, last_viewed_at, view_count. No address/agent columns exist. |
| `design_comments` | project, page_id (null on page delete), element_id, x, y, user_id or guest_name + share_id, body, mentions json, parent_id (cascade), resolved_at/by. |
| `design_reviews` | project, version_id (pinned), requested_by, reviewer_id, status, note, decision_note, decided_at. |
| `design_activity` | team_id, project_id, user_id, action, subject_type, subject_id, meta json. **No foreign keys on purpose**: a deleted project/user/workspace must not erase or null the record. Append-only (model refuses update/delete). |
| `design_user_settings`, `design_exports`, `design_project_grants` | see below. |

Every foreign key and every list-query column has an index (asserted by `StudioMigrationTest`). All migrations roll back; backfill is portable (one personal team at a time) and a no-op on an empty database.

## 3. Access (tenancy)
Workspace = Jetstream team. Visibility is a **global scope** (`HasWorkspaceScope`, replaces `HasUserScope` on `DesignProject` and `DesignAsset`; `HasUserScope` stays on `AiGenerationLog`): a row you cannot see does not exist (route binding answers 404, same body as a missing id). What you may DO with a visible row is `DesignAccess::capabilities()` and the policies, which only ask that (403).

A project is visible to: its owner; members of its team **when visibility = team**; holders of an explicit grant (`design_project_grants`, members of the same workspace only); the reviewer named on a non-cancelled review. A workspace admin does **not** see a teammate's private project.

| Capability | owner / admin | editor | viewer ("Reviewer") |
|---|---|---|---|
| view | yes | yes | yes |
| create (projects in the workspace) | yes | yes | no |
| edit | yes | yes | no |
| comment | yes | yes | yes |
| export | yes | yes | no |
| share_link | yes | yes | no |
| request_review | yes | yes | no |
| approve | yes | no | yes |
| delete | yes | own projects only | no |
| change_visibility, manage_grants | yes | own projects only | no |
| view_activity (audit) | yes | no | no |
| manage_library (brand assets) | yes | no | no |

Other rules: a **former member** keeps view/export/delete on their own work only; an unaccepted invitation grants nothing; a deleted workspace leaves its projects private to their owners (full control, as personal); a grant adds view / comment / edit(+export); a pending review adds view, comment, approve (a decided one keeps view; cancelled, nothing). Roles are read from the database on every check, so a removal or demotion bites on the very next request. The Jetstream `viewer` role is registered in `JetstreamServiceProvider`; InfoDot sync maps an InfoDot `viewer` to it and an ordinary `member` to `editor`.

Pictures: an image element may reference an asset the saver owns, one already placed anywhere in that project, one filed under it, or a brand asset of the workspace (`ProjectAssets`). A teammate loads a colleague's picture through `GET design/projects/{project}/assets/{asset}/file` (policy-checked, picture must belong to the project).

## 4. Document sanitiser (`DocumentSanitiser`, `DocumentSchema`)
Reads `resources/design/document-schema.json` (limits, enums, value patterns, per-property min/max/default) and font keys from `config/design_fonts.php` (list, key=>meta map, or under `fonts`; built-in fallback of the 18 keys). Three outcomes for a bad value:
- **correction** (counted by generic kind, never echoing values): clamp, wrong type, unknown enum/font/colour/bind/token, duplicate or malformed id, unknown key, control characters, name truncated, empty group dropped.
- **error** (422, field-level `elements.3.d`): unknown element type or shape kind, text over 5000 chars, unusable path data (only M L H V C S Q T Z, correct argument counts, numbers bounded, re-serialised from tokens), unusable icon key, unusable or not-permitted picture (`asset_id`, also in groups and page backgrounds, resolved in one batch).
- **rejection**: more than 300 elements (nested children count) or groups deeper than 3 (422); over 1 MB of normalised JSON (413). Errors reported are capped at 50.
v1 -> v2: `rectangle` -> `shape/rect`, text gains `font: inter`; idempotent; applied on every read (`readElements`, lenient: nothing throws, unusable elements are dropped and counted) and never written back until a real save. All defaults are materialised on save.

## 4b. Endpoints
All under the signed-in session (`auth:sanctum` + verified), CSRF via the `X-CSRF-TOKEN` header, JSON in/out (`JsonApi` middleware forces JSON errors). 401 JSON for a lapsed session, 404 invisible, 403 forbidden, 409 conflict, 413 too large, 422 invalid `{status:'invalid', message, errors:{field:[...]}}`, 429 `{status:'throttled'}`.

| Method + path (route name `design.…`) | Body / notes | Response |
|---|---|---|
| `GET projects/{p}/document` (`projects.document`) | | `{schema_version, project{id,name,type,width,height,unit,status,visibility,team_id,owner,is_owner,thumbnail_url,review}, pages[{id,page_number,name,width,height,background,duration_ms,transition,guides,revision,updated_at,elements}], assets{id:{id,name,url,width,height,folder,tags,license,owned,placeholder}}, permissions{role,capabilities[],can{}}, limits, read_corrections}` |
| `PUT projects/{p}/pages/{page}` (`projects.pages.update`; throttle `design-saves` 120/min; body cap 1.4 MB) | `{revision (required), elements?, name?, width?, height?, background?, duration_ms?, transition?, guides?, background_color?}`; only carried fields change | 200 `{status:'saved', revision, updated_at, corrections{total,by_kind}}`; 409 `{status:'conflict', revision}`; 413 `{status:'too_large'}`; 422 |
| `POST projects/{p}/pages` (`pages.store`) | `{name?, after_page_id?, width?, height?, background?}` + header `Idempotency-Key` (8-80 of `A-Za-z0-9._:-`, remembered 24 h per user+project) | 201 `{status:'created', idempotent_replay:false, page}`; replay 200 with the same page; 422 at 60 pages |
| `DELETE projects/{p}/pages/{page}` (`pages.destroy`) | the last page cannot be deleted | `{status:'deleted'}` |
| `POST projects/{p}/pages/{page}/duplicate` | fresh element ids, "Copy of", inserted after the source | 201 `{status:'created', page}` |
| `PUT projects/{p}/pages/order` | `{order:[every page id]}` | `{status:'ordered', order}` |
| `PUT projects/{p}` (`projects.update`) | `{name?, status? (draft/archived only), visibility? (private/team)}`; width/height/unit are 422 | `{status:'updated', project}` |
| `DELETE projects/{p}` (`projects.destroy`) | audit row written in the same transaction; versions/shares/comments/reviews cascade; pictures kept | JSON `{status:'deleted'}` or HTML redirect |
| `PUT projects/{p}/thumbnail` (raw PNG/WebP body, <= 300 KB) or `POST .../thumbnail` multipart `thumbnail` | decoded and re-encoded through GD, stored on the private disk | `{status:'saved', thumbnail_url}`; 413 / 422 |
| `GET projects/{p}/thumbnail` (`projects.thumbnail.show`) | policy-checked image, nosniff, private cache | image |
| `GET projects/{p}/assets/{id}/file` | picture that belongs to the project | image |
| `GET/PUT settings` | `{mode simple/pro, snap, grid, rulers, reduced_motion_pref system/reduce/full, last_format_key, onboarded}` | `{settings}` |
| `GET projects/{p}/versions?kind=&per_page=&cursor=`, `POST` `{label}`, `GET .../{v}`, `GET .../{v}/diff?against=current\|{id}`, `POST .../{v}/restore` | list never carries pages; diff = counts per page and element (added/removed/changed), no pixels | restore: `{status:'restored', restored_version, pre_restore_version}` |
| `GET/POST projects/{p}/shares`, `DELETE .../shares/{id}` | create `{permission view/comment, expires_in_days?, download_allowed?}` | create returns `{share, url, note}`: **the URL carries the token and is never shown again** |
| `GET/POST projects/{p}/comments`, `PUT/DELETE .../{c}`, `POST/DELETE .../{c}/resolve` | `{body<=2000, page_id?, element_id?, x?, y?, parent_id?, mentions?[ids]}` | list: threads with `replies[]`, `unresolved`, per-comment `can{edit,delete,resolve}` |
| `GET/POST projects/{p}/reviews`, `POST .../{r}/approve`, `.../request-changes` (note required), `.../cancel`; `GET reviews?box=assigned\|requested&status=` | request `{reviewer_id, note?}` | review `{id,status,note,decision_note,requester,reviewer,version,created_at,decided_at}` |
| `POST projects/{p}/exports` | `{format png/jpg/svg/pdf/gif/webm, pages 'all'\|[ids], scale 0.1-8, bytes?}` | records only; the browser made the file |
| `GET projects/{p}/activity`, `GET activity?team_id=&project_id=&action=&cursor=` | admins only | `{activity[{id,action,subject_type,subject_id,project_id,actor,meta,created_at}], next_cursor}` |
| `GET/POST projects/{p}/grants`, `DELETE .../{g}` | `{user_id (workspace member), level view/comment/edit}` | |
| `GET design/assets` (JSON or HTML), `GET assets/folders`, `PUT assets/{a}` | filters `q folder (_none) tag license source project_id mine per_page cursor` | `{assets[…folder,tags,license,source,owned], next_cursor, usage}` |
| Public: `GET /s/{token}`, `GET /s/{token}/assets/{id}` (signed, 10 min), `POST /s/{token}/comments` | guest `{guest_name, body, page_id?, element_id?, x?, y?, website (honeypot)}` | page HTML; image; `{status:'created'}` |

## 5. Versions
Automatic snapshot of the state **before** a save, at most every 10 minutes (any version counts), inside the save transaction; a conflicting save writes none. Always a snapshot before restore (kind `restore`). Other agents call `VersionService::checkpoint($project, $user, 'Before applying X')` before an AI apply, brand apply or template swap (always snapshots). Retention: newest 50 `auto`; `manual`, `restore` and `approved` are kept. Restore = pre-restore snapshot, then pages rewritten as a NEW revision each (open editors conflict), deleted pages recreated, pages not in the version removed (recoverable from the pre-restore snapshot), approval marked stale. Sending for review pins a `manual` "Sent for review" snapshot; approval promotes it to `approved`.

## 6. Reviews
`ReviewStatus` enum is the single source of legal moves: pending -> approved | changes_requested | cancelled; approved -> stale; everything else final (409 on any other attempt). One pending review per project. Reviewer must be a workspace member whose role has approve (owner, admin, viewer). With more than one member nobody reviews or approves their own request (422 at request, 403 at decision); a one-member workspace reviews itself. Editing after approval (page save, add, duplicate, delete, reorder, restore) marks the approved review `stale` and the project `draft` inside the same transaction; a pending review is pinned to its snapshot and unaffected. Events: `DesignReviewRequested`, `DesignReviewDecided`.

## 7. Shares and public routes
Token: 40 random alphanumerics, sha256 stored, shown once. Lookup validates the format, matches the hash (`hash_equals`), then expiry and revocation; malformed, unknown, expired and revoked all render the identical 404 page. Public routes run **without** `StartSession`, CSRF and error-share middleware (no cookie is ever set) and with `PublicShareHeaders`: `X-Robots-Tag: noindex`, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`. The page embeds the sanitised read-only document in `<script type="application/json" id="dot-share-document">` (`JSON_HEX_TAG|AMP|APOS|QUOT`, so no content can close the tag) plus a server-drawn SVG per page; pictures are `temporarySignedRoute` URLs re-checked against the live link. Views are a counter and a timestamp; throttling uses the framework limiter (address hashed, kept for the window only). Guest comments: name required, honeypot swallowed silently, 6/min per link+visitor, no address stored, only when permission = comment.

## 8. Events (plain Laravel events; the integrations agent wires Notify)
`DesignCommentAdded($comment, $project, $mentioned[])`, `DesignReviewRequested($review)`, `DesignReviewDecided($review)`, `DesignShared($share, $project, $by)` (no token on it), plus the existing `DesignCanvasSaved`, `DesignProjectCreated/Deleted`, `AssetUploaded/Deleted`.

## 9. Hardening and limits
- Request body ceiling for page saves is `design.studio.max_request_bytes` (1.4 MB). **The web server must allow at least that**: nginx `client_max_body_size 2m;`, Apache `LimitRequestBody 2097152`, PHP `post_max_size >= 2M`, otherwise the server answers before the app can give its plain-language 413.
- Throttles (defined in `StudioBackendServiceProvider::boot`, tunable in `config/design.php` `studio.*`): saves 120/min/user, shares 20, comments 30, thumbnails 30, exports 60; public views 120/min and comments 6/min per link+visitor.
- JSON errors never contain traces or model names (`bootstrap/app.php`).
- Large-document test: 300 elements / ~0.8 MB saves and loads well under the budget (asserted at 5 s / 3 s; typically < 1 s on SQLite). Every list endpoint is covered by a constant-query-count test.
- Honest limits: the sanitiser validates icon keys by pattern only (no icon catalogue exists on this branch); thumbnails and exports are produced by the browser; no real-time co-editing (CAS conflict only).

# Dot.Design HTTP interface

What the application answers over HTTP, as of the R-45 rebuild. The route tables below are **generated** from the running application (`php artisan route:list --json`) by `php scripts/ops/generate-docs.php`, and `tests/Feature/DocsInSyncTest.php` fails when they drift, so a route cannot be added without this file changing. The prose around them is hand-written and describes behaviour the code and its tests pin; the per-area detail lives in the R-45 area documents linked at the end.

There is no separate public API. Every JSON endpoint below is the editor's own backend and belongs to the signed-in session. The only machine-to-machine endpoint is the InfoDot membership webhook.

## Authentication, sessions and CSRF

- **Sign-in** is InfoDot's: a person arrives with a one-time token (`POST /auth/ecosystem`), the app redeems it at InfoDot (`INFODOT_REDEEM_URL`, `INFODOT_SERVICE_SECRET`) and opens a normal Laravel session. Fortify's own login stays for accounts that exist. Public sign-up is closed (`ALLOW_REGISTRATION=false`).
- **Signed-in routes** run behind `auth:sanctum` (session cookie), Jetstream's session guard and `verified` email. A lapsed session on a JSON route answers `401` JSON rather than a redirect.
- **CSRF**: send the token from `<meta name="csrf-token">` as `X-CSRF-TOKEN` on every non-GET request. The public share routes (`/s/*`) and `POST /csp-report` and `POST /api/ecosystem/membership` start no session and need none.
- **Personal access tokens** (Sanctum) exist for the Jetstream API-tokens page and `GET /api/user`; no studio endpoint accepts them as a separate API.

## Conventions

| Status | Meaning |
|---|---|
| 200 / 201 | Done. Writes answer `{status: 'saved' \| 'created' \| 'updated' \| 'deleted' ...}` plus the new state. |
| 401 | The session lapsed (JSON routes). |
| 403 | You can see the row but your role may not do this. |
| 404 | The row does not exist **or you may not see it**; the body is the same plain `{message: 'Not found.'}` either way. |
| 409 | Compare-and-swap conflict on a page `revision` (`{status: 'conflict', revision}`), or a state change that is not legal (a decided review). |
| 413 | Body over the limit (`{status: 'too_large'}`); also what the server answers when `post_max_size` is exceeded. |
| 422 | Invalid: `{status: 'invalid', message, errors: {field: [...]}}`. |
| 429 | Throttled: `{status: 'throttled'}`, or `{status: 'limit', scope, ...}` for the assistant's daily caps. |

Limits you will hit: a page save is at most 1.4 MB on the wire and 1 MB of normalised JSON, 300 elements, groups three deep; an upload is 8 MB, JPG/PNG/WebP only, 16 to 8000 px, 40 megapixels, 200 MB per person. Throttles per person per minute: saves 120, shares 20, comments 30, thumbnails 30, exports 60, uploads 20; public share views 120 and guest comments 6 per link and visitor. All are in `config/design.php` and `config/design_ai.php`.

## Page saves are compare-and-swap

`PUT /design/projects/{project}/pages/{page}` carries the `revision` the editor loaded. A stale revision is answered `409` with the latest revision and nothing is written; "Saved" is shown only after a `2xx`. The same rule protects restores, brand applies and assistant applies (each takes a version snapshot first).

## Public share links

`GET /s/{token}` renders a read-only viewer (comment links also take guest comments at `POST /s/{token}/comments`). The token is 40 random characters, stored only as a SHA-256 hash, shown once at creation. Unknown, malformed, expired and revoked tokens render the identical `404` page. These routes run without a session (no cookie is ever set) and answer `X-Robots-Tag: noindex`, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`. A picture in a shared design is a 10-minute signed URL.

## Integrations

`POST /api/ecosystem/membership` is InfoDot's membership webhook (HMAC `X-InfoDot-Signature` over `{timestamp}.{raw body}` with `INFODOT_SERVICE_SECRET`, five-minute window). It answers `404` unless `DESIGN_INTEGRATION_MEMBERSHIP=true`. Outgoing calls (Dot.Notify, Dot.Files) are documented in `docs/R45_INTEGRATIONS.md`; they are queued or user-triggered and never block a page.

## Routes

<!-- BEGIN GENERATED: routes -->
162 routes, generated from `php artisan route:list --json` by `php scripts/ops/generate-docs.php`. Framework routes (Livewire, Sanctum, two-factor, the signed local-disk route) are left out. "signed in" means the session guard plus a verified email; every JSON route answers JSON errors (`App\Http\Middleware\JsonApi`).

#### Projects, pages, history, sharing, review

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| GET | `/design/projects` | `design.projects.index` | signed in |  | `DesignProjectController@index` |
| POST | `/design/projects` | `design.projects.store` | signed in |  | `DesignProjectController@store` |
| GET | `/design/projects/create` | `design.projects.create` | signed in |  | `DesignProjectController@create` |
| POST | `/design/projects/from-brief` | `design.projects.from-brief` | signed in | `throttle:design-briefs` | `Studio\BriefController@store` |
| DELETE | `/design/projects/{project}` | `design.projects.destroy` | signed in |  | `DesignProjectController@destroy` |
| GET | `/design/projects/{project}` | `design.projects.show` | signed in |  | `Studio\EditorController@show` |
| PUT | `/design/projects/{project}` | `design.projects.update` | signed in |  | `Studio\ProjectController@update` |
| GET | `/design/projects/{project}/activity` | `design.projects.activity` | signed in |  | `Studio\ActivityController@project` |
| POST | `/design/projects/{project}/ai-generations` | `design.projects.ai-generations.store` | signed in |  | `AiGenerationController@store` |
| GET | `/design/projects/{project}/assets/{asset}/file` | `design.projects.assets.file` | signed in |  | `Studio\ProjectAssetController@file` |
| POST | `/design/projects/{project}/assistant/improve` | `design.assistant.improve` | signed in | `throttle:design-ai` | `Studio\AiProposalController@improve` |
| POST | `/design/projects/{project}/assistant/proposals` | `design.assistant.proposals.store` | signed in | `throttle:design-briefs` | `Studio\AiProposalController@store` |
| GET | `/design/projects/{project}/brand` | `design.projects.brand.show` | signed in |  | `Studio\BrandProjectController@show` |
| PUT | `/design/projects/{project}/brand-kit` | `design.projects.brand.link` | signed in |  | `Studio\BrandProjectController@link` |
| POST | `/design/projects/{project}/brand/apply` | `design.projects.brand.apply` | signed in |  | `Studio\BrandProjectController@apply` |
| POST | `/design/projects/{project}/brand/dismiss` | `design.projects.brand.dismiss` | signed in |  | `Studio\BrandProjectController@dismiss` |
| POST | `/design/projects/{project}/canvases` | `design.projects.canvases.store` | signed in |  | `DesignCanvasController@store` |
| PUT | `/design/projects/{project}/canvases/{canvas}` | `design.projects.canvases.update` | signed in |  | `DesignCanvasController@update` |
| GET | `/design/projects/{project}/canvases/{canvas}/export.png` | `design.projects.canvases.export.png` | signed in |  | `DesignCanvasExportController@png` |
| GET | `/design/projects/{project}/canvases/{canvas}/export.svg` | `design.projects.canvases.export.svg` | signed in |  | `DesignCanvasExportController@svg` |
| GET | `/design/projects/{project}/comments` | `design.projects.comments.index` | signed in |  | `Studio\CommentController@index` |
| POST | `/design/projects/{project}/comments` | `design.projects.comments.store` | signed in | `throttle:design-comments` | `Studio\CommentController@store` |
| DELETE | `/design/projects/{project}/comments/{comment}` | `design.projects.comments.destroy` | signed in | `throttle:design-comments` | `Studio\CommentController@destroy` |
| PUT | `/design/projects/{project}/comments/{comment}` | `design.projects.comments.update` | signed in | `throttle:design-comments` | `Studio\CommentController@update` |
| DELETE | `/design/projects/{project}/comments/{comment}/resolve` | `design.projects.comments.unresolve` | signed in | `throttle:design-comments` | `Studio\CommentController@unresolve` |
| POST | `/design/projects/{project}/comments/{comment}/resolve` | `design.projects.comments.resolve` | signed in | `throttle:design-comments` | `Studio\CommentController@resolve` |
| POST | `/design/projects/{project}/copies` | `design.projects.copies.store` | signed in | `throttle:30,1` | `Studio\DesignCopyController@store` |
| GET | `/design/projects/{project}/document` | `design.projects.document` | signed in |  | `Studio\DocumentController@show` |
| POST | `/design/projects/{project}/dot-files` | `design.integrations.files.store` | signed in | `throttle:20,1` | `Integrations\DotFilesController@store` |
| POST | `/design/projects/{project}/exports` | `design.projects.exports.store` | signed in | `throttle:design-exports` | `Studio\ExportAuditController@store` |
| GET | `/design/projects/{project}/grants` | `design.projects.grants.index` | signed in |  | `Studio\GrantController@index` |
| POST | `/design/projects/{project}/grants` | `design.projects.grants.store` | signed in | `throttle:design-shares` | `Studio\GrantController@store` |
| DELETE | `/design/projects/{project}/grants/{grant}` | `design.projects.grants.destroy` | signed in | `throttle:design-shares` | `Studio\GrantController@destroy` |
| POST | `/design/projects/{project}/pages` | `design.projects.pages.store` | signed in | `throttle:design-saves` | `Studio\PageController@store` |
| PUT | `/design/projects/{project}/pages/order` | `design.projects.pages.order` | signed in | `throttle:design-saves` | `Studio\PageController@order` |
| DELETE | `/design/projects/{project}/pages/{page}` | `design.projects.pages.destroy` | signed in | `throttle:design-saves` | `Studio\PageController@destroy` |
| PUT | `/design/projects/{project}/pages/{page}` | `design.projects.pages.update` | signed in | `throttle:design-saves` | `Studio\PageController@update` |
| POST | `/design/projects/{project}/pages/{page}/duplicate` | `design.projects.pages.duplicate` | signed in | `throttle:design-saves` | `Studio\PageController@duplicate` |
| GET | `/design/projects/{project}/people` | `design.projects.people` | signed in |  | `Studio\PeopleController@index` |
| GET | `/design/projects/{project}/reviews` | `design.projects.reviews.index` | signed in |  | `Studio\ReviewController@index` |
| POST | `/design/projects/{project}/reviews` | `design.projects.reviews.store` | signed in | `throttle:design-comments` | `Studio\ReviewController@store` |
| POST | `/design/projects/{project}/reviews/{review}/approve` | `design.projects.reviews.approve` | signed in | `throttle:design-comments` | `Studio\ReviewController@approve` |
| POST | `/design/projects/{project}/reviews/{review}/cancel` | `design.projects.reviews.cancel` | signed in | `throttle:design-comments` | `Studio\ReviewController@cancel` |
| POST | `/design/projects/{project}/reviews/{review}/request-changes` | `design.projects.reviews.request-changes` | signed in | `throttle:design-comments` | `Studio\ReviewController@requestChanges` |
| GET | `/design/projects/{project}/shares` | `design.projects.shares.index` | signed in |  | `Studio\ShareController@index` |
| POST | `/design/projects/{project}/shares` | `design.projects.shares.store` | signed in | `throttle:design-shares` | `Studio\ShareController@store` |
| DELETE | `/design/projects/{project}/shares/{share}` | `design.projects.shares.destroy` | signed in | `throttle:design-shares` | `Studio\ShareController@destroy` |
| GET | `/design/projects/{project}/thumbnail` | `design.projects.thumbnail.show` | signed in |  | `Studio\ThumbnailController@show` |
| POST | `/design/projects/{project}/thumbnail` | `design.projects.thumbnail.upload` | signed in | `throttle:design-thumbnails` | `Studio\ThumbnailController@update` |
| PUT | `/design/projects/{project}/thumbnail` | `design.projects.thumbnail.update` | signed in | `throttle:design-thumbnails` | `Studio\ThumbnailController@update` |
| GET | `/design/projects/{project}/versions` | `design.projects.versions.index` | signed in |  | `Studio\VersionController@index` |
| POST | `/design/projects/{project}/versions` | `design.projects.versions.store` | signed in | `throttle:design-saves` | `Studio\VersionController@store` |
| GET | `/design/projects/{project}/versions/{version}` | `design.projects.versions.show` | signed in |  | `Studio\VersionController@show` |
| GET | `/design/projects/{project}/versions/{version}/diff` | `design.projects.versions.diff` | signed in |  | `Studio\VersionController@diff` |
| POST | `/design/projects/{project}/versions/{version}/restore` | `design.projects.versions.restore` | signed in | `throttle:design-saves` | `Studio\VersionController@restore` |

#### Sign-in, account, system

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| GET | `/` | `` | public |  | `Closure` |
| GET | `/api/user` | `` | signed in |  | `Closure` |
| GET | `/auth/ecosystem` | `` | public |  | `Auth\EcosystemAuthController@legacy` |
| POST | `/auth/ecosystem` | `ecosystem.auth` | public | `throttle:10,1` | `Auth\EcosystemAuthController@handle` |
| GET | `/cookies` | `cookies` | public |  | `Closure` |
| POST | `/csp-report` | `csp.report` | public | `throttle:60,1,csp-report` | `CspReportController` |
| PUT | `/current-team` | `current-team.update` | signed in |  | `Laravel\Jetstream\Http\Controllers\CurrentTeamController@update` |
| GET | `/dashboard` | `dashboard` | signed in |  | `Closure` |
| GET | `/forgot-password` | `password.request` | public |  | `Laravel\Fortify\Http\Controllers\PasswordResetLinkController@create` |
| POST | `/forgot-password` | `password.email` | public |  | `Laravel\Fortify\Http\Controllers\PasswordResetLinkController@store` |
| GET | `/login` | `login` | public |  | `Laravel\Fortify\Http\Controllers\AuthenticatedSessionController@create` |
| POST | `/login` | `login.store` | public | `throttle:login` | `Laravel\Fortify\Http\Controllers\AuthenticatedSessionController@store` |
| POST | `/logout` | `logout` | signed in |  | `Laravel\Fortify\Http\Controllers\AuthenticatedSessionController@destroy` |
| GET | `/privacy-policy` | `policy.show` | public |  | `Laravel\Jetstream\Http\Controllers\Livewire\PrivacyPolicyController@show` |
| ANY | `/register` | `register` | public |  | `Illuminate\Routing\RedirectController` |
| POST | `/reset-password` | `password.update` | public |  | `Laravel\Fortify\Http\Controllers\NewPasswordController@store` |
| GET | `/reset-password/{token}` | `password.reset` | public |  | `Laravel\Fortify\Http\Controllers\NewPasswordController@create` |
| GET | `/team-invitations/{invitation}` | `team-invitations.accept` | signed in |  | `Laravel\Jetstream\Http\Controllers\TeamInvitationController@accept` |
| GET | `/teams/create` | `teams.create` | signed in |  | `Laravel\Jetstream\Http\Controllers\Livewire\TeamController@create` |
| GET | `/teams/{team}` | `teams.show` | signed in |  | `Laravel\Jetstream\Http\Controllers\Livewire\TeamController@show` |
| GET | `/terms-of-service` | `terms.show` | public |  | `Laravel\Jetstream\Http\Controllers\Livewire\TermsOfServiceController@show` |
| GET | `/up` | `` | public |  | `Closure` |
| GET | `/user/confirm-password` | `password.confirm` | signed in |  | `Laravel\Fortify\Http\Controllers\ConfirmablePasswordController@show` |
| POST | `/user/confirm-password` | `password.confirm.store` | signed in |  | `Laravel\Fortify\Http\Controllers\ConfirmablePasswordController@store` |
| GET | `/user/confirmed-password-status` | `password.confirmation` | signed in |  | `Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController@show` |
| PUT | `/user/password` | `user-password.update` | signed in |  | `Laravel\Fortify\Http\Controllers\PasswordController@update` |
| GET | `/user/profile` | `profile.show` | signed in |  | `Laravel\Jetstream\Http\Controllers\Livewire\UserProfileController@show` |

#### Create flow: formats, brief, templates

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| GET | `/design/brief/flow` | `design.brief.flow` | signed in |  | `Studio\BriefController@flow` |
| POST | `/design/brief/recommend` | `design.brief.recommend` | signed in | `throttle:design-briefs` | `Studio\BriefController@recommend` |
| GET | `/design/formats` | `design.formats.index` | signed in |  | `Studio\FormatsController@index` |
| GET | `/design/new` | `design.start` | signed in |  | `Studio\StartController@index` |
| GET | `/design/new/brief` | `design.start.brief` | signed in |  | `Studio\StartController@brief` |
| GET | `/design/templates` | `design.templates.index` | signed in |  | `Studio\TemplateBrowserController@index` |
| POST | `/design/templates` | `design.templates.store` | signed in | `throttle:design-briefs` | `Studio\TemplateController@store` |
| GET | `/design/templates/facets` | `design.templates.facets` | signed in |  | `Studio\TemplateController@facets` |
| DELETE | `/design/templates/{template}` | `design.templates.destroy` | signed in |  | `Studio\TemplateController@destroy` |
| GET | `/design/templates/{template}` | `design.templates.show` | signed in |  | `Studio\TemplateBrowserController@show` |
| PUT | `/design/templates/{template}` | `design.templates.update` | signed in |  | `Studio\TemplateController@update` |
| POST | `/design/templates/{template}/fill` | `design.templates.fill` | signed in | `throttle:design-briefs` | `Studio\TemplateController@fill` |
| POST | `/design/templates/{template}/publish` | `design.templates.publish` | operator |  | `Studio\TemplateController@publish` |
| POST | `/design/templates/{template}/retire` | `design.templates.retire` | operator |  | `Studio\TemplateController@retire` |

#### Brand

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| GET | `/design/brand` | `design.brand.index` | signed in |  | `Studio\BrandStudioController@index` |
| GET | `/design/brand/kits` | `design.brand.kits.index` | signed in |  | `Studio\BrandKitController@index` |
| POST | `/design/brand/kits` | `design.brand.kits.store` | signed in |  | `Studio\BrandKitController@store` |
| DELETE | `/design/brand/kits/{kit}` | `design.brand.kits.destroy` | signed in |  | `Studio\BrandKitController@destroy` |
| GET | `/design/brand/kits/{kit}` | `design.brand.kits.show` | signed in |  | `Studio\BrandKitController@show` |
| PUT | `/design/brand/kits/{kit}` | `design.brand.kits.update` | signed in |  | `Studio\BrandKitController@update` |
| POST | `/design/brand/kits/{kit}/assets` | `design.brand.kits.assets.store` | signed in |  | `Studio\BrandKitController@attachAsset` |
| DELETE | `/design/brand/kits/{kit}/assets/{brandAsset}` | `design.brand.kits.assets.destroy` | signed in |  | `Studio\BrandKitController@detachAsset` |
| POST | `/design/brand/kits/{kit}/default` | `design.brand.kits.default` | signed in |  | `Studio\BrandKitController@makeDefault` |
| GET | `/design/brand/new` | `design.brand.create` | signed in |  | `Studio\BrandStudioController@create` |
| GET | `/design/brand/notices` | `design.brand.notices` | signed in |  | `Studio\BrandProjectController@notices` |
| POST | `/design/brand/palette` | `design.brand.palette` | signed in | `throttle:design-briefs` | `Studio\BrandPaletteController` |
| GET | `/design/brand/updates` | `design.brand.updates` | signed in |  | `Studio\BrandStudioController@updates` |
| GET | `/design/brand/updates/{project}` | `design.brand.updates.show` | signed in |  | `Studio\BrandStudioController@updatesShow` |
| GET | `/design/brand/{kit}/edit` | `design.brand.edit` | signed in |  | `Studio\BrandStudioController@edit` |

#### Assets

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| POST | `/design/asset-bulk` | `design.assets.bulk` | signed in | `throttle:60,1` | `Studio\AssetStudioController@bulk` |
| DELETE | `/design/asset-folders` | `design.assets.folders.destroy` | signed in | `throttle:60,1` | `Studio\AssetStudioController@deleteFolder` |
| POST | `/design/asset-folders` | `design.assets.folders.store` | signed in | `throttle:60,1` | `Studio\AssetStudioController@createFolder` |
| PUT | `/design/asset-folders` | `design.assets.folders.rename` | signed in | `throttle:60,1` | `Studio\AssetStudioController@renameFolder` |
| DELETE | `/design/asset-tags` | `design.assets.tags.destroy` | signed in | `throttle:60,1` | `Studio\AssetStudioController@deleteTag` |
| PUT | `/design/asset-tags` | `design.assets.tags.rename` | signed in | `throttle:60,1` | `Studio\AssetStudioController@renameTag` |
| GET | `/design/assets` | `design.assets.index` | signed in |  | `Studio\AssetLibraryController@index` |
| POST | `/design/assets` | `design.assets.store` | signed in | `throttle:design-uploads` | `DesignAssetController@store` |
| GET | `/design/assets/folders` | `design.assets.folders` | signed in |  | `Studio\AssetLibraryController@foldersJson` |
| GET | `/design/assets/library` | `design.assets.library` | signed in |  | `Studio\AssetStudioController@library` |
| GET | `/design/assets/meta` | `design.assets.meta` | signed in |  | `Studio\AssetStudioController@meta` |
| DELETE | `/design/assets/{asset}` | `design.assets.destroy` | signed in |  | `DesignAssetController@destroy` |
| PUT | `/design/assets/{asset}` | `design.assets.update` | signed in |  | `Studio\AssetLibraryController@update` |
| GET | `/design/assets/{asset}/file` | `design.assets.file` | signed in |  | `DesignAssetController@file` |
| GET | `/design/assets/{asset}/usage` | `design.assets.usage` | signed in |  | `Studio\AssetStudioController@usage` |

#### Assistant

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| POST | `/design/ai-proposals/{proposal}/apply` | `design.assistant.proposals.apply` | signed in | `throttle:design-briefs` | `Studio\AiProposalController@apply` |
| POST | `/design/ai-proposals/{proposal}/dismiss` | `design.assistant.proposals.dismiss` | signed in | `throttle:design-briefs` | `Studio\AiProposalController@dismiss` |
| POST | `/design/assistant/compose` | `design.assistant.compose` | signed in | `throttle:design-ai` | `Studio\AiAssistantController@compose` |
| POST | `/design/assistant/copy` | `design.assistant.copy` | signed in | `throttle:design-ai` | `Studio\AiAssistantController@copy` |
| GET | `/design/assistant/status` | `design.assistant.status` | signed in |  | `Studio\AiAssistantController@status` |

#### Integrations

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| POST | `/api/ecosystem/membership` | `design.integrations.membership` | public | `throttle:60,1` | `Integrations\MembershipWebhookController` |
| GET | `/design/integrations` | `design.integrations.index` | signed in |  | `Integrations\IntegrationsController@index` |
| GET | `/design/integrations/status` | `design.integrations.status` | signed in |  | `Integrations\IntegrationsController@status` |

#### Settings, activity, review inbox, editor data

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| GET | `/design/activity` | `design.activity.index` | signed in |  | `Studio\ActivityController@workspace` |
| GET | `/design/activity/log` | `design.activity.page` | signed in |  | `Studio\ActivityPageController@index` |
| GET | `/design/review` | `design.reviews.index` | signed in |  | `Studio\ReviewInboxController@index` |
| GET | `/design/reviews` | `design.reviews.inbox` | signed in |  | `Studio\ReviewController@inbox` |
| GET | `/design/settings` | `design.settings.show` | signed in |  | `Studio\SettingsController@show` |
| PUT | `/design/settings` | `design.settings.update` | signed in |  | `Studio\SettingsController@update` |
| GET | `/design/settings/my-data` | `design.settings.my-data` | signed in | `throttle:design-exports` | `Studio\MyDataController@download` |
| GET | `/design/studio-settings` | `design.settings` | signed in |  | `Studio\SettingsPageController@show` |
| GET | `/design/studio/elements` | `design.studio.elements` | signed in |  | `Studio\EditorLibraryController@elements` |

#### Public share links (no session)

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| GET | `/s/{token}` | `studio.share.show` | public | `throttle:design-public-view` | `Studio\PublicShareController@show` |
| GET | `/s/{token}/assets/{asset}` | `studio.share.asset` | public | `throttle:design-public-view` | `Studio\PublicShareController@asset` |
| POST | `/s/{token}/comments` | `studio.share.comment` | public | `throttle:design-public-comments` | `Studio\PublicShareController@comment` |

#### Design-system catalogue (operator writes)

| Method | Path | Route name | Access | Limit | Controller |
|---|---|---|---|---|---|
| GET | `/design-system/components` | `design-system.components.index` | signed in |  | `ComponentController@index` |
| POST | `/design-system/components` | `design-system.components.store` | operator |  | `ComponentController@store` |
| DELETE | `/design-system/components/{component}` | `design-system.components.destroy` | operator |  | `ComponentController@destroy` |
| GET | `/design-system/components/{component}` | `design-system.components.show` | signed in |  | `ComponentController@show` |
| PUT/PATCH | `/design-system/components/{component}` | `design-system.components.update` | operator |  | `ComponentController@update` |
| GET | `/design-system/token-sets` | `design-system.token-sets.index` | signed in |  | `TokenSetController@index` |
| POST | `/design-system/token-sets` | `design-system.token-sets.store` | operator |  | `TokenSetController@store` |
| DELETE | `/design-system/token-sets/{tokenSet}` | `design-system.token-sets.destroy` | operator |  | `TokenSetController@destroy` |
| GET | `/design-system/token-sets/{tokenSet}` | `design-system.token-sets.show` | signed in |  | `TokenSetController@show` |
| PUT/PATCH | `/design-system/token-sets/{tokenSet}` | `design-system.token-sets.update` | operator |  | `TokenSetController@update` |
| POST | `/design-system/token-sets/{tokenSet}/consumers` | `design-system.token-sets.consumers.store` | operator |  | `TokenConsumptionRecordController@store` |
| DELETE | `/design-system/token-sets/{tokenSet}/consumers/{record}` | `design-system.token-sets.consumers.destroy` | operator |  | `TokenConsumptionRecordController@destroy` |
| PUT | `/design-system/token-sets/{tokenSet}/consumers/{record}` | `design-system.token-sets.consumers.update` | operator |  | `TokenConsumptionRecordController@update` |
| POST | `/design-system/token-sets/{tokenSet}/tokens` | `design-system.token-sets.tokens.store` | operator |  | `DesignTokenController@store` |
| DELETE | `/design-system/token-sets/{tokenSet}/tokens/{token}` | `design-system.token-sets.tokens.destroy` | operator |  | `DesignTokenController@destroy` |
| PUT | `/design-system/token-sets/{tokenSet}/tokens/{token}` | `design-system.token-sets.tokens.update` | operator |  | `DesignTokenController@update` |
<!-- END GENERATED: routes -->

## Where the detail is

| Area | Document |
|---|---|
| Document model and the editor boot JSON | `docs/R45_ARCHITECTURE.md`, `resources/design/document-schema.json`, `docs/R45_UI_EDITOR.md` |
| Pages, versions, shares, comments, reviews, activity, capability matrix | `docs/R45_BACKEND.md` |
| Formats, brief flow, recommender, templates, brand kits | `docs/R45_BUSINESS.md` |
| Assistant endpoints and safety rules | `docs/R45_AI.md` |
| Export, copies, animation | `docs/R45_OUTPUT.md` |
| Integrations, owner steps | `docs/R45_INTEGRATIONS.md` |
| Security review | `docs/R45_SECURITY.md` when present |

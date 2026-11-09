# Dot.Design database

The schema as it exists after the R-45 rebuild. The table listing below is **generated** from the migrations (run on a throw-away SQLite database) by `php scripts/ops/generate-docs.php`, and `tests/Feature/DocsInSyncTest.php` fails when it drifts. Production runs PostgreSQL; the test suite runs SQLite in memory; every migration is written to run on both.

## How the data is organised

- **Workspace = Jetstream team.** Every project, asset, brand kit and share carries `team_id` and `user_id`. Rows created before R-45 were backfilled with the owner's personal team (users who had no personal team keep `team_id` null and stay visible to their owner only). Visibility is a global scope (`HasWorkspaceScope`): a row you may not see does not exist (404), and policies re-check what you may do.
- **A design is a project plus pages.** `design_projects` holds the format, status, visibility, brief and brand link; each page is a `design_canvases` row whose `elements` JSON follows `resources/design/document-schema.json`. Version 1 rows (text, rectangle, image) upgrade on read and are only rewritten by a real save. `revision` is the compare-and-swap counter.
- **History and audit.** `design_versions` holds page snapshots (50 automatic kept per project; manual, review, approved and restore snapshots are never pruned). `design_activity` is append-only with no foreign keys on purpose, so deleting a project, user or workspace never erases the record.
- **Files are on the private disk.** `design_assets` rows point at files under `storage/app/private/design-assets` (the `design_assets` disk), which also holds project thumbnails. Nothing in the database or on the disk is reachable by URL; pictures are served through policy-checked routes.
- **Reference data** is global: `design_templates` (the curated library is seeded from `database/templates` by `php artisan design:seed-templates`), and the token / component catalogue (`token_sets`, `design_tokens`, `components`, `token_consumption_records`, `token_drift_notices`) written only by platform operators.

## Backfills and safety of the R-45 migrations

All R-45 migrations are additive: new tables, and new nullable or defaulted columns on `design_projects`, `design_canvases` and `design_assets`. Two of them backfill data, and both are set-based per personal team and a no-op on an empty table:

- `2026_11_09_100001_add_workspace_columns_to_design_projects_table` fills `design_projects.team_id` from the owner's personal team (`WHERE team_id IS NULL`, so a second run changes nothing) and leaves `visibility = private`.
- `2026_11_09_100003_add_workspace_columns_to_design_assets_table` does the same for `design_assets` and sets `license = own`.
- `2026_11_10_100200_add_brief_and_brand_columns_to_design_projects` guards each column with `hasColumn`, so it is a no-op wherever another migration already added them.

Checked 2026-11-09 on PostgreSQL 16 with 2,000 projects, 2,666 pages, 500 assets and 50 users (5 without a personal team): the 16 R-45 migrations ran in about 0.1 s, 1,800 projects and 450 assets were backfilled, the 200 projects and 50 assets of users without a personal team stayed `NULL`, a second `migrate` was "Nothing to migrate", and `migrate:rollback --step=16` followed by `migrate` restored the same state. PostgreSQL runs each migration in a transaction, so a failure rolls that migration back. **`down()` methods drop the new columns and tables, so never roll back in production**: restore the pre-deploy `pg_dump` instead (`docs/R45_OPERATIONS.md`).

## Tables

<!-- BEGIN GENERATED: schema -->
28 tables, read from the migrations (run on a throw-away SQLite database) by `php scripts/ops/generate-docs.php`. A trailing `?` means nullable. Framework tables (cache, sessions, jobs, failed_jobs, password resets, personal access tokens, migrations) are left out. Types are SQLite's names, normalised: `text` also stands for every `json` column (PostgreSQL keeps `json`), `integer` is `bigint`/`integer`/`smallint` on PostgreSQL, `boolean` and `timestamp` are the same on both.

- **`ai_generation_logs`**: id integer, user_id integer, design_project_id integer?, prompt text, result_url varchar?, provider varchar, tokens_used integer?, created_at timestamp?, updated_at timestamp?
  - foreign keys: design_project_id -> design_projects.id on delete set null; user_id -> users.id on delete cascade
- **`brand_assets`**: id integer, brand_kit_id integer, design_asset_id integer, role varchar, label varchar?, created_at timestamp?, updated_at timestamp?
  - indexes: unique (brand_kit_id, design_asset_id, role)
  - foreign keys: brand_kit_id -> brand_kits.id on delete cascade; design_asset_id -> design_assets.id on delete cascade
- **`brand_kits`**: id integer, team_id integer?, user_id integer, name varchar, is_default boolean, version integer, colors text?, fonts text?, type_styles text?, guidelines text?, voice text?, tagline varchar?, website varchar?, contacts text?, created_at timestamp?, updated_at timestamp?
  - indexes: (team_id, is_default); (user_id, is_default)
  - foreign keys: team_id -> teams.id on delete cascade; user_id -> users.id on delete cascade
- **`components`**: id integer, name varchar, slug varchar, category varchar, is_brain_surface boolean, version integer, description text?, props_schema text?, created_at timestamp?, updated_at timestamp?
  - indexes: unique (slug)
- **`design_activity`**: id integer, team_id integer?, project_id integer?, user_id integer?, action varchar, subject_type varchar, subject_id integer?, meta text?, created_at timestamp
  - indexes: (action); (project_id, created_at); (team_id, created_at); (user_id)
- **`design_ai_proposals`**: id integer, design_project_id integer?, team_id integer?, user_id integer, mode varchar, status varchar, prompt text?, payload text?, model varchar?, tokens_in integer, tokens_out integer, error varchar?, created_at timestamp?, updated_at timestamp?
  - indexes: (design_project_id); (status, created_at); (team_id, created_at); (user_id, created_at)
  - foreign keys: design_project_id -> design_projects.id on delete cascade; user_id -> users.id on delete cascade
- **`design_asset_folders`**: id integer, user_id integer, name varchar, created_at timestamp?, updated_at timestamp?
  - indexes: unique (user_id, name)
  - foreign keys: user_id -> users.id on delete cascade
- **`design_assets`**: id integer, user_id integer, name varchar, type varchar, file_path varchar, mime_type varchar?, file_size integer?, meta text?, created_at timestamp?, updated_at timestamp?, design_project_id integer?, disk varchar?, content_hash varchar?, width integer?, height integer?, team_id integer?, folder varchar?, tags text?, license varchar, source varchar?
  - indexes: (team_id, license); (user_id, design_project_id); (user_id, folder); unique (user_id, content_hash)
  - foreign keys: design_project_id -> design_projects.id on delete set null; team_id -> teams.id on delete set null; user_id -> users.id on delete cascade
- **`design_brand_updates`**: id integer, brand_kit_id integer, design_project_id integer, from_version integer, to_version integer, summary text?, status varchar, resolved_at timestamp?, resolved_by integer?, created_at timestamp?, updated_at timestamp?
  - indexes: (brand_kit_id, status); (design_project_id, status)
  - foreign keys: brand_kit_id -> brand_kits.id on delete cascade; design_project_id -> design_projects.id on delete cascade; resolved_by -> users.id on delete set null
- **`design_canvases`**: id integer, design_project_id integer, page_number integer, elements text?, background_color varchar, background_image varchar?, created_at timestamp?, updated_at timestamp?, revision integer, name varchar?, width integer?, height integer?, background text?, duration_ms integer, transition varchar, guides text?
  - indexes: unique (design_project_id, page_number)
  - foreign keys: design_project_id -> design_projects.id on delete cascade
- **`design_comments`**: id integer, design_project_id integer, page_id integer?, element_id varchar?, x double?, y double?, user_id integer?, guest_name varchar?, share_id integer?, body text, mentions text?, parent_id integer?, resolved_at timestamp?, resolved_by integer?, created_at timestamp?, updated_at timestamp?
  - indexes: (design_project_id, page_id, created_at); (design_project_id, parent_id); (page_id); (parent_id); (resolved_by); (share_id); (user_id)
  - foreign keys: design_project_id -> design_projects.id on delete cascade; page_id -> design_canvases.id on delete set null; parent_id -> design_comments.id on delete cascade; resolved_by -> users.id on delete set null; share_id -> design_shares.id on delete set null; user_id -> users.id on delete set null
- **`design_exports`**: id integer, design_project_id integer, user_id integer?, format varchar, pages varchar, scale numeric, bytes integer?, created_at timestamp
  - indexes: (created_at); (design_project_id, created_at); (user_id)
  - foreign keys: design_project_id -> design_projects.id on delete cascade; user_id -> users.id on delete set null
- **`design_file_links`**: id integer, design_project_id integer, team_id integer, user_id integer?, scope varchar, format varchar, files_uuid varchar, version integer?, open_url varchar?, filed_at timestamp, created_at timestamp?, updated_at timestamp?
  - indexes: unique (design_project_id, team_id, scope, format)
  - foreign keys: design_project_id -> design_projects.id on delete cascade; team_id -> teams.id on delete cascade; user_id -> users.id on delete set null
- **`design_project_grants`**: id integer, design_project_id integer, user_id integer, level varchar, granted_by integer?, created_at timestamp?, updated_at timestamp?
  - indexes: (granted_by); (user_id); unique (design_project_id, user_id)
  - foreign keys: design_project_id -> design_projects.id on delete cascade; granted_by -> users.id on delete set null; user_id -> users.id on delete cascade
- **`design_projects`**: id integer, user_id integer, name varchar, type varchar, width integer, height integer, unit varchar, created_at timestamp?, updated_at timestamp?, team_id integer?, visibility varchar, status varchar, thumbnail_path varchar?, thumbnail_updated_at timestamp?, format_key varchar?, template_id integer?, brief text?, brand_kit_id integer?, brand_version integer?
  - indexes: (brand_kit_id); (status); (team_id, visibility, updated_at); (user_id, updated_at)
  - foreign keys: team_id -> teams.id on delete set null; user_id -> users.id on delete cascade
- **`design_reviews`**: id integer, design_project_id integer, version_id integer?, requested_by integer?, reviewer_id integer?, status varchar, note text?, decision_note text?, decided_at timestamp?, created_at timestamp?, updated_at timestamp?
  - indexes: (design_project_id, status); (requested_by, status); (reviewer_id, status); (version_id)
  - foreign keys: design_project_id -> design_projects.id on delete cascade; requested_by -> users.id on delete set null; reviewer_id -> users.id on delete set null; version_id -> design_versions.id on delete set null
- **`design_shares`**: id integer, design_project_id integer, created_by integer?, token_hash varchar, permission varchar, expires_at timestamp?, revoked_at timestamp?, download_allowed boolean, last_viewed_at timestamp?, view_count integer, created_at timestamp?, updated_at timestamp?
  - indexes: (created_by); (design_project_id, revoked_at); unique (token_hash)
  - foreign keys: created_by -> users.id on delete set null; design_project_id -> design_projects.id on delete cascade
- **`design_templates`**: id integer, slug varchar, name varchar, description text?, source varchar, team_id integer?, user_id integer?, status varchar, category varchar, kind varchar, industries text?, purposes text?, styles text?, platforms text?, objectives text?, format_key varchar?, width integer, height integer, pages text, slots text?, page_count integer, popularity integer, uses integer, version integer, content_hash varchar?, published_at timestamp?, created_at timestamp?, updated_at timestamp?
  - indexes: (category); (format_key); (kind); (status, source); (team_id, source); unique (slug)
  - foreign keys: team_id -> teams.id on delete cascade; user_id -> users.id on delete set null
- **`design_tokens`**: id integer, token_set_id integer, name varchar, slug varchar, category varchar, value varchar, version integer, description text?, created_at timestamp?, updated_at timestamp?
  - indexes: unique (token_set_id, slug)
  - foreign keys: token_set_id -> token_sets.id on delete cascade
- **`design_user_settings`**: id integer, user_id integer, mode varchar, snap boolean, grid boolean, rulers boolean, reduced_motion_pref varchar, last_format_key varchar?, onboarded_at timestamp?, created_at timestamp?, updated_at timestamp?
  - indexes: unique (user_id)
  - foreign keys: user_id -> users.id on delete cascade
- **`design_versions`**: id integer, design_project_id integer, user_id integer?, kind varchar, label varchar?, pages text, bytes integer, created_at timestamp
  - indexes: (design_project_id, created_at); (design_project_id, kind, created_at); (user_id)
  - foreign keys: design_project_id -> design_projects.id on delete cascade; user_id -> users.id on delete set null
- **`team_invitations`**: id integer, team_id integer, email varchar, role varchar?, created_at timestamp?, updated_at timestamp?
  - indexes: unique (team_id, email)
  - foreign keys: team_id -> teams.id on delete cascade
- **`team_user`**: id integer, team_id integer, user_id integer, role varchar?, created_at timestamp?, updated_at timestamp?
  - indexes: unique (team_id, user_id)
- **`teams`**: id integer, user_id integer, name varchar, personal_team boolean, created_at timestamp?, updated_at timestamp?, infodot_team_uuid varchar?
  - indexes: (user_id); unique (infodot_team_uuid)
- **`token_consumption_records`**: id integer, token_set_id integer, platform_id varchar, pinned_version integer, last_synced_at timestamp?, created_at timestamp?, updated_at timestamp?
  - indexes: unique (token_set_id, platform_id)
  - foreign keys: token_set_id -> token_sets.id on delete cascade
- **`token_drift_notices`**: id integer, token_set_id integer, platform_id varchar, pinned_version integer, current_version integer, detected_at timestamp, cleared_at timestamp?, created_at timestamp?, updated_at timestamp?
  - indexes: (token_set_id, platform_id)
  - foreign keys: token_set_id -> token_sets.id on delete cascade
- **`token_sets`**: id integer, name varchar, slug varchar, description text?, version integer, created_at timestamp?, updated_at timestamp?
  - indexes: unique (slug)
- **`users`**: id integer, name varchar, email varchar, email_verified_at timestamp?, password varchar, remember_token varchar?, current_team_id integer?, profile_photo_path varchar?, created_at timestamp?, updated_at timestamp?, two_factor_secret text?, two_factor_recovery_codes text?, two_factor_confirmed_at timestamp?, is_platform_operator boolean
  - indexes: unique (email)

#### Migration files (32)

- `0001_01_01_000000_create_users_table`
- `0001_01_01_000001_create_cache_table`
- `0001_01_01_000002_create_jobs_table`
- `2026_06_27_000001_create_design_tables`
- `2026_06_27_153541_create_personal_access_tokens_table`
- `2026_06_27_154117_add_two_factor_columns_to_users_table`
- `2026_06_27_154305_create_teams_table`
- `2026_06_27_154306_create_team_user_table`
- `2026_06_27_154307_create_team_invitations_table`
- `2026_08_01_000001_create_design_system_tables`
- `2026_08_09_000001_create_token_drift_notices_table`
- `2026_10_18_000001_add_is_platform_operator_to_users_table`
- `2026_10_18_000002_add_unique_page_number_to_design_canvases`
- `2026_10_18_100000_add_infodot_team_uuid_to_teams_table`
- `2026_10_18_200000_add_upload_columns_to_design_assets_table`
- `2026_10_18_300000_add_revision_to_design_canvases_table`
- `2026_11_09_100001_add_workspace_columns_to_design_projects_table`
- `2026_11_09_100002_add_studio_columns_to_design_canvases_table`
- `2026_11_09_100003_add_workspace_columns_to_design_assets_table`
- `2026_11_09_100004_create_design_versions_table`
- `2026_11_09_100005_create_design_shares_table`
- `2026_11_09_100006_create_design_comments_table`
- `2026_11_09_100007_create_design_reviews_table`
- `2026_11_09_100008_create_design_activity_table`
- `2026_11_09_100009_create_design_user_settings_table`
- `2026_11_09_100010_create_design_exports_and_grants_tables`
- `2026_11_09_600000_create_design_file_links_table`
- `2026_11_10_100000_create_brand_studio_tables`
- `2026_11_10_100100_create_design_templates_table`
- `2026_11_10_100200_add_brief_and_brand_columns_to_design_projects`
- `2026_11_10_300000_create_design_ai_proposals_table`
- `2026_11_11_900000_create_design_asset_folders_table`
<!-- END GENERATED: schema -->

| endpoint | status | queries | db ms | wall ms | KB | most repeated query (x) |
|---|---|---|---|---|---|---|
| GET /dashboard | 200 | 15 | 29 | 54.7 | 43.7 |  |
| GET /design/projects (designs list) | 200 | 6 | 7.9 | 32.2 | 119.6 |  |
| GET /design/new (start) | 200 | 2 | 1.4 | 8.6 | 103.5 |  |
| GET /design/templates (page) | 200 | 16 | 6.5 | 13.7 | 43.8 | 12x select "brief" from "design_projects" where "id" = ? limit N @ ProjectMeta.php:80 < ProjectMeta.php:20 < StartScreen.php:89 |
| GET /design/templates json 24 + first_page | 200 | 3 | 3.4 | 11.6 | 145.5 |  |
| GET /design/templates json q=sale | 200 | 2 | 2.1 | 4.7 | 40.1 |  |
| GET /design/templates/facets | 200 | 2 | 1.1 | 2 | 2.9 |  |
| GET /design/templates/{id} | 200 | 1 | 0.6 | 1.6 | 6.3 |  |
| GET /design/formats | 200 | 0 | 0 | 1.1 | 51.5 |  |
| GET /design/brand (page) | 200 | 3 | 1.1 | 6.7 | 37.3 |  |
| GET /design/brand/kits | 200 | 1 | 0.3 | 1.4 | 0.7 |  |
| GET /design/assets (page) | 200 | 4 | 9 | 17.1 | 35.8 |  |
| GET /design/assets/library | 200 | 16 | 70.9 | 117.7 | 14.2 | 6x select "id", "design_project_id", "page_number", "elements", "background" from "design_canvases" where exists  @ AssetUsageIndex.php:32 < AssetUsageIndex.php:51 < AssetStudioController.php:354 |
| GET /design/assets/library?q=photo&sort=name | 200 | 16 | 52.9 | 97.5 | 14.2 | 6x select "id", "design_project_id", "page_number", "elements", "background" from "design_canvases" where exists  @ AssetUsageIndex.php:32 < AssetUsageIndex.php:51 < AssetStudioController.php:354 |
| GET /design/assets/meta | 200 | 6 | 9.5 | 13.1 | 0.3 |  |
| GET /design/assets/folders | 200 | 1 | 1.8 | 2.7 | 0.1 |  |
| GET /design/assets/{id}/usage | 200 | 21 | 118.1 | 261.8 | 0.2 | 7x select "id", "design_project_id", "page_number", "elements", "background" from "design_canvases" where exists  @ AssetUsageIndex.php:32 < AssetUsageIndex.php:46 < AssetStudioController.php:81 |
| GET /design/activity | 200 | 5 | 2.7 | 5.7 | 10.3 |  |
| GET /design/activity/log (page) | 200 | 9 | 4.4 | 14.2 | 58 |  |
| GET /design/review (inbox page) | 200 | 4 | 1.5 | 7.6 | 29.7 |  |
| GET /design/reviews | 200 | 1 | 0.2 | 0.9 | 0 |  |
| GET /design/settings | 200 | 2 | 0.8 | 1.8 | 0.2 |  |
| GET /design/studio-settings (page) | 200 | 2 | 0.6 | 4.9 | 32.1 |  |
| GET /design/studio/elements | 200 | 0 | 0 | 1.5 | 0 |  |
| GET /design/projects/{id} (editor boot, 21 pages) | 200 | 24 | 19.3 | 41.4 | 826.8 | 4x select a.attname as name, t.typname as type_name, format_type(a.atttypid, a.atttypmod) as type, (select tc.col @ Columns.php:25 < ProjectMeta.php:76 < ProjectMeta.php:48 |
| GET .../document | 200 | 13 | 10.9 | 24.4 | 733.3 |  |
| GET .../comments | 200 | 9 | 3.7 | 5.7 | 0 |  |
| GET .../shares | 200 | 5 | 2.6 | 4.3 | 0 |  |
| GET .../reviews | 200 | 5 | 1.4 | 2.6 | 0 |  |
| GET .../activity | 200 | 6 | 2.2 | 3.9 | 2.7 |  |
| GET .../grants | 200 | 5 | 1.8 | 3.2 | 0 |  |
| GET .../people | 200 | 25 | 6.4 | 8.9 | 1 | 7x select "teams"."user_id" as "owner_id", "team_user"."id" as "member_row", "team_user"."role" as "role" from "t @ DesignAccess.php:34 < DesignAccess.php:118 < DesignAccess.php:166 |
| GET /design/brand/notices | 200 | 1 | 1.2 | 2.3 | 0 |  |
| GET .../versions (200 versions) | 200 | 6 | 2.4 | 4.4 | 4.1 |  |
| GET .../versions/{id} | 200 | 7 | 2.8 | 4.9 | 54.6 |  |
| GET .../versions/{id}/diff | 200 | 7 | 3.4 | 5.9 | 0.6 |  |
| PUT page save: 300 elements (36 KB) | 200 | 12 | 14 | 25.3 | 0.1 |  |
| PUT page save again (CAS ok, 2nd): 300 elements | 200 | 12 | 9.1 | 16.4 | 0.1 |  |
| PUT page save: ~1 MB payload (476 KB) | 200 | 12 | 17.8 | 27.4 | 0.1 |  |
| PUT page save again (CAS ok, 2nd): ~1 MB payload | 200 | 12 | 17.2 | 27.1 | 0.1 |  |

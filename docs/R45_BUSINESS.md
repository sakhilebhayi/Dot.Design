# R-45 business layer: formats, guided brief, templates, Brand Studio

Owner: the business agent. Branch `feat/r45-business`. Everything here is JSON (no pages): the create-flow UI (wave 2) renders from these endpoints. All routes are in `routes/studio/business.php`, behind the auth group, and answer JSON errors when the caller sends `Accept: application/json` (401 guest, 403 forbidden, 404 not visible, 422 validation as `{message, errors: {field: [msg]}}`). Rows a user cannot see are 404, never 403.

## 1. Format catalog

`config/design_formats.php` read through `App\Services\Design\Formats`. 42 formats in four groups (social, print, digital, documents).

`GET /design/formats` (`design.formats.index`) returns `{groups: [{key,label,help,formats:[...]}], formats: [...], popular: [keys]}`. Each format:

```
key, label, use, group, kind (digital|print), width, height, unit (px|mm|in), size_label ("210 x 297 mm"),
px {width,height}            canvas size at 72 dpi (what the project and elements use)
print_px {width,height}|null the size at dpi_print (print only)
dpi_print (300)|null, bleed_mm, bleed_px, safe {top,right,bottom,left,unit (mm for print, px for digital)}, safe_px,
family (aspect family, used by resize), platform, orientation, type (design_projects.type), popular, notes (plain language)
```

Conversion: `px = mm / 25.4 * 72` (A4 = 595 x 842); export multiplies by `dpi_print / 72` (A4 = 2480 x 3508). `App\Services\Design\Units` exposes `toMm / toPx / mmToPx / pxToMm`; `Formats::pixels($key, $dpi)`, `familyOf`, `matchingKey(w, h)`.

Template authors: a template's `width`/`height` must equal the format's `px` size (the checker enforces it).

## 2. Guided brief

Config: `config/design_brief_flow.php` (steps, goals, fields, styles, industries) and `config/design_copy.php` (slot labels, template kinds/categories/purposes/objectives, reason phrases, brand role labels). The UI never invents text.

Steps (`essential` cannot be skipped): `goal`* , `what`, `business_name`*, `details`, `logo`, `style`, `where`. Goals: advertise_business, announce_sale, promote_product, announce_event, social_media, menu_price_list, stationery, document_report, something_else. Styles: bold, clean, elegant, playful, corporate, dark, earthy. Every goal lists its relevant detail `fields` and its usual `formats`.

`GET /design/brief/flow` -> `{steps, goals, styles, fields, limits}`.
`GET /design/brief/flow?goal=announce_sale` -> `{goal, steps (with details.fields and where.options filled for that goal), styles}`.

### The brief object
```
{ goal, what, business_name,
  details: { headline, subheadline, tagline, offer, price, date, time, venue, address, phone, whatsapp, email, website, social_handle, cta, description },
  logo_asset_id, photos: [asset ids, max 3], style, format_key, industry, brand_kit_id, apply_brand (bool), skipped: [step keys] }
```
`BriefValidator`: strings are bounded, stripped of control and direction-override characters, and refused when they look like markup (`<tag`, `&#`, `{{`, `${`). Phone 7 to 15 digits, URL http(s) with a real domain, email via filter_var, handle `@[A-Za-z0-9._]{1,30}`. Asset ids and the kit id must be visible to the user (policy + scope). Unknown keys are ignored and reported in `ignored`. Errors are keyed by brief field (`details.phone`).

### Endpoints
- `POST /design/brief/recommend` body `{brief}` (goal required, business name not) -> `{templates: [...ranked], formats: [...], industry, goal, ignored}`. Nothing is saved. Each template entry is the list shape (section 3) plus `score, reasons[], reason (one sentence), match {purpose,kind,format,style,industry}`. Each format entry: `key,label,use,size_label,kind,notes,orientation,reason,popular`.
- `POST /design/projects/from-brief` body `{brief, template: id|slug|null, name?}` -> 201 `{project:{id,name,format_key,width,height,url}, pages, template_id, result|null}`. `result = {width,height,filled[],left[],brand_applied[],warnings[]}` (section 4). Blank (no template) = one empty page at the chosen size; with no size, the goal's most-used format. Stores the brief in `design_projects.brief`, plus `format_key`, `template_id`; fires `DesignProjectCreated`.

### Recommender rules (deterministic, no AI)
Points: goal purpose in `template.purposes` 30; template kind position in the goal's kind list 24, 21, 18 ... floor 4; exact size 30 (or same aspect family 10; or, with no size picked, the goal's 1st/2nd/3rd usual size 12/8/5); chosen style 25; stated or guessed industry 15 (template for any business 3); a team/own template 5; popularity 0 to 10; usage 0 to 5. Ties: popularity, then slug. Fewer than 4 relevant templates: topped up with the most popular others, reason "A good general starting point". Industry is guessed from whole-word keywords in the business name, "what", description, offer, headline and tagline.

## 3. Templates

Table `design_templates`: slug (unique), name, description, source (curated|team|user), team_id, user_id, status (draft|published|retired), category (marketing|social|identity|commercial|document), kind, industries, purposes, styles, platforms, objectives (json lists), format_key, width, height, pages (v2 pages with `bind`), slots, page_count, popularity (0-100 editorial), uses (counter, no personal data), version, content_hash, published_at. No `thumbnail_svg`: render previews in the browser from `pages` (or `?include=first_page`).

Vocabulary: kinds `config('design_copy.template_kinds')`, purposes `...purposes`, objectives `...objectives`, styles = the seven brief styles, industries = the keys of `design_brief_flow.industries` plus `general`.

### Endpoints
- `GET /design/templates` filters `category, kind, industry, purpose, style, platform, format, objective, source (curated|team|user|mine), q, sort (popular|newest|name), per_page (max 48), page, include=first_page` -> `{data: [summary], meta: {current_page,last_page,per_page,total}}`. Summary: `id, slug, name, description, source, status, category(_label), kind(_label), industries, purposes, styles, platforms, objectives, format_key, format_label, width, height, page_count, slots[{key,label,type,help,example,default?}], popularity, uses, version, preview?`. `q` matches name, description, slug, kind and industry words ("bakery" finds the food templates).
- `GET /design/templates/facets` -> counts by category/kind plus the label maps.
- `GET /design/templates/{id|slug}` -> summary + `pages`, `format`, `can {update,delete}`.
- `POST /design/templates/{id|slug}/fill` body `{brief}` -> `{pages, width, height, filled, left, brand_applied, warnings}`; a dry run for previews (no project, no usage count).
- `POST /design/templates` body `{project_id, name, description?, kind, category?, scope: team|user, styles?, industries?, purposes?, bindings?: {element_id: slot_key}}` -> 201. Saves one of your designs: v1 elements are upgraded, unknown keys dropped, **pictures stripped to empty slots**, listed bindings applied, then checked; a design that cannot be a valid template gets a 422 naming why. Max 100 saved per user.
- `PUT /design/templates/{id}` (name, description, styles, industries, purposes), `DELETE /design/templates/{id}` (designs made from it are untouched). Owner, or a team member whose Jetstream role grants `update` / `delete`; curated ones: operators only.
- `POST /design/templates/{id}/publish` and `/retire`: operators only (`operator` middleware).

### Seeding (library agent contract)
`php artisan design:seed-templates [--check] [--path=dir] [--retire-missing]`. Reads `database/templates/*.php`. A file returns one definition array, a list of them, an object with `definitions()`, or a Closure returning any of those. Files whose name starts with `_` or an upper-case letter are helpers and are never loaded. A definition:

```php
['slug' => 'sale-bold-square', 'name' => '...', 'description' => '...',
 'category' => 'social', 'kind' => 'instagram-post', 'format_key' => 'instagram-post-square',
 'industries' => ['general'], 'purposes' => ['sale'], 'styles' => ['bold'], 'objectives' => ['sales'],
 'popularity' => 80, 'status' => 'published',               // optional
 'pages' => [[ 'background' => [...], 'elements' => [ ... ] ]]]
```
`width`/`height`/`platforms` default from the format; `slots` are derived from the `bind` keys (label from the copy bank, sample text as `default`); authored `slots` entries override. `tests/Fixtures/templates/*.php` are three working examples.

Hard rules the checker enforces (every one is an error, nothing is silently repaired): element keys must be in the document schema, enums and ranges as in `resources/design/document-schema.json`, ids unique per page, `asset_id` empty everywhere, `bind` a known slot of the right type (text slot on a text element, image slot on an image element), `tokens` only on props that exist, group depth max 3, template size equals the format px size, `slots` only for keys that are bound. `--check` is the CI gate.

### Slot key vocabulary
`business_name, tagline, headline, subheadline, offer, price, date, time, venue, address, phone, whatsapp, email, website, social_handle, cta, description` (text) and `logo, photo_1, photo_2, photo_3` (image).

## 4. Instantiation rules (`TemplateInstantiator`)
1. The template row is never modified; pages are deep copies with fresh element ids (ULID, lower case, nested children too) and without page ids.
2. Only elements with a `bind` are touched. Text: only the `text` string is replaced, styling untouched. Image: only `asset_id` is set.
3. Text values come from the brief (`details.*`, `business_name`; `what` is the headline when no headline was given). If the brief has none and a kit was chosen, the kit supplies `tagline`, `website` and contact details (`phone, whatsapp, email, address, social_handle`), reported as source `brand`.
4. Pictures: `logo` = the brief's logo, else the kit's `logo` (or `logo-light` on a `dark` template); `photo_1..3` = `brief.photos`. An asset the user may not use is left empty (`not_visible`). No asset = an empty slot (`no_picture`).
5. No value for a text slot: the template's sample text stays and the slot is listed in `left` (`no_value`) so the UI can ask for it.
6. Brand colours and fonts: only when `brief.apply_brand` is true and a kit is given, and only the props listed in an element's `tokens` (`color, fill, stroke_color, shadow_color, font`). Gradient fills are never overwritten; an 8-digit hex keeps its alpha. A token the kit has no value for leaves the element alone and adds a warning.
7. Long text replacing short sample text adds a "may not fit" warning (autoFit `none` only).
Result: `{pages, width, height, filled[{slot,element_id,page,name,source,type}], left[{slot,element_id,page,name,reason}], brand_applied[{element_id,prop,token,from,to}], warnings[]}`. Template authors: put `tokens` on elements whose colour or font is brand-driven, for example the main backdrop `['fill' => 'brand.colors.primary']` and the headline `['font' => 'brand.fonts.heading']`.

## 5. Brand Studio

Tables: `brand_kits` (team_id nullable, user_id, name, is_default, version, colors, fonts, type_styles, guidelines, voice, tagline, website, contacts), `brand_assets` (brand_kit_id, design_asset_id, role, label), `design_brand_updates` (the notice list). Access: owner, or a member of the kit's workspace; write needs the owner or a role with `update` (`delete` for delete). A kit is created in the user's current team unless `team_id` / `workspace: "personal"` is sent.

Kit shape: `colors [{key,name,hex,role (primary|secondary|accent|neutral|extra)}]` (one of each non-extra role; keys are the role name or a slug, up to 16), `fonts {heading,body,accent}` (font keys), `type_styles [{key,label,font (slot),size,weight,line_height,letter_spacing,case}]` (defaults supplied on create), `guidelines`, `voice {tone,keywords[],avoid[]}`, `tagline`, `website`, `contacts {phone,whatsapp,email,address,social_handle}`. `version` goes up when colours, fonts, type styles, guidelines, voice, tagline, website, contacts or a kit picture change; a rename or a default switch does not.

- `GET /design/brand/kits` -> `{data: [kit], options: {color_roles, brand_roles, fonts, font_slots, contact_keys}}`; kit adds `assets [{id,role,role_label,label,asset (editor array)}]`, `projects_using`, `can`.
- `POST /design/brand/kits`, `GET|PUT|DELETE /design/brand/kits/{kit}` (PUT is partial and returns `changed` and `summary {colors:[{key,name,from,to}], fonts:[{slot,from,to}], other:[...]}`), `POST .../default`.
- `POST /design/brand/kits/{kit}/assets` `{design_asset_id, role (logo|logo-light|mark|image|pattern|background), label?}` (asset must be visible to the user; logo, logo-light, mark are single-slot and replace), `DELETE .../assets/{brandAsset}` (never deletes the upload).
- `POST /design/brand/palette` `{asset_id, max?}` -> `{colors: [{hex, share, is_neutral, hue, saturation, lightness, contrast {on_white,on_black,text_colour,aa_text_on_white,...}}], roles {primary,secondary,accent,neutral}}` for "use my logo colours".

### The never-silent rule
Editing a kit never reads or writes a canvas. It bumps `version` and writes/refreshes one pending `design_brand_updates` row per project that applied the kit (`brand_kit_id` set and `brand_version` not null). Nothing in a project changes until an explicit apply.
- `GET /design/projects/{project}/brand` -> `{brand_outdated, linked, kit {id,name,version}, project_version, items [...], counts {elements, changes}}`. `items`: `{key, page_id, page_number, element_id, element_name, element_type, prop, kind (color|font), token, current, proposed}`: exactly the token-referenced props whose value differs from the kit. For a project with no kit the workspace default kit is the suggestion (`linked: false`).
- `POST .../brand/apply` `{items: [key...]}` or `{all: true}`, optional `kit_id` (needed when the project is not linked). No selection = 422 and nothing changes. Takes a snapshot first (`VersionSnapshotter`; response says `snapshot_taken`), rewrites only the chosen props, bumps each changed page `revision`, records `brand_version` only when nothing is left to apply. Returns `{applied, skipped, snapshot_taken, snapshot_id, outdated, remaining}`.
- `POST .../brand/dismiss` keeps the design as it is and acknowledges the new version. `PUT .../brand-kit` `{kit_id|null}` links or unlinks without changing the design.
- `GET /design/brand/notices` -> pending "your brand changed" notices for the user's projects `{id, project_id, project_name, kit_id, kit_name, from_version, to_version, summary, updated_at}`.

## 6. Seams (for the merge)
- `App\Contracts\Design\VersionSnapshotter` (null default): the backend binds its VersionService; brand apply, and later AI apply, call it first.
- `App\Contracts\Design\ActivityRecorder` (null default): kit created/changed/deleted, brand applied, template published/retired.
- `ScopesToWorkspace` on `BrandKit` and `DesignTemplate`: swap for the backend's `HasWorkspaceScope`. `DesignAsset` visibility goes through `AssetAccess` (model scope + policy), so widening asset visibility needs no change here.
- `TemplateChecker` is self-contained (reads `document-schema.json` through `SchemaReader`); at merge, run the backend `DocumentSanitiser` on top in `checkDefinition`.
- `StarterProjectBuilder` and `ProjectMeta` write only the columns that exist (guarded by `Schema::hasColumn`) and fill v2 page columns (`name,width,height,background,duration_ms,transition,guides`) when the backend migration has added them. Project `team_id` is set to the user's current team when that column exists.
- Rate limiter `design-briefs` (60/min per user) is defined in `StudioBusinessServiceProvider::boot`.

## 7. Not built, on purpose
- No AI anywhere in this layer; recommendations are rules.
- No stock imagery, no thumbnails stored server-side.
- Template content is the library agent's: `database/templates` is empty on this branch and `design:seed-templates` is a clean no-op until it lands.
- Brand colours only reach a template through its `tokens`; a template without `tokens` is never recoloured (no guessing).

## Library QA additions (wave 3, D-1604 and D-1605)
- Instantiation result gains `readable[]` (text colours changed so text stays readable on the brand colours: `element_id, from, to, ratio_before, ratio_after`), produced by `ReadableText` only when `apply_brand` and a kit are in use; a plain warning says how many. `brand_applied` still lists token applications only.
- New text slot `initials` ("Monogram letters"): filled from the business name (`TemplateInstantiator::initials`), reported as left when the brief has no business name.
- `php artisan design:export-templates --json [--out=file] [--slug=...] [--status=published|all]` prints the stored templates (pages, slots, format) as JSON for offline tools.

# R-45 brand UI: Brand Studio, the review of a brand update, assets library, settings

Owner: the brand agent (wave 2). Branch `feat/r45-brand`. Decisions `D-900` to `D-913` in `docs/R45_DECISIONS.md`. Skill record in `docs/R45_SKILLS.md`. Screens in `docs/R45_screens/brand/`.

## Pages and stable routes

| Route name | URL | What it is |
|---|---|---|
| `design.brand.index` | `/design/brand` | The workspace's kits as proof sheets: logo (or the name set in the kit's heading font), colour strip, type specimen, version, default tag, designs using it, designs behind it. Make default, delete. In the rail as **Brand**. |
| `design.brand.create` / `design.brand.edit` | `/design/brand/new`, `/design/brand/{kit}/edit` | The kit editor. |
| `design.brand.updates` | `/design/brand/updates` | Every design waiting on a brand update, with what changed in the kit. **The editor-ui Brand tab links here.** |
| `design.brand.updates.show` | `/design/brand/updates/{project}` | The review of one design. **The editor-ui Brand tab links here per project.** |
| `design.assets.index` | `/design/assets` | The Assets screen (replaces the backend stub). |
| `design.settings` | `/design/studio-settings` | Studio preferences, in the user menu and the phone "More" sheet. (`design.settings.show` and `.update` stay the JSON endpoint.) |
| `design.integrations.index` | `/design/integrations` | Restyled, same honest statuses, no controls. |

New JSON routes (all `JsonApi`, behind the same auth group): `GET design/assets/library`, `GET design/assets/meta`, `GET design/assets/{asset}/usage`, `POST|PUT|DELETE design/asset-folders`, `PUT|DELETE design/asset-tags`, `POST design/asset-bulk`. The kit, palette, brand-update, template, upload, settings and thumbnail endpoints are the existing ones.

## Brand Studio editor

One page, sections, an explicit **Save** (also Ctrl or Cmd S). Nothing is sent until Save. Leaving by a link or closing the tab with unsaved changes asks first. A failed save says what is wrong next to the field (the server's own words) and keeps what was typed. After a save the kit is read back from the server, so what is on screen is what is stored. The save bar says "All changes saved", "You have unsaved changes", "Saving" or "Not saved" in words, with an icon that matches.

- **Name**, **Logo** (logo, logo for dark backgrounds, icon or mark: upload, drag a file on the card, or choose from assets; each shown on a light and a dark swatch with a plain verdict, judged by the logo's average colour and said so).
- **Colours**: roles (main, second, highlight, neutral, extras up to 16), colour picker and typed code (bad codes are refused as you type), rename, role change (a role is never doubled), reorder by dragging or with the up and down buttons, remove. **Use my logo colours** calls the palette endpoint on the logo and shows candidates with their share of the picture and the role the picture suggests; accept one by one or take the suggestions. **Check two colours together** gives "Good for body text", "Good for headlines" or "Hard to read" with the ratio; **How each colour reads** gives every colour as a background with its best text colour.
- **Fonts**: heading, body, accent over the 18 shared fonts with live specimens, a font browser that shows each name in its own font, eight curated pairings (only those whose fonts the manifest has). Fonts load lazily through the engine font module when the section scrolls into view; a failed load keeps the fallback stack.
- **Text styles**: up to 12, each with a live preview (shown at most 44 px tall).
- **Voice and guidelines**: tone chips and a free line, words to use, words to avoid (12 each, 30 characters), guidelines (4000, with a counter). It says plainly that these guide suggestions and the assistant when it is on and are never applied to a design by themselves.
- **Tagline, website and contacts**: the fields templates fill their slots from, with the brief's labels and examples.
- **Brand imagery**: pictures, patterns and backgrounds from assets with a label each.
- **Preview on a design**: three library templates that carry brand tokens, drawn with the engine renderer and the unsaved kit applied (and the kit logo in `logo` slots). Client side only.

Because logos and pictures are separate calls on the server, a Save sends the kit first, then each picture attach or detach, then reloads. A single-slot role that is replaced is one attach with no detach (the server swaps it). One Save can therefore raise the kit version more than once; designs still get one merged notice.

## Never-silent review

`/design/brand/updates` lists notices. `/design/brand/updates/{project}` loads `GET .../brand` (the exact token-linked props that differ from the kit) and `GET .../document`, then:

1. Nothing is selected. Quick choices: Everything, Colours only, Fonts only, Nothing (the two partial ones are hidden when they equal another), or tick single changes. The summary says how many changes and elements are selected.
2. Each change shows before and after as colour chips with their codes, or as the two fonts in their own faces.
3. Each page with changes is drawn **Now** and **With the changes you picked** (client side, exactly the selected changes).
4. Text that would become harder to read is listed before you apply (4.5 to 1, or 3 for large text), including text whose own colour did not change but whose backing fill did. Not judged: text over gradients, pictures and translucent shapes.
5. **Apply N changes** asks for confirmation (repeating any legibility warning as "Apply anyway?"), then calls the apply endpoint with the chosen keys. The server saves a version first, rewrites only those props and bumps the page revision. The result says how many were applied, how many are left, and "A version was saved before applying. To undo this, open the design and restore it from Version history."
6. **Keep my design as it is** acknowledges the new kit version and changes nothing. **Decide later** just leaves.

Looking at any of these pages, the previews and the kit list changes no row (pinned by `StudioBrandUiTest`). Applying through the review saves a real version (D-910).

## Assets library

Grid or list (remembered per browser), search by name, filters for type, used or unused, folder and tag, four sorts, "Show more" by cursor, URL keeps the filters. Upload by button or by dropping files anywhere on the page: a per-file queue (two at a time) with progress, cancel, and on failure the server's exact reason (type, size, dimensions, quota, rate); type, size and dimension refusals have no Retry because the same file cannot pass, quota, network, rate and server ones do. Folders can be made empty, renamed and removed (pictures move to No folder); dragging a picture or the selection onto a folder moves it. Tags are added in the drawer or in bulk, and renamed or deleted from the active tag filter (your own pictures). Bulk: move, tag, delete, with a per-picture result when some fail and why. The details drawer shows size, type, date, uploader, folder, tags, licence and where it is used (links to the designs); only the uploader can change them. Delete is refused with an explanation when the picture is placed on a design (the server refuses it too); otherwise it is confirmed first. The storage meter is the server's. There is no copy-link (files are private) and no stock library (stated on the page); an image licensing note says the person must own the right to what they upload.

## Home, designs list, settings, integrations, copy

- Tiles on Home and Designs use the real thumbnail when the editor has uploaded one (lazy, versioned by render time) and the neutral frame otherwise; status chips (In review, Approved; Draft on the list) and a **Brand updated** marker that links to the review. The Home brand line reads the real default kit, its version, its colours and how many designs are waiting.
- Studio settings: editor mode, snap, grid, rulers, movement. Each control saves at once and the line under it says "Saved." or why not.
- Integrations: ds table and tags, same statuses and wording, no button or form in the page.
- Jetstream workspace pages, member actions and the invitation email say "workspace" in visible copy.

## Tests

PHP: `tests/Feature/StudioBrandUiTest.php` (21: routes, auth, roles, cross-tenant isolation, previews and reviews change nothing, apply through the review saves a version and changes only the picked props, dismiss, settings, home and list state, thumbnails, copy, no inline script or external host or em dash), `tests/Feature/StudioAssetsUiTest.php` (13: filters, cursor, usage, isolation, folders, tags, bulk, refusals with reasons). One existing test changed (D-913).
JS (`node --test "tests/js/*.test.mjs"`): `brand-contrast` (WCAG values, verdict limits), `brand-palette` (candidate acceptance, roles, reorder, 16 limit), `brand-kit-state` (dirty, payload, picture ops, words, tone chips, pairings), `brand-review` (selection modes, summary, apply-to-pages, legibility incl. large text and unjudgeable backdrops), `assets-queue` (state machine: precheck, concurrency, progress, refusal reasons, retry, cancel, throw), `brand-pages` (preview template choice, logo slot, tags, messages).

## Browser acceptance (headless Chrome over DevTools, own profile, own viewport)

Done and looked at: create a kit from an uploaded logo (and a logo for dark backgrounds), take the logo colours, pick a pairing, tone and words, a wrong email refused with the server's sentence and the typed values kept, create, edit colours and a font, the unsaved-changes guard on a link, save; two designs made from library templates with brand colours show "Brand updated" on Home and the list and in the review list; the review with nothing selected, Colours only with a legibility warning list, the confirm, the result with the version note (checked in the database: one version holding the old colours, only the colour props changed, the font left alone, an activity row written); assets: good upload, tiny picture (dimensions), gif (type), 9 MB (size), over quota (a second server with a 0 MB quota), folder created, bulk move, bulk tag, drag to a folder, details drawer, delete refused with where it is used, used filter, list view; a real thumbnail on the tiles; settings saved; integrations. Every page in light and dark at 1440 and 390: no horizontal overflow, one h1, no console errors other than the expected 422 of a refused request.

## Known limits (honest)

- Contrast verdicts for logos use the average colour of the visible pixels, not each part of the logo.
- Legibility in the review does not model rotation, gradients, pictures or translucent backings.
- Palette candidates come from the server's clustering; a small accent in a logo may not appear.
- The used and unused filter and the where-used list scan the visible pages once per request; fine for hundreds of pages, to be indexed if a workspace holds tens of thousands.
- Duplicate kit, kit export and a design-to-kit link from the editor Brand tab (editor-ui) are not part of this scope.
- Tile thumbnails stay the neutral frame until the editor uploads one after a save (editor-ui). The thumbnail route and the tile code are tested.
- Drag and drop reordering of colours uses HTML drag events (mouse); touch and keyboard use the up and down buttons.

# R-45 library: templates, icons and design elements

Owner: the library agent. Branch `feat/r45-library`. Content only: no app code, routes, migrations or UI. Everything here is plain data (PHP definition files and JSON) plus the tools that build and check it.

| What | Where | Count |
|---|---|---|
| Templates | `database/templates/<slug>.php` | 58 (`_index.php` lists them) |
| Template builder DSL and craft checker | `database/templates/Support/` | 11 classes |
| Icons | `resources/design/icons.json` | 178 |
| Design elements (50), 12 patterns, 12 gradient presets, 20 palettes | `resources/design/elements.json` | 94 entries |
| Strict schema validator (tests) | `tests/Support/TemplateSchemaValidator.php` | |
| Tests | `tests/Unit/Library{Templates,Icons,Elements}Test.php` | 478 cases |
| Dev tools | `scripts/dev/` (see section 6) | |

## 1. Catalogue

Every template is a real, editable document (text, shapes, icons, groups and empty picture slots, never a flattened image). Picture slots are `image` elements with `asset_id: null` sitting on a vector backing with an accent block, ring or strip, so the design looks finished before a photo is added. "Covers" is the library's own classification used by the coverage test (the catalogue `kind` is coarser).

| Slug | Kind | Format | Styles | Pages | Category | Covers |
|---|---|---|---|---|---|---|
| `animated-story-flash-sale` | instagram-story | instagram-story (1080x1920) | bold, dark | 1 | social | animated social |
| `announcement-clean` | instagram-post | instagram-post-square (1080x1080) | clean | 1 | marketing | business announcement; instagram post square |
| `banner-leaderboard` | web-banner | web-banner-leaderboard (728x90) | bold | 1 | marketing | web banner |
| `banner-mpu` | web-banner | web-banner-medium-rectangle (300x250) | dark, bold | 1 | marketing | web banner |
| `banner-roll-up` | roll-up-banner | roll-up-banner (2409x5669) | corporate, bold | 1 | marketing | roll-up banner |
| `brand-guidelines-clean` | brand-guidelines | presentation (1920x1080) | clean | 4 | identity | brand guidelines |
| `brochure-trifold` | brochure | a4-landscape (842x595) | corporate | 3 | marketing | brochure |
| `business-card-clean` | business-card | business-card-85x55 (241x156) | clean | 2 | identity | business card |
| `business-card-dark` | business-card | business-card-85x55 (241x156) | dark, elegant | 2 | identity | business card |
| `carousel-3-clean` | instagram-post | instagram-post-portrait (1080x1350) | clean | 3 | social | social carousel |
| `certificate-elegant` | certificate | certificate-a4-landscape (842x595) | elegant | 1 | commercial | certificate |
| `company-profile-earthy` | company-profile | a4-portrait (595x842) | earthy, elegant | 4 | identity | company profile |
| `ebook-dark-3` | ebook | ebook-6x9 (432x648) | dark, elegant | 3 | document | e-book |
| `email-signature-clean` | email-signature | email-signature (600x200) | clean, corporate | 1 | identity | email signature |
| `event-invitation-elegant` | invitation | a5-portrait (420x595) | elegant | 1 | marketing | event invitation; invitation |
| `event-invitation-playful` | invitation | instagram-story (1080x1920) | playful, bold | 1 | marketing | event invitation; instagram story |
| `event-poster-dark` | poster | a3-portrait (842x1191) | dark, bold | 1 | marketing | promotional poster; event poster |
| `facebook-cover-corporate` | facebook-cover | facebook-cover (1640x924) | corporate | 1 | social | facebook cover |
| `facebook-post-clean` | facebook-post | facebook-post (1200x630) | clean | 1 | social | facebook post |
| `flyer-a4-corporate` | flyer | a4-portrait (595x842) | corporate | 1 | marketing | flyer a4 |
| `flyer-a4-playful` | flyer | a4-portrait (595x842) | playful | 1 | marketing | flyer a4 |
| `flyer-a5-bold` | flyer | a5-portrait (420x595) | bold | 1 | marketing | flyer a5 |
| `flyer-a5-clean` | flyer | a5-portrait (420x595) | clean | 1 | marketing | flyer a5 |
| `ig-post-portrait-bold` | instagram-post | instagram-post-portrait (1080x1350) | bold | 1 | social | instagram post portrait |
| `ig-post-portrait-elegant` | instagram-post | instagram-post-portrait (1080x1350) | elegant | 1 | social | instagram post portrait |
| `ig-post-square-playful` | instagram-post | instagram-post-square (1080x1080) | playful | 1 | social | instagram post square |
| `ig-story-bold` | instagram-story | instagram-story (1080x1920) | bold | 1 | social | instagram story |
| `ig-story-dark` | instagram-story | instagram-story (1080x1920) | dark | 1 | social | instagram story |
| `label-product-earthy` | label | label-100x70 (283x198) | earthy | 1 | commercial | product label |
| `letterhead-a4-clean` | letterhead | letterhead-a4 (595x842) | clean, corporate | 1 | identity | letterhead |
| `linkedin-banner-clean` | linkedin-banner | linkedin-banner (1584x396) | clean | 1 | social | linkedin banner |
| `linkedin-post-corporate` | linkedin-post | linkedin-post (1200x627) | corporate | 1 | social | linkedin post |
| `logo-monogram-badge` | logo | instagram-post-square (1080x1080) | elegant, earthy | 1 | identity | logo wordmark |
| `logo-wordmark-bold` | logo | instagram-post-square (1080x1080) | bold | 1 | identity | logo wordmark |
| `logo-wordmark-clean` | logo | instagram-post-square (1080x1080) | clean | 1 | identity | logo wordmark |
| `menu-a4-elegant` | menu | menu-a4 (595x842) | elegant, earthy | 1 | commercial | restaurant menu a4 |
| `menu-a5-dark` | menu | menu-a5 (420x595) | dark, earthy | 1 | commercial | restaurant menu a5 |
| `newsletter-clean` | newsletter | newsletter-a4 (595x842) | clean, earthy | 1 | document | newsletter |
| `packaging-box-front` | packaging | a4-portrait (595x842) | playful, bold | 1 | commercial | packaging concept |
| `presentation-dark-4` | presentation | presentation (1920x1080) | dark, corporate | 4 | identity | presentation |
| `price-list-clean` | price-list | a4-portrait (595x842) | clean | 1 | commercial | price list |
| `product-ad-clean` | instagram-post | instagram-post-portrait (1080x1350) | clean | 1 | marketing | product advertisement; instagram post portrait |
| `product-ad-dark` | poster | a4-portrait (595x842) | dark | 1 | marketing | product advertisement |
| `product-catalogue-clean` | product-catalogue | a4-portrait (595x842) | clean, earthy | 2 | commercial | product catalogue |
| `promo-poster-bold` | poster | a3-portrait (842x1191) | bold | 1 | marketing | promotional poster |
| `promo-poster-elegant` | poster | a3-portrait (842x1191) | elegant | 1 | marketing | promotional poster |
| `proposal-document-clean` | report | report-a4 (595x842) | clean, corporate | 4 | document | multi-page document |
| `quotation-clean` | quotation | a4-portrait (595x842) | clean, corporate | 1 | commercial | quotation |
| `report-corporate` | report | report-a4 (595x842) | corporate, clean | 3 | document | report |
| `sale-ad-bold` | facebook-post | facebook-post (1200x630) | bold | 1 | marketing | sale advertisement; facebook post |
| `sale-ad-clean` | flyer | a4-portrait (595x842) | clean | 1 | marketing | sale advertisement |
| `service-ad-corporate` | flyer | a4-portrait (595x842) | corporate | 1 | marketing | service advertisement |
| `service-ad-earthy` | facebook-post | facebook-post (1200x630) | earthy | 1 | marketing | service advertisement |
| `service-catalogue-corporate` | service-catalogue | a4-portrait (595x842) | corporate | 1 | commercial | service catalogue |
| `tiktok-cover-bold` | tiktok-cover | tiktok-cover (1080x1920) | bold | 1 | social | tiktok cover |
| `voucher-gift-dl` | voucher | voucher-dl (595x281) | bold, playful | 1 | commercial | promotional voucher |
| `whatsapp-status-playful` | whatsapp-status | whatsapp-status (1080x1920) | playful, earthy | 1 | social | whatsapp status |
| `youtube-thumbnail-bold` | youtube-thumbnail | youtube-thumbnail (1280x720) | bold | 1 | social | youtube thumbnail |

Style coverage: bold, clean, elegant, playful, corporate, dark, earthy each have several templates. The animated-ready template is `animated-story-flash-sale`: eight staggered entrances (fade, slide-left, slide-right, zoom-in, pop, rise) inside a 5000 ms page plus a pulse loop on the button, all set per the schema; the animation evaluator itself lives in the engine.

## 2. The builder DSL (one page)

Templates are written in PHP and emit exact document-schema v2 arrays. A file returns one definition and loads the support classes itself (no composer autoload change):

```php
require_once __DIR__.'/Support/bootstrap.php';
$t = Template::start([... meta ..., 'format_key' => 'instagram-post-portrait'])
    ->palette(new Palette($bg, $ink, $accent, $surface, $extra))   // 5 colours, defined once
    ->type(new TypePair('playfair-display', 'inter', 400, 400));     // heading + body, max 2 families
$p = $t->page();            // a Page sized to the format (size comes from the format table)
$p->bg('bg');                // palette name or hex; brand-driven page colours also get a backdrop shape
$p->text('Headline', $x, $y, $w, ['font' => 'head', 'size' => 80, 'role' => 'headline', 'bind' => 'headline', 'name' => 'Headline']); // returns the bottom y
$p->rect() / ellipse() / shape() / path() / rule() / image() / icon() / group(name, x, y, fn ($g) => ...)
$p->photo(x, y, w, h, 'photo_1', 'offset|tab|ring|corner|plain')   // empty slot with a designed backing
$p->button(label, x, y, w, h, fill, ink), $p->contactList([...]), $p->contactRow([...])
$p->stack(x, y, w)->t(text, opts)->t(...)     // auto vertical rhythm: each text sits at the cursor
return $t->done();           // slots are derived from the bind keys
```

- **Proportional sizing.** `$p->u` is 1% of the short side, `$p->m` the default margin (7%), `$p->col(i, n, gutter)` a column, `right()`, `bottom()`, `cw()` the content box. One design logic therefore scales across formats; sizes come from `Support/Formats.php`, which mirrors the 42 keys of the business catalogue (px at 72 dpi) with their safe margins.
- **Text boxes size themselves** with the real advance widths and kerning of the shipped fonts (`Support/font-metrics.json`, measured in headless Chrome from `public/fonts/design/*.woff2` for every weight and italic the editor can load; weights resolve the way the engine resolves them, so a 600 Oswald is measured as the 700 it is drawn in). The estimator agrees with the engine's own layout to better than 0.4% and breaks lines in the same places in all 1,890 text layouts checked (`scripts/dev/calibrate-metrics.mjs`). Authoring wraps at 85% of the box width, so every text keeps 15% spare width. **Every text element is emitted with `autoFit: shrink`**, so a longer value, or a wider brand font, makes the text smaller inside its own box instead of overflowing it or breaking a word (the engine's shrink also refuses mid-word breaks, see `docs/R45_LIBQA.md`).
- **Brand tokens.** `Palette` maps palette colours to brand-kit roles (light pages: accent to primary, ink to neutral; dark pages: page colour to primary, accent to accent; six corporate templates map the navy ink to primary). `Page` then puts `tokens` on every colour and font that follows the brand (`fill`, `color`, `stroke_color`, `font` = heading or body). A brand-driven page colour also gets a full-page "Background colour" shape, because only elements can carry tokens.
- **Vocabulary is enforced at load time.** `Template::start` throws for any kind, category, purpose, objective, industry or style outside the business catalogue vocabulary (`Support/Vocab.php`).
- **Sample copy** is real and specific per purpose; contact slots use obvious neutral samples (`Your Business`, `012 345 6789`, `www.yourbusiness.co.za`). No lorem ipsum, no invented customers, metrics, testimonials or brand names, no em or en dashes.

## 3. Craft rules (all executable, in `Support/Craft.php`)

Each template must return an empty list from `Craft::problems()`; `LibraryTemplatesTest` runs it per template and a self-test proves the checker catches bad contrast, text off the page, overflowing text and a sixth colour.

1. A single focal point: the largest text is the headline, price, logo or badge, and the headline is at least 2x the largest body or contact text.
2. At most 5 colours per template (all hex literals) and at most 2 font families from the allowlist.
3. Text contrast of at least 4.5:1 against what is directly behind it, computed from the page colour, gradients (every stop) and every shape under each line of text, with opacity.
4. Text, icons and logo slots stay inside the format's own safe zone (stories 250/340 px top/bottom, TikTok 480 px bottom, roll-up 150 mm bottom, 3 to 6 mm print margins) and never less than 4% of the short side; nothing sits off the page except shapes marked `role: background`.
5. Consistent alignment grid: at most 6 distinct left edges per page.
6. Text fits its box by the real-metrics estimator with 15% headroom; no word is wider than 97% of its box (it would break mid-word); no two lines of text collide; no text overlaps a picture slot or an icon. The same rules are re-checked against the engine's own layout for the sample copy and for much longer and shorter copy by `tests/js/library/templates-real.test.mjs`.
7. Every picture slot is empty and has a vector backing behind it; every element is named in plain language; every text element has a role; replaceable copy has a `bind` from the owner vocabulary and a matching slot.
8. Modest layers: at most 30 top-level and 48 flattened elements per page, with repeated parts grouped.
9. No external URLs of any kind, no forbidden characters, no filler text (tested on every visible string).

## 4. Icons and elements

- **Icons**: 178 own-drawn icons (six added in the QA pass for words owners search for: tree, bed, wifi, crown, printer, paperclip; search tags added so microphone, doctor, graduate, family, wedding, birthday find an icon)
- **Elements** (`elements.json`): dividers (8), corners and marks (6), badges and ribbons (8), speech bubbles (6), frames with picture slots (6), price tags (4), star bursts (4), arrows and callouts (8), 12 geometric patterns (dots, stripes, checks, waves, grid, confetti, contours, halftone, triangles, zigzag, rings, plus) as 16 to 130 element groups on a 600 by 600 tile, 12 gradient presets and 20 brand-neutral palettes whose colours use the Brand Studio roles (primary, secondary, accent, neutral, extra) with a use note and the best text colour on the main colour with its contrast ratio. Each element is a schema-valid group that the editor inserts as is; colours carry brand tokens. `scripts/dev/build-elements.php` regenerates the file.

## 5. Skills

Invoked and applied (the skills are web-oriented, so what was applied is their principles of hierarchy, scale contrast, restraint, type pairing, flat colour fields and checking by looking, translated to print and social graphics):

- `design-taste-frontend`: invoked. Applied: one dominant idea per piece, large-to-small type contrast, one ground/one ink/one accent per template, full fields of colour instead of ornaments, no decorative filler, and the "look at screenshots and fix" loop. Its draw/inspect scripts target web pages and were not run.
- `impeccable`: invoked (and its typography reference read). Applied: role-based type scale, measure and leading by face and width, contrast and zoom stress checks, consistent repeated roles. The detector CLI is for web source and was not run.
- `open-design`: invoked, but its content catalogue (a local clone of the Open Design repository) is not installed and cloning it needs approval, so no brand spec or template from it was used.
- `ui-ux-pro-max`: the folder exists but is empty, so it was unavailable and is not claimed. `emil-design-eng` is empty and was not used; `awesome-design-md` was not needed.

## 6. Dev tools (not product code)

Everything below runs headless Chrome with its own throw-away profile (never the shared browser) and kills it again. Details, method and results: `docs/R45_LIBQA.md`.

- `php scripts/dev/check-templates.php [slug]`: schema validation, craft rules and icon lookup for every template (the authoring gate).
- `node scripts/dev/check-templates.mjs [slug]`: the engine's own text layout with the real font metrics, for the sample copy and for long and short copy (`tests/js/library/templates-real.test.mjs` asserts the same).
- `node scripts/dev/check-recolour.mjs [slug] [--kits vivid,dull,navy,pastel] [--briefs full,long]`: instantiates through the real PHP `TemplateInstantiator` with brand kits and checks the result.
- `node scripts/dev/check-instantiation.mjs --out <dir>`: full and empty briefs, exported to PNG with the product's output module.
- `node scripts/dev/sheet.mjs --out <dir> [--only a,b] [--kit vivid] [--kind icons|elements] [--no-placeholders]`: PNG contact sheets drawn by the real renderer (`--serve` prints a URL to open in any browser). This replaces the old standalone approximation sheet.
- `node scripts/dev/measure-fonts.mjs` regenerates `Support/font-metrics.json`; `node scripts/dev/calibrate-metrics.mjs` proves it against Chrome; `node scripts/dev/qa-report.mjs --base <old database/templates>` prints the before and after table.
- `php scripts/dev/build-icons.php`, `php scripts/dev/build-elements.php`: regenerate the JSON files. `php scripts/dev/contrast.php 'fg:bg'`: contrast of colour pairs. `php scripts/dev/dump-templates.php`: all templates as JSON. `php artisan design:export-templates --json`: the stored rows as JSON.

## 7. Contract notes for other owners (read before merging)

1. **Schema.** `document-schema.json` `bind_key` is now `^[a-z][a-z0-9_]{0,39}$` (the coordinator's change, applied in this branch) so `photo_1` to `photo_3` are valid.
2. **Seeder.** The index is `database/templates/_index.php` (underscore prefix) because `design:seed-templates` loads every other top-level `*.php`; the DSL lives in `database/templates/Support/`, outside the glob.
3. **Vocabulary additions the catalogue does not have yet.** Seven library kinds have no entry in `config/design_copy.php`: `logo`, `brand-guidelines`, `company-profile`, `product-catalogue`, `service-catalogue`, `quotation`, `packaging`. Add them to `template_kinds` (and `kind_categories`: logo, brand-guidelines, company-profile to identity; product-catalogue, service-catalogue, quotation, packaging to commercial) or the UI will show the raw key. All other kinds, purposes, objectives, industries and styles are taken from the catalogue.
4. **Format keys.** All 42 keys used are in `config/design_formats.php`; `Support/Formats.php` mirrors their px sizes and safe margins, so re-sync it if that file changes. Logos are drawn on the square Instagram format (there is no logo format).
5. **Slots.** Authored `slots` (key, label from the copy bank, type, help, default text) are emitted for every template; business cards and the email signature relabel `headline` as "Your name" and `subheadline` as "Job title" because the vocabulary has no person-name slot. The QA pass added the slot `initials` ("Monogram letters", text): the instantiator derives it from the business name (`TemplateInstantiator::initials`: "Thandi's Bakery" is TB), so a monogram never keeps the sample letters.
6. **Tokens.** Colour tokens use the Brand Studio role keys (`brand.colors.primary|secondary|accent|neutral`); font tokens use `brand.fonts.heading|body`.

## 8. Known weaknesses (candid, after the QA pass)

- The estimator and the engine agree, but the product's final judge is still the browser: a font that fails to load falls back to its stack and the text is measured with the fallback.
- Logos are honest starting points: type and simple shapes, not designed marks. The monogram badge now takes its letters from the business name; the wordmarks and the mark in `logo-wordmark-clean` are generic shapes.
- `packaging-box-front` is a concept panel, not a dieline. `product-catalogue-clean` page 2 is functional rather than exciting. `proposal-document-clean`, `report-corporate` and `brochure-trifold` leave generous space on some pages on purpose: they are documents the person fills with their own text, and filling them with invented content would be worse than white space. The carousel cover leaves its lower third empty for the swipe cue.
- Fixed layouts do not reflow. A longer value shrinks inside its own box (to as little as 45% of the authored size in the tests, which is what a 30 character business name or a 56 character address needs in a one line slot), and a value far beyond that stays small rather than moving other elements. A tall headline placed beside a badge keeps to its column and gets smaller, it does not wrap around the badge.
- Sample dishes, services and prices (menus, price list, quotation, catalogues) are plausible examples, flagged as such in each description; replace them. The quotation figures add up (VAT at 15%: 4 500.00 plus 675.00 is 5 175.00) but any real use needs the user's own rate and terms.
- No template uses a gradient (flat fields only); gradients appear in the `elements.json` presets, and the engine's angle convention (0 = towards the top, 90 = towards the right, 180 = towards the bottom, the CSS convention) matches the presets, checked by a test and by eye.
- Brand colours can no longer make text illegible: after a kit is applied, `ReadableText` swaps any text that falls under 4.5:1 against its backdrop to white or near-black and lists the change (`readable` in the result, a plain-language warning). It only trusts a shape that fully holds the text box, so it is conservative: text that sits across the edge of a decorative shape is left alone. It runs at template instantiation; the later "apply brand update" flow (`BrandService`) does not run it yet.
- An exported design with an empty picture slot shows the slot's backing colour (a pale tint in most templates, a dark panel in `ig-story-bold`, `animated-story-flash-sale`, `banner-mpu` and the dark templates). The editor shows the dashed "Add a picture" placeholder in the same place.
- Print templates are layouts at 72 dpi page units, not press-ready files: no bleed is built into the canvas (the exporter adds bleed from the format), so full-bleed backgrounds run exactly to the page edge.
- Brand logos (Instagram, Facebook and similar) are deliberately not in the icon set: they are trademarks. WhatsApp and generic chat, mail, phone and share icons are.

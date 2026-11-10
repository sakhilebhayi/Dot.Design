# R-45 template artwork polish (ptpl agent)

Branch `feat/r45-ptpl`, decisions D-2200 and up. Driven by the outside jury (items 2 data side, 12, parts of 13 and 8). Everything here was checked in the real engine renderer in headless Chrome and by the executable craft gate (PHP craft rules, real-metrics fit, contrast, 2x rule, safe zones, brand recolour legibility, long and short copy). Screens: `docs/R45_screens/polish-templates/` (`before-*` are the 58 templates as they were, `after-cards-*` the 64 now as template cards, `after-editor-*` the story and new templates with the editor's empty-slot prompt).

## 1. Empty picture slots (jury 12)

The cards and the brief rail showed grey boxes with "Add a picture". There is no licensed photography and none is faked. The engine now draws an empty slot in one of three ways (`placeholderStyle`, documented in `docs/R45_ENGINE.md`):

| Where | Look | Words |
|---|---|---|
| Editor canvas | tonal gradient from the page colours, dashed outline in the slot's own shape, thin picture glyph | "Add a picture" when it fits |
| Template cards, Templates tab (`'preview'`) | abstract tonal art: dunes and a sun, overlapping blobs or flowing ribbons in the template's own accent and neutral colours, generated from the slot id | none |
| Exports, thumbnails, share viewer | nothing (unchanged) | none |

Tones come from the page itself (the shape behind the slot, the page colour, the most colourful large fill), so a recoloured brand kit recolours the placeholder. Dark slots get quiet greys with the accent kept for the light, never a muddy olive. Honest status: the preview art is decoration so a card reads as a finished layout. It is not a photograph, not the user's content, and never ends up in a file they download. Code: `resources/js/studio/engine/placeholder-art.js`, `render.js` (`emptySlotNodes`), tests `tests/js/render-placeholder.test.mjs`.

## 2. Safe zones for stories (jury 12, 21d)

`Formats::copyZone()` (new) is stricter than the catalogue for the three full-screen vertical formats: copy, icons and buttons stay below the top 14% and above the bottom 22% (TikTok: the 25% caption band). `Craft` and the JS analysis (`dump-formats.php` now reports the copy zone) both use it, so every story, status and cover is held to it by the same tests. Pictures and colour may still run into the bands, which is how the empty lower third was fixed: the picture, a colour block or large confetti shapes now occupy the bottom of each story, and the button sits above the band.

## 3. What changed per template

Redesigned with a different composition (14):

| Template | Was | Now |
|---|---|---|
| `ig-story-bold` | headline, boxed picture, button at 1540 (inside the app band) | type on top, button and price badge in a row, full-bleed picture band across the bottom |
| `ig-story-dark` | ringed picture high, text low, empty bottom | centred: headline, date, a ringed picture rising from the bottom |
| `animated-story-flash-sale` | everything in the upper two thirds | full-bleed picture on top, huge sale words below, badge beside them, button above the band (all eight entrances and the pulse kept) |
| `whatsapp-status-playful` | content stacked high, empty lower third | round picture and badge in the middle, big soft circles hold the lower edge, button above the band |
| `tiktok-cover-bold` | numeral, headline, small picture, empty lower half | numeral disc and hook on one row, headline, full-bleed picture to the bottom edge |
| `event-invitation-playful` | "Birthday Party" hard-coded as the event, buttons at 1490 | ticket-stub cards with notches, neutral "Come and celebrate with us", confetti shapes at the lower edge |
| `announcement-clean` | left-aligned headline over a card | framed and centred, two detail cards side by side |
| `sale-ad-bold` | headline left, badge right | big numerals: the offer is the picture ("40% OFF" across the middle) under a ticker band |
| `facebook-post-clean` | boxed split | diagonal split with an accent band |
| `flyer-a5-clean` | left-aligned text, boxed picture, "Order by noon" promise | centred editorial: full-width picture, centred words, three points in a row, one button |
| `youtube-thumbnail-bold` | headline box 384 px for 2 lines, 20 px from the picture | diagonal split, headline bottom-aligned to the block under it, 20% narrower words so nothing touches the picture |
| `linkedin-post-corporate` | panel plus boxed picture | round team picture breaking out of the panel |
| `banner-mpu` | small picture, text stack | split: full-height picture left, words right |
| `facebook-cover-corporate` | text lost on navy, bare picture slab | concentric rings, centred safe-area text, framed picture |

New (6, so 64 in all): `sale-post-square-bold` (coupon, answers "a sale post in Instagram square has no match"), `event-post-square-date` (giant date panel), `product-post-square-framed` (gallery frame and price tag), `story-announcement-clean`, `story-coupon-sale`, `facebook-event-ticket`. Each passes every check including long and short copy and the vivid, dull, navy and pastel brand kits.

Copy and boxes:
- Unbound or over-specific sample claims neutralised: "Birthday Party" (now a neutral invitation line), "Order by noon and get it to your door the same day", "Free delivery within 10 km / Pay by card / Cancel any time", "Free delivery, Secure payment, Easy returns", "Experienced team / On time delivery / Fixed, honest pricing", "RSVP by 30 May". A brief can no longer leave a promise on a design that the business never made.
- Headline boxes: `scripts/dev/hug-report.mjs` lists boxes more than 1.35x taller than the text they hold. Six were found (presentation title, product catalogue title, ebook chapter title, Facebook cover name, LinkedIn headline, thumbnail) and fixed; one document note remains by design.
- Safe zones and the jury's "CTA too close to the bottom": all six story-class templates pass the 14%/22% zone.

Icons (jury 8, item 4): the catalogue file is ordered in the picker's chip order with the most useful icons first in each group (the "All" view reads as sections, not an alphabet), `leaf` and `flame` moved to Symbols, 70 search words added (certificate, winner, beauty, tablet, tv, language, battery, hide, show, unlock, speech, sports, loan, compass, bread, chicken, vegetable and more). Groups hold 10 to 29 icons; the biggest (Business 29, Services 24) were left whole because every smaller split needs a new group key and label in the picker's own list, see the seam below.

## 4. Checks run

`php artisan design:seed-templates --check` 64 valid, 0 refused. `php scripts/dev/check-templates.php` 64 templates, 0 problems. `node scripts/dev/check-templates.mjs` 0 problems (sample, long, short copy). `node scripts/dev/check-recolour.mjs --kits vivid,dull,navy,pastel --briefs full,long` 0 problems. `node scripts/dev/check-instantiation.mjs` no problems. PHP: 1,620 tests (1,613 pass, 7 skipped, as before). JS: 727 pass (was 718 plus the 9 new placeholder tests). Count tests were updated where the library grew (58 to 64, upper bound 72).

## 5. Seams for other owners (nothing edited outside my scope except two one-line caller changes)

- `pages/lib/preview.js` and `ui/template-render.js` now pass `placeholderStyle: 'preview'` (one line each) so cards and the Templates tab use the art. If the UI agents prefer the editor prompt in cards, change those two lines.
- Icon groups: to split Business and Services (or to show the new tags) the picker's `catLabels` in `ui/tabs/elements.js` needs the new keys. The data side is ready to take new `category` values.
- The template dialog's "Headline prefilled Flash sale on a menu" and the borderless switch are UI/createflow defects (jury 1), not data.
- Duplicate subtitles on cards ("Instagram post, Instagram post (portrait)") come from the card renderer's format label, not from template data.
- A bound sample the brief did not fill still shows as sample copy; the instantiator reports it in `left`. A better fix is for the UI to list those slots (it already can) rather than for the data to blank them.
- Box height is authored for the sample copy. When a brief headline is shorter than the sample, the box is taller than the text (the text is top or bottom aligned, so layouts hold). Re-hugging boxes at instantiation would need the instantiator to measure text (a PHP port of `FontMetrics` exists in `database/templates/Support` but is not autoloaded by the app).

## 6. Candid remaining weaknesses

- About half of the 64 are still left-aligned type over a block; the redesign changed 14, and the carousel, hiring banner, flyers A4, restaurant menus, price list and the document templates keep the older rhythm.
- The preview art colours come from a heuristic ("most colourful large fill"). Pages with gradient backgrounds or a single flat colour give plain duotone fields; a page whose only colour is the picture backing gets grey art.
- `facebook-event-ticket` and `banner-mpu` carry small text at card size because the formats are small; they are legible at full size, less so in a thumbnail.
- The art and the redesigns were judged on contact sheets drawn by the real renderer and by the automated gate, not clicked through in the product UI (cards, Templates tab, editor) in a browser: the UI is being changed by other agents in parallel.
- Logos, packaging and document templates were not redesigned.

## 7. Skills

See `docs/R45_SKILLS.md` (ptpl section): `impeccable`, `design-taste-frontend` and `open-design` were invoked; the open-design catalogue is not installed, so no brand spec or template came from it.

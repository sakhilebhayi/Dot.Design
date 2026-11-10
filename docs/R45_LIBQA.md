# R-45 library QA (wave 3, libqa agent)

Owner rule: templates must be properly composed, easily customisable and genuinely professional, with no fake or placeholder content. The library was composed against an approximate text-fit estimator and an approximate preview. This pass checks every template, icon and element with the **real engine renderer and the real fonts**, fixes what is wrong, and leaves checks behind so it stays right. Branch `feat/r45-libqa`, decisions D-1600 and up (`docs/R45_DECISIONS.md`).

## 1. Method

| Layer | What it uses | Where |
|---|---|---|
| Real font metrics | Advance width of every character and the kerning of every ASCII pair for every font variant the editor can load (18 families, each shipped weight and italic), measured with canvas `measureText` in headless Chrome from `public/fonts/design/*.woff2` | `scripts/dev/measure-fonts.mjs` writes `database/templates/Support/font-metrics.json` (0.75 MB) |
| Proof that they are real | The engine's own `layoutText` run twice, with the browser's measurer and with the cached metrics, on 1,890 text layouts (every text of every template, sample, long and short copy): identical line breaks and fitted size in all but one (0.25 px of shrink), widest width error 0.37% | `scripts/dev/calibrate-metrics.mjs`; `tests/js/library/fixtures/chrome-widths.json` keeps 120 Chrome widths so CI proves the cache without Chrome |
| Checks | The engine's own `layoutText` (not a copy) with the cached metrics, on the document the editor would load (`normalisePage`) | `scripts/dev/qa/analyse.mjs`, run by `scripts/dev/check-templates.mjs` and `tests/js/library/templates-real.test.mjs` |
| Looking | The real renderer (`renderPageToSvgString`) in headless Chrome: every page of every template, with placeholders (the editor) and without (the export), plus 178 icons and 50 elements, patterns, 12 gradients and 20 palettes in grids | `scripts/dev/sheet.mjs` (PNG sheets, or `--serve` to open in a browser) |
| Brand kits | The real PHP `TemplateInstantiator` with four kits and three briefs, then the same checks | `scripts/dev/instantiate.php`, `scripts/dev/check-recolour.mjs` |
| End to end | Instantiate with a full and an empty brief, export with the product's own output module (`exportPageRaster` on a real engine instance) to PNG, and look | `scripts/dev/check-instantiation.mjs` |

What each text element is asserted to do, for the sample copy **and** for much longer values (a 30 character business name, a 56 character address, a 60 character offer, long phone formats; the cap grows with the sample copy so a one word badge is not asked for a paragraph) **and** much shorter ones:

1. no word wider than its box (it would break mid-word; 97% for sample copy, 100% for stress copy);
2. the text fits its box; the sample copy never needs the shrink fallback;
3. it stays inside the page and the format's safe zone (stories, TikTok, roll-up, print margins, 4% of the short side);
4. no two texts overlap, and no text straddles the edge of a shape (a rule, a ring, a badge, a decorative circle);
5. contrast of at least 4.5:1 against what is actually behind it (page, gradients, every shape under it);
6. headline at least 2x the body text on the sizes actually drawn; small print floor (8 px print, 7 px on cards and labels, 1.9% of the short side on screen);
7. long copy never shrinks below 45% of the authored size.

Known limits live in `tests/js/library/waivers.json` with their reasons. It is empty: nothing is waived.

## 2. Results

Scenario counts are text-level findings. Before = the library as merged (`5e0a831`), after = this branch, both judged by the same checks.

| Template | Pages | Before: sample copy | Before: longer and shorter copy | After: all three |
|---|---|---|---|---|
| `animated-story-flash-sale` | 1 | pass | FAIL 1 word-break, 3 overflow, 2 outside-safe, 6 text-overlap, 3 text-crosses-shape | pass |
| `announcement-clean` | 1 | pass | FAIL 5 overflow, 1 contrast, 1 word-break, 2 text-crosses-shape | pass |
| `banner-leaderboard` | 1 | pass | pass | pass |
| `banner-mpu` | 1 | pass | FAIL 3 overflow, 1 text-overlap, 2 text-crosses-shape | pass |
| `banner-roll-up` | 1 | pass | FAIL 1 overflow | pass |
| `brand-guidelines-clean` | 4 | pass | FAIL 2 overflow, 1 text-overlap | pass |
| `brochure-trifold` | 3 | pass | pass | pass |
| `business-card-clean` | 2 | pass | FAIL 3 overflow, 1 text-overlap | pass |
| `business-card-dark` | 2 | pass | FAIL 4 overflow, 1 text-overlap | pass |
| `carousel-3-clean` | 3 | pass | FAIL 4 overflow | pass |
| `certificate-elegant` | 1 | pass | FAIL 1 overflow | pass |
| `company-profile-earthy` | 4 | pass | FAIL 1 overflow | pass |
| `ebook-dark-3` | 3 | pass | FAIL 1 overflow, 1 text-overlap, 1 text-crosses-shape | pass |
| `email-signature-clean` | 1 | pass | pass | pass |
| `event-invitation-elegant` | 1 | pass | FAIL 2 overflow, 2 text-overlap, 1 text-crosses-shape | pass |
| `event-invitation-playful` | 1 | pass | FAIL 2 overflow, 3 text-overlap, 1 text-crosses-shape | pass |
| `event-poster-dark` | 1 | pass | FAIL 6 overflow, 2 word-break, 1 outside-page, 6 text-overlap, 3 text-crosses-shape | pass |
| `facebook-cover-corporate` | 1 | pass | FAIL 2 overflow, 1 word-break, 1 text-overlap, 1 text-crosses-shape | pass |
| `facebook-post-clean` | 1 | pass | FAIL 3 overflow | pass |
| `flyer-a4-corporate` | 1 | pass | FAIL 3 overflow, 1 word-break, 1 outside-safe | pass |
| `flyer-a4-playful` | 1 | pass | FAIL 5 overflow, 1 text-overlap, 2 text-crosses-shape | pass |
| `flyer-a5-bold` | 1 | pass | FAIL 4 overflow, 4 text-overlap, 1 text-crosses-shape | pass |
| `flyer-a5-clean` | 1 | pass | FAIL 3 overflow, 1 word-break | pass |
| `ig-post-portrait-bold` | 1 | pass | FAIL 4 overflow, 1 word-break, 2 text-overlap, 1 text-crosses-shape | pass |
| `ig-post-portrait-elegant` | 1 | pass | pass | pass |
| `ig-post-square-playful` | 1 | pass | FAIL 4 overflow, 1 word-break, 1 text-overlap, 1 text-crosses-shape | pass |
| `ig-story-bold` | 1 | FAIL 1 word-break, 1 overflow, 1 text-crosses-shape | FAIL 1 word-break, 1 overflow, 1 contrast, 1 text-overlap, 2 text-crosses-shape | pass |
| `ig-story-dark` | 1 | pass | FAIL 2 overflow, 2 outside-safe, 2 text-overlap | pass |
| `label-product-earthy` | 1 | pass | FAIL 3 overflow, 4 text-overlap, 2 text-crosses-shape | pass |
| `letterhead-a4-clean` | 1 | pass | FAIL 3 overflow, 2 text-overlap | pass |
| `linkedin-banner-clean` | 1 | pass | FAIL 2 overflow, 1 word-break, 1 text-overlap | pass |
| `linkedin-post-corporate` | 1 | pass | FAIL 2 overflow, 1 word-break, 1 text-overlap | pass |
| `logo-monogram-badge` | 1 | pass | FAIL 2 overflow, 1 text-overlap, 1 text-crosses-shape | pass |
| `logo-wordmark-bold` | 1 | FAIL 1 word-break, 1 overflow, 1 contrast, 1 text-overlap, 1 text-crosses-shape | FAIL 1 word-break, 2 overflow, 1 outside-page, 1 contrast, 2 text-overlap, 1 text-crosses-shape | pass |
| `logo-wordmark-clean` | 1 | pass | FAIL 2 overflow, 1 text-overlap, 1 text-crosses-shape | pass |
| `menu-a4-elegant` | 1 | pass | FAIL 1 overflow, 1 text-overlap, 1 text-crosses-shape | pass |
| `menu-a5-dark` | 1 | pass | FAIL 2 overflow, 2 text-overlap, 1 text-crosses-shape | pass |
| `newsletter-clean` | 1 | pass | FAIL 1 overflow, 1 text-overlap | pass |
| `packaging-box-front` | 1 | pass | FAIL 2 overflow, 1 text-crosses-shape | pass |
| `presentation-dark-4` | 4 | pass | FAIL 5 overflow, 2 word-break | pass |
| `price-list-clean` | 1 | pass | FAIL 1 overflow, 1 contrast, 1 text-overlap, 1 text-crosses-shape | pass |
| `product-ad-clean` | 1 | pass | FAIL 4 overflow, 2 word-break, 1 text-overlap, 1 text-crosses-shape | pass |
| `product-ad-dark` | 1 | pass | FAIL 1 overflow | pass |
| `product-catalogue-clean` | 2 | pass | FAIL 1 overflow | pass |
| `promo-poster-bold` | 1 | pass | FAIL 6 overflow, 2 word-break, 1 contrast, 2 text-overlap, 5 text-crosses-shape | pass |
| `promo-poster-elegant` | 1 | pass | FAIL 4 overflow, 1 word-break, 1 outside-safe, 1 text-overlap, 1 text-crosses-shape | pass |
| `proposal-document-clean` | 4 | pass | FAIL 2 overflow, 1 text-overlap | pass |
| `quotation-clean` | 1 | pass | FAIL 5 overflow, 2 word-break, 2 text-overlap, 1 text-crosses-shape | pass |
| `report-corporate` | 3 | pass | FAIL 2 overflow, 1 text-overlap | pass |
| `sale-ad-bold` | 1 | pass | FAIL 5 overflow, 2 word-break, 2 outside-safe, 1 contrast, 4 text-overlap, 2 text-crosses-shape | pass |
| `sale-ad-clean` | 1 | FAIL 1 text-crosses-shape | FAIL 7 overflow, 1 contrast, 1 word-break, 5 text-overlap, 3 text-crosses-shape | pass |
| `service-ad-corporate` | 1 | pass | FAIL 5 overflow, 3 word-break, 1 text-crosses-shape | pass |
| `service-ad-earthy` | 1 | pass | FAIL 2 overflow, 1 text-overlap, 1 text-crosses-shape | pass |
| `service-catalogue-corporate` | 1 | pass | FAIL 3 overflow, 1 word-break | pass |
| `tiktok-cover-bold` | 1 | pass | FAIL 3 overflow, 1 word-break, 1 text-overlap | pass |
| `voucher-gift-dl` | 1 | pass | FAIL 4 overflow, 1 word-break, 2 text-overlap | pass |
| `whatsapp-status-playful` | 1 | pass | FAIL 3 overflow, 1 contrast, 3 text-crosses-shape | pass |
| `youtube-thumbnail-bold` | 1 | FAIL 1 word-break, 1 overflow, 1 outside-safe, 1 text-overlap, 1 text-crosses-shape | FAIL 4 overflow, 3 contrast, 2 outside-safe, 2 text-overlap, 4 text-crosses-shape | pass |

58 templates: 4 failed with their own sample copy before, 54 failed with longer or shorter copy, 0 fail after.

Recoloured through the real instantiator (4 kits: a saturated violet and pink, a washed-out greige, a navy and amber, a pastel; briefs: full, long, empty; plus no kit): **1,421 contrast failures, 30 text-over-shape, 19 text-overlap and 4 overflow findings before the readable-text guard and the token and layout fixes; 0 after.** Checked in `tests/js/library/templates-real.test.mjs` for the vivid and navy kits (set `LIBQA_KITS=vivid,dull,navy,pastel` for all four).

Icons: 172 drawn, all parse with the engine's path parser, none malformed; the grid is consistent in weight and corner. Six added (tree, bed, wifi, crown, printer, paperclip) and search tags extended, because owners searching "hotel", "wifi", "microphone", "doctor", "graduate", "family", "wedding", "print", "tree" found nothing (`tests/js/library/elements-icons.test.mjs` keeps a list of words that must hit). Elements and patterns (50 plus 12): every tree normalises with no repairs; the circle frame now shows a circular "Add a picture" slot. Gradients: the presets use the engine's convention (0 = to top, 90 = to right, 180 = to bottom, the CSS convention) as the library agent suspected they might not; tested on the renderer's actual gradient vector and checked by eye (Midnight is dark on top, Candy runs pink to orange to the lower right). Palettes: 20 sets with the five Brand Studio roles each.

## 3. What was wrong and what changed

**The two reported failures and the third nobody had reported.** `ig-story-bold` ("LANDE / D"), `youtube-thumbnail-bold` ("OVERPAYIN / G") and `logo-wordmark-bold` ("BUSINES / S", the wordmark broke out of its block): the headline words were wider than their boxes at the sizes authored (104%, 104% and 113%). Sizes lowered (218 to 184, 150 to 128, 196 to 170 with a wider block) and layouts rebalanced. `sale-ad-clean` had its body paragraph running through the divider rule.

**The estimator.** It was optimistic exactly where the engine is strict: it interpolated between 400 and 700 where the engine draws the nearest shipped weight (a 600 Oswald, Fraunces or Cormorant is drawn in 700), it had no kerning, and it measured a lone word against 85% of the box without checking it fits the box at all. `FontMetrics` now reads the real metrics (`font-advances.json` is gone), `Craft` fails any word wider than 97% of its box and passes `italic` through. `LibraryTemplatesTest` stays green (478 cases).

**Customising a template.** Every text element is now emitted with `autoFit: shrink`: a longer name, a wider brand font or a longer offer makes the text smaller inside its own box instead of overflowing, colliding or breaking a word. Round badges (price, offer) now have the text box inside the circle, not the circle's bounding square, so a longer price shrinks to stay on the badge. Text beside a badge or a picture keeps to its own column.

**Engine and renderer fixes** (small, each with a test; reported in `docs/R45_ENGINE.md`):
- `layoutText` with `autoFit: shrink` treated "fits" as rows times line height, so a word wider than the box counted as fitting once broken across lines. A word wider than the box now counts as not fitting, so a headline gets smaller instead of breaking mid-word (this is the bug behind "LANDE / D" once shrink is on).
- The shrink search rounded to the nearest quarter point, which could round up past the size that fits (an email in a one line box overflowed by a pixel); it floors now, and compares with a 0.01 tolerance (a line exactly as tall as its box was reported as overflowing).
- The empty picture slot placeholder ignored the slot's shape and radius (a round frame showed a square placeholder), spilled its label out of small slots (a 56 px logo slot) and stayed tiny on huge ones (roll-up banner). It now follows the mask and radius, drops the label when it cannot fit, and scales with the slot.

**Brand colours.** A template pairs literal colours with kit colours (white text on a primary button, an accent headline), and tokens cannot say "readable on". With a pale or low-contrast kit most of the library became illegible (1,421 failures). New `App\Services\Design\ReadableText` runs inside `TemplateInstantiator` after a kit is applied: any text under 4.5:1 against what is behind it becomes white or near-black (faded text that cannot reach the bar becomes solid), the colour token is dropped from that text so a later brand update does not flip it back, and the result lists what changed (`readable`) with a plain warning. It trusts only a shape that fully holds the text box (a button, a card, the middle of a round badge), so it never changes text on a guess. Also: single-weight display faces (Anton, Bebas Neue, Archivo Black) now ask for weight 700 so a headline does not turn thin when a kit's font replaces them; a decorative circle on a dark page is now a translucent veil (`ebook-dark-3`) instead of a second solid colour.

**Placeholder copy that should have followed the brief.** `logo-monogram-badge` showed "YB" whatever the business was called: new slot `initials`, derived from the business name by the instantiator (TB for "Thandi's Bakery"); `logo-wordmark-clean` showed "Your Business" in its reversed version, now bound; `animated-story-flash-sale` had FLASH bound and SALE not (a new headline left a stray "SALE"), now one headline; the proposal's "For Your Business" label is "Authorised signature".

**Designs weaker than the rest (the ones the library agent flagged, and more I found by looking).**
- `company-profile-earthy` cover: a deep green field with gold rings under a full bleed picture (finished even before a photo is added), a two line 60 px title, name and tagline below.
- `quotation-clean`: bigger type (11 to 12 px body, 40 px title), a header rule, four line items whose totals add up, a total band, terms beside an acceptance line, a footer that no longer needs icons.
- `newsletter-clean`: full width lead picture, 30 px lead headline, 12 px body, two story columns, a diary box that fills its space.
- `packaging-box-front`: a large pill window for the product, bigger product name, flavour tag and contents; circles moved off the type.
- `proposal-document-clean`, `report-corporate`, `brochure-trifold`: type scale up (headings 30 to 36, body 11 to 14 and larger), the cover of the proposal rebuilt, key points box, signature block and a contact panel.
- Collisions and accidents fixed after looking: a decorative circle behind "You're" (`event-invitation-playful`), a leaf badge on the button and phone (`service-ad-earthy`), a ring crossing the title (`whatsapp-status-playful`), a teal dot under the address (`flyer-a4-playful`), a headline running into its badge (`promo-poster-bold`, now a narrower headline column), the date running into the time (`event-poster-dark`), cramped footer (`promo-poster-elegant`), the offer and picture too close (`sale-ad-clean`), name overlapping address (`letterhead-a4-clean`).

**Left as it was on purpose.** `product-catalogue-clean` page 2 (functional), the carousel cover's empty third (swipe cue), the dark empty-slot backings, quiet document pages. See `docs/R45_LIBRARY.md` section 8.

## 4. End to end (instantiation)

`scripts/dev/check-instantiation.mjs` instantiated ten templates across kinds (`ig-post-portrait-bold`, `facebook-post-clean`, `flyer-a4-corporate`, `business-card-clean`, `menu-a4-elegant`, `event-poster-dark`, `quotation-clean`, `logo-monogram-badge`, `presentation-dark-4`, `banner-leaderboard`) with a full brief and an empty brief, with no kit and with the vivid kit, and exported every page with the output module to PNG, then I looked at them. Asserted: with the full brief every bound text holds the brief's value (the monogram says TB), no sample copy is left where the brief had a value, and nothing is reported as left except the pictures; with the empty brief nothing is filled, every text slot is reported as left (so the UI can ask), and the sample copy stays. Unbound sample copy was scanned for strings that should have followed the brief; the four found are listed above and fixed.

## 5. Tests

| | before | after |
|---|---|---|
| PHP (`php artisan test --compact`) | 1,417 | 1,425 (1,418 passed, 7 skipped as before; +7 `ReadableTextTest`, +1 monogram instantiation; `InstantiatorTest` updated for the readable guard) |
| JS (`node --test "tests/js/*.test.mjs" "tests/js/*/*.test.mjs"`) | 615 | 690 (`tests/js/library/*` 72, +4 engine tests for shrink and placeholders; `tests/js/output/measure.mjs` now uses the real metrics) |

`tests/js/library/templates-real.test.mjs` needs `php` (it dumps the template definitions and runs the instantiator); it does not need a database, a browser or a network. Headless Chrome is needed only by the dev scripts that measure fonts, draw sheets and export PNGs.

## 6. Not done, honestly

- No browser run of the editor UI itself in this pass (the library sheets and exports use the engine and the output module directly, in headless Chrome); the picker panels were not re-clicked.
- `BrandService::applyUpdate` (the "apply brand update" flow on existing projects) does not run `ReadableText`; only instantiation does. It is the same call when someone wants it there.
- The tests cover `vivid` and `navy` kits by default for speed; `dull` and `pastel` pass too (`LIBQA_KITS`).
- Icons for Instagram and Facebook do not exist by design (trademarks).

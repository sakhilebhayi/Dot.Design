# R-45 design system: The Light Table (ds agent)

Source of truth for tokens and rationale: `DESIGN.md` (repo root, Google design.md format, lint clean). Skill record: `docs/R45_SKILLS.md`. Decisions: `D-100` to `D-113` in `docs/R45_DECISIONS.md`. Screens: `docs/R45_screens/ds/`.

## The idea
A print studio's light table. Quiet cool paper-and-ink chrome around a neutral, colour-true stage (`--lt-stage`, pure grey in both themes). One hue (process cyan) for selection, focus, links, progress; the primary button is ink on paper. Structural vocabulary: crop marks (`.lt-crop`), ruled strip (`.lt-ruler`, page header), proof frame at true aspect ratio (`.lt-frame`), swatch chip (`.lt-swatch`), format chip (`.lt-fchip`), registration target (empty states).

## Files
- `resources/css/ds/`: `tokens.css` (the `--lt-*` contract, both themes, `@font-face`), `base.css` (reset replacing Tailwind preflight, type roles, focus, selection, reduced motion), `vocab.css`, `components.css`, `shell.css`, `pages.css`, `landing.css`, `legacy.css` + `legacy-icons.css` (bridge). `resources/css/app.css` imports them, then Tailwind.
- `resources/js/ds/`: `theme`, `menu`, `dialog`, `tabs`, `toast`, `view`, `progress`, `landing`, `legacy-icons`. `public/js/ds-theme.js` runs in `<head>` before paint.
- `resources/views/components/ds/`: `icon`, `button`, `icon-button`, `chip`, `swatch`, `frame`, `format-chip`, `field`, `select`, `tabs` + `tabpanel`, `link-tabs`, `panel`, `empty`, `toast` (the region + flash), `menu` + `menu-item`, `dialog`, `tooltip`, `kbd`, `status-line`, `progress`, `tag`, `avatar`, `stat-line`, `page-header`. Icons: `components/ds/icons/*.svg` (133, Lucide ISC + 5 drawn; `App\Support\Ui\Icons`).
- PHP: `App\Support\Ui\Navigation` (route-aware destinations), `StartFormats` (`?format=` keys), `DesignListing` (search/filter/sort), `BrandStatus` (honest brand line), `Icons`.
- Fonts: `public/fonts/ui/` (Bricolage Grotesque for display, Atkinson Hyperlegible Next for UI, OFL, latin, `font-display: swap`; the UI face is preloaded).

## Contract for other agents
- Use tokens (`var(--lt-ink)`, `--lt-panel`, `--lt-stage`, `--lt-accent`, `--lt-radius-1..3`, `--lt-space-1..8`, `--lt-font-ui|display|mono`, `--lt-ease`, `--lt-dur-1..3`) and `<x-ds.*>`; never a literal colour or font. Design pages always sit on `--lt-paper`; text drawn on a page uses `--lt-paper-ink`.
- Layout: `<x-app-layout>`; pages wrap content in `<div class="lt-page">` (or pass a `header` slot with plain text and the layout supplies the container and the single h1). `lt-page--narrow` for forms, `lt-page--wide` for dense tools. An editor that needs the full sheet can render without `lt-page` and sit directly in `<main id="main">`.
- Navigation: register a route named `design.templates.index`, `design.brand.index`, `design.assets.index` or `design.reviews.index` (or the alternates in `Navigation::plan()`) and the destination appears in the rail and phone bar.
- Create flow: read `?format=<key>`; keys in `StartFormats`.
- Toasts: flash `status`, `success`, `error` or `warning` and it shows as a toast; in JS call `window.dotToast(message, 'ok'|'bad'|'warn')`.
- Confirm destructive actions with `<x-ds.dialog>` (see the designs list), not `confirm()`.
- Grid or list: the choice is stored per browser in `localStorage` (`dot-design-view`) and applied to `<html data-view>`; add class `lt-views` to a `lt-contacts` list to opt in.

## Legacy bridge (and what deletes it)
The old editor (`design/projects/show.blade.php`, `public/js/design-editor/`) is untouched and works in both themes through: `legacy.css` (old custom properties `--bg --text --text-dim --text-faint --border --bg-input` and the old `--ink --paper --gold` family mapped onto tokens; `.dot-btn`, `.dot-card`, `.dot-input`, `.dot-badge`, `.page`, `.card-grid`, `.kpi-grid`, `.form-grid-3`, `.table-scroll`, `.ed-*`), `legacy-icons.css` (generated: 71 Material Symbols names to inline-SVG masks) and `legacy-icons.js` (copies the ligature text to `data-i`, follows Alpine `x-text`). When wave 2 deletes the old editor page: delete those three files, their imports in `resources/css/app.css` and `resources/js/app.js`, and run `grep -rn "dot-btn\|dot-card\|dot-input\|material-symbols" resources public/js` to confirm nothing remains. The old editor's inline pink (#ec4899) accents are not remapped; they disappear with it.

## What changed in tests
`ResponsiveShellTest` was rewritten for the new markup (the drawer hooks no longer exist; it now pins the rail, phone bar, More sheet, skip link, one h1, theme controls, and the unchanged table-scroll and wrapper checks). `DashboardTest` asserts "Start something" instead of the old title. `DesignHonestyTest` creates one design so the single "View all designs" link exists. New: `DesignSystemShellTest` (navigation visibility and alternates, theme plumbing, no external hosts on 8 pages, render sweep of 15 pages for 200 + landmarks + one h1, landing honesty, home/list/create contracts, icon set).

## Verification done
- Headless Chrome, light and dark, 1440 / 768 / 390: no horizontal overflow on home, designs, create, token sets, components, profile, team pages, landing, login, error; one h1 each.
- Impeccable detector on rendered HTML of 9 pages: 0 findings after fixes (first pass: overused face, width animation, grid-background, 2 marquee loops).
- `DESIGN.md` lint: 0 errors, 0 warnings. Contrast computed numerically (see DESIGN.md).
- `view:cache` passes; Pint clean.

## Known gaps
- Browser pane check of every Jetstream team-member and API-token modal state was not exhaustive: those screens are restyled through the shared components and Tailwind colour remap and were rendered (200, no overflow) but not clicked through. Livewire flows (photo upload, 2FA setup) are unchanged logic.
- The CSP is still report-only and is not mine to change: the headers allow the old CDNs, so tighten them after merge (layouts no longer need any external host; inline `style=` attributes and Alpine's `unsafe-eval` mean `style-src 'unsafe-inline'` and `script-src 'unsafe-eval'` are still required).
- The designs list filters in memory (D-109) and the home brand line is a seam until the brand kit exists (D-110).
- Format keys are ds-owned (D-108) until the business track's `config/design_formats.php` lands.
- Landing copy asserts wave 1 and 2 capabilities; re-verify before release (D-113). The landing hero picker needs script for the preview; without script the chips are plain links.
- No real thumbnails exist yet: frames are neutral on purpose. When `thumbnail_asset_id` ships, put an `<img>` inside `<x-ds.frame :blank="false">`.
- Team and API-token pages still say "Team" in Livewire form copy while the menus say "workspace".
- Two screenshots per theme were not kept; only 8 representative JPEGs are in `docs/R45_screens/ds/`.

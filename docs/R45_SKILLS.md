# R-45 ds agent: design skills record

Global owner rule: UI work goes through the installed skills in `~/.claude/skills`. What was available, what was applied, how.

| Skill | Status | How it was used |
|---|---|---|
| `impeccable` | available, invoked | Ran `impeccable context` (no PRODUCT.md, incumbent visual system present). Modes: shell, home, lists, forms = Operate; landing = Persuade. The interview step was replaced by the owner's standing order (no questions) and the brief. Craft floor applied: no kicker/eyebrow, no gradient text, no side-stripe borders, no hero-metric tiles, no modal where a page works, browser surfaces themed (selection, caret via accent-color, scrollbars, focus rings, tabular numerals). Detector (`impeccable detect`) run on rendered HTML of 9 pages: it found the overused face (Instrument Sans, changed to Atkinson Hyperlegible Next), width animation on a progress bar (now transform), a "grid background" (the ruled strip, now an SVG tick mask) and two "marquee" loops (loading bars, now a breathing bar and a finite grow). Final run: see R45_DS.md. |
| `design-taste-frontend` | available, invoked | Read `references/app-screens.md` and applied it to shell, home and lists: nav recedes (grey/neutral active item), colour on data and selection only, KPIs are type not tiles (and the metric row was deleted), flat surfaces, one primary action, honest empty states, labelled rail naming the product and the workspace, one h1, phone folds the rail. The page run (draw/inspect scripts) was NOT run: the direction was fixed by the owner brief and architecture section 7 (the skill's own rule: the brief wins). Where the skill's page house rules ban crop marks, rules and mono (they are this product's vocabulary), the brief won; no eyebrows, tracked caps, gradients, glass, pill badges or icon cards were used. |
| `hallmark` | available, invoked | Applied to the landing page: the Floor (token-only colours, no eyebrows or tracked caps, no invented metrics, no middle-dot strings, focus-visible, reduced motion, overflow-x clip, hero headline + sentence + action visible at 1280x800). `sloplint` was run (`--url`; the render tier needs puppeteer-core, not installed so it did not run). Its per-file FAILs about the Hallmark stamp, global focus and reduced-motion blocks come from linting `landing.css` alone (those rules live in `base.css`); real findings (vh hero, colour literals) were fixed. The deal/theme scripts were not used: the direction is the owner's, recorded in DESIGN.md. |
| `design-md` | available, invoked | Wrote `DESIGN.md` (Google format) and linted with `npx @google/design.md lint DESIGN.md`: 0 errors, 0 warnings. |
| `awesome-design-md`, `open-design` | available, glanced at | Reference only (structure of brand DESIGN.md files). No brand spec was borrowed. |
| `ui-ux-pro-max` | UNAVAILABLE | `~/.claude/skills/ui-ux-pro-max` is a symlink to `../../.agents/skills/ui-ux-pro-max`, which does not exist (dangling), and the Skill tool answers "Unknown skill". Not used and not claimed. |
| `emil-design-eng` | UNAVAILABLE (empty) | `~/.claude/skills/emil-design-eng` is a dangling symlink to `../../.agents/skills/emil-design-eng` (missing). Not used and not claimed. |
| `gsap-*`, `scroll-world`, `react-bits`, `canvas-ui`, `terminal-dark` | not used | No scroll-cinematic or WebGL work in this scope; motion is CSS only, short, behind `prefers-reduced-motion`. |

Verification tools actually run: `@google/design.md lint`, `impeccable detect` (on rendered HTML), `hallmark sloplint` (source tier), a headless-Chrome screenshot pass at 1440, 768 and 390 in light and dark with a horizontal-overflow check, and 40+ PHP feature tests.

## ai agent (assistant panel, 2026-11-09)
| Skill | Status | How it was used |
|---|---|---|
| `claude-api` | available, invoked before writing API code | Read the Messages API shape from its cURL reference (no PHP SDK used, see D-1100), the model table and the Sonnet 5.5 notes (forced tool_choice 400, `between_tools`, effort, strict tools). Not verified against the live API. |
| `impeccable` | available, invoked | Ran `impeccable context` (no PRODUCT.md; the incumbent Light Table system in DESIGN.md is the authority, Operate mode), read `craft-floor.md` and `operate.md`, then built the panel with the DS tokens only: ruled rows instead of cards, no eyebrows, no gradient text, no side stripes, tone steps not shadows, skeleton bars not spinners, states for empty, loading, error, off, limit and success. `impeccable detect` on the CSS and JS: 0 findings after fixing a width transition and three off-scale radii. |
| `design-taste-frontend`, `hallmark`, `open-design`, `awesome-design-md`, `design-md`, `gsap-*`, `scroll-world`, `react-bits`, `canvas-ui`, `terminal-dark` | not used | This is an editor side panel inside a fixed direction (DESIGN.md), not a page, brand surface or animation. |
| `ui-ux-pro-max` | UNAVAILABLE | Dangling symlink (see above). Not used, not claimed. |
| `emil-design-eng` | UNAVAILABLE (empty) | Dangling symlink. Not used, not claimed. |

Verification actually run: `impeccable detect`, headless Chrome through the DevTools protocol (own browser, so it does not fight the shared pane) at 1280 px dark and light and at 390 px, with a horizontal-overflow and touch-target check.

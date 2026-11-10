---
version: alpha
name: The Light Table
description: A print studio's light table for a design product. A quiet paper-and-ink chrome around a neutral, colour-true stage. Production marks (crop marks, a ruled strip, swatch chips, a registration target) are the structural vocabulary. One cyan hue for selection, links and progress; everything else is ink on paper, except one warm yellow taken from the logo and kept for confirmations.
colors:
  primary: "#14181C"
  ground: "#ECEEF0"
  panel: "#FAFBFB"
  panel-sunk: "#F0F2F3"
  stage: "#D6D6D6"
  paper: "#FFFFFF"
  ink: "#14181C"
  ink-2: "#464E56"
  ink-3: "#5E6670"
  rule: "#D9DDE1"
  rule-strong: "#B7BDC4"
  edge: "#7F8890"
  accent: "#0A6A91"
  accent-ink: "#FFFFFF"
  accent-wash: "#DCEEF6"
  ok: "#1B6E3A"
  ok-wash: "#E3F2E8"
  warn: "#8A5400"
  warn-wash: "#FBEED5"
  bad: "#B3261E"
  bad-wash: "#FBE5E3"
  btn: "#14181C"
  btn-ink: "#FAFBFB"
  dark-ground: "#0E1012"
  dark-panel: "#16191C"
  dark-panel-sunk: "#101214"
  dark-stage: "#2A2A2A"
  dark-ink: "#ECEEF0"
  dark-ink-2: "#B6BCC3"
  dark-ink-3: "#939AA2"
  dark-rule: "#262B30"
  dark-rule-strong: "#3A4147"
  dark-edge: "#6E767F"
  dark-accent: "#5CC3EE"
  dark-accent-ink: "#04202C"
  dark-ok: "#5FCB85"
  dark-warn: "#E2AE4A"
  dark-bad: "#FF8A80"
  dark-ok-wash: "#12281A"
  dark-warn-wash: "#2B2210"
  dark-bad-wash: "#331816"
  dark-accent-wash: "#0F2B38"
  signal: "#F1C82D"
  signal-ink: "#14181C"
  signal-wash: "#FDF2C4"
  dark-signal-wash: "#2C2510"
  dark-btn: "#ECEEF0"
  dark-btn-ink: "#14181C"
typography:
  display:
    fontFamily: Bricolage Grotesque
    fontSize: 5.25rem
    fontWeight: 600
    lineHeight: 0.98
    letterSpacing: -0.035em
  h1:
    fontFamily: Bricolage Grotesque
    fontSize: 1.75rem
    fontWeight: 600
    lineHeight: 1.12
    letterSpacing: -0.02em
  h2:
    fontFamily: Bricolage Grotesque
    fontSize: 1.25rem
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: -0.015em
  h3:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 1rem
    fontWeight: 600
    lineHeight: 1.3
  body:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 0.875rem
    fontWeight: 400
    lineHeight: 1.5
  small:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 0.8125rem
    fontWeight: 400
    lineHeight: 1.5
  meta:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 0.75rem
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 0.8125rem
    fontWeight: 600
    lineHeight: 1.4
  mono:
    fontFamily: ui-monospace
    fontSize: 0.75rem
    fontWeight: 400
    lineHeight: 1.4
rounded:
  1: 3px
  2: 6px
  3: 10px
  full: 999px
spacing:
  1: 4px
  2: 8px
  3: 12px
  4: 16px
  5: 24px
  6: 32px
  7: 48px
  8: 72px
components:
  button-primary:
    backgroundColor: "{colors.btn}"
    textColor: "{colors.btn-ink}"
    typography: "{typography.label}"
    rounded: "{rounded.2}"
    height: 36px
    padding: 16px
  button-secondary:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.2}"
    height: 36px
    padding: 16px
  button-accent:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.accent-ink}"
    typography: "{typography.label}"
    rounded: "{rounded.2}"
    height: 36px
    padding: 16px
  button-primary-dark:
    backgroundColor: "{colors.dark-btn}"
    textColor: "{colors.dark-btn-ink}"
    typography: "{typography.label}"
    rounded: "{rounded.2}"
    height: 36px
    padding: 16px
  input:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.2}"
    height: 40px
    padding: 12px
  tag-ok:
    backgroundColor: "{colors.ok-wash}"
    textColor: "{colors.ok}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
  tag-warn:
    backgroundColor: "{colors.warn-wash}"
    textColor: "{colors.warn}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
  tag-bad:
    backgroundColor: "{colors.bad-wash}"
    textColor: "{colors.bad}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
  tag-accent:
    backgroundColor: "{colors.accent-wash}"
    textColor: "{colors.accent}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
  switch-off:
    backgroundColor: "{colors.panel-sunk}"
    textColor: "{colors.ink-2}"
    rounded: "{rounded.full}"
    width: 44px
    height: 24px
  switch-on:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.accent-ink}"
    rounded: "{rounded.full}"
    width: 44px
    height: 24px
  signal-seal:
    backgroundColor: "{colors.signal}"
    textColor: "{colors.signal-ink}"
    rounded: "{rounded.full}"
    width: 14px
    height: 14px
  milestone:
    backgroundColor: "{colors.signal-wash}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.2}"
    padding: 16px
  dark-milestone:
    backgroundColor: "{colors.dark-signal-wash}"
    textColor: "{colors.dark-ink}"
    typography: "{typography.body}"
    rounded: "{rounded.2}"
    padding: 16px
  sheet:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
  rail:
    backgroundColor: "{colors.ground}"
    textColor: "{colors.ink-2}"
    typography: "{typography.body}"
  well:
    backgroundColor: "{colors.panel-sunk}"
    textColor: "{colors.ink-2}"
    typography: "{typography.small}"
    rounded: "{rounded.2}"
  stage:
    backgroundColor: "{colors.stage}"
    textColor: "{colors.ink}"
  design-page:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
  caption:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink-3}"
    typography: "{typography.meta}"
  rule-line:
    backgroundColor: "{colors.rule}"
    height: 1px
  rule-line-strong:
    backgroundColor: "{colors.rule-strong}"
    height: 1px
  control-edge:
    backgroundColor: "{colors.edge}"
    height: 1px
  dark-sheet:
    backgroundColor: "{colors.dark-panel}"
    textColor: "{colors.dark-ink}"
    typography: "{typography.body}"
  dark-rail:
    backgroundColor: "{colors.dark-ground}"
    textColor: "{colors.dark-ink-2}"
    typography: "{typography.body}"
  dark-well:
    backgroundColor: "{colors.dark-panel-sunk}"
    textColor: "{colors.dark-ink-2}"
    typography: "{typography.small}"
    rounded: "{rounded.2}"
  dark-stage:
    backgroundColor: "{colors.dark-stage}"
    textColor: "{colors.dark-ink}"
  dark-caption:
    backgroundColor: "{colors.dark-panel}"
    textColor: "{colors.dark-ink-3}"
    typography: "{typography.meta}"
  dark-button-accent:
    backgroundColor: "{colors.dark-accent}"
    textColor: "{colors.dark-accent-ink}"
    typography: "{typography.label}"
    rounded: "{rounded.2}"
    height: 36px
    padding: 16px
  dark-rule-line:
    backgroundColor: "{colors.dark-rule}"
    height: 1px
  dark-rule-line-strong:
    backgroundColor: "{colors.dark-rule-strong}"
    height: 1px
  dark-control-edge:
    backgroundColor: "{colors.dark-edge}"
    height: 1px
  dark-tag-ok:
    backgroundColor: "{colors.dark-ok-wash}"
    textColor: "{colors.dark-ok}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
  dark-tag-warn:
    backgroundColor: "{colors.dark-warn-wash}"
    textColor: "{colors.dark-warn}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
  dark-tag-bad:
    backgroundColor: "{colors.dark-bad-wash}"
    textColor: "{colors.dark-bad}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
  dark-tag-accent:
    backgroundColor: "{colors.dark-accent-wash}"
    textColor: "{colors.dark-accent}"
    typography: "{typography.meta}"
    rounded: "{rounded.1}"
    padding: 8px
---

## Overview

The Light Table is a print studio's backlit table. The work is a sheet under glass, judged against a neutral grey that adds no tint of its own. Everything that is not the work stays quiet: paper-and-ink chrome, one cyan hue, no gradients, no glow, no decorative shadow.

The one idea the interface keeps returning to is the proof. A design is always drawn at its true proportions (never a fake thumbnail), a colour is a swatch chip, and the edges of things carry the marks a printer would put there: crop marks at the trim corners of a design, a ruled strip with ticks under a page title, a registration target on an empty state. These marks are structure, not wallpaper. Each appears where it says something (a trim edge, a measured strip, "nothing registered here yet") and nowhere else.

Two modes serve one product. In the signed-in shell the visitor is working, so the product behaves like a product: a labelled rail, a flush sheet, standard controls, standard list and form patterns. On the public landing page the visitor is deciding, so the same marks lead: the hero is a table with a proof frame that redraws at the true proportions of whichever format is picked, and what sits in the frame is a real library template drawn by the editor's own renderer, with a swatch strip of that drawing's own colours beneath it. The brand section shows one real template before and after an example kit, side by side on the stage. Nothing on the page is a mock-up built from boxes.

What it is not: a pink-on-black dashboard, a warm cream page with a serif, a near-black screen with one acid accent, a broadsheet of hairlines with zero radius, four equal metric tiles, or floating rounded cards on a gutter.

## Colors

The palette is cool, near-neutral paper and ink with exactly one working hue (cyan), plus a single warm signal taken from the logo. The default is light ("paper"); dark is a real second theme, chosen with `data-theme` on `<html>` (stored choice) or by `prefers-color-scheme` when nothing is stored.

- **Ground (#ECEEF0)** and **Panel (#FAFBFB)**: the rail sits on the ground, the work sits on one flush panel sheet a tone step lighter. Depth is a tone step, never a shadow.
- **Panel-sunk (#F0F2F3)**: wells, ruled strips and list cards.
- **Stage (#D6D6D6)**: the canvas surround. A pure neutral grey in both themes (#2A2A2A in dark) so a design's colours are judged true. The design page itself is **Paper (#FFFFFF)** until the design says otherwise, in both themes.
- **Ink (#14181C), Ink-2 (#464E56), Ink-3 (#5E6670)**: text. Each is at least 4.5:1 on ground, panel and panel-sunk. Ink-3 is the quietest text and the floor.
- **Edge (#7F8890)**: the boundary of a control (input, outlined button, chip). 3:1 or better on every surface. Rule and rule-strong are decoration only.
- **Accent (#0A6A91, process cyan)**: selection, focus, links, progress, the current item. It is not the primary button. Dark theme uses #5CC3EE.
- **Status**: ok, warn and bad each have a text colour and a wash. All pairs are at least 4.5:1.
- **Primary button** is ink on paper (and paper on ink in dark), not the accent.
- **Signal (#F1C82D, the yellow of the logo)**: a deliberate accent for the moments that matter and nowhere else. The focus ring of a primary action (a yellow gap between the button and a full-contrast ink outline), the seal on a success message and on "Approved", and the one-time message for a first design, first file and first approval (on **Signal-wash** #FDF2C4, #2C2510 in dark). It is always a filled shape with ink on it, never text and never a boundary on paper: ink on signal 11.1, ink on signal-wash 15.9, ink-2 on signal-wash 7.5, ink-3 on signal-wash 5.2, and in dark the signal on panel 11.0, light ink on signal-wash 13.1, ink-2 on it 8.0. Signal on paper alone is only 1.55, which is why it is never used as the only edge.

Contrast, measured numerically against every surface the token sits on (light, then dark): ink 17.2 / 15.9 / 15.3 and 15.2 / 16.1 / 16.4; ink-2 8.2 / 7.5 / 7.3 and 9.2 / 9.8 / 10.0; ink-3 5.6 / 5.2 / 5.0 and 6.2 / 6.6 / 6.7 (panel / sunk / ground); accent 5.8 / 5.4 / 5.2 and 8.8 / 9.4 / 9.5; the control edge 3.5 / 3.2 / 3.1 and 3.8 / 4.1 / 4.1; accent on the stage 4.15 (UI use only) and 7.2. White on accent is 6.0, accent-ink on dark accent 8.4, button text on button 17.2 and 15.3.

## Typography

Two families, self-hosted as latin-subset woff2 in `public/fonts/ui/` with `font-display: swap`, and neither is used by another Dot platform.

- **Bricolage Grotesque** (display): page titles, section titles, the landing headline. Weight 600, tight tracking (-0.015em to -0.035em), balanced wrapping.
- **Atkinson Hyperlegible Next** (UI and body): everything else. Drawn by the Braille Institute for legibility, with deliberately distinct letterforms (a clear I, l and 1, an open 0 and O), which suits a product whose users are checking prices, phone numbers and dates in the work they are about to print. 14px is the working size in the app, 16px or more for reading on the landing page. Figures use tabular numerals wherever they align.
- **System monospace** only for key caps and exact values (a hex code, a dimension readout). No monospace as decoration.

Labels, table heads and tags are sentence case at a readable size, never tracked capitals. No eyebrows above headings. One h1 per page.

## Layout

A 4px base. Spacing scale 4, 8, 12, 16, 24, 32, 48, 72. On desktop the shell is a 15rem labelled rail and one flush sheet; page content is capped at 80rem and centred with a 24px gutter (16px on phones). Section rhythm is 48px between sections inside the app and 72px or more on the landing page; tight groups, generous separation, more space above a heading than below it.

Below 56rem the rail is replaced by a slim top bar and a bottom tab bar (Home, Designs, Templates, Brand and a More sheet). Touch targets are 44px on coarse pointers and phones. Nothing scrolls the page horizontally at 390, 768 or 1440; wide things (the format strip, tables) scroll inside their own region.

The signature layout move is the contact strip: recent designs shown on neutral stage tiles, each frame at its true aspect ratio, name and size beneath, with crop marks that open slightly on hover.

## Elevation & Depth

Surfaces are separated by tone steps and, sparingly, a 1px edge. Nothing at rest has a shadow except a design page on the stage (a hairline plus a soft contact shadow, because it is a sheet of paper on a table). Floating things (menus, dialogs, toasts) carry one real shadow with an offset and a soft blur. No glows, no glass, no blur used as decoration.

## Shapes

Radii are small and decided by role: 3px for keys, tags and swatch chips, 6px for controls, 10px for menus and dialogs, full only for filter chips. Design pages and the stage are square-cornered because paper is. There are no rounded floating cards.

Production marks are the vocabulary: crop marks (four L ticks outside a trim corner), a ruled strip (a 1px rule with ticks every 8px and a longer tick every 40px), a swatch chip (a colour, font or format as one small unit), a registration target (an empty state's mark). Icons are one inline-SVG set in a single 1.75px stroke (Lucide, ISC, plus a few drawn for this product).

## Components

Controls share one grammar: a visible focus ring (2px accent, offset), a 120ms colour transition, a one-pixel press, and a busy state (aria-busy plus a spinner) that also blocks a second press.

- **Primary button**: ink fill, paper text, 6px radius, 36px high (44px on touch). One per view. Its keyboard focus is the signal: a yellow ring in the gap, then an ink outline.
- **Switch, checkbox, radio**: drawn by the system (`controls.css`), never the browser's. A switch is a 44 by 24 track with a thumb: off is an outlined sunk track with a dark thumb on the left, on is an accent track with a light thumb on the right carrying a tick, so the state never depends on colour alone. Checkboxes are 18px squares and radios round, filled with the accent and a tick or dot when chosen, a dash when mixed. All have the 2px focus ring and map to system colours in forced-colours mode.
- **First-time message**: one calm line on a signal-wash ground with a yellow seal and a "Got it" button. The crop marks of the proof sheet close in around it once; with reduced motion it is simply there. It appears once per person (the server remembers) and is read from real work: a design made, a file downloaded, a design approved. It is never a score, a streak or a prompt to do more.
- **Secondary**: panel fill, 1px edge. **Ghost**: no fill. **Accent** and **danger** exist for rare cases; destructive actions always confirm in a dialog.
- **Fields**: 40px, panel fill, 1px edge, 3px focus halo in the accent, error text in the bad colour below the field and tied to it with aria-describedby.
- **Chips** filter and toggle. **Format chips** draw the true proportion of a format above its name and size. **Tags** label status and kind in sentence case.
- **Menu, dialog, tabs, tooltip, toast, progress, status line, empty state, page header, stat line, avatar, kbd** exist as `<x-ds.*>` Blade components; the dialog is the native `<dialog>` element and the menu is keyboard-complete (arrows, Home, End, Escape, focus return).
- **Empty states** name what belongs here and offer the one next step, with a drawn registration mark. **Loading** is a thin progress bar for navigations and an aria-busy button for submits. **Success** arrives as a toast that stays readable without script. **Errors** name the problem and the recovery.
- **Page header**: the h1 sits on the ruled strip with the page's primary action at the right.

Motion is short and exponential-out (120, 200 and 320ms), one authored moment (a page arrives with a 6px rise) plus feedback on controls. Everything sits behind `prefers-reduced-motion`.

## Do's and Don'ts

- Do draw designs at their true aspect ratio. Don't draw a fake thumbnail; until real previews exist show a neutral frame.
- Do keep colour for the work, selection and status. Don't tint the chrome.
- Do keep the signal yellow for confirmations and the focus of a primary action, and fill it with ink on top. Don't use it as text, as the only edge of a control, as a background wash for a whole page, or for decoration.
- Do use ink for the primary action. Don't use the accent as a button fill by default.
- Do keep the stage a pure neutral. Don't tint it, and don't put a design page on anything but paper.
- Do use crop marks, the ruled strip and swatch chips where they say something. Don't scatter them as decoration.
- Do write visible text without em or en dashes and without filler verbs. Don't invent customers, metrics or testimonials.
- Don't use gradient text, radial blooms, glass panels, side-stripe card borders, four equal metric tiles, or icons in tinted squares.
- Don't load fonts, icons, scripts or styles from any external host. The app works offline.

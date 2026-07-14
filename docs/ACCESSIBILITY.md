# Accessibility Improvements

> WCAG 2.2 AA compliance guide for Dot.Design. Each section is independently actionable.

---

## 1. Baseline Standards

Target: **WCAG 2.2 Level AA** for all public-facing and authenticated views.

Test with:
- [axe DevTools](https://www.deque.com/axe/) browser extension (automated)
- Keyboard-only navigation (manual)
- macOS VoiceOver / NVDA on Windows (screen reader)
- [Colour Contrast Analyser](https://www.tpgi.com/color-contrast-checker/) for colour ratios

---

## 2. Colour Contrast (WCAG 1.4.3)

Minimum contrast ratios:
- Normal text (< 18pt): **4.5:1**
- Large text (≥ 18pt or 14pt bold): **3:1**
- UI components (borders, icons): **3:1**

**Current brand colour audit:**

| Combination | Ratio | Pass? |
|---|---|---|
| `brand-500 (#4f6ef7)` on white | ~3.1:1 | ✗ — Fix: darken to `#3b54e0` (brand-600) for text |
| `ink-muted (#6b7280)` on white | ~4.6:1 | ✓ |
| `ink-subtle (#9ca3af)` on white | ~2.9:1 | ✗ — Do not use for body text; icons only with a label |
| White on `brand-500` | ~3.1:1 | ✗ — Use `brand-600` or darker for button backgrounds with white text |

**Fix in `tailwind.config.js`:**
```js
colors: {
  brand: {
    500: '#3b54e0',   // was #4f6ef7 — now passes 4.5:1 on white
    600: '#2c3ecc',
  }
}
```

---

## 3. Keyboard Navigation (WCAG 2.1.1)

### 3a. All interactive elements must be keyboard-reachable

Verify with Tab key. Every button, link, input, and select must receive focus in logical document order.

### 3b. Focus visible (WCAG 2.4.11 — AA in 2.2)

Never remove the focus ring with `outline: none` without providing an equivalent `focus-visible` style.

```css
/* resources/css/app.css */
*:focus-visible {
  outline: 2px solid theme('colors.brand.500');
  outline-offset: 2px;
}

/* Remove default focus for mouse users only */
*:focus:not(:focus-visible) {
  outline: none;
}
```

In Tailwind:
```html
<button class="... focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
```

### 3c. Modal focus trap

When a modal is open, focus must be trapped inside it. Alpine.js `x-trap` directive (from the `@alpinejs/focus` plugin) handles this:

```bash
npm install @alpinejs/focus
```

```js
// resources/js/app.js
import Focus from '@alpinejs/focus';
Alpine.plugin(Focus);
```

```html
<div x-dialog x-trap.noscroll="open" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  ...
</div>
```

### 3d. Skip navigation link

Add as the very first element in `<body>`:

```html
<a
  href="#main-content"
  class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-brand-500 focus:text-white focus:rounded-card"
>
  Skip to main content
</a>
```

---

## 4. Semantic HTML

### 4a. Landmark regions

Every page must use semantic landmark elements:

```html
<header>       <!-- site-wide header / top bar -->
<nav>          <!-- navigation menu -->
<main id="main-content">  <!-- primary page content -->
<aside>        <!-- sidebar panels -->
<footer>       <!-- page footer -->
```

### 4b. Heading hierarchy

Never skip heading levels. Every page has exactly one `<h1>` (the page title).

```
h1: Dashboard
  h2: Recent Projects
    h3: [project card title — only if needed]
  h2: Assets
  h2: AI Activity
```

### 4c. Button vs. anchor

- Use `<a href="...">` for navigation (changes the URL).
- Use `<button>` for actions (opens modal, submits form, triggers JS).
- Never use `<div>` or `<span>` as interactive elements without `role`, `tabindex`, and keyboard handlers.

---

## 5. Forms (WCAG 1.3.1, 3.3.2)

Every form input must have an associated `<label>`:

```html
<!-- Correct -->
<label for="project-name" class="label">Project Name</label>
<input id="project-name" type="text" name="name" class="..." />

<!-- Correct (visually hidden label for icon-only inputs) -->
<label for="search" class="sr-only">Search projects</label>
<input id="search" type="search" name="search" placeholder="Search..." />

<!-- WRONG — placeholder is not a label -->
<input type="text" placeholder="Project Name" />
```

Group related fields with `<fieldset>` and `<legend>`:

```html
<fieldset>
  <legend class="label">Canvas dimensions</legend>
  <label for="width">Width</label>
  <input id="width" type="number" name="width" />
  <label for="height">Height</label>
  <input id="height" type="number" name="height" />
</fieldset>
```

---

## 6. Images (WCAG 1.1.1)

### 6a. Meaningful images

```html
<img src="{{ $asset->thumbnailUrl() }}" alt="{{ $asset->name }}" />
```

### 6b. Decorative images

```html
<img src="/images/empty-state.svg" alt="" role="presentation" />
```

### 6c. AI-generated images

Add a descriptive `alt` attribute derived from the user's prompt:

```php
// In the canvas element data:
'alt' => Str::limit($log->prompt, 100)
```

---

## 7. Colour Alone (WCAG 1.4.1)

Never use colour as the only visual differentiator.

| Context | Bad | Good |
|---|---|---|
| Error field | Red border only | Red border + error icon + error message text |
| Active nav item | Blue text only | Blue text + left border + bold weight |
| AI provider badge | Coloured dot only | Coloured dot + provider name label |
| Status indicator | Green/red dot | Green/red dot + "Active" / "Failed" text |

---

## 8. Canvas Editor Accessibility

The Fabric.js canvas (`<canvas>`) element is not accessible to screen readers by default. Implement a parallel accessible tree:

```html
<div role="application" aria-label="Design canvas" aria-describedby="canvas-instructions">
  <p id="canvas-instructions" class="sr-only">
    Use Tab to navigate elements, arrow keys to move, Delete to remove, and Enter to edit text.
  </p>

  <canvas id="design-canvas"></canvas>

  <!-- Accessible element list — updated via JS as canvas changes -->
  <ul aria-label="Canvas elements" class="sr-only" aria-live="polite">
    <template x-for="el in layers">
      <li>
        <button @click="selectElement(el.id)" :aria-pressed="el.selected">
          <span x-text="el.label"></span>
          — at position <span x-text="`${Math.round(el.left)}, ${Math.round(el.top)}`"></span>
        </button>
      </li>
    </template>
  </ul>

  <!-- Live region for selected element state -->
  <div aria-live="polite" aria-atomic="true" class="sr-only" x-text="selectedElementDescription"></div>
</div>
```

---

## 9. Reduced Motion (WCAG 2.3.3 — AAA, but good practice)

Respect the user's OS preference for reduced motion:

```css
/* resources/css/app.css */
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
```

In Tailwind, use `motion-safe:` and `motion-reduce:` variants:

```html
<div class="transition-all duration-300 motion-reduce:transition-none">
```

---

## 10. ARIA Live Regions

Use `aria-live` for dynamic content that changes without a page reload:

```html
<!-- Toast notifications -->
<div role="status" aria-live="polite" aria-atomic="true">
  <!-- Toast messages injected here by Alpine.js -->
</div>

<!-- Error messages -->
<div role="alert" aria-live="assertive">
  <!-- Urgent error messages -->
</div>

<!-- Auto-save status -->
<span aria-live="polite" x-text="saving ? 'Saving...' : (saved ? 'Saved' : '')"></span>
```

---

## 11. Testing Checklist

Before each release, verify:

- [ ] Tab through the entire page — every interactive element receives visible focus
- [ ] All form inputs have associated labels
- [ ] All images have `alt` attributes (or `alt=""` for decorative)
- [ ] No colour contrast failures (run axe scan)
- [ ] All modals trap focus and return focus to the trigger on close
- [ ] Page has a single `<h1>` and no skipped heading levels
- [ ] Skip-to-content link appears on first Tab press
- [ ] `aria-live` regions announce dynamic changes
- [ ] Canvas accessible list reflects current elements

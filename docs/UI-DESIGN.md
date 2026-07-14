# UI Design Improvements

> Actionable visual design upgrades for Dot.Design. Each section is independent — pick and implement in any order.

---

## 1. Design Token System

**Problem:** Tailwind utility classes scattered across views make global restyling fragile.

**Fix:** Define a single source of truth in `tailwind.config.js`.

```js
// tailwind.config.js
theme: {
  extend: {
    colors: {
      brand: {
        50:  '#f0f4ff',
        100: '#dce6fe',
        500: '#4f6ef7',   // primary action
        600: '#3b54e0',
        900: '#1a2463',
      },
      surface: {
        DEFAULT: '#ffffff',
        raised: '#f8f9fc',
        overlay: '#f1f3f9',
      },
      ink: {
        DEFAULT: '#111827',
        muted:   '#6b7280',
        subtle:  '#9ca3af',
      },
    },
    fontFamily: {
      sans: ['Figtree', 'ui-sans-serif', 'system-ui'],
      mono: ['JetBrains Mono', 'ui-monospace'],
    },
    borderRadius: {
      canvas: '0.75rem',
      card:   '0.625rem',
      chip:   '9999px',
    },
    boxShadow: {
      card:   '0 1px 4px 0 rgb(0 0 0 / 0.06), 0 4px 16px 0 rgb(0 0 0 / 0.04)',
      canvas: '0 8px 32px 0 rgb(0 0 0 / 0.12)',
      float:  '0 16px 48px 0 rgb(0 0 0 / 0.18)',
    },
  },
}
```

---

## 2. Dark Mode

**Problem:** No dark mode support. Design tools are typically used in low-light environments.

**Steps:**
1. Add `darkMode: 'class'` to `tailwind.config.js`.
2. Toggle class `dark` on `<html>` via Alpine.js and persist to `localStorage`.
3. Add `dark:` variants to every layout component.

```html
<!-- resources/views/layouts/app.blade.php — theme toggle button -->
<button
  x-data
  @click="
    let d = document.documentElement;
    d.classList.toggle('dark');
    localStorage.setItem('theme', d.classList.contains('dark') ? 'dark' : 'light');
  "
  class="p-2 rounded-lg text-ink-muted hover:bg-surface-raised dark:hover:bg-gray-700"
  aria-label="Toggle dark mode"
>
  <svg class="w-5 h-5 dark:hidden" ...><!-- sun icon --></svg>
  <svg class="w-5 h-5 hidden dark:block" ...><!-- moon icon --></svg>
</button>
```

```js
// resources/js/app.js — apply saved preference before first paint
(function () {
  const saved = localStorage.getItem('theme');
  if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
  }
})();
```

---

## 3. Component Visual Hierarchy

**Problem:** The dashboard currently uses a flat card grid with no clear visual weight hierarchy.

**Improvements:**

| Element | Current | Target |
|---|---|---|
| KPI cards | Plain white boxes | Coloured left-border accent per metric type |
| Project thumbnails | No image | Canvas preview screenshot via `spatie/browsershot` or stored PNG |
| Empty states | None defined | Illustrated empty state with CTA per section |
| Action buttons | Mix of styles | Strict 3-level system: Primary / Ghost / Danger |

**Button system:**

```html
<!-- Primary -->
<button class="inline-flex items-center gap-2 px-4 py-2 rounded-card bg-brand-500 text-white font-medium text-sm hover:bg-brand-600 transition-colors focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
  New Project
</button>

<!-- Ghost -->
<button class="inline-flex items-center gap-2 px-4 py-2 rounded-card border border-gray-200 text-ink font-medium text-sm hover:bg-surface-raised transition-colors">
  View All
</button>

<!-- Danger -->
<button class="inline-flex items-center gap-2 px-4 py-2 rounded-card bg-red-500 text-white font-medium text-sm hover:bg-red-600 transition-colors">
  Delete
</button>
```

---

## 4. Typography Scale

**Problem:** No enforced type scale — headings and body copy sizes are inconsistent across views.

**Add to `tailwind.config.js`:**

```js
fontSize: {
  'display': ['2.25rem', { lineHeight: '2.5rem', fontWeight: '700', letterSpacing: '-0.02em' }],
  'title-lg': ['1.5rem',  { lineHeight: '2rem',  fontWeight: '600', letterSpacing: '-0.01em' }],
  'title':    ['1.25rem', { lineHeight: '1.75rem',fontWeight: '600' }],
  'label':    ['0.875rem',{ lineHeight: '1.25rem',fontWeight: '500' }],
  'caption':  ['0.75rem', { lineHeight: '1rem',  fontWeight: '400' }],
}
```

**Apply via Blade component `<x-heading level="h2" size="title">`** to enforce usage.

---

## 5. Icon System

**Problem:** No icon library is defined — ad-hoc inline SVGs lead to inconsistency.

**Recommendation:** Install [Blade Icons](https://blade-ui-kit.com/blade-icons) with the Heroicons pack.

```bash
composer require blade-ui-kit/blade-icons
composer require blade-heroicons/blade-heroicons
```

```html
<!-- Usage -->
<x-heroicon-o-plus class="w-4 h-4" />
<x-heroicon-s-folder class="w-5 h-5 text-brand-500" />
```

Define a size convention: `w-4 h-4` for inline, `w-5 h-5` for buttons, `w-6 h-6` for nav items.

---

## 6. Sidebar & Navigation

**Problem:** Navigation menu inherits Jetstream defaults without branding.

**Improvements:**
- Pin logo to top-left with a `max-w-[140px]` constraint.
- Group nav items under collapsible sections: **Create**, **Library**, **Team**, **Settings**.
- Add active-route highlight with `brand-50` background and `brand-500` left border.
- Show user avatar + name + team switcher at the bottom of the sidebar (not top-right).

```html
<!-- Sidebar nav item with active state -->
<a
  href="{{ route('dashboard') }}"
  @class([
    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
    'bg-brand-50 text-brand-600 border-l-2 border-brand-500 pl-[10px]' => request()->routeIs('dashboard'),
    'text-ink-muted hover:bg-surface-raised hover:text-ink' => !request()->routeIs('dashboard'),
  ])
>
  <x-heroicon-o-squares-2x2 class="w-5 h-5 shrink-0" />
  Dashboard
</a>
```

---

## 7. Loading & Skeleton States

**Problem:** Livewire page transitions show no feedback during wire:loading.

**Add global loading indicator:**

```html
<!-- resources/views/layouts/app.blade.php -->
<div
  wire:loading.delay
  class="fixed top-0 inset-x-0 h-0.5 bg-brand-500 animate-pulse z-50"
></div>
```

**Add skeleton cards for project grid:**

```html
<!-- resources/views/components/skeleton-card.blade.php -->
<div class="rounded-canvas bg-surface-raised animate-pulse">
  <div class="aspect-[4/3] bg-gray-200 rounded-t-canvas"></div>
  <div class="p-4 space-y-2">
    <div class="h-4 bg-gray-200 rounded w-3/4"></div>
    <div class="h-3 bg-gray-200 rounded w-1/2"></div>
  </div>
</div>
```

---

## 8. Responsive Breakpoints

**Problem:** Dashboard layout not tested below `lg` (1024px).

| Breakpoint | Target behaviour |
|---|---|
| `< sm` (< 640px) | Sidebar hidden, hamburger menu, single-column grid |
| `sm–md` (640–1024px) | Collapsed icon-only sidebar, 2-column grid |
| `md–lg` (1024–1280px) | Full sidebar, 3-column grid |
| `≥ xl` (≥ 1280px) | Full sidebar, 4-column grid |

**Add to dashboard grid:**

```html
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
```

---

## 9. Colour-Blind Safe Palette

Ensure all status colours pass the WCAG 1.4.1 (Use of Color) criterion — never rely on colour alone.

| Status | Safe colour | Icon | Pattern |
|---|---|---|---|
| Success | `green-600` | checkmark | ✓ |
| Warning | `amber-600` | exclamation | ⚠ |
| Error | `red-600` | x-circle | ✗ |
| Info | `blue-600` | info | ℹ |

Always pair colour with an icon and/or text label.

---

## 10. Print / Export Preview

When users export designs, show a modal preview with:
- Accurate canvas dimensions rendered at 1:1 CSS pixels
- Export format selector (PNG, JPEG, SVG, PDF)
- DPI selector (72 / 150 / 300)
- File size estimate

This sets expectations before the download begins.

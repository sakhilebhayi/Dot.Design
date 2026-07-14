# UX Improvements

> User-experience upgrades for Dot.Design. Each section addresses a distinct workflow — implement independently.

---

## 1. Onboarding Flow

**Problem:** New users land on the dashboard with no guidance. The blank state gives no indication of where to start.

**Solution: 3-step onboarding wizard** (Livewire component, shown once per user).

| Step | Content |
|---|---|
| 1. Welcome | Brand intro, key feature highlights (30-second animated overview) |
| 2. Create first project | Inline form: project name, type, dimensions — creates `DesignProject` immediately |
| 3. Choose a template | Show 6 starter templates from `DesignAsset` where `type = 'template'` |

**Implementation sketch:**

```php
// app/Livewire/Onboarding/OnboardingWizard.php
class OnboardingWizard extends Component
{
    public int $step = 1;
    public string $projectName = '';
    public string $projectType = 'social';

    public function nextStep(): void
    {
        $this->step++;
    }

    public function createProject(): void
    {
        DesignProject::create([
            'user_id' => auth()->id(),
            'name'    => $this->projectName,
            'type'    => $this->projectType,
        ]);
        $this->nextStep();
    }
}
```

Track completion with a `onboarding_completed_at` timestamp on the `users` table.

---

## 2. Dashboard UX

**Problem:** The dashboard shows raw data (counts) but doesn't help users take the next action.

**Priority improvements:**

### 2a. Contextual empty states
Replace blank zero-count cards with actionable prompts:

```html
<!-- No projects empty state -->
<div class="flex flex-col items-center gap-4 py-16 text-center">
  <img src="/images/empty-canvas.svg" alt="" class="w-32 h-32 opacity-60" />
  <h3 class="text-title font-semibold text-ink">No projects yet</h3>
  <p class="text-ink-muted text-sm max-w-xs">
    Start by creating a blank canvas or picking a template.
  </p>
  <div class="flex gap-3">
    <x-button href="{{ route('projects.create') }}">New Project</x-button>
    <x-button variant="ghost" href="{{ route('templates.index') }}">Browse Templates</x-button>
  </div>
</div>
```

### 2b. "Continue where you left off" section
Surface the last-edited canvas as a hero CTA at the top of the dashboard, above the stats grid.

### 2c. Quick-action bar
Row of 4 icon buttons below the top bar: **New Project**, **Upload Asset**, **Browse Templates**, **Invite Team Member**.

---

## 3. Canvas Editor UX

**Problem:** The canvas editor does not yet exist as a Livewire component — only the data models are in place. The following defines the expected UX before implementation begins.

### 3a. Toolbar layout

```
┌──────────────────────────────────────────────────────────────┐
│  ← Back   [Project Name]          [Undo] [Redo]   [Export ▾] │
├──────────────────────────────────────────────────────────────┤
│  [Select] [Text] [Shape] [Image] [AI ✦] [Pen]                │
├──────┬───────────────────────────────────────┬───────────────┤
│      │                                       │               │
│ Lyr  │           C A N V A S                │   Properties  │
│ ers  │                                       │   Panel       │
│      │                                       │               │
└──────┴───────────────────────────────────────┴───────────────┘
```

### 3b. Keyboard shortcuts

| Shortcut | Action |
|---|---|
| `Ctrl/⌘ + Z` | Undo |
| `Ctrl/⌘ + Shift + Z` | Redo |
| `Ctrl/⌘ + D` | Duplicate selected element |
| `Ctrl/⌘ + G` | Group elements |
| `Delete / Backspace` | Delete selected element |
| `Ctrl/⌘ + A` | Select all |
| `Ctrl/⌘ + C / V` | Copy / Paste |
| `Space + drag` | Pan canvas |
| `Ctrl/⌘ + scroll` | Zoom in/out |
| `Escape` | Deselect / close panel |

### 3c. Auto-save
Save `elements` JSON to `design_canvases` every 10 seconds when the canvas is dirty, and on every element drop. Show a subtle "Saved" indicator that fades out after 2 seconds.

```js
// Debounced auto-save via Alpine.js
autoSave: Alpine.debounce(function () {
    this.$wire.saveCanvas(this.elements);
    this.saved = true;
    setTimeout(() => this.saved = false, 2000);
}, 10000)
```

### 3d. Undo / redo stack
Maintain an in-memory stack of `elements` snapshots (max 50 states) in Alpine.js state before persisting.

---

## 4. Template Discovery

**Problem:** Users can't browse or preview templates before committing to one.

**Solution:**
- `/templates` — filterable gallery: All / Social / Print / Presentation / Email / Custom
- Hover a template card → show full-resolution preview in a modal
- "Use this template" duplicates the asset into a new `DesignProject` for the current user
- Show a "Trending" and "New" badge based on `created_at` and a future `use_count` counter

---

## 5. Asset Library UX

**Problem:** `DesignAsset` model exists but there is no UI for browsing or managing assets.

**Features needed:**

| Feature | Priority |
|---|---|
| Drag-to-upload (multiple files) | High |
| Type filter: Images / Icons / Fonts / Templates | High |
| Search by name | High |
| Rename in-place (double-click) | Medium |
| Bulk delete with confirmation | Medium |
| Sort by: Date / Name / Size | Medium |
| Preview pane on click | Low |
| Folder / collection grouping | Low |

**Upload UX:**
Show an `<input type="file" multiple>` styled as a dashed drop zone. On drop, show per-file progress bars with filename + file size. Validate MIME type client-side before upload.

---

## 6. Notifications & Feedback

**Problem:** No toast notification system. User actions (save, export, invite sent) give no visual feedback.

**Recommendation:** Use a simple Alpine.js toast stack.

```html
<!-- resources/views/components/toast-stack.blade.php -->
<div
  x-data="toastStack()"
  @toast.window="add($event.detail)"
  class="fixed bottom-6 right-6 z-50 flex flex-col gap-2"
>
  <template x-for="toast in toasts" :key="toast.id">
    <div
      x-show="toast.visible"
      x-transition:enter="transition ease-out duration-200"
      x-transition:enter-start="opacity-0 translate-y-2"
      x-transition:enter-end="opacity-100 translate-y-0"
      x-transition:leave="transition ease-in duration-150"
      x-transition:leave-end="opacity-0"
      :class="{
        'bg-green-600': toast.type === 'success',
        'bg-red-600':   toast.type === 'error',
        'bg-gray-800':  toast.type === 'info',
      }"
      class="flex items-center gap-3 px-4 py-3 rounded-card text-white text-sm shadow-float min-w-[240px]"
    >
      <span x-text="toast.message"></span>
      <button @click="remove(toast.id)" class="ml-auto opacity-70 hover:opacity-100">✕</button>
    </div>
  </template>
</div>
```

Dispatch from Livewire: `$this->dispatch('toast', ['type' => 'success', 'message' => 'Project saved.']);`

---

## 7. Team Collaboration UX

**Problem:** Jetstream provides team management but there is no design-specific collaboration context.

**Improvements:**

- **Shared projects** — toggle a `DesignProject` between `private` and `team` visibility.
- **Presence indicators** — show team member avatars on a canvas that others are currently editing (via Laravel Reverb presence channels).
- **Comments** — attach a `DesignComment` to canvas coordinates; show as floating pins.
- **Activity feed** — sidebar panel listing recent team actions (created, edited, exported).
- **Role-gated actions** — only team Owner/Editor can export or delete; Viewers get read-only canvas.

---

## 8. Search & Discoverability

**Problem:** No global search. Users with many projects cannot find assets quickly.

**Implementation:**
- Global `Ctrl/⌘ + K` command palette (Alpine.js, client-side filter).
- Searches: Projects, Assets, Templates, Team Members.
- Show recent items by default; filter as user types.
- Deep-link results (`/projects/{id}`, `/assets/{id}`).

Back-end: wire up `DesignProject` and `DesignAsset` to Laravel Scout + Meilisearch (already in tech stack).

---

## 9. Export Experience

**Problem:** Export is a critical user action but there is no defined UX for it.

**Export flow:**
1. User clicks **Export** button in canvas toolbar.
2. Modal opens with format tabs: PNG | JPEG | SVG | PDF.
3. User selects resolution (1x / 2x / 4x) and quality (for JPEG).
4. Real-time preview of approximate file size.
5. Click **Download** → queue a `ExportDesignJob` (avoids blocking the UI for large canvases).
6. When job completes, toast notification with download link (valid for 1 hour via signed URL).

---

## 10. Mobile / Touch Experience

**Problem:** Canvas editing on touch devices is currently undefined.

**Minimum viable touch support:**
- Pinch-to-zoom on canvas
- Two-finger pan
- Long-press to show context menu (delete, duplicate, bring forward)
- Simplified toolbar for `< md` screens (hide advanced tools, show essential ones)

Defer full mobile editing to a later phase; ensure the dashboard and asset library are fully usable on mobile now.

---

## 11. Error States

Define and implement all error scenarios with recovery paths:

| Scenario | Current | Target |
|---|---|---|
| API timeout during AI generation | Blank / unresponsive | "Generation timed out. Try again." with retry button |
| Upload fails | Silent | Per-file error with reason (size, type, server error) |
| Canvas save fails | Silent | Banner: "Changes couldn't be saved. Reconnect or download backup." |
| Session expired mid-edit | Redirect to login, canvas lost | Prompt: "Your session expired. Save your work before logging in again." |
| 404 project | Generic Laravel 404 | Branded page: "This project doesn't exist or you don't have access." |

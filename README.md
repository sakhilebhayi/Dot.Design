<div align="center">

<img src="public/images/logo.png" alt="Dot.Design" width="220" />

<br /><br />

**The Dot Ecosystem's canvas-first AI design tool — plus a shared design-token / component-library scaffold.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the Dot Ecosystem** &nbsp;·&nbsp; see [Dot.Brain](https://github.com/sakhilebhayi/Dot.Brain) for the ecosystem knowledge map

</div>

---

## What is Dot.Design?

Dot.Design is the visual-creation platform in the Dot Ecosystem — a canvas-first editor paired with generative AI, aimed at producing on-brand graphics, social posts, and marketing collateral without requiring professional design skill. Users open a project, drop text/shapes/images/icons onto a canvas, optionally ask AI to generate imagery, and export the result.

**This repository currently holds two coexisting domains** (see `wiki.md` for the full detail and the open framing question this raises with Dot.Brain):

1. **Canvas / AI creation tool** — `DesignProject`, `DesignCanvas`, `DesignAsset`, `AiGenerationLog`. Per-user data. 
2. **Design-token / component-library scaffold** (`/design-system/*`) — `TokenSet`, `DesignToken`, `Component`, `TokenConsumptionRecord`. Deliberately global/shared (no per-user or per-team scoping), since a design system is one catalog every platform consumes.

## Core Features

- Canvas editor — text, rectangles and uploaded images with move/resize/rotate, z-order, duplicate, undo/redo, zoom, snapping, autosave with conflict detection (icons are not implemented); see [The canvas editor](#the-canvas-editor)
- AI image generation logging from text prompts, multi-provider (`AiGenerationLog.provider`: Anthropic, OpenAI, Stability, Replicate)
- Asset library — real image uploads (JPG/PNG/WebP, private storage, per-user quota) plus labelled AI placeholders; see [Uploads](#uploads)
- Design-token / component catalog — versioned color/type/spacing/motion tokens, reusable component definitions, and a tracking table of which ecosystem platform consumes which token-set version
- Ecosystem SSO via Sanctum handoff token (`EcosystemAuthController`)

## Uploads

Signed-in users can upload images from the asset panel in a project's editor.

| | |
|---|---|
| Accepted | JPG, PNG, WebP. The type is read from the file's content, never its name or the browser's claim. |
| **Not accepted** | **SVG** (an SVG can carry script, so it is refused outright), GIF, anything else. |
| Size | 8 MB per file (`design.assets.max_kilobytes`). |
| Dimensions | Each side 16 to 8000 px and at most 40 megapixels. Checked from the header, before the image is decoded. |
| Quota | 200 MB of stored bytes per user (`DESIGN_ASSET_QUOTA_MB`), checked inside a transaction that locks the user row. |
| Rate limit | 20 uploads per user per minute (`DESIGN_ASSET_UPLOADS_PER_MINUTE`). |
| Duplicates | Identical content from the same user returns the existing asset instead of storing a second copy and costs no quota. If that asset sits in another project it moves to the library so both places can see it. |

**What is stored.** The image is decoded and re-encoded with GD, which drops EXIF (including GPS) and anything appended after the image data; EXIF orientation is applied to the pixels first. Animated WebP keeps only its first frame. The file is written to a private disk (`storage/app/private/design-assets`, disk `design_assets`) under a random name, with its sha256, size, dimensions and MIME type recorded on the `design_assets` row. `content_hash` is the hash of the stored bytes.

**How it is served.** Only through `GET /design/assets/{asset}/file`, behind login and `DesignAssetPolicy`; another user's asset is a 404. The response carries the stored `Content-Type`, `X-Content-Type-Options: nosniff`, a sandboxing `Content-Security-Policy`, `Cache-Control: private` and an ETag. There is no public URL.

**Deleting.** Deleting an asset removes its file from the disk unless another row still points at the same path. Deleting a project moves its uploads to the library.

**Assets and projects.** An asset belongs to one project or to the user's library (no project). The editor panel shows the current project's assets and the library. AI results are filed under the project that asked for them and stay labelled as placeholders; they are external URLs, not files on our disk.

### Not supported

- SVG upload (deliberate; see above).
- Real AI image generation. No provider is connected; the mock driver returns placehold.co stand-ins that the UI labels "Placeholder (no AI provider connected)".
- Collaboration or sharing of assets between users or teams. Assets are per user.

### Upgrade notes

1. Run `php artisan migrate` (adds `design_project_id`, `disk`, `content_hash`, `width`, `height` to `design_assets`; all nullable, existing rows stay valid).
2. The new `design_assets` disk is defined in `config/filesystems.php`. Point `DESIGN_ASSET_DISK` at another private disk (for example an S3 bucket with no public access) to move storage; never at `public`. Make sure `storage/app/private` is writable and included in backups.
3. The PHP `gd` extension (with WebP support) is required for sanitising. Without it, uploads are still accepted but stored **unmodified**, so EXIF/GPS is not stripped; each such row has `meta.reencoded = false`. Set `DESIGN_ASSET_REENCODE=false` to choose that deliberately. Check with `php -m | grep gd`.
4. GD holds a decoded image at 4 bytes per pixel, so a 40 megapixel image needs roughly 160 MB. Images that would not fit in PHP's `memory_limit` are refused with a clear message instead of crashing; raise `memory_limit` if you want the full range.
5. If the web server or a proxy limits request bodies (nginx `client_max_body_size`, PHP `upload_max_filesize` / `post_max_size`), keep them at 8 MB or above or users will see "larger than the server accepts".

## Image elements and export

A canvas page holds `text`, `rectangle` and `image` elements. Every element can carry `rotation` (degrees, about its centre) and `opacity` (0 to 1). An `image` element stores `asset_id`, never a URL.

- **Place** an asset from the panel with the add-image button. AI placeholders can be placed too; they are drawn with a PLACEHOLDER tag in the editor.
- **Validation** (`UpdateDesignCanvasRequest`): at most 300 elements and 512 KB of serialized JSON per page (`design.canvas.*`), numeric bounds on position, size, rotation and opacity, hex-only colours, unknown keys dropped, and every `asset_id` must be an asset owned by the user who is saving. Another user's id is a 422, never accepted.
- **Deleting a placed asset is refused** (409, naming the project and page) until it is removed from the canvas, so a page never points at a file that is gone.

**SVG export** (always available) embeds images as data URIs read from the private disk (6 MB per image and 20 MB per export; over either cap the image is replaced by a labelled box that says so), applies rotation and opacity, and breaks long text into lines. SVG cannot wrap text itself, so line breaks use an estimated glyph width; another viewer's font may wrap slightly differently from the editor. AI placeholders are external URLs and are never fetched; they export as a labelled "Placeholder image" box.

**PNG export** rasterises with GD: rectangles, images, rotation, opacity. It is narrower than SVG on purpose:

- **Text needs a font.** GD draws scalable text only from a TrueType file and none ships with the app. Set `DESIGN_EXPORT_FONT` to an absolute `.ttf` path to enable it. Without one, a canvas that contains text is **refused** with a message pointing at SVG, rather than exported with the text missing (the PNG button is disabled with the reason).
- Canvases over 4096 px a side or 16 megapixels are refused, as are renders that would not fit in `memory_limit`.
- Opacity is exact for opaque pixels and approximate for already-translucent edges.

JPEG and PDF export do not exist.

## The canvas editor

The editor is Alpine.js with no extra framework. Its logic is split so the part that can be tested is: `public/js/design-editor/geometry.js` (resize and rotate maths, snapping, zoom, z-order, duplicate) and `history.js` (undo/redo) are pure functions with a Node test suite (`npm run test:js`, needs Node 20 or newer, no dependencies); `editor.js` is the Alpine glue. They are plain classic scripts rather than built bundles, so there is no build step and they load before Livewire starts Alpine.

| | |
|---|---|
| Move | Drag. Snaps to the page edges and centre with a visible guide (hold Alt to turn snapping off). |
| Resize | Eight handles on the selected element; Shift keeps the aspect ratio (corner handles). Works on rotated elements: the opposite side stays put. |
| Rotate | The dot above the selection; Shift snaps to 15 degrees. |
| Arrange | Bring to front / forward / backward / send to back (`Ctrl/Cmd+]`, `]`, `[`, `Ctrl/Cmd+[`), duplicate (`Ctrl/Cmd+D`), delete (`Del`). |
| Nudge | Arrow keys, 1 px; Shift for 10 px. |
| Undo / redo | `Ctrl/Cmd+Z`, `Ctrl/Cmd+Shift+Z` (or `Ctrl+Y`). In memory only: up to 100 steps per page, gone on reload. Typing and arrow-key bursts count as one step. |
| Zoom | 25% to 400%: buttons, `Ctrl`+wheel (also trackpad pinch), Fit. Starts fitted. |
| Text | Tap twice on a text element, or use the Text field in Properties. X, Y, size, rotation and opacity can also be typed. |
| Touch | Everything above runs on pointer events, so mouse, pen and touch share one code path. |

**Saving.** Edits autosave after 500 ms. The header shows `Unsaved changes…`, `Saving…`, `Saved` or `Not saved`; a failure also puts a banner on screen that stays until a save succeeds (network errors retry every 8 seconds, or press Retry), and leaving the page with unsaved work asks first. Every save carries the page's `revision`; the server only writes if it still matches and bumps it in the same statement. A stale save returns **409** and the editor stops saving, saying the page changed elsewhere, and offers "Reload their version" or an explicit "Overwrite with mine". Nothing is overwritten silently.

**Not supported:** multi-select, grouping, copy/paste between pages, live collaboration (two people editing see a conflict, not each other's cursors), and the app shell (`layouts/app.blade.php`) has no phone layout, so on a phone the 260 px sidebar leaves little room; the editor itself stacks its panels below 1100 px and is usable on tablets.

## Domain Models

**Canvas / AI tool:**
- **DesignProject** — canvas project with metadata (type, dimensions, unit)
- **DesignCanvas** — one or more pages per project, JSON `elements` blob
- **DesignAsset** — uploaded images (stored on a private disk) and AI placeholder references
- **AiGenerationLog** — one row per AI generation request

**Design-token / component-library scaffold:**
- **TokenSet** — a versioned group of tokens (e.g. "Core Palette")
- **DesignToken** — a single color/type/spacing/motion token
- **Component** — a reusable UI component definition
- **TokenConsumptionRecord** — which ecosystem platform consumes which token set, pinned to which version

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.4 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared across the Dot Ecosystem) |
| Realtime | Laravel Reverb |
| Auth | Laravel Sanctum + Jetstream (teams, 2FA) |
| AI | Multi-provider: Anthropic, OpenAI, Stability, Replicate |
| Storage | AWS S3 / Local (Flysystem) |
| Search | Laravel Scout · Meilisearch (planned) |
| Queue | Redis · Laravel Horizon (planned) |

## Quick Start

```bash
git clone <this-repo-url> Dot.Design
cd Dot.Design
cp .env.example .env
composer install
npm install && npm run build   # Vite assets; the editor scripts need no build
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared Dot Ecosystem PostgreSQL instance. Users authenticated elsewhere in the ecosystem gain access automatically via Sanctum handoff tokens through `EcosystemAuthController`.

## Ecosystem

**Dot.Design** is one of roughly 20 platforms in the Dot Ecosystem, connected via shared PostgreSQL and Sanctum SSO, and unified by a shared knowledge repository, **Dot.Brain**. See `wiki.md` in this repo for Dot.Design's own account of what's implemented, and Dot.Brain's `platforms/dot-design.md` for the ecosystem-ingested view (currently a known, tracked mismatch — see `wiki.md` §1.1).

## License

MIT

## Tooling notes

- **Tailwind is still loaded from the CDN** by `layouts/app.blade.php`, not from the Vite build. Vite and Tailwind are configured (`npm run build` works), but moving the app layout over is not a mechanical swap: the layout loads the CDN with `preflight: false` and `darkMode: 'class'`, while `resources/css/app.css` pulls in Tailwind's full base layer and `tailwind.config.js` adds `@tailwindcss/forms`, which would restyle every unstyled input; `package.json` also lists `@tailwindcss/vite` v4 next to `tailwindcss` v3. Doing it properly needs a visual pass over every page, so it was left as is. Only the guest, welcome and error pages use the Vite bundle.
- `npm run test:js` runs the editor's Node tests; `./vendor/bin/phpunit` runs the PHP suite.

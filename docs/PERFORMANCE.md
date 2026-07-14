# Performance Improvements

> Performance upgrades for Dot.Design. Each section is independently applicable and safe to implement incrementally.

---

## 1. Database Query Optimisation

### 1a. Eager-load relationships

The dashboard query currently risks N+1 problems when projects are listed with their canvas counts.

```php
// Instead of:
DesignProject::where('user_id', $userId)->get(); // + N canvases() calls

// Use:
DesignProject::where('user_id', $userId)
    ->withCount('canvases')
    ->with(['canvases' => fn ($q) => $q->where('page_number', 1)->select('id', 'design_project_id', 'background_color')])
    ->latest()
    ->limit(6)
    ->get();
```

### 1b. Strict lazy-load prevention

Add to `AppServiceProvider::boot()`:

```php
Model::preventLazyLoading(! app()->isProduction());
```

This throws an exception in development whenever a relationship is accessed without eager loading — eliminating N+1 issues before they reach production.

### 1c. Select only needed columns

```php
DesignAsset::where('user_id', $userId)
    ->select('id', 'name', 'type', 'file_path', 'mime_type', 'created_at')
    ->latest()
    ->limit(8)
    ->get();
```

Never `SELECT *` in list queries — `elements` JSON on `DesignCanvas` can be large.

---

## 2. Caching Strategy

### 2a. Dashboard stats cache

```php
// Cache per-user dashboard stats for 60 seconds
$stats = Cache::remember("dashboard:{$user->id}", 60, fn () => $this->buildStats($user));
```

Invalidate on mutations:

```php
// app/Listeners/InvalidateDashboardCache.php
class InvalidateDashboardCache
{
    public function handle(object $event): void
    {
        $userId = match (true) {
            property_exists($event, 'project') => $event->project->user_id,
            property_exists($event, 'asset')   => $event->asset->user_id,
            default => null,
        };

        if ($userId) {
            Cache::forget("dashboard:{$userId}");
        }
    }
}
```

### 2b. Canvas element cache

Avoid hitting the database on every Livewire re-render:

```php
#[Computed]
public function canvasElements(): array
{
    return Cache::remember("canvas:elements:{$this->canvas->id}", 30, fn () =>
        $this->canvas->elements ?? []
    );
}
```

### 2c. Template gallery cache

Templates change infrequently — cache for 1 hour:

```php
$templates = Cache::remember('templates:all', 3600, fn () =>
    DesignAsset::where('type', 'template')->get(['id', 'name', 'file_path', 'meta'])
);
```

---

## 3. Livewire Performance

### 3a. Defer non-critical components

```html
<!-- Load AI generation history only when the panel is opened -->
<livewire:canvas.ai-history :canvasId="$canvas->id" lazy />
```

### 3b. Isolate polling

If presence/auto-save uses polling, scope it to the smallest component possible:

```php
// Only poll from the save-status indicator, not the whole page
#[Polling(every: '10s')]
class AutoSaveIndicator extends Component { ... }
```

### 3c. Avoid full-page re-renders

Use `wire:model.live` only on inputs that require instant feedback. Use `wire:model.blur` or `wire:model.lazy` for form fields that don't need real-time sync.

### 3d. Pagination instead of loading all records

```php
public function projects(): LengthAwarePaginator
{
    return DesignProject::forUser(auth()->user())
        ->withCount('canvases')
        ->latest()
        ->paginate(12);  // never ->get() on unbounded sets
}
```

---

## 4. Asset Delivery

### 4a. Image optimisation on upload

Use `spatie/image` to resize and compress images on upload before storing:

```bash
composer require spatie/image
```

```php
// app/Actions/Assets/UploadDesignAsset.php
use Spatie\Image\Image;

Image::load($file->getRealPath())
    ->width(2048)           // cap max dimension
    ->optimize()            // lossy compression
    ->save($storagePath);
```

### 4b. Responsive thumbnails

Generate three thumbnail sizes on upload:
- `thumb_sm` — 300×300 (asset library grid)
- `thumb_md` — 600×600 (template preview)
- `thumb_lg` — 1200×1200 (full preview modal)

Store paths in `design_assets.meta`:
```json
{
  "thumbnails": {
    "sm": "assets/thumbs/sm/abc123.webp",
    "md": "assets/thumbs/md/abc123.webp",
    "lg": "assets/thumbs/lg/abc123.webp"
  }
}
```

### 4c. WebP conversion

Convert PNG/JPEG uploads to WebP for ~30% smaller file sizes:

```php
Image::load($path)->save(str_replace(['.png', '.jpg'], '.webp', $path));
```

### 4d. CDN / S3 signed URLs

Never expose raw S3 paths. Use signed URLs with a short TTL for private assets:

```php
Storage::temporaryUrl($asset->file_path, now()->addMinutes(30));
```

---

## 5. Queue Configuration

**Problem:** All jobs run on the default queue. AI generation, exports, and emails should have separate priority queues.

```dotenv
QUEUE_CONNECTION=redis
```

```php
// config/queue.php — define priority queues
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'queue'  => 'default',
        ...
    ],
],
```

Dispatch to specific queues:

```php
GenerateAiImageJob::dispatch(...)->onQueue('ai');
ExportDesignJob::dispatch(...)->onQueue('exports');
SendTeamInvitationEmail::dispatch(...)->onQueue('mail');
```

Run workers with priority:

```bash
php artisan horizon  # manage via Laravel Horizon dashboard
```

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'queues'     => ['ai', 'exports', 'mail', 'default'],
            'balance'    => 'auto',
            'processes'  => 10,
            'tries'      => 3,
        ],
    ],
],
```

---

## 6. Search Performance

**Problem:** No search is implemented. Naive `LIKE` queries on large tables will be slow.

**Use Laravel Scout + Meilisearch** (already in the tech stack):

```php
// app/Models/DesignProject.php
use Laravel\Scout\Searchable;

class DesignProject extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
            'type' => $this->type,
        ];
    }
}
```

```php
// app/Models/DesignAsset.php
use Laravel\Scout\Searchable;

public function toSearchableArray(): array
{
    return [
        'id'   => $this->id,
        'name' => $this->name,
        'type' => $this->type,
    ];
}
```

Meilisearch returns results in <50ms even on millions of records.

---

## 7. HTTP Response Optimisation

### 7a. Compress responses

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    ]);
})
```

Enable gzip/brotli at the Nginx/Caddy level (do not compress in PHP).

### 7b. HTTP cache headers for static assets

Vite already appends content hashes to asset filenames. Set `Cache-Control: immutable, max-age=31536000` on `public/build/` via the web server config.

### 7c. Lazy-load below-the-fold images

```html
<img src="{{ $asset->thumbnailUrl('sm') }}" loading="lazy" decoding="async" alt="{{ $asset->name }}" />
```

---

## 8. Database Connection Pooling

**Problem:** Each PHP-FPM worker opens its own PostgreSQL connection. Under load, this exhausts the connection limit.

**Use PgBouncer** between Laravel and PostgreSQL in transaction mode:

```dotenv
DB_HOST=pgbouncer
DB_PORT=6432
DB_POOL_MODE=transaction
```

Alternatively, use `DB_PERSISTENT=true` if PgBouncer is unavailable — this reuses connections across the PHP process lifetime but requires `PGBOUNCER_STATEMENT_CACHE_SIZE=0`.

---

## 9. Front-end Bundle Size

**Current:** `resources/js/app.js` is empty. As Fabric.js and Alpine plugins are added, monitor bundle size.

**Targets:**
- Initial JS bundle: < 200 KB gzipped
- CSS bundle: < 50 KB gzipped

**Strategies:**
- Tree-shake Fabric.js: import only used modules (`import { Canvas, Text, Rect } from 'fabric/es'`).
- Code-split the canvas editor — load it only on canvas routes via dynamic `import()`.
- Remove `@tailwindcss/typography` plugin if prose content is minimal (saves ~25 KB CSS).

```js
// vite.config.js — enable manual chunk splitting
build: {
    rollupOptions: {
        output: {
            manualChunks: {
                fabric: ['fabric'],
                vendor: ['alpinejs'],
            },
        },
    },
},
```

---

## 10. Profiling & Monitoring

Add **Laravel Telescope** (development) and **Laravel Pulse** (production) to detect slow queries, queued job failures, and cache misses:

```bash
composer require laravel/telescope --dev
composer require laravel/pulse
php artisan telescope:install
php artisan pulse:install
php artisan migrate
```

Pulse dashboard route (`/pulse`) should be behind the `admin` gate.

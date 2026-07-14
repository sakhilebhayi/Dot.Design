# Code Architecture Improvements

> Structural and patterns-level upgrades for the Dot.Design codebase. Each section is independently applicable.

---

## 1. Service Layer

**Problem:** `routes/web.php` contains inline business logic (dashboard stats query). Controllers and routes should be thin.

**Fix:** Introduce a `app/Services/` layer.

```
app/
  Services/
    DesignProjectService.php
    DesignCanvasService.php
    DesignAssetService.php
    AiGenerationService.php
    ExportService.php
```

**Example — extract dashboard query:**

```php
// app/Services/DesignProjectService.php
class DesignProjectService
{
    public function getDashboardStats(User $user): array
    {
        return Cache::remember("dashboard.{$user->id}", 60, function () use ($user) {
            return [
                'totalProjects'   => DesignProject::where('user_id', $user->id)->count(),
                'recentProjects'  => DesignProject::where('user_id', $user->id)
                                        ->latest()->limit(6)->get(),
                'totalAssets'     => DesignAsset::where('user_id', $user->id)->count(),
                'aiGenerations'   => AiGenerationLog::where('user_id', $user->id)->count(),
                'recentAssets'    => DesignAsset::where('user_id', $user->id)
                                        ->latest()->limit(8)->get(),
                'byProvider'      => AiGenerationLog::where('user_id', $user->id)
                                        ->groupBy('provider')
                                        ->selectRaw('provider, count(*) as total')
                                        ->pluck('total', 'provider'),
            ];
        });
    }
}
```

```php
// routes/web.php — after refactor
Route::get('/dashboard', function (DesignProjectService $svc) {
    return view('dashboard', $svc->getDashboardStats(auth()->user()));
})->name('dashboard');
```

---

## 2. Livewire Component Structure

**Problem:** No Livewire components exist yet. Establishing a consistent structure now prevents spaghetti later.

**Proposed component tree:**

```
app/Livewire/
  Dashboard/
    DashboardPage.php           # main dashboard (replaces inline route closure)
    ProjectGrid.php             # filterable project list
    RecentAssets.php
  Canvas/
    CanvasEditor.php            # main editor host
    LayerPanel.php
    PropertiesPanel.php
    ToolBar.php
  Projects/
    CreateProject.php
    ProjectSettings.php
  Assets/
    AssetLibrary.php
    AssetUploader.php
  Ai/
    AiPromptPanel.php           # prompt input + generation history
  Teams/
    TeamSwitcher.php
  Onboarding/
    OnboardingWizard.php
```

**Convention:**
- One Livewire component = one public concern. Keep each file under 200 lines.
- Use `#[Computed]` properties for derived data instead of computing inside `render()`.
- Use `#[Locked]` on properties that must not be modified client-side.

```php
use Livewire\Attributes\{Computed, Locked};

class ProjectGrid extends Component
{
    public string $search = '';
    public string $type = '';

    #[Locked]
    public int $userId;

    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return DesignProject::where('user_id', $this->userId)
            ->when($this->search, fn ($q) => $q->where('name', 'ilike', "%{$this->search}%"))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->latest()
            ->paginate(12);
    }
}
```

---

## 3. Action Classes

**Problem:** Complex single-purpose operations (create project, export canvas, log AI generation) should not live in controllers or Livewire components directly.

**Use Laravel's Action pattern** (already scaffolded for Fortify/Jetstream — apply the same pattern everywhere):

```
app/Actions/
  Design/
    CreateDesignProject.php
    DuplicateDesignProject.php
    DeleteDesignProject.php
    ExportDesignCanvas.php
  Ai/
    GenerateImageFromPrompt.php
    LogAiGeneration.php
  Assets/
    UploadDesignAsset.php
    DeleteDesignAsset.php
```

```php
// app/Actions/Design/CreateDesignProject.php
class CreateDesignProject
{
    public function execute(User $user, array $data): DesignProject
    {
        $project = DesignProject::create([
            'user_id' => $user->id,
            'name'    => $data['name'],
            'type'    => $data['type'],
            'width'   => $data['width']  ?? 1080,
            'height'  => $data['height'] ?? 1080,
            'unit'    => $data['unit']   ?? 'px',
        ]);

        // Create first blank canvas
        $project->canvases()->create(['page_number' => 1]);

        event(new DesignProjectCreated($project));

        return $project;
    }
}
```

---

## 4. Events & Listeners

**Problem:** `AppServiceProvider` is empty. Domain events are not being fired.

**Define events for all state changes:**

```
app/Events/
  Design/
    DesignProjectCreated.php
    DesignProjectDeleted.php
    DesignCanvasSaved.php
    DesignExported.php
  Ai/
    AiGenerationRequested.php
    AiGenerationCompleted.php
    AiGenerationFailed.php
  Assets/
    AssetUploaded.php
    AssetDeleted.php
```

**Register in `EventServiceProvider` (create one):**

```php
protected $listen = [
    DesignProjectCreated::class => [
        SendWelcomeProjectNotification::class,
        InvalidateDashboardCache::class,
    ],
    DesignCanvasSaved::class => [
        InvalidateDashboardCache::class,
        BroadcastCanvasUpdate::class,       // triggers Reverb broadcast
    ],
    AiGenerationCompleted::class => [
        NotifyUserOfGeneration::class,
        InvalidateDashboardCache::class,
    ],
];
```

---

## 5. Form Request Validation

**Problem:** No `FormRequest` classes exist. Validation is either missing or inline.

**Create request classes for every user-submitted action:**

```
app/Http/Requests/
  Design/
    CreateProjectRequest.php
    UpdateProjectRequest.php
    SaveCanvasRequest.php
  Assets/
    UploadAssetRequest.php
  Ai/
    GenerateImageRequest.php
```

```php
// app/Http/Requests/Design/CreateProjectRequest.php
class CreateProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'   => ['required', 'string', 'max:255'],
            'type'   => ['required', Rule::in(['social', 'print', 'presentation', 'email', 'custom'])],
            'width'  => ['required', 'integer', 'min:1', 'max:10000'],
            'height' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit'   => ['required', Rule::in(['px', 'mm', 'cm', 'in'])],
        ];
    }
}
```

---

## 6. Model Improvements

**Problem:** Models are minimal with no scopes, casts, or accessors defined beyond the basics.

**Additions per model:**

```php
// app/Models/DesignProject.php
class DesignProject extends Model
{
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Scopes
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    // Accessor
    public function getDimensionLabelAttribute(): string
    {
        return "{$this->width}×{$this->height} {$this->unit}";
    }
}
```

```php
// app/Models/DesignCanvas.php
protected $casts = [
    'elements'         => AsCollection::class,  // cast JSON to Collection
    'background_color' => 'string',
];
```

```php
// app/Models/AiGenerationLog.php
protected $casts = [
    'created_at' => 'datetime',
];

public function scopeByProvider(Builder $query, string $provider): Builder
{
    return $query->where('provider', $provider);
}
```

---

## 7. Repository Pattern (Optional — for complex queries)

If query logic grows complex, introduce repositories behind interfaces to keep models clean and enable easy testing:

```
app/Repositories/
  Contracts/
    DesignProjectRepositoryInterface.php
  Eloquent/
    DesignProjectRepository.php
```

Bind in `AppServiceProvider`:
```php
$this->app->bind(
    DesignProjectRepositoryInterface::class,
    DesignProjectRepository::class,
);
```

Only introduce this pattern when a service class exceeds ~5 distinct query shapes on the same model.

---

## 8. Configuration & Environment

**Problem:** `config/app.php` and `.env.example` don't yet document AI provider keys or ecosystem-specific config.

**Add to `.env.example`:**

```dotenv
# AI Providers
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
STABILITY_API_KEY=
REPLICATE_API_TOKEN=
AI_DEFAULT_PROVIDER=anthropic

# Storage
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_URL=
FILESYSTEM_DISK=local

# Ecosystem SSO
INFODOT_HUB_URL=https://app.infodot.app
INFODOT_SSO_SECRET=

# Search
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=

# Queue / Cache
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=redis

# Reverb (WebSocket)
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
```

**Add `config/ai.php`:**

```php
return [
    'default' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
    'providers' => [
        'anthropic' => [
            'key'   => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-sonnet-4-6',
        ],
        'openai' => [
            'key'   => env('OPENAI_API_KEY'),
            'model' => 'gpt-image-1',
        ],
        'stability' => [
            'key' => env('STABILITY_API_KEY'),
        ],
        'replicate' => [
            'token' => env('REPLICATE_API_TOKEN'),
        ],
    ],
];
```

---

## 9. Route Organisation

**Problem:** All routes are in `web.php` and `api.php` with no grouping. As routes grow, this becomes unmaintainable.

**Refactor to grouped includes:**

```php
// routes/web.php
require __DIR__.'/web/auth.php';
require __DIR__.'/web/dashboard.php';
require __DIR__.'/web/projects.php';
require __DIR__.'/web/canvas.php';
require __DIR__.'/web/assets.php';
require __DIR__.'/web/templates.php';
require __DIR__.'/web/teams.php';
```

Each sub-file contains only routes for that domain, with shared middleware applied once at the file level.

---

## 10. Autoloading & PSR-4

**Problem:** `app/Providers/AppServiceProvider.php` is empty. Defer all service bindings there rather than using anonymous boot callbacks.

**Structure `AppServiceProvider` properly:**

```php
class AppServiceProvider extends ServiceProvider
{
    public array $bindings = [
        DesignProjectRepositoryInterface::class => DesignProjectRepository::class,
    ];

    public function register(): void
    {
        $this->app->singleton(AiGenerationService::class, function ($app) {
            return new AiGenerationService(config('ai'));
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Model::unguard(); // rely on $fillable per-model only
    }
}
```

---

## 11. Error Handling

**Problem:** No custom exception handler logic. Laravel defaults will expose stack traces in non-production without explicit handling.

**Add to `bootstrap/app.php`:**

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (ModelNotFoundException $e, Request $request) {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }
    });

    $exceptions->render(function (AuthorizationException $e, Request $request) {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthorised.'], 403);
        }
    });

    $exceptions->reportable(function (Throwable $e) {
        // Send to Sentry / Flare if configured
    });
})
```

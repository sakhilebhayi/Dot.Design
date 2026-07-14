# API Design Improvements

> REST API design guidelines and endpoint specifications for Dot.Design. The current API only exposes `/api/user`. This document defines the full API surface.

---

## 1. API Design Principles

- **Versioned from day one:** All endpoints live under `/api/v1/`.
- **Consistent resource naming:** Plural nouns, kebab-case. No verbs in URLs.
- **Standard HTTP verbs:** GET (read), POST (create), PUT/PATCH (update), DELETE (remove).
- **JSON:API-adjacent responses:** Consistent envelope with `data`, `meta`, and `errors` keys.
- **Sanctum authentication:** All endpoints require `Authorization: Bearer <token>` unless explicitly public.

---

## 2. Response Envelope

```json
// Success (single resource)
{
  "data": { "id": 1, "name": "My Campaign", ... }
}

// Success (collection)
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page":    4,
    "per_page":     15,
    "total":        58
  }
}

// Error
{
  "message": "The name field is required.",
  "errors": {
    "name": ["The name field is required."]
  }
}
```

Implement via a base `ApiController` with helper methods:

```php
// app/Http/Controllers/Api/ApiController.php
abstract class ApiController extends Controller
{
    protected function ok(mixed $data, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];
        if ($meta) $payload['meta'] = $meta;
        return response()->json($payload, 200);
    }

    protected function created(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data], 201);
    }

    protected function noContent(): Response
    {
        return response()->noContent();
    }
}
```

---

## 3. Endpoint Specification

### 3a. Projects

| Method | URL | Description |
|---|---|---|
| `GET` | `/api/v1/projects` | List authenticated user's projects (paginated) |
| `POST` | `/api/v1/projects` | Create a new project |
| `GET` | `/api/v1/projects/{id}` | Get a single project with canvases |
| `PATCH` | `/api/v1/projects/{id}` | Update project name / type / dimensions |
| `DELETE` | `/api/v1/projects/{id}` | Soft-delete project |
| `POST` | `/api/v1/projects/{id}/duplicate` | Duplicate project with all canvases |
| `POST` | `/api/v1/projects/{id}/restore` | Restore soft-deleted project |

### 3b. Canvases

| Method | URL | Description |
|---|---|---|
| `GET` | `/api/v1/projects/{id}/canvases` | List all pages for a project |
| `POST` | `/api/v1/projects/{id}/canvases` | Add a new page |
| `PUT` | `/api/v1/canvases/{id}` | Save canvas elements + background |
| `DELETE` | `/api/v1/canvases/{id}` | Delete a page (min 1 page enforced) |
| `POST` | `/api/v1/canvases/{id}/export` | Queue an export job |

### 3c. Assets

| Method | URL | Description |
|---|---|---|
| `GET` | `/api/v1/assets` | List assets (filterable by type, searchable by name) |
| `POST` | `/api/v1/assets` | Upload a new asset (multipart/form-data) |
| `GET` | `/api/v1/assets/{id}` | Get single asset with signed URL |
| `PATCH` | `/api/v1/assets/{id}` | Rename asset |
| `DELETE` | `/api/v1/assets/{id}` | Soft-delete asset |

### 3d. Templates (public)

| Method | URL | Description |
|---|---|---|
| `GET` | `/api/v1/templates` | List all templates (public, no auth required) |
| `GET` | `/api/v1/templates/{id}` | Get template detail |
| `POST` | `/api/v1/templates/{id}/use` | Create a project from this template (auth required) |

### 3e. AI Generation

| Method | URL | Description |
|---|---|---|
| `POST` | `/api/v1/ai/generate-image` | Queue an AI image generation job |
| `GET` | `/api/v1/ai/history` | Paginated generation history |
| `DELETE` | `/api/v1/ai/history/{id}` | Delete a generation log entry |

### 3f. User & Team

| Method | URL | Description |
|---|---|---|
| `GET` | `/api/v1/user` | Current authenticated user profile |
| `GET` | `/api/v1/teams` | List user's teams |
| `GET` | `/api/v1/teams/{id}/members` | List team members |

---

## 4. Route Definition

```php
// routes/api.php
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/{project}/duplicate', [ProjectController::class, 'duplicate']);
    Route::post('projects/{project}/restore',   [ProjectController::class, 'restore'])->withTrashed();

    Route::get('projects/{project}/canvases',   [CanvasController::class, 'index']);
    Route::post('projects/{project}/canvases',  [CanvasController::class, 'store']);
    Route::put('canvases/{canvas}',             [CanvasController::class, 'update']);
    Route::delete('canvases/{canvas}',          [CanvasController::class, 'destroy']);
    Route::post('canvases/{canvas}/export',     [CanvasController::class, 'export']);

    Route::apiResource('assets', AssetController::class)->except(['show']);
    Route::get('assets/{asset}', [AssetController::class, 'show']); // returns signed URL

    Route::post('ai/generate-image', [AiController::class, 'generate'])
         ->middleware('throttle:10,1');
    Route::get('ai/history',  [AiController::class, 'history']);
    Route::delete('ai/history/{log}', [AiController::class, 'destroyLog']);

    Route::get('user', fn (Request $r) => $r->user());
    Route::apiResource('teams', TeamController::class)->only(['index', 'show']);
    Route::get('teams/{team}/members', [TeamController::class, 'members']);
});

// Public routes (no auth)
Route::prefix('v1')->group(function () {
    Route::get('templates', [TemplateController::class, 'index']);
    Route::get('templates/{template}', [TemplateController::class, 'show']);
    Route::post('templates/{template}/use', [TemplateController::class, 'use'])
         ->middleware('auth:sanctum');
});
```

---

## 5. API Resources (Transformers)

Never return raw Eloquent models — use `JsonResource` to control the API contract:

```php
// app/Http/Resources/DesignProjectResource.php
class DesignProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'type'           => $this->type,
            'width'          => $this->width,
            'height'         => $this->height,
            'unit'           => $this->unit,
            'dimension_label'=> $this->dimension_label,
            'thumbnail_url'  => $this->thumbnail_path
                                    ? Storage::temporaryUrl($this->thumbnail_path, now()->addMinutes(30))
                                    : null,
            'canvas_count'   => $this->canvases_count ?? $this->canvases()->count(),
            'created_at'     => $this->created_at->toIso8601String(),
            'updated_at'     => $this->updated_at->toIso8601String(),
        ];
    }
}
```

---

## 6. API Versioning Strategy

- All breaking changes require a new version prefix (`/api/v2/`).
- Non-breaking additions (new optional fields, new endpoints) are made in the current version.
- Deprecation notice: add `Deprecation: date="YYYY-MM-DD"` header to responses for deprecated endpoints.
- Maintain `v1` for 12 months after `v2` releases.

---

## 7. Rate Limiting

```php
// bootstrap/app.php or RouteServiceProvider
RateLimiter::for('api', function (Request $request) {
    return [
        Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
        Limit::perDay(2000)->by($request->user()?->id ?: $request->ip()),
    ];
});

RateLimiter::for('ai-generation', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())
               ->response(fn () => response()->json(['message' => 'Too many AI requests. Slow down.'], 429));
});
```

---

## 8. Token Abilities / Scopes

Define explicit Sanctum token abilities for third-party integrations:

| Ability | Description |
|---|---|
| `projects:read` | List and view projects |
| `projects:write` | Create, update, delete projects |
| `assets:read` | List and download assets |
| `assets:write` | Upload and delete assets |
| `ai:generate` | Trigger AI generation |
| `ecosystem:read` | InfoDot ecosystem SSO handshake (one-time use) |

When creating tokens via the UI, allow users to select individual abilities.

---

## 9. API Documentation

Use `dedoc/scramble` for automatic OpenAPI documentation generation from PHP docblocks and request classes:

```bash
composer require dedoc/scramble
php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider"
```

The generated docs are available at `/docs/api`. Restrict to authenticated team admins in production.

---

## 10. Webhook Support (Future)

Allow external apps in the InfoDot ecosystem to subscribe to design events:

| Event | Payload |
|---|---|
| `project.created` | `{ project_id, user_id, name, type }` |
| `project.exported` | `{ project_id, format, download_url, expires_at }` |
| `ai.generation.completed` | `{ log_id, project_id, result_url, provider }` |

Implement with a `webhooks` table and a `DispatchWebhookJob` that signs payloads with HMAC-SHA256 using a per-subscription secret.

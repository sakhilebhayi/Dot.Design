# Testing Strategy

> A complete testing approach for Dot.Design. Each section maps to a layer of the application and can be built incrementally.

---

## 1. Current State

**Status (R-45, 2026-11-09):** the suite below is real; the rest of this document (sections 2 onward) is the original strategy proposal and sections 5 and 7 assume Livewire components that the studio does not use.

| Layer | What | How to run | Size |
|---|---|---|---|
| PHP feature and unit | The Jetstream scaffold tests plus the whole design domain: document API and sanitiser, tenancy (404 not 403), versions, shares, comments, reviews, brand kits, templates and the 58-template library, uploads, exports, assistant (HTTP faked, never a real model), integrations (HTTP faked, signatures), `design:preflight`, the ops commands, docs sync | `php artisan test --compact` (SQLite in memory, Vite faked) | about 1,450 tests, 7 skipped, under 25 s |
| JS | The editor engine, output (export, animation, resize), assistant logic, collaboration state, create flow, brand pages, the ops manifest check | `npm run test:js` (`node:test`, no browser) | about 620 tests, about 1 s |
| Build and manifest | Every `@vite` entry in every Blade view is built, every manifest file exists, no view names an unbuilt `resources/` path | `npm run build && node scripts/ops/check-vite-manifest.mjs` | seconds |
| Signed-in smoke | Boots the app like production (`APP_ENV=production`, cached routes, config, views and events, the built assets, a throw-away SQLite file), signs a seeded user in over HTTP with CSRF, creates a design from a template, shares and comments on it, then requests every key page (landmark asserted), the JSON endpoints, a public share link (no cookie), the built assets and the fonts, and fails on any `ERROR` in the application log | `tests/Support/signed-in-smoke.sh` | about 90 checks, about 5 s |
| Docs sync | `docs/API.md` and `docs/DATABASE.md` match the code | `php scripts/ops/generate-docs.php --check` (also a PHPUnit test) | |

**CI** (`.github/workflows/ci.yml`) runs all of it on every pull request: `tests`, `js` (tests, build, manifest) and `signed-in-smoke`. `deploy.yml` calls the same workflow as its gate. **Not covered by automation:** a real browser (the editor was exercised by hand in headless Chrome, with the screens under `docs/R45_screens/`), a real PostgreSQL run of the whole suite (the migrations were run on PostgreSQL by hand, see `docs/DATABASE.md`; a PostgreSQL CI job may be added beside these), real third-party services, and the live host.

---

## 2. Test Pyramid Target

```
          /\
         /  \
        / E2E\       ~5%   (browser tests — critical paths only)
       /──────\
      /  Feat  \      ~40%  (HTTP + Livewire feature tests)
     /──────────\
    /    Unit    \    ~55%  (services, actions, models, value objects)
   ──────────────────
```

---

## 3. Unit Tests

### 3a. Action tests

```php
// tests/Unit/Actions/CreateDesignProjectTest.php
class CreateDesignProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_project_with_first_canvas(): void
    {
        $user   = User::factory()->create();
        $action = new CreateDesignProject();

        $project = $action->execute($user, [
            'name'   => 'My Campaign',
            'type'   => 'social',
            'width'  => 1080,
            'height' => 1080,
            'unit'   => 'px',
        ]);

        $this->assertDatabaseHas('design_projects', [
            'user_id' => $user->id,
            'name'    => 'My Campaign',
        ]);

        $this->assertDatabaseHas('design_canvases', [
            'design_project_id' => $project->id,
            'page_number'       => 1,
        ]);
    }
}
```

### 3b. Service tests — AI driver abstraction

```php
// tests/Unit/Services/Ai/AiGenerationServiceTest.php
class AiGenerationServiceTest extends TestCase
{
    public function test_falls_back_to_second_driver_on_failure(): void
    {
        $failingDriver  = Mockery::mock(AiImageDriver::class);
        $successDriver  = Mockery::mock(AiImageDriver::class);

        $failingDriver->shouldReceive('generate')->andThrow(new RuntimeException('API down'));
        $successDriver->shouldReceive('generate')->andReturn(
            new AiImageResult(url: 'https://cdn.example.com/img.png', tokensUsed: 500, provider: 'openai')
        );

        $service = new AiGenerationService([$failingDriver, $successDriver]);
        $result  = $service->generate('a sunset over mountains');

        $this->assertSame('openai', $result->provider);
    }

    public function test_throws_when_all_drivers_fail(): void
    {
        $this->expectException(AiGenerationException::class);

        $driver = Mockery::mock(AiImageDriver::class);
        $driver->shouldReceive('generate')->andThrow(new RuntimeException('API down'));

        $service = new AiGenerationService([$driver]);
        $service->generate('test');
    }
}
```

### 3c. Model scope tests

```php
// tests/Unit/Models/DesignProjectTest.php
class DesignProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_user_scope_returns_only_user_projects(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        DesignProject::factory()->for($user)->create();
        DesignProject::factory()->for($other)->create();

        $projects = DesignProject::forUser($user)->get();

        $this->assertCount(1, $projects);
        $this->assertSame($user->id, $projects->first()->user_id);
    }
}
```

---

## 4. Feature Tests

### 4a. Project CRUD

```php
// tests/Feature/DesignProjectTest.php
class DesignProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_project(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->post('/projects', [
            'name'   => 'New Campaign',
            'type'   => 'social',
            'width'  => 1080,
            'height' => 1080,
            'unit'   => 'px',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('design_projects', ['name' => 'New Campaign', 'user_id' => $user->id]);
    }

    public function test_user_cannot_view_another_users_project(): void
    {
        $owner   = User::factory()->withPersonalTeam()->create();
        $viewer  = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::factory()->for($owner)->create();

        $this->actingAs($viewer)
             ->get("/projects/{$project->id}")
             ->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
```

### 4b. Asset upload

```php
// tests/Feature/AssetUploadTest.php
class AssetUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_image_asset(): void
    {
        Storage::fake('s3');
        $user = User::factory()->withPersonalTeam()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $this->actingAs($user)->post('/assets', ['file' => $file, 'name' => 'My Photo'])
             ->assertCreated();

        $this->assertDatabaseHas('design_assets', ['user_id' => $user->id, 'name' => 'My Photo']);
        Storage::disk('s3')->assertExists('assets/' . $user->id . '/photo.jpg');
    }

    public function test_upload_rejects_non_image_files(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = UploadedFile::fake()->create('malware.php', 10, 'application/php');

        $this->actingAs($user)->post('/assets', ['file' => $file])
             ->assertUnprocessable();
    }
}
```

### 4c. Ecosystem auth

```php
// tests/Feature/EcosystemAuthTest.php
class EcosystemAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_ecosystem_token_logs_user_in(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('ecosystem', ['ecosystem:read']);

        $this->get("/auth/ecosystem?token={$token->plainTextToken}")
             ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->get('/auth/ecosystem?token=invalid')->assertUnauthorized();
    }

    public function test_token_is_deleted_after_use(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('ecosystem', ['ecosystem:read']);

        $this->get("/auth/ecosystem?token={$token->plainTextToken}");

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'ecosystem']);
    }
}
```

### 4d. Canvas save

```php
// tests/Feature/CanvasSaveTest.php
class CanvasSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_canvas(): void
    {
        $user    = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::factory()->for($user)->create();
        $canvas  = DesignCanvas::factory()->for($project)->create();

        $payload = [
            'elements'         => ['version' => '6.x', 'objects' => []],
            'background_color' => '#ffffff',
        ];

        $this->actingAs($user)
             ->putJson("/canvas/{$canvas->id}", $payload)
             ->assertOk();

        $this->assertDatabaseHas('design_canvases', [
            'id'               => $canvas->id,
            'background_color' => '#ffffff',
        ]);
    }

    public function test_non_owner_cannot_save_canvas(): void
    {
        $owner   = User::factory()->withPersonalTeam()->create();
        $other   = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::factory()->for($owner)->create();
        $canvas  = DesignCanvas::factory()->for($project)->create();

        $this->actingAs($other)
             ->putJson("/canvas/{$canvas->id}", ['elements' => [], 'background_color' => '#fff'])
             ->assertForbidden();
    }
}
```

---

## 5. Livewire Component Tests

```php
// tests/Feature/Livewire/ProjectGridTest.php
use Livewire\Livewire;

class ProjectGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_projects(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        DesignProject::factory()->for($user)->create(['name' => 'Summer Campaign']);
        DesignProject::factory()->for($user)->create(['name' => 'Winter Sale']);

        Livewire::actingAs($user)
            ->test(ProjectGrid::class, ['userId' => $user->id])
            ->set('search', 'Summer')
            ->assertSee('Summer Campaign')
            ->assertDontSee('Winter Sale');
    }

    public function test_pagination_works(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        DesignProject::factory()->for($user)->count(15)->create();

        Livewire::actingAs($user)
            ->test(ProjectGrid::class, ['userId' => $user->id])
            ->assertViewHas('projects', fn ($p) => $p->total() === 15 && $p->perPage() === 12);
    }
}
```

---

## 6. API Tests

```php
// tests/Feature/Api/UserEndpointTest.php
class UserEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_own_profile(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
             ->getJson('/api/user')
             ->assertOk()
             ->assertJsonPath('email', $user->email);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
```

---

## 7. Model Factories

```php
// database/factories/DesignProjectFactory.php
class DesignProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name'    => $this->faker->words(3, true),
            'type'    => $this->faker->randomElement(['social', 'print', 'presentation', 'email', 'custom']),
            'width'   => $this->faker->randomElement([1080, 1920, 800]),
            'height'  => $this->faker->randomElement([1080, 1080, 600]),
            'unit'    => 'px',
        ];
    }
}

// database/factories/DesignCanvasFactory.php
class DesignCanvasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'design_project_id' => DesignProject::factory(),
            'page_number'       => 1,
            'elements'          => null,
            'background_color'  => '#ffffff',
        ];
    }
}

// database/factories/DesignAssetFactory.php
class DesignAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'   => User::factory(),
            'name'      => $this->faker->words(2, true),
            'type'      => $this->faker->randomElement(['image', 'icon', 'font', 'template']),
            'file_path' => 'assets/' . $this->faker->uuid() . '.png',
            'mime_type' => 'image/png',
            'file_size' => $this->faker->numberBetween(10000, 5000000),
            'meta'      => null,
        ];
    }
}
```

---

## 8. Test Configuration

### 8a. In-memory SQLite for speed

```xml
<!-- phpunit.xml -->
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="MAIL_MAILER" value="array"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="FILESYSTEM_DISK" value="local"/>
</php>
```

### 8b. Parallel test execution

```bash
php artisan test --parallel --processes=4
```

Add `--coverage --min=70` once the test suite is established.

---

## 9. Continuous Integration

Run on every pull request:

```yaml
# .github/workflows/tests.yml (see DEVOPS.md for full workflow)
- name: Run tests
  run: php artisan test --parallel --coverage --min=70
```

Fail the PR if coverage drops below 70%.

---

## 10. What NOT to test

- Jetstream/Fortify internals (already tested by the package itself)
- Laravel framework behaviour (routing, middleware chain, ORM)
- Third-party API responses — mock them with `Http::fake()`
- Purely presentational Blade template rendering without business logic

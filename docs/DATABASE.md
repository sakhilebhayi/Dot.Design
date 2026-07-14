# Database Improvements

> Schema design, indexing, and data integrity improvements for Dot.Design. Each section is independently migratable.

---

## 1. Missing Indexes

The current migrations create foreign keys but no indexes on frequently-queried columns. Every column used in a `WHERE`, `ORDER BY`, or `GROUP BY` clause needs an index.

```php
// database/migrations/xxxx_add_missing_indexes.php
Schema::table('design_projects', function (Blueprint $table) {
    $table->index('user_id');              // forUser() scope
    $table->index(['user_id', 'type']);    // type-filtered queries
    $table->index('updated_at');           // "recently edited" sort
});

Schema::table('design_canvases', function (Blueprint $table) {
    $table->index(['design_project_id', 'page_number']); // page lookup
});

Schema::table('design_assets', function (Blueprint $table) {
    $table->index(['user_id', 'type']);    // asset library filter
    $table->index('created_at');           // recent assets sort
});

Schema::table('ai_generation_logs', function (Blueprint $table) {
    $table->index(['user_id', 'created_at']);       // history panel
    $table->index(['user_id', 'provider']);          // stats by provider
    $table->index('design_project_id');              // project-scoped logs
});
```

---

## 2. Soft Deletes

Permanently deleting a `DesignProject` or `DesignAsset` is irreversible. Add soft deletes to allow a "Trash" recovery flow.

```php
// database/migrations/xxxx_add_soft_deletes.php
Schema::table('design_projects', function (Blueprint $table) {
    $table->softDeletes();
});

Schema::table('design_assets', function (Blueprint $table) {
    $table->softDeletes();
});
```

```php
// app/Models/DesignProject.php
use Illuminate\Database\Eloquent\SoftDeletes;

class DesignProject extends Model
{
    use SoftDeletes;
}
```

Add a scheduled command to permanently delete records soft-deleted more than 30 days ago:

```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [DesignProject::class, DesignAsset::class]])
         ->daily();
```

```php
// app/Models/DesignProject.php
use Illuminate\Database\Eloquent\Prunable;

public function prunable(): Builder
{
    return static::where('deleted_at', '<=', now()->subDays(30));
}
```

---

## 3. Schema Additions

### 3a. Add `team_id` to `design_projects`

To support team-owned (shared) projects alongside personal ones:

```php
// database/migrations/xxxx_add_team_id_to_design_projects.php
Schema::table('design_projects', function (Blueprint $table) {
    $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete()->after('user_id');
    $table->string('visibility')->default('private')->after('unit'); // private | team
    $table->index(['team_id', 'visibility']);
});
```

### 3b. Add `thumbnail_path` to `design_projects`

Store a generated PNG thumbnail path for the project grid cards:

```php
$table->string('thumbnail_path')->nullable()->after('unit');
```

Populate via a queued job that renders the first canvas page after every save.

### 3c. Add `use_count` to templates

Track how many times each template has been used for "trending" sorting:

```php
Schema::table('design_assets', function (Blueprint $table) {
    $table->unsignedBigInteger('use_count')->default(0)->after('meta');
    $table->index(['type', 'use_count']); // trending templates query
});
```

### 3d. Add `cost_usd` to `ai_generation_logs`

```php
Schema::table('ai_generation_logs', function (Blueprint $table) {
    $table->decimal('cost_usd', 8, 6)->nullable()->after('tokens_used');
});
```

### 3e. Add `onboarding_completed_at` to `users`

```php
Schema::table('users', function (Blueprint $table) {
    $table->timestamp('onboarding_completed_at')->nullable()->after('profile_photo_path');
});
```

---

## 4. JSON Column Validation

`design_canvases.elements` is a raw JSON column. Without a schema, invalid or oversized payloads can be stored silently.

**Mitigations:**
1. Validate at the application layer (see SECURITY.md §8a).
2. Add a PostgreSQL `CHECK` constraint on column size:

```sql
ALTER TABLE design_canvases
  ADD CONSTRAINT elements_max_size
  CHECK (octet_length(elements::text) < 5242880); -- 5 MB max
```

Express via a raw migration statement:

```php
DB::statement('ALTER TABLE design_canvases ADD CONSTRAINT elements_max_size CHECK (octet_length(elements::text) < 5242880)');
```

---

## 5. Cascade Behaviour Audit

Current cascades:

| Child table | On parent delete |
|---|---|
| `design_canvases` | CASCADE (project deleted → canvases deleted) ✓ |
| `design_assets` | CASCADE (user deleted → assets deleted) ✓ |
| `ai_generation_logs.design_project_id` | SET NULL (project deleted → log kept, project_id = null) ✓ |
| `ai_generation_logs.user_id` | CASCADE (user deleted → logs deleted) ✓ |

**Review:** When a `Team` is deleted, `design_projects.team_id` should be SET NULL (not cascade-deleted) so team-project history is preserved for the owning user.

---

## 6. PostgreSQL-Specific Optimisations

Since the stack uses PostgreSQL 16:

### 6a. GIN index for full-text search on names

```sql
CREATE INDEX idx_design_projects_name_fts ON design_projects USING GIN (to_tsvector('english', name));
CREATE INDEX idx_design_assets_name_fts ON design_assets USING GIN (to_tsvector('english', name));
```

This enables fast `@@ to_tsquery` queries without Meilisearch for simple search scenarios.

### 6b. JSONB instead of JSON

If not already set, ensure `elements` uses the `jsonb` type in PostgreSQL (faster reads, supports indexing):

```php
// In migration:
$table->jsonb('elements')->nullable();
// Instead of:
$table->json('elements')->nullable();
```

### 6c. Partial index for active projects

Most queries filter by `user_id` and exclude soft-deleted records:

```sql
CREATE INDEX idx_active_projects ON design_projects (user_id, updated_at DESC)
  WHERE deleted_at IS NULL;
```

---

## 7. Database Seeding

Production-safe seed data for templates and sample assets:

```php
// database/seeders/TemplateSeeder.php
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['name' => 'Instagram Post',     'type' => 'template', 'meta' => ['width' => 1080, 'height' => 1080, 'category' => 'social']],
            ['name' => 'LinkedIn Banner',    'type' => 'template', 'meta' => ['width' => 1584, 'height' => 396,  'category' => 'social']],
            ['name' => 'A4 Flyer',           'type' => 'template', 'meta' => ['width' => 2480, 'height' => 3508, 'category' => 'print']],
            ['name' => 'Email Header',       'type' => 'template', 'meta' => ['width' => 600,  'height' => 200,  'category' => 'email']],
            ['name' => 'Presentation Slide', 'type' => 'template', 'meta' => ['width' => 1920, 'height' => 1080, 'category' => 'presentation']],
        ];

        foreach ($templates as $template) {
            DesignAsset::firstOrCreate(
                ['name' => $template['name'], 'type' => 'template'],
                array_merge($template, ['user_id' => null, 'file_path' => '', 'mime_type' => 'application/json', 'file_size' => 0])
            );
        }
    }
}
```

---

## 8. Migration Best Practices

- **Never edit existing migrations** after they have been run in any environment. Always add new migrations.
- **Name migrations descriptively:** `add_team_id_to_design_projects`, not `update_design_projects`.
- **Make migrations reversible:** Always implement `down()` unless the migration is destructive by intent.
- **Wrap DDL in transactions** (PostgreSQL supports transactional DDL):

```php
public function up(): void
{
    DB::transaction(function () {
        Schema::table('design_projects', function (Blueprint $table) {
            $table->string('visibility')->default('private')->after('unit');
        });
    });
}
```

---

## 9. Backup Strategy

- **PostgreSQL continuous archiving (WAL):** Configure `wal_level = replica` and stream WAL to S3 using pgWAL or Barman.
- **Daily logical dump:** `pg_dump dotdesign | gzip | aws s3 cp - s3://backups/dotdesign/$(date +%Y-%m-%d).sql.gz`
- **Retention:** Keep 7 daily + 4 weekly + 12 monthly backups.
- **Test restores:** Run a monthly restore drill into a staging environment and verify schema + row counts.

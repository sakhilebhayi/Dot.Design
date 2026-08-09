# Token Consumption Drift Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build this platform's first-ever scheduled process — a token-consumption drift scan — that detects when a platform's pinned token-set version has fallen behind and surfaces it, purely informational (no gate, per the confirmed design).

**Architecture:** A new `design-system:scan-token-drift` console command (this repo's first scheduled job) compares every `TokenConsumptionRecord.pinned_version` against its `TokenSet.version`. Drifted records get a `TokenDriftNotice` (created or refreshed); caught-up records with an open notice get it auto-cleared. The existing `token-sets.show` page renders a drift indicator per consuming platform.

**Tech Stack:** Laravel 13 (pgsql in production, sqlite in tests), plain Blade + resource controllers, PHPUnit.

## Global Constraints

- No new controller, no new route, no gate — this feature only detects and surfaces; it never changes `pinned_version` itself (that stays exclusively human-driven via the existing `TokenConsumptionRecordController::update()`, unchanged by this plan).
- `TokenDriftNotice` is global/shared, not team-scoped — matches every other model in this domain (`TokenSet`, `DesignToken`, `Component`, `TokenConsumptionRecord` all lack a `team_id`/`user_id` column).
- Tests use `Model::create()` directly (confirmed: no factories exist for the design-system domain models, and `tests/Feature/TokenSetTest.php` already establishes `Model::create()` as this repo's convention here — not `Model::factory()`, which is Dot.Central's convention, not this repo's).
- Match `resources/views/design-system/token-sets/show.blade.php`'s existing `.dot-card`/`.dot-badge`/`var(--text)`/`var(--text-dim)` styling convention exactly for any new markup.
- Per this repo's own `CLAUDE.md` Laravel Boost guidelines ("Verification Scripts" section): do not create verification scripts or use `tinker` when tests cover the functionality and prove it works.
- Run `vendor/bin/pint --dirty --format agent` after every task before committing.

---

### Task 1: `token_drift_notices` table + model + `design-system:scan-token-drift` command + schedule

**Files:**
- Create: `database/migrations/2026_08_09_000001_create_token_drift_notices_table.php`
- Create: `app/Models/TokenDriftNotice.php`
- Create: `app/Console/Commands/ScanTokenDrift.php`
- Modify: `routes/console.php` (add the schedule entry)
- Test: `tests/Feature/Console/ScanTokenDriftCommandTest.php`

**Interfaces:**
- Produces: `TokenDriftNotice` model, `$fillable = ['token_set_id', 'platform_id', 'pinned_version', 'current_version', 'detected_at', 'cleared_at']`, relation `tokenSet()`. Artisan command `design-system:scan-token-drift`.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000001_create_token_drift_notices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_drift_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_set_id')->constrained()->cascadeOnDelete();
            $table->string('platform_id');
            $table->unsignedInteger('pinned_version');
            $table->unsignedInteger('current_version');
            $table->timestamp('detected_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['token_set_id', 'platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_drift_notices');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: runs without error.

- [ ] **Step 3: Write the model**

Create `app/Models/TokenDriftNotice.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenDriftNotice extends Model
{
    protected $fillable = [
        'token_set_id', 'platform_id', 'pinned_version',
        'current_version', 'detected_at', 'cleared_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function tokenSet(): BelongsTo
    {
        return $this->belongsTo(TokenSet::class);
    }
}
```

- [ ] **Step 4: Write the failing command test**

Create `tests/Feature/Console/ScanTokenDriftCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\TokenConsumptionRecord;
use App\Models\TokenDriftNotice;
use App\Models\TokenSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanTokenDriftCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeTokenSet(int $version): TokenSet
    {
        return TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette-'.uniqid(),
            'version' => $version,
        ]);
    }

    public function test_a_drifted_record_creates_exactly_one_notice(): void
    {
        $tokenSet = $this->makeTokenSet(3);
        $record = $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();
        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertDatabaseCount('token_drift_notices', 1);
        $this->assertDatabaseHas('token_drift_notices', [
            'token_set_id' => $tokenSet->id,
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
            'current_version' => 3,
            'cleared_at' => null,
        ]);
    }

    public function test_a_record_already_current_creates_no_notice(): void
    {
        $tokenSet = $this->makeTokenSet(2);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.billing',
            'pinned_version' => 2,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertSame(0, TokenDriftNotice::count());
    }

    public function test_a_record_that_catches_up_gets_its_notice_auto_cleared(): void
    {
        $tokenSet = $this->makeTokenSet(2);
        $record = $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.central',
            'pinned_version' => 1,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();
        $notice = TokenDriftNotice::firstOrFail();
        $this->assertNull($notice->cleared_at);

        $record->update(['pinned_version' => 2, 'last_synced_at' => now()]);
        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertNotNull($notice->fresh()->cleared_at);
    }

    public function test_a_record_that_falls_further_behind_refreshes_the_existing_notice(): void
    {
        $tokenSet = $this->makeTokenSet(2);
        $record = $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.central',
            'pinned_version' => 1,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();
        $notice = TokenDriftNotice::firstOrFail();
        $originalDetectedAt = $notice->detected_at;

        $tokenSet->update(['version' => 5]);
        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertSame(1, TokenDriftNotice::count());
        $notice->refresh();
        $this->assertSame(5, $notice->current_version);
        $this->assertEquals($originalDetectedAt, $notice->detected_at);
    }
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Console/ScanTokenDriftCommandTest.php`
Expected: FAIL — command `design-system:scan-token-drift` does not exist yet.

- [ ] **Step 6: Write the command**

Create `app/Console/Commands/ScanTokenDrift.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\TokenConsumptionRecord;
use App\Models\TokenDriftNotice;
use Illuminate\Console\Command;

class ScanTokenDrift extends Command
{
    protected $signature = 'design-system:scan-token-drift';

    protected $description = 'Detect design-token consumers whose pinned version has fallen behind the current token set, and surface it -- never changes pinned_version itself.';

    public function handle(): int
    {
        $records = TokenConsumptionRecord::with('tokenSet')->get();
        $processed = 0;

        foreach ($records as $record) {
            try {
                $this->evaluate($record);
                $processed++;
            } catch (\Throwable $e) {
                $this->error("Failed to evaluate consumption record #{$record->id}: {$e->getMessage()}");
            }
        }

        $this->info("Evaluated {$processed} consumption record(s).");

        return self::SUCCESS;
    }

    private function evaluate(TokenConsumptionRecord $record): void
    {
        $tokenSet = $record->tokenSet;

        $existingNotice = TokenDriftNotice::where('token_set_id', $tokenSet->id)
            ->where('platform_id', $record->platform_id)
            ->whereNull('cleared_at')
            ->first();

        if ($record->pinned_version < $tokenSet->version) {
            if ($existingNotice) {
                $existingNotice->update(['current_version' => $tokenSet->version, 'pinned_version' => $record->pinned_version]);
            } else {
                TokenDriftNotice::create([
                    'token_set_id' => $tokenSet->id,
                    'platform_id' => $record->platform_id,
                    'pinned_version' => $record->pinned_version,
                    'current_version' => $tokenSet->version,
                    'detected_at' => now(),
                ]);
            }

            return;
        }

        $existingNotice?->update(['cleared_at' => now()]);
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Console/ScanTokenDriftCommandTest.php`
Expected: PASS, 4 tests, 0 failures.

- [ ] **Step 8: Add the schedule entry**

`routes/console.php` currently has only the stock `inspire` command. Add:

```php
use App\Console\Commands\ScanTokenDrift;
use Illuminate\Support\Facades\Schedule;

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-token-drift-detection.md.
Schedule::command(ScanTokenDrift::class)
    ->daily()
    ->withoutOverlapping();
```

- [ ] **Step 9: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_08_09_000001_create_token_drift_notices_table.php \
  app/Models/TokenDriftNotice.php \
  app/Console/Commands/ScanTokenDrift.php \
  routes/console.php \
  tests/Feature/Console/ScanTokenDriftCommandTest.php
git commit -m "$(cat <<'EOF'
feat: design-system:scan-token-drift -- this platform's first scheduler entry

Detects TokenConsumptionRecords whose pinned_version has fallen
behind their TokenSet's current version and creates a
TokenDriftNotice (or refreshes one already open if the set moved
again before the platform synced). Auto-clears once the platform
catches up -- safe, since that only ever reflects a fact a human
already made true via the existing
TokenConsumptionRecordController::update(), unchanged by this
commit. Never writes pinned_version itself.

Purely informational: no gate, no new controller, no new route --
per the confirmed design, this domain has no natural Level 2 (the
only consequential action is already fully human-driven and there's
no live distribution API to act on).

Scheduled daily in routes/console.php, this platform's first
Schedule:: entry.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Surface drift on the token-set show page

**Files:**
- Modify: `app/Http/Controllers/TokenSetController.php` (`show()` loads active notices)
- Modify: `resources/views/design-system/token-sets/show.blade.php` (drift indicator per row)
- Test: `tests/Feature/TokenSetTest.php` (extend with drift-indicator cases)

**Interfaces:**
- Consumes: `TokenDriftNotice` (Task 1).

- [ ] **Step 1: Write the failing feature test cases**

Add to `tests/Feature/TokenSetTest.php` (append inside the existing class,
after `test_a_token_set_can_be_created_and_shows_up_in_the_index`):

```php
    public function test_show_page_flags_a_platform_with_an_active_drift_notice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tokenSet = TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette-drift',
            'version' => 3,
        ]);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
        ]);
        $tokenSet->driftNotices()->create([
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
            'current_version' => 3,
            'detected_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('design-system.token-sets.show', $tokenSet));

        $response->assertOk();
        $response->assertSee('Drifted');
    }

    public function test_show_page_does_not_flag_a_platform_with_no_drift_notice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $tokenSet = TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette-no-drift',
            'version' => 1,
        ]);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.billing',
            'pinned_version' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('design-system.token-sets.show', $tokenSet));

        $response->assertOk();
        $response->assertDontSee('Drifted');
    }
```

This requires a `driftNotices(): HasMany` relation on `TokenSet` — add it
to `app/Models/TokenSet.php` alongside the existing `tokens()`/
`consumptionRecords()` relations:

```php
public function driftNotices(): HasMany
{
    return $this->hasMany(TokenDriftNotice::class);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/TokenSetTest.php`
Expected: FAIL — the two new tests fail (`assertSee('Drifted')` finds
nothing; `TokenSet::driftNotices()` doesn't exist yet either, so this may
error rather than just fail the assertion — either way, confirm the two
new tests are the ones failing before proceeding).

- [ ] **Step 3: Wire active notices into `TokenSetController::show()`**

In `app/Http/Controllers/TokenSetController.php`, update `show()`:

```php
public function show(TokenSet $tokenSet): View
{
    $tokenSet->load(['tokens', 'consumptionRecords']);

    $driftedPlatforms = $tokenSet->driftNotices()
        ->whereNull('cleared_at')
        ->pluck('platform_id')
        ->all();

    return view('design-system.token-sets.show', compact('tokenSet', 'driftedPlatforms'));
}
```

- [ ] **Step 4: Render the drift indicator**

In `resources/views/design-system/token-sets/show.blade.php`, inside the
"Consuming Platforms" table's header row, add one more `<th>` after
"Last synced":

```blade
<th style="padding:0.6rem 0.75rem;color:var(--text-dim);font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;">Status</th>
```

And inside the `@foreach ($tokenSet->consumptionRecords as $record)` loop,
add one more `<td>` after the "Last synced" cell:

```blade
<td style="padding:0.6rem 0.75rem;">
    @if(in_array($record->platform_id, $driftedPlatforms))
        <span class="dot-badge" style="background:rgba(239,68,68,0.12);color:#ef4444;">Drifted</span>
    @else
        <span class="dot-badge" style="background:rgba(34,197,94,0.12);color:#22c55e;">Up to date</span>
    @endif
</td>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/TokenSetTest.php`
Expected: PASS, 6 tests (4 existing + 2 new), 0 failures.

- [ ] **Step 6: Manual verification**

Per this repo's own no-tinker rule, do not verify with `tinker` or a
throwaway script — the feature test in Step 5 already exercises this.
Skip manual verification.

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/TokenSetController.php \
  app/Models/TokenSet.php \
  resources/views/design-system/token-sets/show.blade.php \
  tests/Feature/TokenSetTest.php
git commit -m "$(cat <<'EOF'
feat: show token-consumption drift status on the token-set page

TokenSetController::show() now loads active TokenDriftNotices for
the token set and passes the drifted platform_id list to the view;
the existing Consuming Platforms table gets a Status column showing
Drifted / Up to date per row. Read-only surfacing, no new action --
matches the confirmed Level-1-only design.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Full regression

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: 0 failures.

- [ ] **Step 2: Run Pint across the whole repo**

Run: `vendor/bin/pint --format agent`
Expected: `passed` (or auto-fixes with no functional change).

- [ ] **Step 3: Report**

Report the final test count and confirm the working tree is clean
(`git status --short`). No manual tinker verification, per this repo's own
Laravel Boost guideline — the test suite (Tasks 1-2) already proves the
scan and display work.

---

## Self-Review Notes

- **Spec coverage:** §1 (table + model) → Task 1 Steps 1, 3. §2 (command +
  schedule) → Task 1 Steps 6, 8. §3 (view) → Task 2 Steps 3-4. Testing
  Strategy → Task 1 Step 4, Task 2 Step 1. All spec sections have a task.
- **Placeholder scan:** none found — every step has complete code.
- **Type consistency:** `TokenDriftNotice::$fillable` (Task 1 Step 3)
  matches every `::create()`/`::update()` call across Tasks 1-2 exactly.
  `TokenSet::driftNotices()` (Task 2 Step 1) is added once and consumed
  identically in Task 2 Steps 1 and 3. `$driftedPlatforms` (a plain array
  of `platform_id` strings) is produced in Task 2 Step 3 and consumed via
  `in_array()` in Task 2 Step 4 — no type mismatch.

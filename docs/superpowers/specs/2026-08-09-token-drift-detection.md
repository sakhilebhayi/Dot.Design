# Token Consumption Drift Detection — Design Spec

## Context

This spec is part of the ecosystem-wide Autonomy & Owner-Independence
Program (per [brain.autonomy.md](https://github.com/sakhilebhayi/Dot.Brain/blob/main/brain.autonomy.md)
§2), applied here to Dot.Design.

**The platform audit was checked against real code and found accurate.**
[`Dot.Brain/platforms/dot-design.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-design.md)
reports zero background automation — confirmed directly: `routes/console.php`
has only the stock `inspire` command, no `app/Console/Commands/`, no
`app/Jobs/`. The audit's own Gap Summary suggests two directions: standing
up the already-specified `.github/workflows/ci.yml` (infrastructure, not
an application-domain gate), or a queued AI-generation job per
`docs/AI-INTEGRATION.md` (a user-triggered async job, not an unattended
process — doesn't fit this program's scope, same reasoning already applied
to Dot.Auction's `AiSqlService`).

**The real, better-grounded gap** lives in the design-token/component-library
domain (added 2026-08-01, alongside the pre-existing canvas/AI tool). Its
own migration comment names it explicitly: *"Consumption record: which
ecosystem platform consumes which token set, pinned to which version...
no drift-detection or breaking-change notice mechanism implemented."*

**No natural Level 2 exists here, and that's an honest finding, not a
gap in the search.** The only consequential action in this domain —
confirming a platform has actually adopted a new token-set version — is
already fully human-driven via the existing
`TokenConsumptionRecordController::update()` endpoint (a human enters the
new `pinned_version`, `last_synced_at` is stamped). There is also no live
cross-platform distribution API (explicitly out of scope per the same
migration comment), so the system has no action available to gate even if
it wanted to. The user confirmed building the Level 1 piece only.

## Goal

Detect when a platform's pinned token-set version has fallen behind the
token set's current version, and surface it — purely informational,
matching the audit's own suggested "recomputes... on a timer" shape.

## Design

### 1. `token_drift_notices` table + `TokenDriftNotice` model

| Column | Type | Notes |
|---|---|---|
| `token_set_id` | FK → `token_sets`, cascade delete | |
| `platform_id` | string | matches `token_consumption_records.platform_id` (not a FK — that column isn't one either, it's a plain ecosystem-platform identifier string) |
| `pinned_version` | unsigned int | snapshot of the drifted `pinned_version` at detection time |
| `current_version` | unsigned int | snapshot of `token_sets.version` at detection time |
| `detected_at` | timestamp | |
| `cleared_at` | nullable timestamp | set automatically once the platform catches up |
| timestamps | | |

Mirrors the `triggered_at`/`cleared_at` shape Dot.Central's `Alert` already
uses for the same "raised, later resolved" lifecycle. Global/shared, not
team-scoped — matches every other model in this domain
(`TokenSet`/`DesignToken`/`Component`/`TokenConsumptionRecord` all lack a
`team_id`/`user_id` column, per the migration's own tenancy note).

### 2. `app/Console/Commands/ScanTokenDrift.php` (`design-system:scan-token-drift`)

This platform's first scheduled command:

```
for each TokenConsumptionRecord (eager-load tokenSet):
    existingNotice = active (cleared_at is null) TokenDriftNotice for (token_set_id, platform_id)

    if record.pinned_version < record.tokenSet.version:
        if existingNotice:
            # refresh the snapshot rather than leaving it stale -- if the
            # token set moved again while still unresolved, the notice
            # should reflect how far behind the platform actually is now.
            # detected_at is preserved (this is the same drift episode,
            # not a new one).
            existingNotice.update(pinned_version: record.pinned_version,
                                   current_version: record.tokenSet.version)
        else:
            TokenDriftNotice::create(token_set_id, platform_id,
                pinned_version: record.pinned_version,
                current_version: record.tokenSet.version,
                detected_at: now())
    else:
        # caught up -- auto-clear if a notice is still open. Safe: this
        # only ever reflects a fact a human already made true via
        # TokenConsumptionRecordController::update(); the command never
        # changes pinned_version itself.
        if existingNotice:
            existingNotice.update(cleared_at: now())
```

Each record processed inside its own try/catch — one bad row is logged
and skipped, not allowed to abort the whole scan (matches
`DetectRetentionPurgeCandidates`'s established per-row resilience
convention from this program's earlier work).

Scheduled in `routes/console.php` — this platform's first `Schedule::`
entry — `->daily()->withoutOverlapping()`. Daily, not hourly or every-few-
minutes like the other platforms in this program: token sets are edited by
hand through the CRUD controllers, not on any fast-moving schedule, so
there's no value in checking more often.

### 3. View

`resources/views/design-system/token-sets/show.blade.php`'s existing
"Consuming Platforms" table gets one more column: a drift indicator per
row, driven by whether an active `TokenDriftNotice` exists for that
`(token_set_id, platform_id)` pair. `TokenSetController::show()` loads the
active notices alongside the existing `tokens`/`consumptionRecords` eager
loads and passes them to the view keyed by `platform_id` for an O(1) lookup
per row rather than an N+1 query.

## Testing Strategy

- `tests/Feature/Console/ScanTokenDriftCommandTest.php`: a drifted
  consumption record (`pinned_version < version`) creates exactly one
  notice and doesn't duplicate it on a second run; a record already at the
  current version creates no notice; a record that catches up between two
  runs gets its existing notice auto-cleared; a record that falls further
  behind while already flagged (the token set bumps again before the
  platform syncs) still has exactly one active notice, but its
  `current_version` is refreshed to the new value while `detected_at` stays
  at the original detection time.
- `tests/Feature/TokenSetTest.php` gets one more case: the show page
  displays a drift indicator for a platform with an active notice and
  doesn't for one without.

## Out of Scope

- Any live cross-platform distribution API or push mechanism — this spec
  only ever reads `pinned_version`/`version`, never causes a platform to
  actually update.
- Any UI/endpoint for a human to acknowledge or dismiss a notice directly —
  the notice self-clears once the underlying `pinned_version` catches up,
  via the existing manual sync flow. Adding a separate acknowledge action
  would be inventing a Level 2 step the user explicitly chose not to add.
- Breaking vs. non-breaking change classification — `token_sets.version` is
  a plain monotonic integer (confirmed via the migration), not semver, so
  there's no encoded distinction to build a notice-severity tier on.
- CI/CD (`.github/workflows/ci.yml`) — the audit's other candidate,
  infrastructure rather than an application-domain autonomy gap.

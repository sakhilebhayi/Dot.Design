# R-45 owner runbook: Dot.Design in production

For the person who owns the platform. Everything here is a command you run on the host (SSH into the cPanel account, then `cd` to the app folder) or a setting you change in a `.env`, cPanel or another Dot platform. The pipeline and its checklist are in `docs/deployment.md`; this file is what happens **after** a deploy and when something goes wrong.

Two rules apply to every `.env` change below:

1. **The host runs from a cached configuration.** After editing `<app>/.env` run `php artisan config:cache` (or redeploy), otherwise the app keeps using the old values. The queue worker and the scheduler start a fresh process every minute, so they pick the change up once the cache is rebuilt.
2. **Check yourself with `php artisan design:preflight`.** It reads the configuration the app actually uses and prints PASS / INFO / OFF / WARN / FAIL lines with the fix. An optional integration that is switched off reads `OFF`, never an error.

## 1. First steps after the first R-45 deploy

Do these in order, once.

1. **Read the deploy log** for `Preflight OK`, `== Database restore point`, `Templates: N created`, and `== Smoke check passed.` Then run `php artisan design:preflight` yourself and read the WARN lines (they are the host settings in `docs/deployment.md`: PHP limits, `SESSION_SECURE_COOKIE`, mail, cron).
2. **Seed the reference data** (the deploy already seeded the 58 templates):
   - Templates: `php artisan design:seed-templates --check` validates every definition and writes nothing; `php artisan design:seed-templates` upserts them by slug (safe to repeat; a slug that a person saved as their own template is skipped and reported, never overwritten).
   - Design-system catalogue, **once**: `php artisan db:seed --class=DesignSystemSeeder --force`. Do not repeat it casually; it resets token sets an operator has edited. **Never** run a bare `php artisan db:seed` in production (it creates `test@example.com` with a known password).
3. **Make yourself the platform operator.** Sign in through InfoDot once (that creates your account), then `php artisan design:operator you@yourdomain.co.za`. Operators can publish curated templates and edit the design-system catalogue; the flag does not give access to other people's designs. `php artisan design:operator you@yourdomain.co.za --revoke` withdraws it.
4. **Link each workspace to its InfoDot company** (sign-in does it automatically; manual: `php artisan infodot:link-team {team id} {infodot uuid}`). Dot.Notify and Dot.Files address a business by that uuid, and a personal workspace has none, so it sends no email and cannot file to Dot.Files.
5. **Upload one 8 MB picture** as a normal user and **open a design and save it**. This proves `upload_max_filesize` (which the deploy cannot test), the GD memory limit and the compare-and-swap save through the real web server.
6. **Install the backup** (section 3). Do this on day one, not later.
7. **Mark the platform live in InfoDot** (`docs/deployment.md`, "InfoDot").

## 2. Switch features on

### The assistant (AI)

It is off until a key exists, and every other feature works without it. With a key, **each compose, improve and copy request is a billed Anthropic Messages call** (nothing else in the product calls a model).

1. Create an API key in the Anthropic console for this platform only, with a spend limit set there.
2. In the host `.env`: `ANTHROPIC_API_KEY=...` (the key is sent only in a header and never logged). Optional: `DESIGN_AI_MODEL` (default `claude-sonnet-5-5`), `DESIGN_AI_USER_DAILY=60`, `DESIGN_AI_WORKSPACE_DAILY=300`, `DESIGN_AI_PER_MINUTE=12`.
3. `php artisan config:cache`, then `php artisan design:preflight`: the line reads `Assistant (AI)  PASS  on, model ...`.
4. As a user, open the assistant panel: it shows today's usage against both caps.

**Cost control, in plain terms.** Spend is bounded by three things you set: the per-person and per-workspace daily call caps (failed calls count, refused ones do not), the per-minute limiter, and the size of one call (prompt at most 1,500 characters, an instruction 500; the reply is capped at 2,048 tokens for compose, 4,096 for improve and 1,536 for copy). The worst case per day is therefore *(number of workspaces) x (workspace cap) calls*, each with the token ceilings above; multiply by the model's current price from your Anthropic console to get a number in rand. Start with the defaults, look at the Anthropic usage page after a week, and lower the caps if the figure surprises you. Setting `DESIGN_AI_ENABLED=false` switches the assistant off again without removing the key. Proposals are deleted after 90 days by the scheduled `design:prune-ai-proposals`.

### Integrations (each is off until its flag AND its URL and secret are set)

**Do the other platform's step first.** A flag switched on before that only produces failed calls (the integration's circuit breaker then pauses it for two minutes after five failures in a row, and preflight fails an enabled integration with an empty secret).

| Integration | On the OTHER platform first | Then here (`.env`, then `config:cache`) | Needs |
|---|---|---|---|
| **Dot.Notify** (emails on shares, review requests and decisions, comments) | In Dot.Notify's `.env` set `NOTIFY_INGEST_SECRET_DESIGN` to a new long random value. Each business then creates a rule per `design.*` event in Notify (four events; without a rule Notify stores the event and sends nothing). | `DESIGN_INTEGRATION_NOTIFY=true`, `NOTIFY_INGEST_URL=https://<notify host>/api/ingest/events`, `NOTIFY_INGEST_SECRET=<the same value>` | the queue worker (cron) and a linked workspace |
| **Dot.Files** (save an export to the business's Dot.Files) | A change in Dot.Files adding `'design' => env('DOT_SERVICE_SECRET_DESIGN')` to its `platform_secrets`, deployed; then set `DOT_SERVICE_SECRET_DESIGN` on the Files host. **Until that change is deployed Files answers 401.** | `DESIGN_INTEGRATION_FILES=true`, `FILES_API_URL=https://files.infodot.co.za`, `FILES_PLATFORM_SECRET=<the same value>` | a linked workspace |
| **InfoDot membership webhook** (removed members lose access at once) | Add `design` to `ECOSYSTEM_MEMBERSHIP_PLATFORMS` in InfoDot and make sure InfoDot's registry URL for Design is the real host. | `DESIGN_INTEGRATION_MEMBERSHIP=true` (it reuses `INFODOT_SERVICE_SECRET`) | nothing else |
| **Dot.Analytics** (two daily counts: projects and assets) | `ANALYTICS_INGEST_SECRET_DESIGN` in Analytics and the operator's company uuid. Analytics cannot receive anything more until its catalogue is extended. | `ANALYTICS_INGEST_URL=.../api/ingest/snapshots`, `ANALYTICS_INGEST_SECRET`, `ANALYTICS_COMPANY_UUID` (all three or none) | the scheduler (cron) |

Check each with `php artisan design:preflight` and with the signed-in page `/design/integrations`, which shows the live state and a plain reason for the platforms that have no contract. Dot.Tasks, Dot.Projects, Dot.Agents, Dot.Brain, Dot.Memory, Dot.Emall, Dot.Pulse, Dot.HR, Dot.Engage, Dot.Forms and Dot.Press are listed there as *no contract* with the reason; nothing is simulated for them. Full detail: `docs/R45_INTEGRATIONS.md`.

## 3. Backups

What must survive: the **PostgreSQL database** and **`storage/app/private/design-assets`** (every uploaded picture and every project thumbnail). Also keep a copy of `APP_KEY` and the `.env` somewhere safe and off the host; without the key encrypted values and sessions cannot be recovered. The code is in git and the build is rebuilt by every deploy.

**What the pipeline already does:** every deploy takes a `pg_dump` and a files copy first (the newest three of each are kept). That is a pre-release restore point, not a backup schedule.

**Daily backup.** `scripts/ops/backup.sh` writes `db-<UTC>.dump` (PostgreSQL custom format, verified readable with `pg_restore --list`), `assets-<UTC>.tar.gz` (verified readable) and a `.sha256` beside each into `~/dot-design-backups` (mode 700), keeps the newest 14 of each, and exits non-zero with the reason on any failure. Run it once by hand and look at the output:

```
cd <app folder> && scripts/ops/backup.sh
```

Then add the cron line. **The marker must be `# design-backup`**: the deploy's crontab step removes every line containing `# dot-design`, so a backup line marked `# dot-design-backup` would be wiped by the next deploy. The account's crontab is shared by all Dot apps and is written under a lock; add the line the same way the deploy does, or, if the account runs the cron runner, put the command (without the schedule) in `~/.dot-cron.d/design-backup.cron`:

```
( flock 9; crontab -l > ~/.crontab.before-design-backup && { cat ~/.crontab.before-design-backup; echo '17 2 * * * cd <app folder> && exec scripts/ops/backup.sh >> storage/logs/backup.log 2>&1 # design-backup'; } | crontab - ) 9>~/.dot-crontab.lock
crontab -l | grep -c .      # the line count must be exactly one more than before
```

(`crontab -l` has been seen answering empty for a moment; if `~/.crontab.before-design-backup` is empty and you know the crontab is not, stop and do not write.) 02:17 is chosen to sit away from the other apps' minute-zero jobs. **Nothing here is installed by the pipeline.**

- **Off the account.** Backups on the same account do not survive losing the account. Set `BACKUP_OFFSITE=user@otherhost:/path/` in the cron line's environment to rsync the folder out after each run, or fetch `~/dot-design-backups` from your machine.
- **Disk.** 14 daily copies of a growing upload folder add up; the script refuses to run with under 500 MB free, and `design:preflight` warns on low space. Lower the count with `BACKUP_KEEP=7` if needed.
- **Watch it.** The last line of `storage/logs/backup.log` should be today's `backup ok`. A missing or stale line means no backup was made.

**Restore drill, monthly.** An untested backup is not a backup. `scripts/ops/restore-drill.sh` restores the newest dump into a scratch database (never the live one), unpacks the newest assets archive into a temp folder, checks the checksum, that every product table exists, the row counts next to the live ones, and that every upload older than the archive has its file in it, then prints how many seconds it took (your real recovery time) and drops the scratch database. Shared hosts often refuse `CREATE DATABASE`: create an empty database in cPanel, grant it to the same role and pass `--target-db NAME`.

### Restore (for real)

1. `php artisan down`. 2. Note the backup you will use (`ls -lt ~/dot-design-backups`; for a bad release, `~/dot-design-db-*.dump`). Verify it: `sha256sum -c db-<stamp>.dump.sha256` from inside the folder. 3. `pg_restore --clean --if-exists --no-owner --exit-on-error -h <DB_HOST> -U <DB_USERNAME> -d <DB_DATABASE> db-<stamp>.dump` (everything written since that backup is lost). 4. Files, only if they were lost: `tar xzf assets-<stamp>.tar.gz -C storage/app/private`. 5. `php artisan config:cache && php artisan up`, then `php artisan design:preflight`.

## 4. Monitoring and alerts

The host has no monitoring stack; these are the cheap signals that exist, in the order of how much they tell you.

| Signal | How | What it means |
|---|---|---|
| Uptime | Point any external monitor at `https://design.infodot.co.za/up` (200 = the app boots; Laravel's health route) and alert on two misses. | The site is down or in maintenance mode (a failed deploy leaves it in maintenance mode on purpose). |
| The environment | `php artisan design:preflight` (add `--strict` to fail on warnings), weekly or after any host change. | Missing extension, limits, a queue that stopped draining, no cron, failed jobs, low disk. |
| Errors | The **Server Logs** workflow (Actions tab) prints the newest 120 lines of `storage/logs/laravel*.log` and the last output of each cron line without a shell. With `LOG_LEVEL=warning` the log holds real problems only. | A line with `production.ERROR` is a request that failed; send it to the developer with the time. |
| Cron alive | `storage/logs/cron-scheduler.log` and `cron-queue.log` are rewritten every minute; their modified time should be under two minutes old. `php artisan design:preflight` prints "Cron seen". | The scheduler or the worker stopped. After a deploy the lines are re-installed; check `crontab -l | grep '# dot-design'` shows exactly two. |
| Queue | `php artisan queue:failed` lists failed jobs (`queue:retry all` retries them); preflight warns when jobs wait over 15 minutes. | Notify emails or the Analytics snapshot are not leaving. |
| Backups | The last line of `storage/logs/backup.log`; the monthly restore drill. | See section 3. |
| Security reports | `grep csp.violation storage/logs/laravel*.log`. The CSP is report-only; the reports say what an enforced policy would block. | Review before ever setting `SECURITY_CSP_REPORT_ONLY=false`. |
| Assistant spend | The Anthropic console's usage page; in the app, `/design/assistant/status` shows each person's day. | Section 2. |
| Disk | cPanel's disk usage, or preflight's "Free disk space". Uploads live in `storage/app/private/design-assets` and every rollback copy and backup is a copy of it. | Prune old rollback copies in `~` first. |

There is no Dot.Brain guardian endpoint for this app, and none is claimed.

## 5. Rollback

**A bad release (the site is up but wrong).** Prefer a forward fix: revert the offending change on `main` and run `confirm=deploy` again (about five minutes; the log prints the code version before and after). The database is not rolled back by this, and the R-45 migrations are additive, so older code keeps working against the newer schema for the tables it knows.

**A failed release (the site is in maintenance mode).** Read the `::error::` line. The cause is nearly always one of: `design:preflight` FAIL (it names the setting), `design:seed-templates --check` refused a template, a migration error, a missing PHP extension. Fix the cause and run the same workflow again; it brings the site up itself. If the cause cannot be fixed quickly, restore the previous release: `tar xzf ~/dot-design-deploy-<time>.tgz` over the app folder (stop writes first), `composer install --no-dev`, redeploy the previous commit to rebuild the frontend, `php artisan up`. The database stays as migrated unless step 3 below is needed.

**When the data must go back** (a migration or a bug corrupted rows): restore the release's dump (`~/dot-design-db-deploy-<time>.dump`, section 3 "Restore"). You lose everything people saved after the release started, so decide quickly and tell the users. Never run `migrate:rollback` on the live database: the `down()` methods drop the new columns and tables, and everything stored in them.

## 6. Revoke share links

A revoked link answers the same plain not-found page as an unknown one, immediately. Each link revoked gets a `share.revoked` entry in the workspace audit trail.

- One design: `php artisan design:revoke-shares --project=<design id>` (the id is in the editor address, `/design/projects/<id>`).
- Every link of every design (a leak, an incident): `php artisan design:revoke-shares --all`. It shows how many links exist and how many still work, and asks to confirm (`--yes` skips the question).
- People can revoke their own links from the Share dialog.

Links cannot be revived; the owner makes a new one. Shared pictures inside a link are 10-minute signed URLs, so they stop working within ten minutes of the link being revoked at the latest.

## 7. Privacy: purge a workspace

`php artisan design:purge-workspace <team id>` shows what Dot.Design holds for one workspace and deletes nothing. Adding `--execute` (and answering the confirmation, or `--yes`) erases it for real:

- all designs with their pages, versions, shares, comments, reviews, grants and exports;
- every uploaded picture and thumbnail, **both the database rows and the files** on the private disk;
- the workspace's brand kits, its own templates, assistant proposals, Dot.Files links and its audit trail.

**Left on purpose:** the workspace itself and its members (InfoDot owns them: remove the company or the people there), files that were already filed in Dot.Files (another system: delete them there), and **one audit entry** `workspace.purged` carrying the workspace id, the counts and the time, with no names or content, so there is a record that the erasure happened. If your policy requires even that to go, delete the row from `design_activity` where `action = 'workspace.purged'` by hand.

Notes:

- Deleting a workspace in Jetstream or in InfoDot does **not** erase its designs: the database only detaches them, and they stay, private to their authors. Run the purge first, then delete the workspace.
- **A person's own account**: deleting an account (Profile, Delete account) now also removes that person's uploaded pictures and project thumbnails from the disk. Before R-45's fix these files were left behind; files orphaned by earlier deletions can be found by comparing `storage/app/private/design-assets` with the `design_assets.file_path` column (a file with no row is an orphan) and deleted by hand.
- Backups still contain the data until they age out (14 days by default) and the pre-release dumps are kept for the last three releases. A strict erasure request must say so.
- Version history, comments and activity of **other** workspaces that mention the erased people are not touched.

## 8. Routine tasks

| When | What |
|---|---|
| After any `.env` edit | `php artisan config:cache`, `php artisan design:preflight` |
| After adding or editing a template definition | merge, deploy (the deploy runs `design:seed-templates`), check the "Templates:" line |
| Weekly | `php artisan design:preflight`; look at the failed jobs; glance at the Server Logs workflow |
| Monthly | the restore drill; the Anthropic usage page; disk usage; prune old rollback copies in `~` |
| When a person leaves a business | they lose access at their next sign-in, or at once once the membership webhook is on; their designs in the workspace stay with the workspace |
| Before the InfoDot domain or host changes | update `APP_URL`, `INFODOT_*`, the integration URLs and the platform entry in InfoDot, redeploy |

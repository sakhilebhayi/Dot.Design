# DevOps and infrastructure: what actually exists

This file used to be a proposal (Docker Compose, Caddy, Horizon, Pulse, Sentry). None of that was adopted, and the document described a system that is not running. It was replaced on 2026-11-09 by this description of the real setup; the old proposal is in git history. The detailed procedures live in `docs/deployment.md` (pipeline, host, `.env`) and `docs/R45_OPERATIONS.md` (the owner's runbook).

## Environments

| | Local | CI (GitHub Actions) | Production |
|---|---|---|---|
| Where | the developer's machine | `ubuntu-latest` runners | the shared cPanel host, `https://design.infodot.co.za` |
| PHP | 8.3 or newer (8.5 in development) | 8.4 | 8.3 or newer, set per domain in cPanel |
| Database | SQLite or PostgreSQL | SQLite in memory (the suite), a throw-away SQLite file (the smoke) | PostgreSQL |
| Queue | `sync` or `database` | `sync` | `database`, drained by a cron-started worker |
| Frontend | `npm run dev` or `npm run build` | built once by the `js` job | built on the runner, rsynced to the host (no Node on the host) |
| Cache, sessions | file or database | array | database |

## Pipeline

- **CI** (`.github/workflows/ci.yml`, every pull request and push to `main`): `tests` (PHPUnit), `js` (JS suite, Vite build, manifest check), `signed-in-smoke` (production-like boot, every key page). Composer and npm caches are keyed on their lock files.
- **Deploy** (`deploy.yml`, manual): CI gate, build, rollback copy and `pg_dump`, maintenance mode, code, migrate, seed templates, caches, `design:preflight`, `up`, cron lines, live smoke check. A failure after maintenance mode starts leaves the site down on purpose, with the reason in the log.
- **Drift report** (`drift.yml`) and **Server Logs** (`server-logs.yml`) are read-only helpers.
- There is no staging environment; the signed-in smoke job is the closest thing to one.

## Runtime on the shared host

No daemon can run. The scheduler (`schedule:run`) and the queue worker (`queue:work --stop-when-empty --tries=3 --max-time=50`) are started by two cron lines every minute. No WebSocket server (Reverb) runs, and nothing in the app broadcasts. Static files (`public/build`, `public/fonts`, images, scripts) may be served from copies in the docroot, which the deploy keeps in step. Private uploads are under `storage/app/private/design-assets`, outside anything the web server serves.

## Observability

There is no APM or error tracker. The signals are `/up`, `php artisan design:preflight`, the Laravel log (read through the Server Logs workflow), the cron output logs, the failed-jobs table, and the CSP report-only entries; the table in `docs/R45_OPERATIONS.md` section 4 says what each means.

## Secrets

Production secrets live only in the host's `.env`, which the pipeline never overwrites and never prints, and in the repository's Actions secrets (`DEPLOY_*`). Integration secrets are one shared value per pair of platforms (for example `NOTIFY_INGEST_SECRET` here equals `NOTIFY_INGEST_SECRET_DESIGN` in Dot.Notify). Nothing is committed; `.env.example` holds names and safe defaults only.

## Backups and recovery

`scripts/ops/backup.sh` (PostgreSQL dump and the private asset folder, with retention and checksums) and `scripts/ops/restore-drill.sh` (proves a backup restores, and how long it takes) exist and are tested. The pipeline does not install the backup cron line; the owner does (`docs/R45_OPERATIONS.md` section 3). Until that line exists there is no scheduled backup, only the pre-release dump each deploy takes.

# Deploying Dot.Design

Target: `https://design.infodot.co.za`, on the shared cPanel host that also runs the other Dot apps. Everything goes through GitHub Actions, run by hand. What the owner does **after** a deploy is in `docs/R45_OPERATIONS.md`.

**What was and was not verified.** The workflows pass `actionlint` and `shellcheck`; the host script's static-folder sync, the `pg_dump` block, the backup and restore scripts and the migrations were run locally against a copy of the layout and a PostgreSQL 16 database. Nothing here has run against the real host since the R-45 rebuild. The first R-45 release is therefore the first time the new steps meet the host: read its log (see "Reading the log").

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR and push to `main`; called by `deploy.yml` | **tests** (PHPUnit, includes the `design:preflight` and docs-sync tests), **js** (JS suite, Vite build, manifest check), **signed-in smoke** (production-like boot, signs in, walks every key page). It is the release gate. |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256) and prints counts and paths. Run it before a takeover. |
| `deploy.yml` | manual: `confirm=adopt` once, then `confirm=deploy` | See "First deploy", "Every later release" and the header of the file. |
| `server-logs.yml` | manual, read-only | Tails the newest Laravel log and the last output of each cron line. |

## What a release does (so you can read the log)

1. **Check the inputs** (nothing runs, nothing is spent, if a secret is missing).
2. **CI gate**: the three CI jobs above.
3. **Build** on the runner: `npm ci`, `npm run build`, the manifest check (`scripts/ops/check-vite-manifest.mjs`: every `@vite` entry in every Blade view is in the build, every file exists, and no view that uses `@vite` names a `resources/` path that is not a real entry, comments included). The build is uploaded to `public/build.incoming` on the host.
4. **On the host, before maintenance mode** (a failure here leaves the live site on the previous release): rollback copy of the files (`~/dot-design-<mode>-<time>.tgz`, newest three kept), **`pg_dump` of the database** (`~/dot-design-db-<mode>-<time>.dump`, custom format, newest three kept), the app-folder guard.
5. **Maintenance mode starts.** Code to `origin/main`, `composer install --no-dev`, `APP_DEBUG=false` and `APP_ENV=production` forced, the new `public/build` swapped in, the docroot's copies kept in step (below), the private storage folders created.
6. **Database and data**: `migrate --force`, then `design:seed-templates --check` (a refused template **fails the deploy**, before anything is written), then `design:seed-templates` (idempotent upsert by slug; a slug that belongs to a template someone saved is skipped with a warning).
7. **Caches** (`config`, `route`, `view`, `event`; targeted clears, never `optimize:clear`), `schedule:list`, then **`design:preflight`**: a `FAIL` line stops the release and **leaves the site in maintenance mode on purpose** with the reason in the log; `WARN`, `INFO` and `OFF` lines never do.
8. `artisan up`.
9. **Cron lines** (two, through the shared crontab block) and the **smoke check**: `/`, `/login`, `/up`; every built entry; the self-hosted fonts (200, a real woff2, not HTML); one file from **every static folder** the checkout ships (proves the docroot serves them); `/design/new` redirects a guest to `/login`; a made-up `/s/<token>` answers the neutral 404 with no cookie; the security headers; the private storage paths are not served; no debug page on `POST /up`. Two **warnings, never failures**: hashed assets served without long cache headers, and a 9 MB `POST` refused for size (`post_max_size` too low for 8 MB uploads).

A failure **before** maintenance mode leaves the live site on the previous release. A failure **after** it starts leaves the site down, in maintenance mode, with the rollback copy named in the log: fix the cause and run the same workflow again; it brings the site back up itself. It is never brought back automatically, because an automatic rollback once fired after failures that never touched the server.

The signed-in pages are **not** checked against the live host (it has no seeded user and a test user must never be created there): the CI `signed-in smoke` job covers them on every pull request, and the deploy's unauthenticated smoke covers wiring.

## Prerequisites checklist

Do these in order. The pipeline updates a live install; it does not create the host, the database or the `.env`.

### Hosting

- [ ] **DNS**: a record for `design.infodot.co.za` pointing at the cPanel host.
- [ ] **cPanel subdomain** `design.infodot.co.za` with its document root and an issued TLS certificate (AutoSSL), so `https://design.infodot.co.za` answers.
- [ ] **Layout like the other Dot apps**: the Laravel app in a folder under the document root, with the web server serving its `public/` the way the other apps' folders do. The pipeline finds the app as the one `artisan` within two levels of `~/design.infodot.co.za` (set the secret `DEPLOY_PATH` to skip the search).
- [ ] **PHP 8.3 or newer** for the subdomain (`composer.json` requires `^8.3`; CI runs 8.4; the development machine runs 8.5). The `php` found by a non-interactive SSH session must be that version.
- [ ] **PHP extensions** (cPanel, Select PHP Version): `mbstring`, `intl`, `bcmath`, `curl`, `fileinfo`, `openssl`, `ctype`, `json`, `pdo`, `pdo_pgsql` (the production database) and **`gd` built with JPEG, PNG and WebP** (uploads are re-encoded through it; without WebP support they would be stored unmodified, with their metadata). `Zend OPcache` is strongly advised. Not needed: `zip`, `exif`, `imagick`, `redis`. `design:preflight` checks every one of these.
- [ ] **PHP limits** (cPanel, MultiPHP INI Editor, for this domain). The editor autosaves documents up to about 1 MB, uploads are up to 8 MB, and the image sanitiser holds a decoded picture in memory at four bytes per pixel:

  | Setting | Set | Why |
  |---|---|---|
  | `upload_max_filesize` | `10M` | The app allows 8 MB per picture; a lower value fails larger uploads at the server with a generic message. |
  | `post_max_size` | `12M` | Must exceed the upload plus its form data, and is what a page save (up to 1.4 MB) is measured against. Below 2M saving breaks (preflight `FAIL`). |
  | `memory_limit` | `256M` | A 12-megapixel camera photo needs about 50 MB to decode and about 100 MB when it must be rotated. At `128M` pictures above roughly 20 megapixels are refused with a plain message instead of crashing (the app allows 40, and a rotated photo needs a second copy). Preflight prints the estimate for the value found. |
  | `max_input_vars` | `3000` | The default 1000 is enough; more is harmless. |
  | `max_execution_time` | `60` | Exports are made in the browser, but AI calls and Dot.Files uploads wait on the network. |

  `design:preflight` reads the limits of the PHP **binary that runs it (the CLI)**, which on cPanel can differ from the web server's. The deploy smoke check therefore also proves `post_max_size` through the real web stack (a 9 MB `POST` must not be refused with 413). `upload_max_filesize` cannot be proved without a signed-in upload: after the first release, upload an 8 MB picture once.
- [ ] On the host: `git`, `composer`, `rsync`, `tar`, `flock`, `crontab`, and **`pg_dump`** (the PostgreSQL client tools; the pre-release database dump needs them; if the account has none, take your own dump and run with `skip_db_dump=skip`). There is **no Node** on the host, by design.
- [ ] **A deploy SSH key pair**: the public half authorised for the cPanel account, the private half stored as `DEPLOY_SSH_KEY`. `DEPLOY_KNOWN_HOSTS` is the host's `ssh-keyscan` line; confirm its fingerprint with the host provider.

### The docroot and the static folders

The application's `public/` folder holds files the browser fetches directly: `build/` (the hashed CSS and JS), **`fonts/ui/`** (2 interface fonts), **`fonts/design/`** (55 editor font files, 1.3 MB), `images/`, `js/` and the icons and `robots.txt` in the root. On this host the document root may serve **copies** of these rather than the app's own `public/` (Dot.Doc 404'd its assets for days that way; InfoDot and the Dopemine pilot hit the same trap). The pipeline therefore keeps them in step, for **every** top-level entry of `public/` except `index.php`, `.htaccess`, `hot`, `storage` and the build folders (so a static folder added later needs no workflow change):

| In the docroot | What the pipeline does |
|---|---|
| nothing | links it to `<app>/public/<name>` |
| a symlink | leaves it alone |
| a real folder | `rsync -a` the shipped files in; deletes nothing |
| a real root file (`robots.txt`, an icon) that differs | **does not overwrite it**; prints a warning naming it |
| the document root is `<app>/public` itself (the "docroot move") | nothing to sync, reported in the log |

`build/` keeps its own rule (mirrored with `--delete`, because it is generated). **Uploaded designs are never in the docroot**: they live on the private disk `storage/app/private/design-assets`, which holds pictures and project thumbnails, and the smoke check requests those paths to prove they are not served. Do not point `DESIGN_ASSET_DISK` at `public`.

### Cache headers (host requirement)

`public/build/assets/*` are content-hashed and the fonts never change under the same name, so both should be cached for a year. The application cannot set headers on static files; the docroot's `.htaccess` (the one the web server reads) must, for example:

```apache
<IfModule mod_mime.c>
    AddType font/woff2 .woff2
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.woff2$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
    <If "%{REQUEST_URI} =~ m#^/build/assets/#">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </If>
</IfModule>
```

If `public/.htaccess` already carries rules like these (the performance work may add them), copy the same rules into the docroot's file when the docroot is not `<app>/public`. The smoke check prints a warning with the header it saw if `/build/assets/...` is not long-cached; nothing breaks, repeat visits are slower.

### Database

- [ ] A **PostgreSQL** database and role created in cPanel. Replace `.env.example`'s `DB_DATABASE=infodot` / `DB_USERNAME=postgres` with the real names. Each release writes its own `pg_dump` (step 4 above); for the very first migration on an existing database also take one by hand.
- [ ] The R-45 migrations are additive and were run on a PostgreSQL copy with thousands of rows (`docs/DATABASE.md`, "Backfills and safety"). They add 17 tables and nullable columns, backfill `team_id` from each owner's personal team, and run in a fraction of a second. Never roll them back on the live database; restore the pre-release dump instead.

### The host `.env`

The pipeline requires `<app>/.env` to exist and never overwrites it. Create it from `.env.example` (every variable is commented there). On every run the pipeline forces `APP_DEBUG=false` and `APP_ENV=production`, and warns about an `http://` `APP_URL`.

| Variable | Value | Why |
|---|---|---|
| `APP_KEY` | `base64:` plus 32 random bytes, once (`php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`) | Generate it **before** the first run (no `vendor/` exists yet to run `key:generate`). Never change it afterwards: it signs everyone out and breaks anything encrypted. Keep a copy off the host. |
| `APP_URL` | `https://design.infodot.co.za` | Links in emails and share links. |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | the cPanel database | The deploy's `pg_dump` and `scripts/ops/backup.sh` read these. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` | Their tables come from the migrations. |
| `SESSION_SECURE_COOKIE` | `true` | The session cookie must not travel over http (preflight warns otherwise). |
| `LOG_LEVEL` | `warning` | |
| `BROADCAST_CONNECTION` | `log` | No WebSocket server can run on shared hosting and nothing in the app broadcasts. |
| `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | the SMTP account the other Dot apps use | `log` silently writes every mail to the log file. This mailer carries password resets; share, review and comment emails go through Dot.Notify. |
| `INFODOT_REDEEM_URL`, `INFODOT_PLATFORM_KEY=design`, `INFODOT_SERVICE_SECRET` | `https://infodot.co.za/api/ecosystem/redeem`, `design`, a long random value **equal to `ECOSYSTEM_SECRET_DESIGN` in InfoDot's `.env`** | Without the secret nobody can sign in through InfoDot (sign-up is closed). Never commit it or print it. |
| `INFODOT_REDEEM_TIMEOUT`, `INFODOT_PROVISION_USERS` | `5`, `true` | `false` stops InfoDot creating accounts here. |
| `DOT_INFODOT_URL` (and the other `DOT_*_URL`) | at least `https://infodot.co.za` | `config/ecosystem.php` defaults InfoDot to `https://infodot.co.za` and the others to dead `https://*.infodot.app` addresses. The InfoDot one feeds the "InfoDot" and "Platforms" links on Home. |
| `ALLOW_REGISTRATION` | `false` | |
| `SECURITY_HSTS_SECONDS`, `SECURITY_CSP_REPORT_ONLY` | `15552000`, `true` | The CSP is report-only until the `csp.violation` log entries have been reviewed. |
| `ANTHROPIC_API_KEY` | blank until the assistant is wanted | **Setting a key makes the assistant bill per call.** `DESIGN_AI_ENABLED`, `DESIGN_AI_MODEL` (default `claude-sonnet-5-5`), `DESIGN_AI_USER_DAILY` (60), `DESIGN_AI_WORKSPACE_DAILY` (300), `DESIGN_AI_PER_MINUTE` (12), `DESIGN_AI_TIMEOUT` (60), `DESIGN_AI_RETRIES` (2), `DESIGN_AI_BASE_URL` bound it. See `docs/R45_OPERATIONS.md`. |
| `AI_IMAGE_DRIVER` | `mock` | The legacy placeholder driver; nothing in the product links to it. Leave as is. |
| `DESIGN_INTEGRATION_NOTIFY`, `NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET` | `false` until the other side is ready | Dot.Notify email. Needs the queue worker. |
| `DESIGN_INTEGRATION_FILES`, `FILES_API_URL`, `FILES_PLATFORM_SECRET` | `false` until the other side is ready | Save exports to Dot.Files. |
| `DESIGN_INTEGRATION_MEMBERSHIP` | `false` until InfoDot lists `design` | Instant removal of ex-members. Uses `INFODOT_SERVICE_SECRET`. |
| `ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET`, `ANALYTICS_PLATFORM_KEY`, `ANALYTICS_COMPANY_UUID` | all three of url, secret, company uuid, or none | Two daily counts to Dot.Analytics; nothing is sent until all three are set. |
| `DESIGN_ASSET_QUOTA_MB`, `DESIGN_ASSET_UPLOADS_PER_MINUTE`, `DESIGN_ASSET_REENCODE`, `DESIGN_*_PER_MINUTE` | optional | Defaults 200, 20, `true`, and the throttles in `.env.example`. |

An integration flag set to `true` with its URL or secret missing is a `design:preflight` **FAIL** (it would fail on every call); one set to `false` reads `OFF`, never an error. What each integration needs from the other platform first is in `docs/R45_INTEGRATIONS.md` ("Owner-only steps") and `docs/R45_OPERATIONS.md`.

### InfoDot (the sign-in hub)

- [ ] **`ECOSYSTEM_SECRET_DESIGN`** in InfoDot's `.env`, equal to `INFODOT_SERVICE_SECRET` above, and `'design'` in the `service_secrets` list of InfoDot's `config/ecosystem.php`. InfoDot refuses this app's redeem call otherwise.
- [ ] **Mark the platform live** (`'active' => true` for `'design'`), only once the app really answers, so no one is sent to a dead address. InfoDot's `DOT_DESIGN_URL` should be `https://design.infodot.co.za`.
- [ ] `ECOSYSTEM_TRUSTED_DOMAINS` in InfoDot keeps its default `infodot.co.za`, which covers `design.infodot.co.za`.

### Repository secrets (Settings, Secrets and variables, Actions)

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | `design.infodot.co.za` |
| `DEPLOY_USER` | the cPanel account user (the one the other Dot apps deploy as) |
| `DEPLOY_SSH_KEY` | the private deploy key |
| `DEPLOY_KNOWN_HOSTS` | the host's `known_hosts` line |
| `DEPLOY_PATH` | optional: the absolute app folder, to skip the search for `artisan` |

## First deploy

1. Finish the checklist. A brand-new, empty subdomain needs a first copy of the code and a `.env` before the pipeline can take over: `git clone https://github.com/sakhilebhayi/Dot.Design.git` into a folder under the document root, then create `.env` as above.
2. Run **Drift Report** and read it. For a fresh clone of `main` it says the host already matches.
3. Run **Deploy to Production** with `confirm=adopt`. It writes the rollback copy and the database dump, makes the folder a checkout of `main` without deleting anything untracked (`.env`, `storage/`), installs dependencies, ships the build, syncs the static folders, forces the production settings, **lists** the pending migrations without running them (no seeding or preflight then: the schema is knowingly behind), rebuilds the caches, installs the cron lines and checks the site. Add `migrate=migrate` only on a database you do not mind migrating at once (a brand-new empty one is fine).
4. Read the pending-migrations list in the log, then run it again with `confirm=deploy`, which migrates, seeds the templates and runs the preflight.
   Load the design-system reference data once, from the app folder: `php artisan db:seed --class=DesignSystemSeeder --force` (idempotent; **do not repeat it casually**, it resets tokens an operator edited). **Never run a bare `php artisan db:seed` in production**: `DatabaseSeeder` creates `test@example.com` with a known password.
5. Grant the first platform operator (`docs/R45_OPERATIONS.md`, "First steps"), then open `https://design.infodot.co.za/login` and sign in through InfoDot.
6. Only then mark the platform live in InfoDot.

## Every later release

**Routine checklist**

1. CI is green on the commit you are releasing (the deploy runs it again as its gate).
2. Skim the PR list for new migrations and new `.env` variables; add any new variable to the host `.env` first (`design:preflight` will say if a required one is missing).
3. `gh workflow run deploy.yml -f confirm=deploy` (or the Actions tab).
4. Watch the log for `== Rollback copy`, `== Database restore point`, `== Pending migrations`, `Templates: ... created, ... updated`, the `design:preflight` summary (`Preflight OK: ...`), `== Deployed: <sha>`, `== crontab: 2 line(s) ... installed, M other line(s) preserved` (M must never shrink between runs) and `== Smoke check passed.`
5. Open the site, sign in, open a design, change something, see "Saved".

Optional input `skip_db_dump=skip` deploys without the pre-release dump; use it only when the host has no `pg_dump` and you took your own.

## Cron and queue lines

The deploy installs **2 lines** in the account's single crontab, each ending in the marker `# dot-design`. The crontab is shared by every Dot app, so the step follows the rules learned when concurrent deploys once wiped it: it takes the shared lock (`~/.dot-crontab.lock`, `flock`), reads the crontab with a retry because `crontab -l` can transiently answer empty (it never writes after an empty read), replaces only the lines carrying its own marker, reads the crontab back, and restores the previous one if another app's line count changed or its own count is wrong. When the account runs the bounded-concurrency cron runner, the commands go to `~/.dot-cron.d/` instead.

```
* * * * * cd <app folder> && <php> artisan schedule:run > storage/logs/cron-scheduler.log 2>&1 # dot-design
* * * * * cd <app folder> && <php> artisan queue:work --stop-when-empty --tries=3 --max-time=50 > storage/logs/cron-queue.log 2>&1 # dot-design
```

**The marker rule matters for backups.** The deploy removes every crontab line containing the text `# dot-design`. The backup line in `docs/R45_OPERATIONS.md` therefore uses the marker `# design-backup`, which does not contain it; never give another line a marker that starts with `# dot-design`.

`schedule:run` runs `routes/console.php`: the daily token-drift scan (`design-system:scan-token-drift`), the daily Dot.Analytics snapshot (`design:publish-metrics`, 00:10 UTC) and the daily pruning of old assistant proposals (`design:prune-ai-proposals`, 03:30). `php artisan schedule:list` (printed in every deploy log) and `design:preflight` both confirm they are registered.

**Queue worker, the cPanel way.** The account cannot run a daemon, so the worker is started by cron every minute, drains the queue and exits (`--stop-when-empty`, at most 50 seconds). A queued job normally runs within a minute, a job released for a retry waits for a later cron minute, new code is picked up on the next start without any `queue:restart`, and nothing is processed while the site is in maintenance mode. Queued work: the Dot.Notify listener (`SendDesignNotifications`, three tries with 60 s and 300 s backoff, and it is not even queued while the integration is off) and the Dot.Analytics snapshot job. **Everything uses the default queue**, so the worker needs no `--queue` argument and no daemon is required. `jobs` and `failed_jobs` come from the migrations; preflight warns when jobs wait longer than 15 minutes (the cron worker is not draining them) or when `failed_jobs` is not empty (`php artisan queue:failed`, `queue:retry all`).

First run: the account's line total grows by 2.

## Rollback

- **Files**: every run writes `~/dot-design-<mode>-<UTC time>.tgz` (the app folder without `vendor/`, `node_modules/`, logs and the build; it includes `storage/app` and `.env`); the newest three are kept. To restore: `php artisan down`, `tar xzf` it over the app folder, `composer install --no-dev`, redeploy to rebuild the frontend, then `php artisan up`.
- **Code**: the pipeline always deploys `origin/main`. To go back, revert the change on `main` and run `confirm=deploy`. The log prints `== Current release: <sha>` before and `== Deployed: <sha>` after.
- **Database**: migrations are not rolled back (their `down()` drops the new tables and columns). Restore the pre-release dump (`~/dot-design-db-*.dump`) as described in `docs/R45_OPERATIONS.md` ("Rollback"); restoring means losing everything written since the release started, so decide quickly after a bad release and prefer a forward fix when the damage is only cosmetic.
- On `adopt`, host-only commits survive on a local branch `host-before-adopt-<time>`, and host-only migration files are moved to `~/dot-design-quarantine-<time>`, never deleted.
- The rollback copies include `storage/app/private/design-assets` (every upload). Three copies of a growing upload folder fill the account's quota: watch the disk (preflight reports free space).

## Reading the log

- The Actions run log shows the steps in order: the input and secret check, the three CI jobs, the build, "Locate the app", the staged upload, "Deploy the code", "Install the scheduler cron lines" and the smoke check. Lines starting `==` are the host script's progress. An `::error::` line names the cause and says whether the site is still up; `::warning::` lines are things to look at that did not stop the release.
- **Server Logs** (`server-logs.yml`) prints the last 120 lines of the newest `storage/logs/laravel*.log` and the last 10 lines of each `storage/logs/cron-*.log`. It is a fixed command, never a shell.
- To see the environment the way the release saw it, on the host: `php artisan design:preflight` (add `--strict` to treat warnings as failures, `--json` for a machine-readable result).

## Known limits

- **Reverb cannot run under shared cPanel** (a long-running WebSocket process). The pipeline never starts, restarts or reloads it. Nothing in Dot.Design broadcasts, so nothing is lost; real-time co-editing is not built (`docs/R45_ARCHITECTURE.md`, section 8).
- The preflight limits belong to the CLI PHP; the web PHP can differ (see "PHP limits").
- A made-up share link and the neutral 404 are checked live, but a **real** share link is not (the live host has no seeded design). The CI smoke covers it.
- Backups are not installed by the pipeline. `scripts/ops/backup.sh` and `restore-drill.sh` exist and are tested; the cron line is the owner's to add (`docs/R45_OPERATIONS.md`).
- The first deploy after a long gap migrates everything pending in one go; read the pending list from the `adopt` run first.
- `INFODOT_*` redeem only works once InfoDot lists this platform (checklist above).

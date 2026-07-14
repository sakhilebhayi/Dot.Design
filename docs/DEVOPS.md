# DevOps & Infrastructure

> Deployment, CI/CD, monitoring, and infrastructure setup for Dot.Design. Each section is independently applicable.

---

## 1. Environment Matrix

| Environment | Purpose | Database | Queue | Cache |
|---|---|---|---|---|
| `local` | Development | SQLite (`:memory:` for tests) | `sync` | `array` |
| `staging` | QA / preview | Shared PostgreSQL (staging schema) | Redis | Redis |
| `production` | Live | Shared InfoDot PostgreSQL | Redis + Horizon | Redis |

---

## 2. CI/CD Pipeline (GitHub Actions)

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: dotdesign_test
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
        ports:
          - 5432:5432

      redis:
        image: redis:7-alpine
        ports:
          - 6379:6379

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo_pgsql, redis, intl
          coverage: xdebug

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}

      - name: Install PHP dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'

      - name: Install Node dependencies
        run: npm ci

      - name: Build assets
        run: npm run build

      - name: Copy environment file
        run: cp .env.example .env

      - name: Generate application key
        run: php artisan key:generate

      - name: Run migrations
        env:
          DB_CONNECTION: pgsql
          DB_HOST: localhost
          DB_PORT: 5432
          DB_DATABASE: dotdesign_test
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
        run: php artisan migrate --force

      - name: Run tests with coverage
        env:
          DB_CONNECTION: pgsql
          DB_HOST: localhost
          DB_PORT: 5432
          DB_DATABASE: dotdesign_test
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
        run: php artisan test --parallel --coverage --min=70

      - name: Lint PHP
        run: ./vendor/bin/pint --test

      - name: Dependency audit
        run: composer audit

      - name: NPM audit
        run: npm audit --audit-level=high
```

---

## 3. Deployment Workflow

```yaml
# .github/workflows/deploy.yml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    needs: [test]   # only deploy if CI passes
    environment: production

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader --no-interaction

      - name: Build assets
        run: |
          npm ci
          npm run build

      - name: Deploy via SSH (zero-downtime)
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.DEPLOY_HOST }}
          username: ${{ secrets.DEPLOY_USER }}
          key: ${{ secrets.DEPLOY_KEY }}
          script: |
            cd /var/www/dotdesign
            git pull origin main
            composer install --no-dev --optimize-autoloader --no-interaction
            php artisan migrate --force
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
            php artisan event:cache
            php artisan horizon:terminate   # graceful worker restart
            php artisan reverb:restart      # restart WebSocket server
            sudo systemctl reload php8.4-fpm
```

---

## 4. Docker Compose (Local Development)

```yaml
# docker-compose.yml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.dev
    volumes:
      - .:/var/www/html
      - /var/www/html/vendor
      - /var/www/html/node_modules
    ports:
      - "8000:8000"
    depends_on:
      - postgres
      - redis
      - meilisearch
    environment:
      APP_ENV: local
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      REDIS_HOST: redis
      MEILISEARCH_HOST: http://meilisearch:7700

  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: dotdesign
      POSTGRES_USER: dotdesign
      POSTGRES_PASSWORD: secret
    volumes:
      - postgres_data:/var/lib/postgresql/data
    ports:
      - "5432:5432"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  meilisearch:
    image: getmeili/meilisearch:v1.8
    ports:
      - "7700:7700"
    volumes:
      - meilisearch_data:/meili_data
    environment:
      MEILI_MASTER_KEY: masterkey

  horizon:
    build:
      context: .
      dockerfile: Dockerfile.dev
    command: php artisan horizon
    depends_on:
      - redis
    volumes:
      - .:/var/www/html

  reverb:
    build:
      context: .
      dockerfile: Dockerfile.dev
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    ports:
      - "8080:8080"
    depends_on:
      - redis
    volumes:
      - .:/var/www/html

volumes:
  postgres_data:
  meilisearch_data:
```

---

## 5. Production Server Stack

```
┌─────────────────────────────────────────────────────────────┐
│                        Cloudflare CDN                        │
│              (DNS, DDoS protection, TLS termination)         │
└─────────────────────┬───────────────────────────────────────┘
                      │
┌─────────────────────▼───────────────────────────────────────┐
│                    Caddy / Nginx                              │
│           (reverse proxy, gzip, static files)                │
└──────┬──────────────────────────────────────┬───────────────┘
       │                                      │
┌──────▼──────┐                    ┌──────────▼─────────┐
│ PHP-FPM 8.4 │                    │ Laravel Reverb     │
│ (app server)│                    │ (WebSocket server) │
└──────┬──────┘                    └────────────────────┘
       │
┌──────▼────────────────────────────┐
│            Redis                   │
│   (cache + queue + sessions)       │
└──────┬────────────────────────────┘
       │
┌──────▼────────────────────────────┐
│         PostgreSQL 16              │
│   (shared InfoDot instance)        │
└───────────────────────────────────┘
```

---

## 6. Caddy Configuration

```caddyfile
# /etc/caddy/Caddyfile
design.infodot.app {
    root * /var/www/dotdesign/public
    php_fastcgi unix//run/php/php8.4-fpm.sock
    encode gzip zstd

    file_server

    header {
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        Referrer-Policy strict-origin-when-cross-origin
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
    }

    # Cache immutable Vite assets
    @vite_assets path /build/*
    header @vite_assets Cache-Control "public, max-age=31536000, immutable"

    # WebSocket proxy for Reverb
    @websocket {
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @websocket localhost:8080
}
```

---

## 7. Monitoring

### 7a. Laravel Pulse (application metrics)

```bash
composer require laravel/pulse
php artisan pulse:install
php artisan migrate
```

Metrics tracked: slow queries, failed jobs, cache hit/miss, queue depth, request counts.

Access at `/pulse` (restricted to admins via `Gate::define('viewPulse', ...)`).

### 7b. Laravel Horizon (queue monitoring)

```bash
# Start in production (managed by Supervisor)
php artisan horizon
```

Dashboard at `/horizon` with queue throughput, job failure rates, and worker counts.

### 7c. Error tracking

Integrate [Flare](https://flareapp.io) (Laravel-native) or Sentry:

```bash
composer require spatie/laravel-ignition
composer require sentry/sentry-laravel
```

```dotenv
SENTRY_LARAVEL_DSN=https://...
LOG_CHANNEL=stack
```

### 7d. Uptime monitoring

Use [BetterUptime](https://betterstack.com/better-uptime) or [UptimeRobot](https://uptimerobot.com) to alert within 1 minute of downtime.

---

## 8. Environment Secrets Management

**Never store secrets in the repository.** In production:

```bash
# Option A: AWS Secrets Manager (recommended for InfoDot ecosystem)
aws secretsmanager create-secret --name dotdesign/production --secret-string file://.env

# Option B: Doppler
doppler run -- php artisan serve

# Option C: GitHub Actions encrypted secrets
# Reference as ${{ secrets.ANTHROPIC_API_KEY }} in workflows
```

---

## 9. Production Artisan Commands

Run these after every deployment:

```bash
# Clear and rebuild all caches
php artisan optimize

# Equivalent to:
php artisan config:cache   # merges all config files into one PHP file
php artisan route:cache    # serialises all routes
php artisan view:cache     # pre-compiles all Blade templates
php artisan event:cache    # caches event/listener map

# For quick cache clear during incident response:
php artisan optimize:clear
```

Add to deployment script — never run `optimize` without also running `migrate --force` first.

---

## 10. Backup & Disaster Recovery

```bash
# Scheduled in routes/console.php:
Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:clean')->weekly();
```

Using `spatie/laravel-backup`:

```bash
composer require spatie/laravel-backup
```

Configure `config/backup.php`:

```php
'destination' => [
    'disks' => ['s3'],
    'filename_prefix' => 'dotdesign-',
],
'notifications' => [
    'mail' => ['to' => 'ops@infodot.app'],
],
```

Backups include: PostgreSQL dump + `storage/app/` files + `.env`.

**RTO / RPO targets:**

| Metric | Target |
|---|---|
| Recovery Time Objective (RTO) | < 1 hour |
| Recovery Point Objective (RPO) | < 24 hours |
| Backup retention | 7 daily, 4 weekly, 6 monthly |

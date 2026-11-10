#!/usr/bin/env bash
# Run the PHP suite on a throwaway PostgreSQL database (R-45 perf).
# Creates design_perf_<random>, runs `php artisan test -c phpunit.pgsql.xml`, always drops it.
# Never touches any other database. Local socket by default (PGHOST=/tmp); set PGHOST/PGUSER/PGPASSWORD to override.
set -u
cd "$(dirname "$0")/.."
export PGHOST="${PGHOST:-/tmp}"
DB="design_perf_$(LC_ALL=C tr -dc 'a-z0-9' </dev/urandom | head -c 8)"
createdb "$DB" || exit 1
trap 'dropdb --if-exists --force "$DB" >/dev/null 2>&1' EXIT
export DB_HOST="$PGHOST" DB_DATABASE="$DB" DB_USERNAME="${PGUSER:-$(whoami)}" DB_PASSWORD="${PGPASSWORD:-}"
echo "throwaway database: $DB"
php artisan test -c phpunit.pgsql.xml "$@"

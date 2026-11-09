#!/usr/bin/env bash
# Dot.Design backup: the PostgreSQL database and the private design assets, with retention.
#
#   scripts/ops/backup.sh [--db-only] [--dir DIR] [--keep N]
#
# Writes to $BACKUP_DIR (default ~/dot-design-backups, mode 700):
#   db-<UTC>.dump          pg_dump custom format (restore with pg_restore), checked with pg_restore --list
#   assets-<UTC>.tar.gz    storage/app/private/design-assets: every uploaded picture AND every project thumbnail
#                          (both live on the private design_assets disk), checked with tar -t
#   *.sha256               a checksum beside each file
# and keeps the newest N (default 14, or $BACKUP_KEEP) of each kind. Reads the DB_* values from <app>/.env; no secret is
# printed. Exit 0 only when every file written was verified; any failure prints the cause on stderr and exits non-zero
# (a cron mail, or the last line of storage/logs/backup.log, is the alarm).
#
# What it does NOT cover: .env (keep a copy of APP_KEY somewhere else: losing it signs everyone out and breaks anything
# encrypted), the application code (git), and the built assets (rebuilt by the deploy). It also does not leave the
# hosting account: set BACKUP_OFFSITE to an rsync destination (user@host:path) to copy the folder out after each run.
#
# Cron (install it by hand, see docs/R45_OPERATIONS.md; the deploy workflow does NOT manage this line, and its marker
# must not contain "# dot-design", which the deploy's crontab step owns):
#   17 2 * * * cd <app folder> && exec scripts/ops/backup.sh >> storage/logs/backup.log 2>&1 # design-backup
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST="${BACKUP_DIR:-$HOME/dot-design-backups}"
KEEP="${BACKUP_KEEP:-14}"
DB_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --db-only) DB_ONLY=1 ;;
    --dir) DEST="$2"; shift ;;
    --keep) KEEP="$2"; shift ;;
    -h|--help) sed -n '2,25p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
  shift
done
case "$KEEP" in ''|*[!0-9]*|0) echo "--keep must be a positive number" >&2; exit 2 ;; esac

fail() { echo "backup FAILED: $*" >&2; exit 1; }
sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1"; else shasum -a 256 "$1"; fi; }
size() { du -h "$1" | cut -f1; }

[ -f "$APP/.env" ] || fail "$APP/.env not found"
envval() { { grep -E "^$1=" "$APP/.env" || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

[ "$(envval DB_CONNECTION)" = "pgsql" ] || fail "DB_CONNECTION is '$(envval DB_CONNECTION)'; this script backs up PostgreSQL only"
command -v pg_dump >/dev/null 2>&1 || fail "pg_dump is not installed for this account"
command -v pg_restore >/dev/null 2>&1 || fail "pg_restore is not installed for this account (needed to verify the dump)"

umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"

# One run at a time (mkdir is atomic and works on every host).
LOCK="$DEST/.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
  if [ -n "$(find "$LOCK" -maxdepth 0 -mmin +180 2>/dev/null)" ]; then rmdir "$LOCK" 2>/dev/null && mkdir "$LOCK"; else fail "another backup is running ($LOCK); remove it if that is wrong"; fi
fi
PARTIALS=()
cleanup() { for f in "${PARTIALS[@]:-}"; do [ -n "$f" ] && rm -f "$f"; done; rmdir "$LOCK" 2>/dev/null || true; }
trap cleanup EXIT

FREE_KB="$(df -Pk "$DEST" | awk 'NR==2 {print $4}')"
[ "${FREE_KB:-0}" -gt 512000 ] || fail "less than 500 MB free where $DEST lives"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SUMMARY=""

# ---- database
DB_FILE="$DEST/db-$STAMP.dump"
PARTIALS+=("$DB_FILE.partial")
PGPASSWORD="$(envval DB_PASSWORD)" pg_dump --format=custom --no-owner --file="$DB_FILE.partial" \
  --host="$(envval DB_HOST)" --port="$(envval DB_PORT)" --username="$(envval DB_USERNAME)" "$(envval DB_DATABASE)" \
  || fail "pg_dump failed"
pg_restore --list "$DB_FILE.partial" > /dev/null || fail "the dump was written but pg_restore cannot read it"
[ -s "$DB_FILE.partial" ] || fail "the dump is empty"
mv "$DB_FILE.partial" "$DB_FILE"
( cd "$DEST" && sha "db-$STAMP.dump" > "db-$STAMP.dump.sha256" )
SUMMARY="$(basename "$DB_FILE") ($(size "$DB_FILE"))"

# ---- private design assets (uploads + thumbnails)
if [ "$DB_ONLY" -eq 0 ]; then
  ASSET_ROOT="$APP/storage/app/private"
  if [ -d "$ASSET_ROOT/design-assets" ]; then
    A_FILE="$DEST/assets-$STAMP.tar.gz"
    PARTIALS+=("$A_FILE.partial")
    # tar exits 1 when a file changed while it was being read (an upload during the run): the archive is still good, and
    # the verification below is what decides.
    rc=0
    tar czf "$A_FILE.partial" -C "$ASSET_ROOT" design-assets || rc=$?
    [ "$rc" -le 1 ] || fail "tar failed with status $rc"
    tar tzf "$A_FILE.partial" > /dev/null || fail "the assets archive was written but cannot be read back"
    mv "$A_FILE.partial" "$A_FILE"
    ( cd "$DEST" && sha "assets-$STAMP.tar.gz" > "assets-$STAMP.tar.gz.sha256" )
    SUMMARY="$SUMMARY, $(basename "$A_FILE") ($(size "$A_FILE"), $(tar tzf "$A_FILE" | grep -vc '/$' || true) files)"
  else
    SUMMARY="$SUMMARY, no storage/app/private/design-assets yet (nothing uploaded)"
  fi
fi

# ---- retention: the newest $KEEP of each kind
prune() {
  local pattern="$1" n=0 f
  while IFS= read -r f; do
    n=$((n + 1))
    if [ "$n" -gt "$KEEP" ]; then rm -f "$f" "$f.sha256"; fi
  done < <(ls -1t "$DEST"/$pattern 2>/dev/null || true)
}
prune 'db-*.dump'
prune 'assets-*.tar.gz'

# ---- optional off-account copy (nothing is deleted over there)
if [ -n "${BACKUP_OFFSITE:-}" ]; then
  command -v rsync >/dev/null 2>&1 || fail "BACKUP_OFFSITE is set but rsync is not installed"
  rsync -a "$DEST"/ "$BACKUP_OFFSITE" || fail "the off-account copy to $BACKUP_OFFSITE failed (local backups are fine)"
  SUMMARY="$SUMMARY; copied to the off-account destination"
fi

echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) backup ok: $SUMMARY; keeping the newest $KEEP of each in $DEST"

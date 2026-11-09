#!/usr/bin/env bash
# Dot.Design restore drill: proves the newest backup can actually be restored, and how long it takes. It NEVER touches
# the live database or the live asset folder.
#
#   scripts/ops/restore-drill.sh [--dir DIR] [--db FILE] [--assets FILE] [--target-db NAME] [--keep-target]
#
# Restores the newest db-*.dump (or --db) into a scratch PostgreSQL database and the newest assets-*.tar.gz (or
# --assets) into a scratch folder, then checks:
#   - the dump's checksum, when a .sha256 sits beside it;
#   - every table the product needs exists in the restored database and `migrations` is not empty;
#   - row counts of the main tables (shown beside the live ones; the live site moves on, so only a missing table or a
#     restored table that is empty while the live one is not fails the drill);
#   - every design_assets row stored on the private disk has its file inside the restored archive (a sample of 2000).
# Prints the elapsed seconds: that is your realistic recovery time.
#
# The scratch database is created with CREATEDB when the role may; shared hosting often forbids it. Then create an empty
# database in cPanel (PostgreSQL Databases), grant it to the same role, and pass --target-db NAME. A database you pass is
# emptied of the dump's objects with --clean and left in place (--keep-target is implied); one the script created is
# dropped at the end unless --keep-target.
#
# The exact steps to restore for real are in docs/R45_OPERATIONS.md ("Restore").
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST="${BACKUP_DIR:-$HOME/dot-design-backups}"
DB_FILE=""; A_FILE=""; TARGET=""; KEEP_TARGET=0; CREATED=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) DEST="$2"; shift ;;
    --db) DB_FILE="$2"; shift ;;
    --assets) A_FILE="$2"; shift ;;
    --target-db) TARGET="$2"; KEEP_TARGET=1; shift ;;
    --keep-target) KEEP_TARGET=1 ;;
    -h|--help) sed -n '2,24p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
  shift
done

fail() { echo "RESTORE DRILL FAILED: $*" >&2; exit 1; }
[ -f "$APP/.env" ] || fail "$APP/.env not found"
envval() { { grep -E "^$1=" "$APP/.env" || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
HOST="$(envval DB_HOST)"; PORT="$(envval DB_PORT)"; USER_="$(envval DB_USERNAME)"; LIVE="$(envval DB_DATABASE)"
PGPASSWORD="$(envval DB_PASSWORD)"; export PGPASSWORD
PSQL=(psql --host="$HOST" --port="$PORT" --username="$USER_" --no-psqlrc -v ON_ERROR_STOP=1 -At)

[ -n "$DB_FILE" ] || DB_FILE="$(ls -1t "$DEST"/db-*.dump 2>/dev/null | head -1 || true)"
[ -n "$DB_FILE" ] && [ -f "$DB_FILE" ] || fail "no db-*.dump in $DEST"
[ -n "$A_FILE" ] || A_FILE="$(ls -1t "$DEST"/assets-*.tar.gz 2>/dev/null | head -1 || true)"

START=$(date +%s)
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dot-design-drill.XXXXXX")"
cleanup() {
  rm -rf "$WORK"
  if [ "$CREATED" -eq 1 ] && [ "$KEEP_TARGET" -eq 0 ]; then "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET\"" >/dev/null 2>&1 || echo "could not drop scratch database $TARGET; drop it by hand" >&2; fi
}
trap cleanup EXIT

echo "== backup: $(basename "$DB_FILE")${A_FILE:+ and $(basename "$A_FILE")}"

# ---- checksum
if [ -f "$DB_FILE.sha256" ]; then
  EXPECT="$(cut -d' ' -f1 "$DB_FILE.sha256")"
  if command -v sha256sum >/dev/null 2>&1; then GOT="$(sha256sum "$DB_FILE" | cut -d' ' -f1)"; else GOT="$(shasum -a 256 "$DB_FILE" | cut -d' ' -f1)"; fi
  [ "$EXPECT" = "$GOT" ] || fail "checksum of $(basename "$DB_FILE") does not match its .sha256 (the file is damaged)"
  echo "ok  checksum"
else
  echo "..  no .sha256 beside the dump; checksum not checked"
fi

# ---- scratch database
if [ -z "$TARGET" ]; then
  TARGET="${LIVE}_drill_$(date -u +%Y%m%d%H%M%S)"
  "${PSQL[@]}" -d postgres -c "CREATE DATABASE \"$TARGET\"" >/dev/null 2>&1 \
    || fail "this role cannot CREATE DATABASE. Create an empty database in cPanel and pass --target-db NAME."
  CREATED=1
fi
[ "$TARGET" != "$LIVE" ] || fail "refusing to restore over the live database ($LIVE)"
echo "== restoring into scratch database $TARGET"
pg_restore --clean --if-exists --no-owner --exit-on-error --host="$HOST" --port="$PORT" --username="$USER_" --dbname="$TARGET" "$DB_FILE" \
  || fail "pg_restore failed (see above)"
echo "ok  pg_restore"

# ---- tables and counts
MISSING=""
for T in migrations users teams design_projects design_canvases design_assets design_versions design_shares design_comments design_reviews design_activity design_templates brand_kits; do
  ok="$("${PSQL[@]}" -d "$TARGET" -c "SELECT to_regclass('public.$T') IS NOT NULL")"
  [ "$ok" = "t" ] || MISSING="$MISSING $T"
done
[ -z "$MISSING" ] || fail "tables missing after the restore:$MISSING"
echo "ok  all product tables exist"
printf '   %-18s %10s %10s\n' table restored live
BAD=""
for T in migrations users teams design_projects design_canvases design_assets design_versions design_shares design_templates brand_kits; do
  R="$("${PSQL[@]}" -d "$TARGET" -c "SELECT count(*) FROM $T")"
  L="$("${PSQL[@]}" -d "$LIVE" -c "SELECT count(*) FROM $T" 2>/dev/null || echo '?')"
  printf '   %-18s %10s %10s\n' "$T" "$R" "$L"
  if [ "$R" = "0" ] && [ "$L" != "0" ] && [ "$L" != "?" ]; then BAD="$BAD $T"; fi
done
[ -z "$BAD" ] || fail "restored tables are empty while the live ones are not:$BAD"

# ---- assets
if [ -n "$A_FILE" ] && [ -f "$A_FILE" ]; then
  mkdir -p "$WORK/assets"
  tar xzf "$A_FILE" -C "$WORK/assets" || fail "the assets archive cannot be extracted"
  N="$(find "$WORK/assets" -type f | wc -l | tr -d ' ')"
  echo "ok  assets archive extracted ($N files)"
  # Only rows that existed when the archive was taken (its UTC time is in the file name; the app stores UTC) must have a file.
  STAMP="$(basename "$A_FILE" | sed -n 's/^assets-\([0-9]\{4\}\)\([0-9]\{2\}\)\([0-9]\{2\}\)T\([0-9]\{2\}\)\([0-9]\{2\}\)\([0-9]\{2\}\)Z.*/\1-\2-\3 \4:\5:\6/p')"
  [ -n "$STAMP" ] || fail "cannot read the time from the assets file name $(basename "$A_FILE")"
  GONE=0; CHECKED=0; FIRST_GONE=""
  while IFS= read -r rel; do
    CHECKED=$((CHECKED + 1))
    if [ ! -f "$WORK/assets/design-assets/$rel" ]; then GONE=$((GONE + 1)); [ -n "$FIRST_GONE" ] || FIRST_GONE="$rel"; fi
  done < <("${PSQL[@]}" -d "$TARGET" -c "SELECT file_path FROM design_assets WHERE disk = 'design_assets' AND created_at <= '$STAMP' ORDER BY id DESC LIMIT 2000")
  [ "$GONE" -eq 0 ] || fail "$GONE of $CHECKED asset rows older than the archive have no file in it (first: $FIRST_GONE). The archive is incomplete or belongs to another database."
  echo "ok  $CHECKED asset rows older than the archive all have their file"
else
  echo "..  no assets archive found; assets not checked"
fi

echo "RESTORE DRILL PASSED in $(( $(date +%s) - START )) seconds (database $TARGET$([ "$CREATED" -eq 1 ] && [ "$KEEP_TARGET" -eq 0 ] && echo ', dropped' || echo ', left in place'))."

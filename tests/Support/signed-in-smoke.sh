#!/usr/bin/env bash
# Production-like signed-in smoke test for Dot.Design.
#
#   tests/Support/signed-in-smoke.sh            (needs a built frontend: npm run build)
#
# Boots the app the way the live host runs it: APP_ENV=production, APP_DEBUG=false, cached config / routes / events /
# views, the BUILT assets, a throw-away SQLite database. Signs in a seeded user over HTTP (CSRF and all), creates a
# design from a template through the real endpoint, shares it, and then requests every key page and JSON endpoint,
# asserting the status AND a landmark in the body, plus the fonts and the built CSS and JS. It also fails when the
# application log gained an ERROR line, because a page that answers 200 after swallowing an exception is still broken.
#
# Why it exists: the checks that run during a deploy (CI tests with a faked Vite, a guest-page smoke) missed outages that
# only a signed-in, cached, built app shows (a route file registering a limiter, a view naming a missing asset).
#
# Everything lives under one temp folder (database, storage, caches) via LARAVEL_STORAGE_PATH and APP_*_CACHE, so the
# working tree, bootstrap/cache and your .env are left untouched. Environment variables of the shell win over .env.
# Optional: PORT (default 8991), PHP (default php), KEEP=1 (keep the temp folder and print its path).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
PHP="${PHP:-php}"
PORT="${PORT:-8991}"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dot-design-smoke.XXXXXX")"
SERVER_PID=""
FAILS=0
CHECKS=0

cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null
  if [ "${KEEP:-0}" = "1" ]; then echo "kept: $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT

die() { echo "SMOKE SETUP FAILED: $*" >&2; [ -f "$WORK/setup.log" ] && tail -30 "$WORK/setup.log" >&2; exit 2; }

[ -f public/build/manifest.json ] || die "public/build/manifest.json is missing: run npm run build first."
[ -f vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php ] || die "vendor/ is missing: run composer install."
command -v curl >/dev/null || die "curl is required."

# ---- the production-like environment --------------------------------------------------------------------------------
mkdir -p "$WORK/storage/app/private" "$WORK/storage/framework/cache/data" "$WORK/storage/framework/sessions" \
         "$WORK/storage/framework/views" "$WORK/storage/logs" "$WORK/cache"
: > "$WORK/smoke.sqlite"
PASSWORD="$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24)"
export APP_ENV=production APP_DEBUG=false APP_URL="$BASE" APP_NAME="Dot.Design"
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export LARAVEL_STORAGE_PATH="$WORK/storage"
export APP_CONFIG_CACHE="$WORK/cache/config.php" APP_ROUTES_CACHE="$WORK/cache/routes-v7.php" \
       APP_EVENTS_CACHE="$WORK/cache/events.php" APP_SERVICES_CACHE="$WORK/cache/services.php" \
       APP_PACKAGES_CACHE="$WORK/cache/packages.php" VIEW_COMPILED_PATH="$WORK/storage/framework/views"
export DB_CONNECTION=sqlite DB_DATABASE="$WORK/smoke.sqlite" DB_FOREIGN_KEYS=true
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync MAIL_MAILER=log BROADCAST_CONNECTION=log
export LOG_CHANNEL=single LOG_LEVEL=warning SESSION_SECURE_COOKIE=false
export DESIGN_AI_ENABLED=false ANTHROPIC_API_KEY= AI_IMAGE_DRIVER=mock
export DESIGN_INTEGRATION_NOTIFY=false DESIGN_INTEGRATION_FILES=false DESIGN_INTEGRATION_MEMBERSHIP=false
export INFODOT_SERVICE_SECRET= ALLOW_REGISTRATION=false
export SMOKE_EMAIL="smoke@example.test" SMOKE_PASSWORD="$PASSWORD"

step() { echo "== $*"; }
run() { "$@" >> "$WORK/setup.log" 2>&1 || die "'$*' failed"; }

step "preparing a throw-away database and caches ($WORK)"
run $PHP artisan migrate --force
run $PHP artisan design:seed-templates
run $PHP artisan db:seed --class=DesignSystemSeeder --force
$PHP tests/Support/smoke-seed.php > "$WORK/seed.json" 2>> "$WORK/setup.log" || die "seeding the smoke user failed"
run $PHP artisan config:cache
run $PHP artisan route:cache
run $PHP artisan view:cache
run $PHP artisan event:cache

step "design:preflight (warnings are expected on a laptop; failures are not)"
# The manifest, fonts, templates, migrations and caches are real here, so only host-specific items may warn. A
# production boot on 127.0.0.1 makes the APP_URL check fail by design, so that single failure is the one accepted.
$PHP artisan design:preflight --json > "$WORK/preflight.json" 2>> "$WORK/setup.log"
BADPRE="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["results"] as $r){ if($r["status"]==="FAIL" && $r["check"]!=="APP_URL"){ echo "  FAIL ".$r["check"].": ".$r["detail"]."\n"; } if($r["status"]==="WARN"){ fwrite(STDERR, "   warn ".$r["check"]."\n"); } }' "$WORK/preflight.json")"
[ -z "$BADPRE" ] || { echo "$BADPRE"; die "design:preflight failed on a production-like boot"; }

step "starting the server on $BASE"
curl -s -o /dev/null --max-time 1 "$BASE/up" && die "something already answers on $BASE; set PORT to a free port."
( cd public && exec $PHP -S "127.0.0.1:$PORT" "$ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" ) > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null --max-time 2 "$BASE/up" && break
  kill -0 "$SERVER_PID" 2>/dev/null || die "the server stopped: $(tail -5 "$WORK/server.log")"
  sleep 0.2
done

# ---- assertion helpers ----------------------------------------------------------------------------------------------
JAR="$WORK/jar.txt"
BODY="$WORK/body.out"
HEAD="$WORK/head.out"
CSRF=""

pass() { CHECKS=$((CHECKS + 1)); printf '  ok    %s\n' "$1"; }
fail() { CHECKS=$((CHECKS + 1)); FAILS=$((FAILS + 1)); printf '  FAIL  %s\n' "$1"; }

# get PATH EXPECTED_STATUS LANDMARK_REGEX [curl args...]  (cookie jar used; an empty LANDMARK skips the body check)
get() {
  local path="$1" want="$2" landmark="$3"; shift 3
  local code
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' "$@" "$BASE$path")
  if [ "$code" != "$want" ]; then fail "GET $path answered $code, expected $want"; return 1; fi
  if [ -n "$landmark" ] && ! grep -Eqi -- "$landmark" "$BODY"; then fail "GET $path answered $want but the body lacks /$landmark/ (title: $(grep -o '<title>[^<]*' "$BODY" | head -1 | sed 's/<title>//'))"; return 1; fi
  pass "GET $path $code"
}

# send METHOD PATH JSON EXPECTED_STATUS  (JSON with CSRF header, session cookie)
send() {
  local method="$1" path="$2" json="$3" want="$4" code
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' -X "$method" \
    -H "Accept: application/json" -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" \
    --data "$json" "$BASE$path")
  if [ "$code" != "$want" ]; then fail "$method $path answered $code, expected $want ($(head -c 200 "$BODY" | tr '\n' ' '))"; return 1; fi
  pass "$method $path $code"
}

json_get() { php -r '$d=json_decode(file_get_contents($argv[1]),true); $v=$d; foreach(explode(".",$argv[2]) as $k){ if(!is_array($v)||!array_key_exists($k,$v)){ exit(3);} $v=$v[$k]; } echo is_scalar($v)?$v:json_encode($v);' "$BODY" "$1"; }

# ---- 1. public surface, signed out ----------------------------------------------------------------------------------
step "signed out"
get "/up" 200 ""
get "/" 200 "<html"
get "/login" 200 'name="_token"'
get "/design/new" 302 "" -o /dev/null
grep -qi '^location:.*/login' "$HEAD" && pass "/design/new redirects to /login" || fail "/design/new did not redirect to /login"
get "/s/$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 40)" 404 ""
grep -qi '^set-cookie:' "$HEAD" && fail "a bogus share link set a cookie" || pass "a bogus share link sets no cookie"
grep -qiE 'stack trace|vendor/laravel|Illuminate\\' "$BODY" && fail "the 404 page leaks internals" || pass "the 404 page leaks nothing"
for H in x-content-type-options x-frame-options referrer-policy "content-security-policy(-report-only)?"; do
  curl -s -D "$HEAD" -o /dev/null "$BASE/login" && grep -Eqi "^$H:" "$HEAD" && pass "header $H on /login" || fail "header $H missing on /login"
done

# ---- 2. sign in ------------------------------------------------------------------------------------------------------
step "sign in"
get "/login" 200 'name="_token"'
TOKEN="$(grep -o 'name="_token" value="[^"]*"' "$BODY" | head -1 | sed 's/.*value="//; s/"$//')"
CODE=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' -X POST \
  --data-urlencode "_token=$TOKEN" --data-urlencode "email=$SMOKE_EMAIL" --data-urlencode "password=$SMOKE_PASSWORD" "$BASE/login")
if [ "$CODE" = "302" ] && ! grep -qi '^location:.*/login' "$HEAD"; then pass "POST /login 302 to $(grep -i '^location:' "$HEAD" | tr -d '\r' | sed 's/^[Ll]ocation: *//')"; else fail "POST /login answered $CODE"; die "cannot sign in, nothing else can be checked"; fi

get "/design/new" 200 "<main"
CSRF="$(grep -o 'name="csrf-token" content="[^"]*"' "$BODY" | head -1 | sed 's/.*content="//; s/"$//')"
[ -n "$CSRF" ] && pass "page carries a CSRF token" || { fail "no csrf-token meta on /design/new"; die "no CSRF token"; }

# ---- 3. create things through the real endpoints ---------------------------------------------------------------------
step "create a design from a template, share it, add a comment, make a brand kit"
SLUG="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["template"] ?? "";' "$WORK/seed.json")"
[ -n "$SLUG" ] && pass "a published template exists ($SLUG)" || { fail "no published template in the database"; }

send POST /design/brief/recommend '{"brief":{"goal":"advertise_business"}}' 200
send POST /design/projects/from-brief "{\"brief\":{\"goal\":\"advertise_business\",\"business_name\":\"Smoke Bakery\",\"what\":\"Fresh bread every morning\"},\"template\":\"$SLUG\",\"name\":\"Smoke design\"}" 201
PID="$(json_get project.id || true)"
[ -n "$PID" ] && pass "project #$PID created" || { fail "from-brief returned no project id"; PID=0; }

send POST "/design/projects/$PID/shares" '{"permission":"comment","expires_in_days":7,"download_allowed":false}' 201
SHARE_URL="$(json_get url || true)"
SHARE_PATH="/s/${SHARE_URL##*/s/}"
[ -n "$SHARE_URL" ] && [ "$SHARE_PATH" != "/s/$SHARE_URL" ] && pass "share link created" || { fail "no share url returned"; SHARE_PATH="/s/none"; }

send POST "/design/projects/$PID/comments" '{"body":"Smoke test comment"}' 201
send POST /design/brand/kits '{"name":"Smoke kit","colors":[{"key":"brand","name":"Brand","hex":"#C2410C","role":"primary"}],"tagline":"Fresh daily"}' 201
KIT="$(json_get id || true)"

# ---- 4. every key signed-in page ------------------------------------------------------------------------------------
step "pages (status + landmark)"
get "/dashboard" 200 "<main"
get "/design/new" 200 "<main"
get "/design/new/brief?goal=advertise_business" 200 "<main"
get "/design/templates" 200 "<main"
get "/design/projects" 200 "<main"
get "/design/projects/$PID" 200 'id="studio-boot"'
get "/design/brand" 200 "<main"
[ -n "${KIT:-}" ] && get "/design/brand/$KIT/edit" 200 "<main"
get "/design/brand/updates" 200 "<main"
get "/design/assets" 200 "<main"
get "/design/review" 200 "<main"
get "/design/reviews" 200 ""
get "/design/activity/log" 200 "<main"
get "/design/studio-settings" 200 "<main"
get "/design/integrations" 200 "<main"
get "/design-system/token-sets" 200 "<main"
get "/design-system/components" 200 "<main"
get "/user/profile" 200 "<main"
TEAM="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["team"] ?? "";' "$WORK/seed.json")"
[ -n "$TEAM" ] && get "/teams/$TEAM" 200 "<main"
get "/privacy-policy" 200 "<html"
get "/terms-of-service" 200 "<html"

step "JSON endpoints"
J=(-H "Accept: application/json")
get "/design/projects/$PID/document" 200 '"pages"' "${J[@]}"
get "/design/formats" 200 '"' "${J[@]}"
get "/design/templates" 200 '"data"' "${J[@]}"
get "/design/templates/facets" 200 '"' "${J[@]}"
get "/design/activity" 200 '"' "${J[@]}"
get "/design/settings" 200 '"' "${J[@]}"
get "/design/brief/flow" 200 '"' "${J[@]}"
get "/design/studio/elements" 200 '"' "${J[@]}"
get "/design/assets" 200 '"' "${J[@]}"
get "/design/brand/kits" 200 '"' "${J[@]}"
get "/design/projects/$PID/versions" 200 '"' "${J[@]}"
get "/design/projects/$PID/comments" 200 'Smoke test comment' "${J[@]}"
get "/design/projects/$PID/shares" 200 '"' "${J[@]}"
get "/design/assistant/status" 200 '"' "${J[@]}"
get "/design/integrations/status" 200 '"' "${J[@]}"

# ---- 5. the public share viewer (no session) ------------------------------------------------------------------------
step "public share viewer, no cookie"
code=$(curl -s --max-time 30 -D "$HEAD" -o "$BODY" -w '%{http_code}' "$BASE$SHARE_PATH")
[ "$code" = "200" ] && grep -q 'dot-share-document' "$BODY" && pass "GET $SHARE_PATH 200 with the document block" || fail "share viewer answered $code or lacks the document block"
grep -qi '^set-cookie:' "$HEAD" && fail "the share viewer set a cookie" || pass "the share viewer sets no cookie"
grep -qi '^x-robots-tag:.*noindex' "$HEAD" && pass "share viewer is noindex" || fail "share viewer lacks X-Robots-Tag noindex"

# ---- 6. built assets and fonts --------------------------------------------------------------------------------------
step "assets and fonts"
ASSETS="$(python3 -c "import json; m=json.load(open('public/build/manifest.json')); print(' '.join(sorted({v['file'] for v in m.values() if v.get('isEntry')} | {f for v in m.values() if v.get('isEntry') for f in v.get('css', [])})))")"
for A in $ASSETS; do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/build/$A")
  [ "$code" = "200" ] && [ -s "$BODY" ] && pass "/build/$A $code" || fail "/build/$A answered $code"
done
for F in fonts/design/inter/inter-400.woff2 fonts/design/fraunces/fraunces-400.woff2 $(cd public && ls fonts/ui/*.woff2 2>/dev/null); do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/$F")
  [ "$code" = "200" ] && [ "$(head -c4 "$BODY")" = "wOF2" ] && pass "/$F is a woff2 font" || fail "/$F answered $code or is not a woff2 file"
done

# ---- 7. nothing was logged as an error ------------------------------------------------------------------------------
step "application log"
if grep -hE '^\[[0-9-]+ [0-9:]+\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY)' "$WORK"/storage/logs/*.log 2>/dev/null | grep -v 'csp.report' > "$WORK/errors.txt" && [ -s "$WORK/errors.txt" ]; then
  fail "the application logged errors during the run:"
  cut -c1-240 "$WORK/errors.txt" | head -10 | sed 's/^/        /'
else
  pass "no ERROR or worse in the application log"
fi
grep -qE 'Fatal error|Uncaught' "$WORK/server.log" && fail "the PHP server log has a fatal error: $(grep -E 'Fatal error|Uncaught' "$WORK/server.log" | head -2)" || pass "no PHP fatal in the server log"

echo
if [ "$FAILS" -eq 0 ]; then
  echo "SIGNED-IN SMOKE PASSED: $CHECKS checks."
  exit 0
fi
echo "SIGNED-IN SMOKE FAILED: $FAILS of $CHECKS checks."
exit 1

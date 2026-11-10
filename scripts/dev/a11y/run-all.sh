#!/bin/sh
# R-45 a11y: every accessibility check, in one go. Needs the dev server (php artisan serve --port=8490) and a build.
#   sh scripts/dev/a11y/run-all.sh            # about 35 minutes: the sweep alone is 384 page states
#   sh scripts/dev/a11y/run-all.sh quick      # skips the sweep and the responsive run
# Logs go to storage/a11y/ (git-ignored). Exit code 1 when anything fails.
cd "$(dirname "$0")/../../.." || exit 1
mkdir -p storage/a11y
fail=0
run() { name="$1"; shift; echo "== $name"; "$@" > "storage/a11y/$name.log" 2>&1 && echo "   passed" || { echo "   FAILED (see storage/a11y/$name.log)"; fail=1; }; tail -n 2 "storage/a11y/$name.log" | sed 's/^/   /'; }
run contrast node scripts/dev/a11y/contrast.mjs
run keyboard-1440 node scripts/dev/a11y/keyboard.mjs --vp 1440
run keyboard-768 node scripts/dev/a11y/keyboard.mjs --vp 768
run keyboard-390 node scripts/dev/a11y/keyboard.mjs --vp 390
run motion node scripts/dev/a11y/motion.mjs
run focus-obscured node scripts/dev/a11y/focus-obscured.mjs
run labels node scripts/dev/a11y/labels.mjs
run states node scripts/dev/a11y/states.mjs
run js-tests npm run --silent test:js
if [ "$1" != "quick" ]; then
  run responsive node scripts/dev/a11y/responsive.mjs --json storage/a11y/responsive.json
  run sweep node scripts/dev/a11y/sweep.mjs --ci --json storage/a11y/sweep.json
fi
exit $fail

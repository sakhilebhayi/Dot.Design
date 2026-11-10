#!/bin/sh
# Contact sheets drawn by the real engine renderer. Usage: scripts/dev/sheet.sh <out-dir> [more sheet.mjs options]
# e.g. scripts/dev/sheet.sh /tmp/sheets --only quotation,menu --kit vivid
out="${1:-/tmp/dd-sheets}"; shift
cd "$(dirname "$0")/../.." && node scripts/dev/sheet.mjs --out "$out" --per 6 --h 520 --w 400 --width 2000 "$@"

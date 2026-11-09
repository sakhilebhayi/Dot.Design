#!/bin/sh
# Rebuild the contact sheet. Usage: scripts/dev/sheet.sh [filter]
cd "$(dirname "$0")/../.." && node scripts/dev/template-contact-sheet.mjs --h 520 --w 760 ${1:+--filter "$1"}

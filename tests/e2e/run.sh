#!/usr/bin/env bash
# R-45 end-to-end suite. Builds the assets, boots a throwaway app (own SQLite file, own storage folder), runs every
# spec in a real Chrome, tears everything down. Usage: tests/e2e/run.sh [spec-name-filter] [--headed] [--skip-build]
set -euo pipefail
cd "$(dirname "$0")/../.."
exec node tests/e2e/run.mjs "$@"

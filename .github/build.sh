#!/usr/bin/env bash
# Builds the plugin folder build/event-toolkit-for-custom-fields from the repository,
# leaving out everything listed in .distignore.
set -euo pipefail
SLUG=event-toolkit-for-custom-fields
OUT="build/${SLUG}"
rm -rf build
mkdir -p "$OUT"
rsync -a --exclude-from=.distignore --exclude=build ./ "$OUT/"
echo "Built $OUT"

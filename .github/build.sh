#!/usr/bin/env bash
# Builds the plugin folder.
#   .github/build.sh github  -> build/event-toolkit-for-custom-fields (updates from GitHub releases)
#   .github/build.sh wporg   -> same, without the GitHub updater (WordPress.org updates it itself)
set -euo pipefail
SLUG=event-toolkit-for-custom-fields
VARIANT=${1:-github}
OUT="build/${SLUG}"
rm -rf build
mkdir -p "$OUT"
rsync -a --exclude-from=.distignore --exclude=build ./ "$OUT/"
if [ "$VARIANT" = "wporg" ]; then
  rm "$OUT/includes/class-updater.php"
  sed -i '/^ \* Update URI:/d' "$OUT/${SLUG}.php"
fi
echo "Built $VARIANT variant in $OUT"

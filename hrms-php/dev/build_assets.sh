#!/usr/bin/env bash
# DEVELOPMENT ONLY — rebuilds the static front-end assets after templates change:
#   public_html/assets/css/app.css    (Tailwind v4 standalone CLI, minified)
#   public_html/assets/icons/lucide.svg (icon sprite)
# The production server never runs this. Requires the Tailwind standalone
# binary (path in $TAILWIND). Rebuilding the icon sprite (only needed when a
# new icon name is used) also needs lucide-react's icon sources: run
# `npm i lucide-react` in any folder and set LUCIDE to its dist/esm/icons.
set -eu
HERE="$(cd "$(dirname "$0")" && pwd)"
TAILWIND="${TAILWIND:?Set TAILWIND to the tailwindcss standalone executable}"
LUCIDE="${LUCIDE:-$HERE/../../node_modules/lucide-react/dist/esm/icons}"
"$TAILWIND" -i "$HERE/assets-src/app.input.css" -o "$HERE/../public_html/assets/css/app.css" --minify
if [ -d "$LUCIDE" ]; then
  php "$HERE/build_icons.php" "$LUCIDE"
else
  echo "icons: lucide-react not found — keeping the existing sprite"
fi

#!/usr/bin/env bash
# DEV ONLY — headless Chrome screenshot of a page as a demo user.
# Usage: bash dev/shot.sh <email|-> <path> <out.png> [width] [height]
set -eu
export MSYS_NO_PATHCONV=1
EMAIL="$1"; P="$2"; OUT="$3"; W="${4:-1440}"; H="${5:-900}"
BASE="${BASE:-http://localhost:8082}"
URL="$BASE$P"
[ "$EMAIL" != "-" ] && URL="$BASE/__devlogin?email=$EMAIL&next=$(python -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$P")"
PROFILE="$(cygpath -m "$(mktemp -d)")"
"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu --hide-scrollbars --user-data-dir="$PROFILE" \
  --window-size="$W,$H" --virtual-time-budget=3000 --screenshot="$OUT" "$URL" >/dev/null 2>&1
echo "$OUT"

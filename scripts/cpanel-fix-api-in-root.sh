#!/usr/bin/env bash
# One-time fix when API files were extracted into public_html/ instead of public_html/api/.
# Run on the server via cPanel Terminal:
#   bash ~/public_html/cpanel-fix-api-in-root.sh ~/public_html
#
# Safe to run multiple times — skips items already under api/.

set -euo pipefail

ROOT="${1:-$HOME/public_html}"
API="${ROOT}/api"

mkdir -p "$API/writable/"{logs,cache,session,uploads}

move_if_in_root() {
  local name="$1"
  if [ -e "${ROOT}/${name}" ] && [ ! -e "${API}/${name}" ]; then
    mv "${ROOT}/${name}" "${API}/${name}"
    echo "Moved ${name} → api/${name}"
  fi
}

for item in app composer.json composer.lock index.php spark public vendor writable .htaccess REVISION; do
  move_if_in_root "$item"
done

# Remove leftover archive if present
rm -f "${ROOT}/api.tgz"

# SPA index.html must stay in document root — restore if API index.php was the only index
if [ ! -f "${ROOT}/index.html" ] && [ -f "${API}/../index.html" ]; then
  :
fi

echo "Done. Document root should have index.html + assets/; API lives in api/"
ls -la "$ROOT" | head -20
ls -la "$API" | head -20

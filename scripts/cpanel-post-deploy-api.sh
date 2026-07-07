#!/usr/bin/env bash
# Post-deploy hook for Reach server-php on cPanel (run via SSH after rsync).
# Run from public_html/api/ (workflow: cd api && bash ./cpanel-post-deploy-api.sh).
# Never overwrites or edits an existing .env — server secrets are managed only on the server.

set -euo pipefail

API_DIR="${1:-.}"
if ! cd "$API_DIR"; then
  echo "ERROR: cannot cd to API directory: ${API_DIR}" >&2
  echo "Ensure PROD_SFTP_REMOTE_ROOT is public_html and api/ exists under it." >&2
  exit 1
fi

# Use pwd after cd — avoids absolute-path cd failures in chrooted cPanel SSH.
SCRIPT_DIR="$(pwd)"

resolve_php() {
  local bin ver major
  for bin in ea-php82 ea-php81 php82 php81 php; do
    if command -v "$bin" >/dev/null 2>&1; then
      ver="$("$bin" -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo 0)"
      major="${ver%%.*}"
      if [ "${major:-0}" -ge 8 ] 2>/dev/null; then
        echo "$bin"
        return 0
      fi
    fi
  done
  echo "ERROR: PHP 8.1+ CLI required (tried ea-php81, ea-php82, php81, php82, php)" >&2
  return 1
}

PHP_BIN="$(resolve_php)"
echo "Using PHP: ${PHP_BIN} ($("${PHP_BIN}" -v | head -1))"
echo "API directory: ${SCRIPT_DIR}"

if [ -f .env ]; then
  echo ".env already exists — leaving server secrets unchanged (deploy will not modify .env)"
else
  echo "ERROR: missing .env in ${SCRIPT_DIR}"
  echo "Create public_html/api/.env manually on the server (copy from .env.example) before running production deploy."
  exit 1
fi

mkdir -p writable/cache writable/session writable/logs writable/uploads
chmod -R 775 writable/cache writable/session writable/logs writable/uploads 2>/dev/null || \
  chmod -R 777 writable/cache writable/session writable/logs writable/uploads

echo "---- Checking .env quoting (CI4 requires quotes around values with spaces) ----"
if [ -f "${SCRIPT_DIR}/cpanel-fix-dotenv-quotes.php" ]; then
  "${PHP_BIN}" "${SCRIPT_DIR}/cpanel-fix-dotenv-quotes.php" .env
else
  echo "WARN: cpanel-fix-dotenv-quotes.php not found beside post-deploy script — skipping auto-quote"
fi

echo "---- Running database migrations ----"
CI_ENVIRONMENT=production "${PHP_BIN}" spark migrate --no-interaction 2>&1

MARKER="writable/.reach_seed_complete"
if [ -f "$MARKER" ]; then
  echo "Seeders already applied — skipping (marker: ${MARKER})"
else
  echo "---- First deploy: running InitialReachSeeder ----"
  CI_ENVIRONMENT=production "${PHP_BIN}" spark db:seed InitialReachSeeder --no-interaction
  touch "$MARKER"
  chmod 644 "$MARKER"
  echo "Seeder complete. Marker created — future deploys will skip seeding."
fi

if "${PHP_BIN}" -r 'if (function_exists("opcache_reset")) { opcache_reset(); echo "OPcache reset\n"; }'; then
  :
fi

chmod 600 .env 2>/dev/null || true
echo "Post-deploy complete (api/.env content was not modified)."

#!/usr/bin/env bash
# Post-deploy hook for Reach server-php on cPanel (run via SSH after rsync).
# Run from public_html/api/ (workflow: cd api && bash ./cpanel-post-deploy-api.sh).
#
# PRODUCTION RULE: never upload, overwrite, edit, or chmod api/.env during deploy.
# Server secrets are managed only on the server (cPanel File Manager / SSH).

set -euo pipefail

API_DIR="${1:-.}"
if ! cd "$API_DIR"; then
  echo "ERROR: cannot cd to API directory: ${API_DIR}" >&2
  echo "Ensure PROD_SFTP_REMOTE_ROOT is public_html and api/ exists under it." >&2
  exit 1
fi

# S01 (moved off cPanel 2026-11): the deploy identity's sandbox has no `php` on
# PATH; the CLI is Remi's /opt/remi/php84/root/usr/bin/php. Take the first PHP
# that is >= 8.2 (composer.json) with pgsql (CodeIgniter's Postgre driver), so
# the deploy neither guesses one path nor migrates with a PHP that cannot reach
# the DB. PHP_BIN, when set, is used only if it passes the same check.
# Same search as console-react-app server-php/bin/ops/find-php.sh.
php_ok() {
  [ -n "$1" ] && [ -x "$1" ] || return 1
  "$1" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pgsql") ? 0 : 1);' >/dev/null 2>&1
}

find_php() {
  if [ -n "${PHP_BIN:-}" ]; then
    php_ok "$PHP_BIN" && { echo "$PHP_BIN"; return 0; }
    echo "ERROR: PHP_BIN=$PHP_BIN is not PHP >= 8.2 with pgsql" >&2
    return 1
  fi
  local c
  for c in \
    "$(command -v php 2>/dev/null || true)" \
    /opt/remi/php84/root/usr/bin/php /opt/remi/php83/root/usr/bin/php /opt/remi/php82/root/usr/bin/php \
    /usr/bin/php84 /usr/bin/php83 /usr/bin/php82 \
    /usr/local/bin/php /usr/bin/php \
    /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php; do
    if php_ok "$c"; then
      echo "$c"
      return 0
    fi
  done
  echo "ERROR: no PHP CLI >= 8.2 with pgsql found (looked on PATH, in /opt/remi/php8x, /usr/bin/php8x, /usr/local/bin, /usr/bin and /opt/cpanel)" >&2
  return 1
}

# Read-only: warn if .env lines look like CI4 DotEnv parse failures (never edit .env).
# Mirrors CI4 unquoted rules: inline " #" comments are stripped before the space check.
validate_dotenv_format() {
  local bad=0
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%%$'\r'}"
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ -z "${line//[[:space:]]/}" ]] && continue
    [[ "$line" != *"="* ]] && continue

    local key val
    key="${line%%=*}"
    val="${line#*=}"
    val="$(printf '%s' "$val" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    [[ -z "$val" ]] && continue

    # Quoted values — CI4 parses these separately; skip bash pre-check.
    if [[ "$val" =~ ^[\"\'] ]]; then
      continue
    fi

    # CI4 DotEnv: unquoted inline comments start with space + #
    if [[ "$val" == *" #"* ]]; then
      val="${val%% #*}"
      val="${val%"${val##*[![:space:]]}"}"
    fi

    if [[ "$val" =~ [[:space:]] ]]; then
      echo "WARNING: .env key may need quotes for CI4 (deploy will not modify .env): ${key}=***" >&2
      bad=1
    fi
  done < .env

  if [ "$bad" -ne 0 ]; then
    echo "WARNING: unquoted .env values with spaces can break php spark — quote them manually if migrate fails." >&2
    echo "Example: SUPER_ADMIN_NAME=\"Reach Superadmin\" or CONTROLLER_APP_CODE=reach" >&2
  fi
}

PHP_BIN="$(find_php)" || exit 1
echo "Using PHP: ${PHP_BIN} ($("${PHP_BIN}" -r 'echo PHP_VERSION;'))"
echo "API directory: $(pwd)"

if [ -f .env ]; then
  echo ".env present — deploy will NOT upload, overwrite, or edit api/.env"
else
  echo "ERROR: missing .env in $(pwd)"
  echo "Create public_html/api/.env manually on the server (copy from .env.example) before running production deploy."
  exit 1
fi

validate_dotenv_format

if ! grep -qE '^CONSOLE_API_TOKEN=.+' .env || ! grep -qE '^CONSOLE_API_URL=.+' .env; then
  echo "WARNING: CONSOLE_API_URL / CONSOLE_API_TOKEN not set — token refresh cannot re-check staff roles with Console (required in production)."
fi

mkdir -p writable/cache writable/session writable/logs writable/uploads
chmod -R 775 writable/cache writable/session writable/logs writable/uploads 2>/dev/null || \
  chmod -R 777 writable/cache writable/session writable/logs writable/uploads

echo "---- Running database migrations ----"
# reach:migrate, not the framework's migrate: CI4's Migrate::run() returns
# nothing even when a migration throws, and Boot::runCommand() turns a
# non-int return into EXIT_SUCCESS. `spark migrate` therefore exits 0 on
# failure, which `set -e` cannot catch — a broken migration would leave the
# deploy green with the site running against a stale schema.
CI_ENVIRONMENT=production "${PHP_BIN}" spark reach:migrate 2>&1

echo "---- Syncing repo content-base into topic candidates ----"
CI_ENVIRONMENT=production "${PHP_BIN}" spark reach:content-base-sync 2>&1 || echo "WARNING: content-base sync failed (will retry on the daily schedule)"

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

echo "Post-deploy complete (api/.env was not modified)."

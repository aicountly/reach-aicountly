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

# Read-only: CI4 DotEnv rejects unquoted values with spaces — fail with guidance, do not edit .env.
validate_dotenv_format() {
  local bad=0
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%%$'\r'}"
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ -z "${line//[[:space:]]/}" ]] && continue
    [[ "$line" != *"="* ]] && continue
    local val="${line#*=}"
    val="$(printf '%s' "$val" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    [[ -z "$val" ]] && continue
    [[ "$val" =~ ^[\"\'] ]] && continue
    if [[ "$val" =~ [[:space:]] ]]; then
      echo "ERROR: .env key has unquoted spaces — fix manually on server (deploy will not modify .env):" >&2
      echo "  ${line%%=*}=***" >&2
      bad=1
    fi
  done < .env
  if [ "$bad" -ne 0 ]; then
    echo "Example: SUPER_ADMIN_NAME=\"Reach Superadmin\"" >&2
    exit 1
  fi
}

PHP_BIN="$(resolve_php)"
echo "Using PHP: ${PHP_BIN} ($("${PHP_BIN}" -v | head -1))"
echo "API directory: $(pwd)"

if [ -f .env ]; then
  echo ".env present — deploy will NOT upload, overwrite, or edit api/.env"
else
  echo "ERROR: missing .env in $(pwd)"
  echo "Create public_html/api/.env manually on the server (copy from .env.example) before running production deploy."
  exit 1
fi

validate_dotenv_format

mkdir -p writable/cache writable/session writable/logs writable/uploads
chmod -R 775 writable/cache writable/session writable/logs writable/uploads 2>/dev/null || \
  chmod -R 777 writable/cache writable/session writable/logs writable/uploads

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

echo "Post-deploy complete (api/.env was not modified)."

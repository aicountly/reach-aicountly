#!/usr/bin/env bash
# Post-deploy hook for Reach server-php on cPanel (run via SSH after rsync).
# Never overwrites or edits an existing .env — server secrets are managed only on the server.

set -euo pipefail

API_DIR="${1:-.}"
cd "$API_DIR"

if [ -f .env ]; then
  echo ".env already exists — leaving server secrets unchanged (deploy will not modify .env)"
else
  echo "ERROR: missing .env in ${API_DIR}"
  echo "Create api/.env manually on the server (copy from .env.example) before running production deploy."
  exit 1
fi

mkdir -p writable/cache writable/session writable/logs writable/uploads
chmod -R 775 writable/cache writable/session writable/logs writable/uploads 2>/dev/null || \
  chmod -R 777 writable/cache writable/session writable/logs writable/uploads

# CodeIgniter 4 DotEnv rejects unquoted values containing spaces (e.g. SUPER_ADMIN_NAME=Reach Superadmin).
fix_dotenv_unquoted_spaces() {
  php <<'PHP'
<?php
$path = '.env';
if (! is_file($path)) {
    exit(0);
}
$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Could not read .env\n");
    exit(1);
}
$out = [];
$changed = false;
foreach ($lines as $line) {
    $trim = ltrim($line);
    if ($trim === '' || $trim[0] === '#') {
        $out[] = $line;
        continue;
    }
    if (! str_contains($line, '=')) {
        $out[] = $line;
        continue;
    }
    [$key, $val] = explode('=', $line, 2);
    $val = trim($val);
    if ($val !== '' && preg_match('/\s/', $val) && ! preg_match('/^["\']/', $val)) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $val);
        $line = $key . '="' . $escaped . '"';
        $changed = true;
        fwrite(STDERR, 'Quoted unquoted .env value: ' . trim($key) . "\n");
    }
    $out[] = $line;
}
if ($changed) {
    copy($path, $path . '.bak-' . gmdate('YmdHis'));
    file_put_contents($path, implode("\n", $out) . "\n");
}
PHP
}

fix_dotenv_unquoted_spaces

echo "---- Running database migrations ----"
CI_ENVIRONMENT=production php spark migrate --no-interaction 2>&1

MARKER="writable/.reach_seed_complete"
if [ -f "$MARKER" ]; then
  echo "Seeders already applied — skipping (marker: ${MARKER})"
else
  echo "---- First deploy: running InitialReachSeeder ----"
  CI_ENVIRONMENT=production php spark db:seed InitialReachSeeder --no-interaction
  touch "$MARKER"
  chmod 644 "$MARKER"
  echo "Seeder complete. Marker created — future deploys will skip seeding."
fi

if php -r 'if (function_exists("opcache_reset")) { opcache_reset(); echo "OPcache reset\n"; }'; then
  :
fi

chmod 600 .env 2>/dev/null || true
echo "Post-deploy complete (api/.env content was not modified)."

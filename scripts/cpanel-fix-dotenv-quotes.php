<?php
/**
 * Quote unquoted .env values that contain spaces (required by CodeIgniter 4 DotEnv).
 * Usage: php cpanel-fix-dotenv-quotes.php [path/to/.env]
 */

declare(strict_types=1);

$path = $argv[1] ?? '.env';

if (! is_file($path)) {
    exit(0);
}

$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
}

$out     = [];
$changed = false;

foreach ($lines as $line) {
    $trim = ltrim($line);
    if ($trim === '' || $trim[0] === '#') {
        $out[] = $line;
        continue;
    }
    if (strpos($line, '=') === false) {
        $out[] = $line;
        continue;
    }

    [$key, $val] = explode('=', $line, 2);
    $val         = trim($val);

    if ($val !== '' && preg_match('/\s/', $val) && ! preg_match('/^["\']/', $val)) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $val);
        $line    = $key . '="' . $escaped . '"';
        $changed = true;
        fwrite(STDERR, 'Quoted unquoted .env value: ' . trim($key) . "\n");
    }

    $out[] = $line;
}

if ($changed) {
    copy($path, $path . '.bak-' . gmdate('YmdHis'));
    file_put_contents($path, implode("\n", $out) . "\n");
    echo "Updated {$path} (backup created)\n";
}

exit(0);

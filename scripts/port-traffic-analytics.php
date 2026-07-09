<?php

$flow = shell_exec('git -C "C:/Users/pc/flow-react-app" show 2cb96bc:server-php/app/Libraries/TrafficAnalyticsService.php');
if (! is_string($flow) || $flow === '') {
    fwrite(STDERR, "Could not read Flow TrafficAnalyticsService\n");
    exit(1);
}

$c = preg_replace("/require_once __DIR__ \. '\/[^']+';\r?\n/", '', $flow);
$c = str_replace('DeskTaxonomy::', 'SaasProductTaxonomy::', $c);
$c = preg_replace('/\(\$_ENV\[([^\]]+)\] \?\? ([^)]+)\)/', '(env($1) ?? $2)', $c);
$c = str_replace("'Flow portal'", "'Reach portal'", $c);
$c = str_replace(
    "env('FLOW_APP_URL') ?? 'https://flow.aicountly.org'",
    "env('REACH_APP_URL') ?? 'https://reach.aicountly.org'",
    $c
);
$c = str_replace(
    "env('GA4_PROPERTY_ID_PORTAL') ?? ''",
    "env('GA4_PROPERTY_ID_REACH') ?? env('GA4_PROPERTY_ID_PORTAL') ?? ''",
    $c
);
$c = str_replace(
    "env('GOOGLE_SERVICE_ACCOUNT_JSON_PORTAL') ?? ''",
    "env('GOOGLE_SERVICE_ACCOUNT_JSON_REACH') ?? env('GOOGLE_SERVICE_ACCOUNT_JSON_PORTAL') ?? ''",
    $c
);
$c = str_replace(
    "if (\$stream === 'portal' || \$stream === 'app' || \$stream === 'flow') {",
    "if (\$stream === 'portal' || \$stream === 'app' || \$stream === 'flow' || \$stream === 'reach') {",
    $c
);

$header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Libraries;\n\n";
$c      = preg_replace('/^<\?php\r?\ndeclare\(strict_types=1\);\r?\n\r?\n/', $header, $c);
$c      = str_replace(
    "public function __construct(private readonly PDO \$db)\n    {\n        \$this->cache = new AnalyticsCache(\$db);\n    }",
    "public function __construct()\n    {\n        \$this->cache = new AnalyticsCache();\n    }",
    $c
);
$c = str_replace(
    '* GA4 traffic reports for Marketing Analytics (adapted for AICountly Flow).',
    '* GA4 traffic reports for Reach Marketing Analytics (ported from Flow).',
    $c
);

$out = dirname(__DIR__) . '/server-php/app/Libraries/TrafficAnalyticsService.php';
file_put_contents($out, $c);
echo "Wrote {$out}\n";

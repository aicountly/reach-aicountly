<?php
declare(strict_types=1);

/**
 * Reach's daily snapshot for analytics.aicountly.org — what marketing produced.
 *
 *   php scripts/analytics_snapshot.php                        # work it out, print it
 *   php scripts/analytics_snapshot.php --push                 # and send it
 *   php scripts/analytics_snapshot.php --date=2026-11-01 --push   # backfill one day
 *
 * Installed by the production deploy: bin/ops/jobs.txt -> install-jobs.sh --apply. There is no
 * cron line to add by hand.
 *
 * ## Why this exists
 *
 * Analytics can see where traffic comes from — the referrer host is on every event — and nothing
 * else about marketing. Campaigns, budgets, leads and content are Reach's, and no quantity of
 * product events will ever produce them: a campaign budget is not a behaviour, and a lead that
 * never became a customer leaves no event anywhere.
 *
 * Analytics upserts on (date, source, metric, dimension), so running this twice is harmless and
 * re-running it after a fix corrects the day rather than doubling it.
 *
 * ## What is NOT reported, and why
 *
 * Impressions, clicks, cost per click and return on ad spend are absent because Reach does not
 * record them. It is a content and community marketing system, not an ad platform: campaigns
 * carry a BUDGET and UTM parameters, not a spend ledger. Reporting a budget under the name
 * "spend" would make every efficiency figure derived from it wrong in the same direction.
 *
 * reach_analytics_snapshots IS a landing area for external platform figures (ga4, gsc, meta,
 * linkedin, …), but its `metrics` column is free-form JSONB whose shape differs per platform and
 * which no code in Reach reads by key. Guessing at that shape would put invented numbers on an
 * executive dashboard, so this reports only HOW MANY figures each platform has delivered — real
 * coverage, and the thing whose silent "no" makes every other marketing number stale.
 *
 * ## Nobody is in here
 *
 * Leads are COUNTED, by where they came from and how far they got. No name, e-mail, mobile or
 * organisation leaves this script; the analytics event contract would refuse them anyway.
 */

// The shared SDK: the .env reader, the dimension rules and the push. Copy-don't-edit.
require_once __DIR__ . '/../app/ThirdParty/AicountlyAnalytics/analytics-platform-snapshot.php';

const SOURCE = 'reach';

/** External platform coverage is counted over this window, so one quiet day is not an outage. */
const PLATFORM_WINDOW_DAYS = 30;

function scalar(PDO $pdo, string $sql, array $args = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($args);

    return (int) $st->fetchColumn();
}

function rows(PDO $pdo, string $sql, array $args = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hasTable(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT count(*) FROM information_schema.tables
                          WHERE table_schema = current_schema() AND table_name = :t');
    $st->execute(['t' => $table]);

    return (int) $st->fetchColumn() > 0;
}

// ── arguments ───────────────────────────────────────────────────────────────
$o    = getopt('', ['push', 'date::', 'env::', 'db::']);
$push = isset($o['push']);
$asOf = is_string($o['date'] ?? null) && $o['date'] !== '' ? $o['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    fwrite(STDERR, "--date must be YYYY-MM-DD\n");
    exit(2);
}

// The shared library connects with PDO. CodeIgniter's own Postgre driver uses ext-pgsql, so a
// jobs.txt that asks install-jobs.sh for `pgsql` can hand this script a PHP CLI without
// pdo_pgsql — and the failure would otherwise be an unexplained fatal in the middle of the night.
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "analytics snapshot: this PHP CLI has no pdo_pgsql (" . PHP_BINARY . ").\n"
        . "  CodeIgniter's Postgre driver uses ext-pgsql; this script uses PDO, and the server needs both.\n"
        . "  Point the job at a CLI that has it: bash bin/ops/install-jobs.sh --php=/path/to/php --apply\n");
    exit(3);
}

$envPath = is_string($o['env'] ?? null) && $o['env'] !== '' ? $o['env'] : dirname(__DIR__) . '/.env';
$env     = aic_analytics_read_env($envPath);
$get     = static fn (string $k, string $d = ''): string => $env[$k] ?? (string) (getenv($k) ?: $d);

$prefix = is_string($o['db'] ?? null) && $o['db'] !== '' ? $o['db'] : 'DB';
try {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
        $get($prefix . '_HOST', '127.0.0.1'), $get($prefix . '_PORT', '5432'), $get($prefix . '_NAME'));
    if ($get($prefix . '_SSLMODE') !== '') {
        $dsn .= ';sslmode=' . $get($prefix . '_SSLMODE');
    }
    $db = new PDO($dsn, $get($prefix . '_USER'), $get($prefix . '_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'cannot reach the Reach database: ' . $e->getMessage() . "\n");
    exit(1);
}

$snapshots = [];
$notes     = [];
$add = static function (string $metric, int $value, string $dimension = '') use (&$snapshots, $asOf): void {
    $snapshots[] = ['metric' => $metric, 'as_of_date' => $asOf, 'value' => max(0, $value)]
        + ($dimension !== '' ? ['dimension' => $dimension] : []);
};

/**
 * Report a GROUP BY as one dimensioned row each.
 *
 * Grouped by whatever the column actually holds rather than against a hard-coded list of states:
 * Reach's status columns are plain VARCHARs with no enum behind them, so a list written here
 * would silently stop covering reality the first time somebody adds a state.
 */
$addGrouped = static function (string $metric, array $grouped) use ($add, &$notes): void {
    foreach ($grouped as $row) {
        $dim = aic_analytics_dimension((string) ($row['k'] ?? ''));
        if ($dim === '') {
            // A label that cannot be made to fit the dimension rule is skipped rather than
            // mangled into a different label that would silently merge two real states.
            $notes[] = "{$metric}: skipped a value that is not a usable dimension label";
            continue;
        }
        $add($metric, (int) $row['n'], $dim);
    }
};

// ── Campaigns ────────────────────────────────────────────────────────────────
if (hasTable($db, 'reach_campaigns')) {
    $addGrouped('campaigns.total', rows($db,
        "SELECT COALESCE(NULLIF(TRIM(status), ''), 'unknown') AS k, count(*) AS n
           FROM reach_campaigns GROUP BY 1 ORDER BY 1"));
    $add('campaigns.total', scalar($db, 'SELECT count(*) FROM reach_campaigns'));

    // Running on the reported date: started, not yet ended, and not a draft or an archive. An
    // open-ended campaign (no end_date) counts as still running, which is what it is.
    $running = "status NOT IN ('draft','archived','cancelled')
                AND (start_date IS NULL OR start_date <= :d::date)
                AND (end_date   IS NULL OR end_date   >= :d::date)";
    $add('campaigns.active', scalar($db, "SELECT count(*) FROM reach_campaigns WHERE {$running}", ['d' => $asOf]));
    $addGrouped('campaigns.active', rows($db,
        "SELECT COALESCE(NULLIF(TRIM(campaign_type), ''), 'unspecified') AS k, count(*) AS n
           FROM reach_campaigns WHERE {$running} GROUP BY 1 ORDER BY 1", ['d' => $asOf]));

    // Budget, in paise.
    //
    // ROUND before the cast, not after: budget_amount is NUMERIC(18,2) rupees, and casting
    // 1234.565 * 100 straight to BIGINT truncates rather than rounds, losing a paisa per campaign
    // in a way that only shows up as a total that will not reconcile.
    //
    // Non-INR campaigns are EXCLUDED, not converted. Analytics stores paise with no currency
    // dimension, so adding a dollar budget to a rupee one would produce a number that is wrong
    // and unmarked. They are counted and named below instead.
    $inr = "(currency IS NULL OR UPPER(TRIM(currency)) IN ('INR', '\u{20B9}'))";
    $add('campaigns.budget_paise', scalar($db,
        "SELECT COALESCE(SUM(ROUND(budget_amount * 100)), 0)::bigint
           FROM reach_campaigns WHERE {$running} AND budget_amount IS NOT NULL AND {$inr}",
        ['d' => $asOf]));
    $addGrouped('campaigns.budget_paise', array_map(
        static fn (array $r): array => ['k' => $r['k'], 'n' => $r['paise']],
        rows($db,
            "SELECT COALESCE(NULLIF(TRIM(campaign_type), ''), 'unspecified') AS k,
                    COALESCE(SUM(ROUND(budget_amount * 100)), 0)::bigint      AS paise
               FROM reach_campaigns
              WHERE {$running} AND budget_amount IS NOT NULL AND {$inr}
           GROUP BY 1 ORDER BY 1", ['d' => $asOf])
    ));
    $foreign = scalar($db,
        "SELECT count(*) FROM reach_campaigns
          WHERE {$running} AND budget_amount IS NOT NULL AND NOT {$inr}", ['d' => $asOf]);
    if ($foreign > 0) {
        $notes[] = "{$foreign} running campaign(s) carry a non-INR budget and are excluded from "
            . 'campaigns.budget_paise — analytics stores paise with no currency dimension';
    }
} else {
    $notes[] = 'reach_campaigns is absent — no campaign figure is reported';
}

// ── Leads ────────────────────────────────────────────────────────────────────
if (hasTable($db, 'reach_leads')) {
    $add('leads.total', scalar($db, 'SELECT count(*) FROM reach_leads'));
    $addGrouped('leads.total', rows($db,
        "SELECT COALESCE(NULLIF(TRIM(source_kind), ''), 'unattributed') AS k, count(*) AS n
           FROM reach_leads GROUP BY 1 ORDER BY 1"));
    $add('leads.new', scalar($db,
        'SELECT count(*) FROM reach_leads WHERE created_at::date = :d', ['d' => $asOf]));

    // The marketing-to-sales hand-off. A lead Reach captured but never delivered to Engage is
    // invisible to the sales pipeline, and this is the only place that gap is countable.
    $addGrouped('leads.handed_to_sales', rows($db,
        "SELECT COALESCE(NULLIF(TRIM(engage_push_status), ''), 'unknown') AS k, count(*) AS n
           FROM reach_leads GROUP BY 1 ORDER BY 1"));
    $add('leads.handed_to_sales', scalar($db,
        "SELECT count(*) FROM reach_leads WHERE engage_lead_code IS NOT NULL AND engage_lead_code <> ''"));
} else {
    $notes[] = 'reach_leads is absent — no lead figure is reported';
}

// ── Content ──────────────────────────────────────────────────────────────────
if (hasTable($db, 'reach_content_items')) {
    // Soft-deleted items are not content anybody has; counting them would inflate the library.
    $live = 'deleted_at IS NULL';
    $add('content.total', scalar($db, "SELECT count(*) FROM reach_content_items WHERE {$live}"));
    $addGrouped('content.total', rows($db,
        "SELECT COALESCE(NULLIF(TRIM(publication_status), ''), 'unknown') AS k, count(*) AS n
           FROM reach_content_items WHERE {$live} GROUP BY 1 ORDER BY 1"));

    $add('content.published', scalar($db,
        "SELECT count(*) FROM reach_content_items WHERE {$live} AND published_at IS NOT NULL"));
    $addGrouped('content.published', rows($db,
        "SELECT COALESCE(NULLIF(TRIM(content_type), ''), 'unspecified') AS k, count(*) AS n
           FROM reach_content_items WHERE {$live} AND published_at IS NOT NULL GROUP BY 1 ORDER BY 1"));
} else {
    $notes[] = 'reach_content_items is absent — no content figure is reported';
}

// ── External platform coverage ───────────────────────────────────────────────
// How many figures each platform has delivered INTO Reach. Not performance: the `metrics` column
// is free-form JSONB whose shape differs per platform, and reading it by key would be guessing.
if (hasTable($db, 'reach_analytics_snapshots')) {
    $addGrouped('marketing.platform_snapshots', rows($db,
        "SELECT source AS k, count(*) AS n
           FROM reach_analytics_snapshots
          WHERE captured_at >= :d::date - :n::int
       GROUP BY 1 ORDER BY 1", ['d' => $asOf, 'n' => PLATFORM_WINDOW_DAYS]));
    $add('marketing.platform_snapshots', scalar($db,
        'SELECT count(*) FROM reach_analytics_snapshots WHERE captured_at >= :d::date - :n::int',
        ['d' => $asOf, 'n' => PLATFORM_WINDOW_DAYS]));
} else {
    $notes[] = 'reach_analytics_snapshots is absent — platform coverage is not reported';
}

// ── report, or push ─────────────────────────────────────────────────────────
printf("%s  %s  (%d counters)\n", $asOf, SOURCE, count($snapshots));
foreach ($snapshots as $s) {
    printf("  %-30s %-22s %s\n", $s['metric'], $s['dimension'] ?? '-', number_format($s['value']));
}
foreach ($notes as $note) {
    printf("  note: %s\n", $note);
}

if (!$push) {
    echo "\nDry run. Pass --push to send.\n";
    exit(0);
}

// Installed into the crontab by the deploy, including where analytics is not switched on yet.
// Exit QUIETLY there: a job that mails an error every night is a job the operator filters, and
// then nobody sees the night it breaks for a real reason.
$enabled = $get('AICOUNTLY_ANALYTICS_ENABLED', '0');
if (!in_array(strtolower($enabled), ['1', 'true', 'yes', 'on'], true)) {
    echo "analytics is not enabled for this product (AICOUNTLY_ANALYTICS_ENABLED={$enabled}); nothing sent.\n";
    exit(0);
}

$key = $get('AICOUNTLY_ANALYTICS_KEY');
if ($key === '') {
    fwrite(STDERR, "AICOUNTLY_ANALYTICS_KEY is not set in {$envPath}; nothing sent.\n");
    exit(1);
}
[$code, $body] = aic_analytics_platform_push(
    $snapshots,
    $get('AICOUNTLY_ANALYTICS_URL', 'https://analytics.aicountly.org/api'),
    $key,
    $get('AICOUNTLY_ANALYTICS_ENVIRONMENT', 'production')
);

echo "\nHTTP {$code}\n" . substr($body, 0, 800) . "\n";
// A failed push is not fatal: tomorrow's run reports the same day again and the upsert makes
// that a correction rather than a duplicate. Non-zero so cron mail says something went wrong.
exit($code >= 200 && $code < 300 ? 0 : 1);

<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Blog\BlogRecategoriseService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * `php spark reach:blog-recategorise [--dry-run] [--apply] [--republish] [--id=N] [--json]`
 *
 * Files every blog under the aicountly.com taxonomy (BlogTaxonomy): the
 * stored category canonicalised through its aliases, and product_slug
 * filled where it can be derived with evidence — never guessed. Prints each
 * change with its evidence and the before/after counts.
 *
 *   (default)    dry run — prints the plan, writes nothing
 *   --apply      writes reach_blog_publication_profiles (category,
 *                product_slug); article text and versions are never touched
 *   --republish  with --apply: queues the normal publication deployment for
 *                every changed live post, so the aicountly.com package gets the
 *                new category / product_slug; the publishing worker sends it
 */
class ReachBlogRecategorise extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-recategorise';
    protected $description = 'File existing blogs under the aicountly.com taxonomy (canonical category + product_slug). Dry run by default.';
    protected $usage       = 'reach:blog-recategorise [--dry-run] [--apply] [--republish] [--id=N] [--json]';
    protected $options     = [
        '--dry-run'   => 'Show the plan only (the default).',
        '--apply'     => 'Write the new category / product_slug to the publication profiles.',
        '--republish' => 'With --apply: queue a republish of every changed live post.',
        '--id'        => 'Only this content item.',
        '--json'      => 'Print the full plan as JSON.',
    ];

    public function run(array $params): int
    {
        $apply     = $this->sparkFlag('apply', $params) && ! $this->sparkFlag('dry-run', $params);
        $republish = $this->sparkFlag('republish', $params);
        $id        = (int) ($this->sparkOption('id', $params, '0') ?? '0');

        if ($republish && ! $apply) {
            CLI::error('--republish needs --apply (a dry run queues nothing).');

            return EXIT_ERROR;
        }

        try {
            $service = new BlogRecategoriseService();
            $plan    = $service->plan($id > 0 ? $id : null);

            if ($this->sparkFlag('json', $params)) {
                CLI::write(json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $this->printPlan($plan);
            }

            if (! $apply) {
                CLI::write('');
                CLI::write("Dry run: {$plan['changed']} blog(s) would change. Nothing written. Re-run with --apply (and --republish to update the live packages on aicountly.com).", 'yellow');

                return EXIT_SUCCESS;
            }

            $n = $service->apply($plan);
            CLI::write('');
            CLI::write("Applied: {$n} publication profile(s) updated. Article text unchanged.", 'green');

            if ($republish) {
                $queued = 0;
                foreach ($service->republish($plan) as $r) {
                    if ($r['queued']) {
                        $queued++;
                        CLI::write(sprintf('  republish queued: #%d %s (deployment %d)', $r['content_item_id'], $r['title'], $r['deployment_id']));
                    } else {
                        CLI::write(sprintf('  republish NOT queued: #%d %s — %s', $r['content_item_id'], $r['title'], $r['reason']), 'red');
                    }
                }
                CLI::write("Republish: {$queued} deployment(s) queued (not yet sent). The publishing worker sends them: php spark reach:work --queue=publishing --once --limit=20");
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('reach:blog-recategorise failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /** @param array<string, mixed> $plan */
    private function printPlan(array $plan): void
    {
        foreach ($plan['rows'] as $r) {
            if (! $r['changed'] && $r['notes'] === [] && $r['category_after'] !== null) {
                continue;
            }
            CLI::write(sprintf('#%d [%s%s] %s', $r['content_item_id'], $r['workflow_status'], $r['live'] ? ', on aicountly.com' : '', $r['title']));
            CLI::write(sprintf('    category: %s → %s   product_slug: %s → %s',
                $r['category_before'] ?? '(none)', $r['category_after'] ?? '(unmapped — left as is)', $r['product_before'] ?? '(none)', $r['product_after'] ?? '(none)'));
            foreach ($r['evidence'] as $e) {
                CLI::write('    evidence: ' . $e);
            }
            foreach ($r['notes'] as $n) {
                CLI::write('    note: ' . $n);
            }
        }

        CLI::write('');
        CLI::write('Before — by category: ' . self::kv($plan['before']['by_category']));
        CLI::write('Before — by product:  ' . self::kv($plan['before']['by_product']));
        CLI::write('After  — by category: ' . self::kv($plan['after']['by_category']));
        CLI::write('After  — by product:  ' . self::kv($plan['after']['by_product']));
        CLI::write(sprintf('After  — by group: %s; app-led %d (Beta %d), topic %d',
            self::kv($plan['after']['by_group']), $plan['after']['app_led'], $plan['after']['app_led_beta'], $plan['after']['topic']));
        if ($plan['unmapped'] !== []) {
            CLI::write(count($plan['unmapped']) . ' blog(s) have no category that maps (left as is; aicountly.com shows them uncategorised): #'
                . implode(', #', array_column($plan['unmapped'], 'content_item_id')), 'yellow');
        }
    }

    /** @param array<string, int> $m */
    private static function kv(array $m): string
    {
        if ($m === []) {
            return '(none)';
        }

        return implode(', ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($m), $m));
    }
}

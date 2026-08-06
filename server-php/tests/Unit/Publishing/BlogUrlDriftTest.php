<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the slug-drift tooling.
 *
 * The underlying defect is a gap between two facts that are individually true:
 * reach:blog-repair-slugs fixes reach_content_items.slug immediately, and a
 * post only moves URL when it is re-published. Between them, "Repaired 0
 * slug(s)" reads as "nothing to do" while every live URL is still wrong.
 */
final class BlogUrlDriftTest extends CIUnitTestCase
{
    public function testDriftCommandIsRegistered(): void
    {
        $this->assertFileExists(APPPATH . 'Commands/ReachBlogUrlDrift.php');
    }

    /**
     * --record-redirects writes; nothing else may. An operator running the
     * report to understand the situation must not change it.
     */
    public function testReportingIsReadOnlyUnlessRecordRedirectsIsPassed(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogUrlDrift.php');

        $recordCall = strpos($source, '$record ? $this->recordRedirects($drifted) : 0');
        $this->assertNotFalse($recordCall, 'Writes must be gated on the --record-redirects flag.');

        // The only insert in the file belongs to recordRedirects().
        $this->assertSame(1, substr_count($source, '->insert('));
    }

    /**
     * Re-running must not stack duplicate rows for the same move.
     */
    public function testRedirectRecordingChecksForAnExistingRow(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogUrlDrift.php');

        $this->assertStringContainsString("whereIn('status', ['pending', 'active'])", $source);
        $this->assertStringContainsString('if ($exists > 0) {', $source);
    }

    /**
     * The whole point of --probe: only the public site can say which URL is
     * real. A live old URL plus a 404 new one is the dangerous case, because
     * re-publishing then breaks a working page.
     */
    public function testProbeDistinguishesTheDangerousCase(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogUrlDrift.php');

        $this->assertStringContainsString('the old URL will 404 without a redirect', $source);
        $this->assertStringContainsString('already moved — the deployment record is stale', $source);
        $this->assertStringContainsString('neither URL resolves', $source);
    }

    // --- Redirect emission ------------------------------------------------

    /**
     * reach_publication_redirects was written by the repair command and read by
     * nothing, so repaired slugs could never produce a 301. The payload builder
     * is the missing reader.
     */
    public function testPayloadBuilderReadsTheRedirectTable(): void
    {
        $source = (string) file_get_contents(
            APPPATH . 'Libraries/Publishing/Blog/BlogPublicationPayloadBuilder.php'
        );

        $this->assertStringContainsString('reach_publication_redirects', $source);
        $this->assertStringContainsString("\$payload['redirects']", $source);
    }

    /**
     * Whether the receiving site understands a `redirects` key is a property of
     * a different codebase. Sending it must therefore be opt-in — a strict
     * receiver would otherwise reject every publish the moment this shipped.
     */
    public function testRedirectEmissionIsOffByDefault(): void
    {
        $source = (string) file_get_contents(
            APPPATH . 'Libraries/Publishing/Blog/BlogPublicationPayloadBuilder.php'
        );

        $this->assertStringContainsString(
            "env('BLOG_PUBLISH_REDIRECTS_ENABLED', false)",
            $source,
            'The default must be false, not true.',
        );

        $this->assertFalse(
            filter_var(env('BLOG_PUBLISH_REDIRECTS_ENABLED', false), FILTER_VALIDATE_BOOL),
            'Nothing in the test environment may switch redirect emission on implicitly.',
        );
    }

    /**
     * A redirect is only "active" once a publish carrying it succeeded. With
     * emission off the rows must stay pending — claiming otherwise would assert
     * the public site is serving something it has never been told about.
     */
    public function testRedirectsActivateOnlyWhenEmissionIsEnabled(): void
    {
        $source = (string) file_get_contents(
            APPPATH . 'Libraries/Publishing/Jobs/PublicationDeploymentService.php'
        );

        $activate = strpos($source, 'private function activateRedirects');
        $this->assertNotFalse($activate);

        $body  = substr($source, $activate);
        $guard = strpos($body, "env('BLOG_PUBLISH_REDIRECTS_ENABLED', false)");
        $write = strpos($body, "'status'        => 'active'");

        $this->assertNotFalse($guard, 'Activation must be gated on the same flag as emission.');
        $this->assertNotFalse($write);
        $this->assertLessThan($write, $guard, 'The flag check must precede the write.');
    }

    /**
     * Publishing already succeeded on the public site by this point; a
     * bookkeeping failure must not turn that into a reported failure.
     */
    public function testActivationFailureCannotFailThePublish(): void
    {
        $source = (string) file_get_contents(
            APPPATH . 'Libraries/Publishing/Jobs/PublicationDeploymentService.php'
        );

        $activate = substr($source, (int) strpos($source, 'private function activateRedirects'));
        $this->assertStringContainsString('catch (\Throwable $e)', $activate);
        $this->assertStringContainsString('Redirect activation skipped', $activate);
    }

    // --- URL construction -------------------------------------------------

    /**
     * CanonicalUrlPolicy emits /blog/ while the site serves /blogs/. Rebuilding
     * the comparison URL from the policy therefore probes a path that does not
     * exist and reports 404 for a reason unrelated to the slug — which is what
     * the first production run of this command did. Substituting the final
     * segment of the URL the site is actually serving keeps every other part
     * identical.
     */
    public function testComparisonUrlKeepsThePathPrefixTheSiteActuallyServes(): void
    {
        $live = 'https://www.aicountly.com/blogs/ookkeeping-hecklist-for-ndian-s';

        $this->assertSame(
            'https://www.aicountly.com/blogs/bookkeeping-checklist-for-indian-smes',
            \App\Libraries\Intelligence\ContentIdentitySyncService::withLastSegment(
                $live,
                'bookkeeping-checklist-for-indian-smes',
            ),
        );
    }

    public function testSegmentSwapToleratesATrailingSlash(): void
    {
        $this->assertSame(
            'https://aicountly.com/blogs/new-slug',
            \App\Libraries\Intelligence\ContentIdentitySyncService::withLastSegment(
                'https://aicountly.com/blogs/old-slug/',
                'new-slug',
            ),
        );
    }

    /**
     * --record-redirects must be honoured however spark hands the flag over;
     * reading it as absent turns a requested write into a silent no-op that
     * still reports success.
     */
    public function testFlagsAreParsedThroughTheSharedSparkOptionHelper(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogUrlDrift.php');

        $this->assertStringContainsString('use ParsesSparkOptions;', $source);
        $this->assertStringContainsString("\$this->sparkFlag('record-redirects', \$params)", $source);
        $this->assertStringNotContainsString("CLI::getOption('record-redirects')", $source);
    }

    /**
     * BlogPublicationPayloadBuilder sends `$seo['slug'] ?? $item['slug']`, so
     * reach_content_seo_profiles decides the published URL. Predicting a move
     * from the content item's slug alone is wrong whenever the profile still
     * holds the old value — the re-publish would then change nothing.
     */
    public function testDriftReportsTheSlugThePayloadWouldActuallySend(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogUrlDrift.php');

        $this->assertStringContainsString('reach_content_seo_profiles', $source);
        $this->assertStringContainsString("'publish_slug'", $source);
        $this->assertStringContainsString("'republish_moves_it'", $source);
    }

    /**
     * "recorded 0" must distinguish "already on file" from "the flag was
     * ignored" — the second is a silent no-op reporting success.
     */
    public function testZeroRecordedIsDisambiguated(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogUrlDrift.php');

        $this->assertStringContainsString("'redirects_already_on_file'", $source);
        $this->assertStringContainsString("'matches_this_move'", $source);
    }

    /**
     * The payload builder must keep preferring the SEO profile slug; the drift
     * report's prediction is only correct while that precedence holds.
     */
    public function testPayloadSlugPrecedenceIsUnchanged(): void
    {
        $source = (string) file_get_contents(
            APPPATH . 'Libraries/Publishing/Blog/BlogPublicationPayloadBuilder.php'
        );

        $this->assertStringContainsString("\$seo['slug'] ?? \$item['slug']", $source);
    }

    // --- Republish command ------------------------------------------------

    /**
     * Moving a live URL cannot be undone by re-running the command, so the
     * default must be a preview.
     */
    public function testRepublishIsDryRunWithoutApply(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogRepublish.php');

        $this->assertStringContainsString("\$apply = \$this->sparkFlag('apply', \$params);", $source);
        $this->assertStringContainsString('if (! $apply) {', $source);

        // The only enqueue sits after the dry-run early return.
        $earlyReturn = strpos($source, 'if (! $apply) {');
        $enqueue     = strpos($source, '->enqueuePublication(');
        $this->assertNotFalse($enqueue);
        $this->assertLessThan($enqueue, $earlyReturn);
    }

    /**
     * One item at a time: a bulk flag would let a single mistake move every
     * live URL at once.
     */
    public function testRepublishRequiresAnExplicitId(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogRepublish.php');

        $this->assertStringContainsString('--id is required', $source);
        $this->assertStringNotContainsString('--all', $source);
    }

    /**
     * The preview must name the specific way this can go wrong: a URL change
     * with no recorded redirect, or one recorded but not being sent.
     */
    public function testRepublishPreviewWarnsAboutEachFailureMode(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogRepublish.php');

        $this->assertStringContainsString('NO redirect is recorded', $source);
        $this->assertStringContainsString('BLOG_PUBLISH_REDIRECTS_ENABLED is false', $source);
        $this->assertStringContainsString('whether the', $source);
    }

    /**
     * Approval is enforced by enqueuePublication; checking first turns a thrown
     * exception into an explanation before anything is queued.
     */
    public function testRepublishChecksApprovalBeforeQueueing(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachBlogRepublish.php');

        $this->assertStringContainsString("!== 'approved'", $source);
        $this->assertStringContainsString('publishing would be refused', $source);
    }
}

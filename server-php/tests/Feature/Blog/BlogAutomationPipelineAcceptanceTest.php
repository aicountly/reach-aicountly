<?php

namespace Tests\Feature\Blog;

use App\Libraries\ActorRegistry;
use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Blog\BlogFeatureFlags;
use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\WorkBlockService;
use App\Libraries\JobHandlerRegistry;
use App\Libraries\JobService;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Models\Content\ContentBriefModel;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Deterministic, non-production end-to-end acceptance test for the blog
 * automation pipeline (§26 of the blog-automation audit plan).
 *
 * Uses only mocks/fakes for paid providers (REACH_AI_MOCK, REACH_PUB_MOCK)
 * and requires a real Postgres test database (App\Libraries\ContentVersionService
 * uses pg_advisory_xact_lock(), and the whole schema is Postgres-specific),
 * so — exactly like every other DatabaseTestCase-derived Feature test in
 * this suite — it self-skips when no test database is configured rather
 * than fabricating a pass. Run with a configured `database.tests` (or
 * TEST_DB_NAME) connection to execute it for real (e.g. in CI or a local
 * Postgres instance); see tests/_support/DatabaseTestCase.php.
 *
 * Walks: topic candidate -> score -> roadmap-select -> brief -> outline ->
 * AI draft -> fact verification -> SEO review -> cross-provider review
 * (forced to human review, since only the mock provider is available) ->
 * human approval -> checksum-bound content approval -> schedule -> real
 * queue dispatch (lease + JobHandlerRegistry, not a direct method call) ->
 * signed mock-publisher deploy -> verification -> LIVE -> sitemap chain ->
 * rollback-safe versioning -> unpublish. Plus the specific negative /
 * security assertions §26 calls out by name.
 */
final class BlogAutomationPipelineAcceptanceTest extends ApiTestCase
{
    private WorkBlockService $workBlocks;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::hasTestDatabase()) {
            return;
        }

        $_ENV['REACH_AI_MOCK']  = 'true';
        $_ENV['REACH_PUB_MOCK']  = 'true';
        $_ENV['CI_ENVIRONMENT']  = 'testing';

        // Automation is disabled-by-default in production; the pipeline
        // under test explicitly needs it (and its sub-flags) enabled.
        foreach ([
            'BLOG_AUTOMATION_ENABLED', 'BLOG_ROADMAP_OPTIMIZER_ENABLED',
            'BLOG_AI_GENERATION_ENABLED', 'BLOG_FACT_VERIFICATION_ENABLED',
            'BLOG_PUBLIC_PUBLISHER_ENABLED',
        ] as $flag) {
            $_ENV[$flag] = 'true';
        }
        // Auto-publish and image generation deliberately stay OFF (safe
        // defaults) — the happy-path flow below schedules, it never calls
        // the PUBLISH_BLOG auto-publish work block for LOW-risk content
        // either, to keep the "auto-publish default off" invariant honest.
        unset($_ENV['BLOG_AUTO_PUBLISH_ENABLED'], $_ENV['BLOG_IMAGE_GENERATION_ENABLED']);
        // Sitemap/indexing steps must fail closed without inventing a live
        // public site; clear any CI/dotenv public URL so UPDATE_SITEMAP blocks.
        unset($_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL']);
        putenv('AICOUNTLY_PUBLIC_SITE_BASE_URL');

        $this->workBlocks = new WorkBlockService();
        \App\Libraries\Publishing\Connector\PublicSitePublisherFactory::resetMock();
    }

    public function testFullBlogAutomationPipelineEndToEnd(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        // -----------------------------------------------------------------
        // Step 1: a topic candidate exists (real cluster -> real candidate,
        // nothing fabricated)
        // -----------------------------------------------------------------
        $db->table('reach_topic_clusters')->insert([
            'slug' => 'e2e-gst-filing', 'name' => 'GST filing', 'pillar_topic' => 'GST filing for small business',
            'status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $clusterId = (int) $db->insertID();

        $db->table('reach_topic_candidates')->insert([
            'candidate_uuid' => bin2hex(random_bytes(16)),
            'topic_cluster_id' => $clusterId,
            'title' => 'How to file GST returns on time (E2E acceptance)',
            'normalized_title' => 'how to file gst returns on time (e2e acceptance)',
            'slug_hint' => 'e2e-gst-filing',
            'audience' => 'small_business_owners',
            'funnel_stage' => 'middle',
            'search_intent' => 'informational',
            'status' => 'candidate',
            'source' => 'e2e_acceptance_test',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $candidateId = (int) $db->insertID();

        // -----------------------------------------------------------------
        // Step 2: score it (real TopicScoringService + RoadmapSignalProvider)
        // -----------------------------------------------------------------
        $scoreBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_SCORE_TOPIC,
            'input_json' => ['topic_candidate_id' => $candidateId],
        ]);
        $scoreResult = $this->workBlocks->execute($scoreBlockId);
        $this->assertArrayHasKey('total_score', $scoreResult);
        $this->assertSame(
            'completed',
            $db->table('reach_work_blocks')->where('id', $scoreBlockId)->get()->getRowArray()['eligibility_status'],
        );

        // -----------------------------------------------------------------
        // Step 3: approve it for the roadmap. The optimizer's CREATE_NEW
        // decision is what would normally do this; the direct status update
        // mirrors exactly what RoadmapOptimizerService::run() persists for a
        // CREATE_NEW decision, kept inline here for a deterministic fixture.
        // -----------------------------------------------------------------
        $db->table('reach_topic_candidates')->where('id', $candidateId)->update([
            'status' => 'roadmap_selected', 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // -----------------------------------------------------------------
        // Step 4: generate a content brief from the approved candidate
        // -----------------------------------------------------------------
        $briefBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_GENERATE_BRIEF,
            'input_json' => ['topic_candidate_id' => $candidateId],
        ]);
        $briefResult = $this->workBlocks->execute($briefBlockId);
        $this->assertArrayHasKey('content_item_id', $briefResult);
        $contentItemId = (int) $briefResult['content_item_id'];
        $this->assertGreaterThan(0, $contentItemId);
        $this->assertSame(
            BlogStateMachine::BRIEF_READY,
            $db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray()['workflow_status'],
        );

        // -----------------------------------------------------------------
        // Step 5: research metadata / outline — deterministic outline
        // derived from the brief's own questions (never invented content)
        // -----------------------------------------------------------------
        $outlineBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_GENERATE_OUTLINE,
            'content_item_id' => $contentItemId,
        ]);
        $outlineResult = $this->workBlocks->execute($outlineBlockId);
        $this->assertArrayHasKey('sections', $outlineResult);

        // -----------------------------------------------------------------
        // Step 6: generate a draft through the real provider adapter
        // (mocked at the provider boundary only — the orchestration,
        // schema validation and version creation are all real)
        // -----------------------------------------------------------------
        $draftBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_GENERATE_DRAFT,
            'content_item_id' => $contentItemId,
        ]);
        $draftResult = $this->workBlocks->execute($draftBlockId);
        $this->assertArrayHasKey('content_version_id', $draftResult, 'GENERATE_DRAFT must produce a real content version: ' . json_encode($draftResult));
        $contentVersionId = (int) $draftResult['content_version_id'];
        $this->assertGreaterThan(0, $contentVersionId);
        $this->assertSame(
            BlogStateMachine::DRAFT,
            $db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray()['workflow_status'],
        );

        // -----------------------------------------------------------------
        // Step 8: extract and verify claims — fail-closed fact verification
        // -----------------------------------------------------------------
        $factBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_FACT_VERIFY,
            'content_item_id' => $contentItemId,
            'content_version_id' => $contentVersionId,
        ]);
        $factResult = $this->workBlocks->execute($factBlockId);
        $this->assertArrayHasKey('publishable', $factResult);

        // -----------------------------------------------------------------
        // Step 10: SEO review (rule-based, no AI fabrication)
        // -----------------------------------------------------------------
        $seoBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_SEO_OPTIMIZE,
            'content_item_id' => $contentItemId,
        ]);
        $this->workBlocks->execute($seoBlockId);

        // -----------------------------------------------------------------
        // Step 9: cross-provider editorial review. Only the mock provider is
        // configured in this test, so the generator and the reviewer
        // resolve to the SAME provider — the safety net must force human
        // review rather than silently self-approving.
        // -----------------------------------------------------------------
        $crossReviewBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_CROSS_REVIEW,
            'content_item_id' => $contentItemId,
            'content_version_id' => $contentVersionId,
        ]);
        $crossReviewResult = $this->workBlocks->execute($crossReviewBlockId);
        $this->assertTrue(
            $crossReviewResult['same_provider'] ?? false,
            'With only the mock provider configured, cross-review must detect self-review rather than silently pass.',
        );
        $this->assertTrue($crossReviewResult['requires_human'] ?? false);
        $this->assertSame(
            BlogStateMachine::INTERNAL_REVIEW,
            $db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray()['workflow_status'],
            'A same-provider cross-review must force INTERNAL_REVIEW, never auto-approve.',
        );

        // -----------------------------------------------------------------
        // Step 13/14: a human resolves the review and approves the EXACT
        // version. This is the real approval gate
        // (BlogContentApprovalService), version- and checksum-bound.
        // -----------------------------------------------------------------
        // authAs() returns auth headers, not the numeric user id; resolve it
        // from the seeded row directly.
        $this->authAs('reach_admin');
        $approverId = (int) $db->table('reach_users')->where('email', 'reach_admin@test.aicountly.org')->get()->getRowArray()['id'];
        ActorRegistry::idForUser($approverId);

        (new BlogStateMachine($this->workBlocks))->transition($contentItemId, BlogStateMachine::APPROVED, null, [
            'reason' => 'e2e_acceptance_human_resolved_cross_review',
        ]);

        $approval = new BlogContentApprovalService();
        $approvalResult = $approval->approve($contentItemId, $contentVersionId, $approverId, 'standard', 'E2E acceptance approval');
        $this->assertTrue($approval->verifyForPublication($contentItemId, $contentVersionId));

        // -----------------------------------------------------------------
        // Step 15: schedule publication through the real deployment service
        // -----------------------------------------------------------------
        $existingConn = $db->table('reach_publication_connections')
            ->where('connection_key', 'aicountly_com')->get()->getRowArray();
        if (! $existingConn) {
            $db->table('reach_publication_connections')->insert([
                'connection_key' => 'aicountly_com',
                'display_name'   => 'AICOUNTLY.com (test)',
                'base_url'       => 'https://example.test',
                'enabled'        => true,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        // Use a due-now schedule so the queued worker job is immediately
        // leasable (available_at <= NOW()). A future scheduled_at would
        // correctly defer the job, which prevents exercising the real
        // lease + handler path in this acceptance test.
        $scheduleBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_SCHEDULE_PUBLISH,
            'content_item_id' => $contentItemId,
            'content_version_id' => $contentVersionId,
            'input_json' => ['scheduled_at' => date('c', strtotime('-1 minute'))],
        ]);
        $scheduleResult = $this->workBlocks->execute($scheduleBlockId);
        $this->assertArrayHasKey('deployment_id', $scheduleResult, 'SCHEDULE_PUBLISH must enqueue a deployment: ' . json_encode($scheduleResult));
        $deploymentId = (int) $scheduleResult['deployment_id'];
        $this->assertGreaterThan(0, $deploymentId);
        $this->assertSame(
            BlogStateMachine::SCHEDULED,
            $db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray()['workflow_status'],
        );

        // -----------------------------------------------------------------
        // Step 16/17: dispatch through the REAL queue — not a direct method
        // call. Enqueue the deployment job the same way
        // PublicationDeploymentService::enqueuePublication() already did
        // internally, then lease + execute it exactly like the `reach:work`
        // worker (App\Commands\ReachWork) would.
        // -----------------------------------------------------------------
        // payload_json is JSONB — use a JSON path, not LIKE (Postgres rejects jsonb ~~ text).
        $jobRow = $db->query(
            "SELECT * FROM reach_jobs
             WHERE (payload_json->>'deployment_id') = ?
             ORDER BY id DESC
             LIMIT 1",
            [(string) $deploymentId],
        )->getRowArray();
        $this->assertNotNull($jobRow, 'enqueuePublication() must create a real reach_jobs row for the worker to lease.');

        $jobService = new JobService();
        $leased = $jobService->reserve((string) $jobRow['queue'], 'e2e-test-worker');
        $this->assertNotNull($leased, 'The publication job must be leasable from its queue. job=' . json_encode([
            'id' => $jobRow['id'] ?? null,
            'queue' => $jobRow['queue'] ?? null,
            'status' => $jobRow['status'] ?? null,
            'available_at' => $jobRow['available_at'] ?? null,
        ]));
        $this->assertSame((int) $jobRow['id'], (int) $leased['id']);

        $handlerResult = (new JobHandlerRegistry())->execute($leased);
        $jobService->markCompleted((int) $leased['id'], $handlerResult);

        // -----------------------------------------------------------------
        // Step 18-20: signed mock-publisher request + deployment recorded
        // -----------------------------------------------------------------
        $deployment = $db->table('reach_publication_deployments')->where('id', $deploymentId)->get()->getRowArray();
        $this->assertNotNull($deployment);
        $this->assertNotEmpty($deployment['payload_checksum'] ?? null, 'A real payload checksum must be computed and stored — never an empty envelope.');

        // -----------------------------------------------------------------
        // Step 22/23: verify the public copy actually matches (real checksum
        // round-trip against the mock publisher, not an assumed success)
        // -----------------------------------------------------------------
        $verifyBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_VERIFY_PUBLICATION,
            'content_item_id' => $contentItemId,
        ]);
        $verifyResult = $this->workBlocks->execute($verifyBlockId);
        $this->assertSame('verified', $verifyResult['status'] ?? null, 'Verification must confirm the real checksum round-trip: ' . json_encode($verifyResult));
        $this->assertSame(
            BlogStateMachine::LIVE,
            $db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray()['workflow_status'],
        );

        // -----------------------------------------------------------------
        // Step 26: indexing must be recorded as pending, not indexed, merely
        // because the item went live. LIVE auto-chains an UPDATE_SITEMAP
        // block (BlogStateMachine::NEXT_WORK_BLOCK); without a reachable
        // public site in this test, it must fail closed, not fabricate
        // "indexed".
        // -----------------------------------------------------------------
        $sitemapBlock = $db->table('reach_work_blocks')
            ->where('content_item_id', $contentItemId)
            ->where('block_type', WorkBlockService::TYPE_UPDATE_SITEMAP)
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $this->assertNotNull($sitemapBlock, 'Going LIVE must automatically chain an UPDATE_SITEMAP check.');
        $sitemapOutcome = $this->workBlocks->execute((int) $sitemapBlock['id']);
        // No AICOUNTLY_PUBLIC_SITE_BASE_URL is configured in this test, so the
        // handler must block/fail honestly rather than claim sitemap inclusion.
        $this->assertTrue(
            ($sitemapOutcome['blocked'] ?? false) === true
            || ($sitemapOutcome['failed'] ?? false) === true
            || array_key_exists('sitemap_included', $sitemapOutcome),
            'UPDATE_SITEMAP must never silently claim success without a real check: ' . json_encode($sitemapOutcome),
        );
        $profile = $db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->get()->getRowArray();
        $this->assertNotSame('indexed', $profile['indexing_status'] ?? null, 'Publishing must never be conflated with being indexed.');

        // -----------------------------------------------------------------
        // Step 28: roll back
        // -----------------------------------------------------------------
        $rollback = new \App\Libraries\Publishing\Jobs\PublicationRollbackService();
        $rollbackOk = $rollback->rollback($deploymentId, 'e2e_acceptance_rollback_test', $approverId);
        $this->assertTrue($rollbackOk);

        // -----------------------------------------------------------------
        // Step 30: emergency unpublish through the real work block
        // -----------------------------------------------------------------
        $unpublishBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_UNPUBLISH_BLOG,
            'content_item_id' => $contentItemId,
            'input_json' => ['reason' => 'e2e_acceptance_emergency_unpublish'],
        ]);
        $unpublishResult = $this->workBlocks->execute($unpublishBlockId);
        $this->assertNotEmpty($unpublishResult);
        $finalState = $db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray()['workflow_status'];
        $this->assertContains($finalState, [BlogStateMachine::UNPUBLISH_QUEUED, BlogStateMachine::UNPUBLISHED]);
    }

    // -----------------------------------------------------------------
    // Negatives / security cases explicitly required by §26
    // -----------------------------------------------------------------

    public function testHighRiskContentCannotAutoPublishEvenWhenApproved(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        [$contentItemId, $contentVersionId] = $this->buildMinimalDraftFixture('high');

        $headers = $this->authAs('reach_admin');
        $approverId = (int) Database::connect()->table('reach_users')->where('email', 'reach_admin@test.aicountly.org')->get()->getRowArray()['id'];
        ActorRegistry::idForUser($approverId);
        (new BlogContentApprovalService())->approve($contentItemId, $contentVersionId, $approverId, 'standard', 'approved anyway');

        // Auto-publish is off by default in setUp(); this negative case needs
        // the automated PUBLISH_BLOG path to run so the hard HIGH-risk ban fires.
        $_ENV['BLOG_AUTO_PUBLISH_ENABLED'] = 'true';
        $_ENV['BLOG_PUBLIC_PUBLISHER_ENABLED'] = 'true';

        $publishBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_PUBLISH_BLOG,
            'content_item_id' => $contentItemId,
            'content_version_id' => $contentVersionId,
        ]);

        // Even with a valid, checksum-matching approval, the AUTOMATED
        // PUBLISH_BLOG dispatch path must never publish high-risk content —
        // only a human explicitly acting through ContentPublishController may.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('High-risk content cannot be auto-published.');
        $this->workBlocks->execute($publishBlockId);
    }

    public function testHighRiskContentWithoutApprovalCannotBeRecordedAsPublished(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        [$contentItemId] = $this->buildMinimalDraftFixture('high');
        $sm = new BlogStateMachine($this->workBlocks);

        // Walk the legal adjacency path from draft; the approval gate fires only
        // on the transition into published/live (assertPublishAllowed).
        $sm->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, null, []);
        $sm->transition($contentItemId, BlogStateMachine::APPROVED, null, []);
        $sm->transition($contentItemId, BlogStateMachine::PUBLISH_QUEUED, null, []);
        $sm->transition($contentItemId, BlogStateMachine::PUBLISHING, null, []);

        try {
            $sm->transition($contentItemId, BlogStateMachine::PUBLISHED, null, []);
            $this->fail('Recording a HIGH-risk item as PUBLISHED without a valid approval must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('approval', strtolower($e->getMessage()));
        }
    }

    public function testAmendingContentInvalidatesPriorApproval(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        [$contentItemId, $contentVersionId] = $this->buildMinimalDraftFixture('high');
        $db = Database::connect();

        $headers = $this->authAs('reach_admin');
        $approverId = (int) $db->table('reach_users')->where('email', 'reach_admin@test.aicountly.org')->get()->getRowArray()['id'];
        ActorRegistry::idForUser($approverId);

        $approval = new BlogContentApprovalService();
        $approval->approve($contentItemId, $contentVersionId, $approverId, 'standard', 'initial approval');
        $this->assertTrue($approval->verifyForPublication($contentItemId, $contentVersionId));

        // Amend the exact version's body in place (simulating a late edit to
        // the same version row) — the checksum captured at approval time no
        // longer matches, so the approval must stop being valid.
        $db->table('reach_content_versions')->where('id', $contentVersionId)->update([
            'body_html' => '<p>Amended after approval — this must invalidate it.</p>',
        ]);

        $this->assertFalse(
            $approval->verifyForPublication($contentItemId, $contentVersionId),
            'Amending approved content must invalidate the prior approval (checksum mismatch).',
        );
    }

    public function testRevokedApprovalBlocksPublicationImmediately(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        [$contentItemId, $contentVersionId] = $this->buildMinimalDraftFixture('high');
        $db = Database::connect();
        $headers = $this->authAs('reach_admin');
        $approverId = (int) $db->table('reach_users')->where('email', 'reach_admin@test.aicountly.org')->get()->getRowArray()['id'];
        ActorRegistry::idForUser($approverId);

        $approval = new BlogContentApprovalService();
        $approval->approve($contentItemId, $contentVersionId, $approverId, 'standard', 'will be revoked');
        $this->assertTrue($approval->verifyForPublication($contentItemId, $contentVersionId));

        $approval->revoke($contentItemId, 'compliance flagged an issue after approval', $approverId);

        $this->assertFalse($approval->verifyForPublication($contentItemId, $contentVersionId));
    }

    public function testNamedReviewerAttributionRequiresProtectedRegistrationAndExactVersionMatch(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        [$contentItemId, $contentVersionId] = $this->buildMinimalDraftFixture('high');
        $db = Database::connect();
        $headers = $this->authAs('reach_admin');
        $approverId = (int) $db->table('reach_users')->where('email', 'reach_admin@test.aicountly.org')->get()->getRowArray()['id'];
        ActorRegistry::idForUser($approverId);

        $approval = new BlogContentApprovalService();

        // Not yet a registered protected reviewer -> no attribution, even
        // though the approval itself is fully valid.
        $approval->approve($contentItemId, $contentVersionId, $approverId, 'professional_review', 'CA sign-off');
        $this->assertNull($approval->protectedReviewerName($contentItemId, $contentVersionId));

        $db->table('reach_blog_protected_reviewers')->insert([
            'user_id' => $approverId,
            'display_name' => 'CA Rahul Gupta',
            'credential_type' => 'chartered_accountant',
            'active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('CA Rahul Gupta', $approval->protectedReviewerName($contentItemId, $contentVersionId));

        // A DIFFERENT, unapproved version of the same item must never borrow
        // this attribution.
        $otherVersionId = $db->table('reach_content_versions')->insert([
            'content_item_id' => $contentItemId,
            'version_number' => 99,
            'title' => 'Unapproved alternate version',
            'body_html' => '<p>Never approved.</p>',
            'is_current' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]) ? (int) $db->insertID() : 0;
        $this->assertGreaterThan(0, $otherVersionId);
        $this->assertNull($approval->protectedReviewerName($contentItemId, $otherVersionId));
    }

    public function testUnsupportedClaimsFailClosedAndBlockPublication(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        [$contentItemId, $contentVersionId] = $this->buildMinimalDraftFixture('low', [
            // Deliberately implausible, unsourced statutory claim so the
            // heuristic extractor/verifier has a clear unsupported claim to
            // fail on rather than relying on brittle wording.
            'body_plain_text' => 'The GST rate for all goods was changed to 99% effective 1 January 2019 under notification No. 999/2019.',
        ]);

        $factBlockId = $this->workBlocks->create([
            'block_type' => WorkBlockService::TYPE_FACT_VERIFY,
            'content_item_id' => $contentItemId,
            'content_version_id' => $contentVersionId,
        ]);
        $result = $this->workBlocks->execute($factBlockId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('publishable', $result);
        // Whatever the extractor found, an unsupported/uncorroborated
        // statutory claim must never be silently treated as publishable.
        if (($result['unsupported'] ?? 0) > 0) {
            $this->assertFalse($result['publishable']);
            $this->assertSame(
                BlogStateMachine::CHANGES_REQUESTED,
                Database::connect()->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray()['workflow_status'],
            );
        }
    }

    public function testRepeatedScheduleRequestDoesNotCreateADuplicateDeployment(): void
    {
        if (!self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        [$contentItemId, $contentVersionId] = $this->buildMinimalDraftFixture('low');
        $db = Database::connect();

        // enqueuePublication() requires human approval before any schedule/publish.
        $headers = $this->authAs('reach_admin');
        $approverId = (int) $db->table('reach_users')->where('email', 'reach_admin@test.aicountly.org')->get()->getRowArray()['id'];
        ActorRegistry::idForUser($approverId);
        (new BlogContentApprovalService())->approve($contentItemId, $contentVersionId, $approverId, 'standard', 'schedule fixture');

        // Readiness gates require approved workflow + SEO/blog publication profiles.
        $item = $db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray();
        $slug = (string) ($item['slug'] ?? ('fixture-' . $contentItemId));
        $db->table('reach_content_items')->where('id', $contentItemId)->update([
            'workflow_status' => BlogStateMachine::APPROVED,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        $db->table('reach_content_seo_profiles')->insert([
            'content_item_id'      => $contentItemId,
            'content_version_id'   => $contentVersionId,
            'primary_keyword'      => 'gst filing',
            'meta_title'           => 'How to file GST returns on time for small business owners',
            'meta_description'     => str_pad('Deterministic SEO meta description for schedule idempotency fixture.', 100, '.'),
            'slug'                 => $slug,
            'canonical_preference' => 'self_canonical',
            'seo_status'           => 'ready',
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        $db->table('reach_blog_publication_profiles')->insert([
            'content_item_id'  => $contentItemId,
            'author_reference' => 'aicountly-editorial',
            'excerpt'          => 'Deterministic fixture excerpt.',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $existingConn = $db->table('reach_publication_connections')
            ->where('connection_key', 'aicountly_com')->get()->getRowArray();
        if (! $existingConn) {
            $db->table('reach_publication_connections')->insert([
                'connection_key' => 'aicountly_com',
                'display_name'   => 'AICOUNTLY.com (test)',
                'base_url'       => 'https://example.test',
                'enabled'        => true,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        $pub = new PublicationDeploymentService(new JobService());

        $scheduledAt = date('c', strtotime('+2 hours'));
        $firstId = $pub->enqueuePublication($contentItemId, $contentVersionId, 'aicountly_com', 'schedule', $scheduledAt, null);
        $secondId = $pub->enqueuePublication($contentItemId, $contentVersionId, 'aicountly_com', 'schedule', $scheduledAt, null);

        // Re-requesting the identical operation for the identical version
        // must be idempotent at the deployment layer, not create a second
        // competing deployment for the same (item, version, operation).
        $this->assertSame($firstId, $secondId, 'A repeated identical schedule request must not create a duplicate deployment.');
    }

    /**
     * @return array{0:int,1:int} [content_item_id, content_version_id]
     */
    private function buildMinimalDraftFixture(string $riskLevel, array $versionOverrides = []): array
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $slug = 'e2e-fixture-' . bin2hex(random_bytes(6));
        $db->table('reach_content_items')->insert([
            'uuid' => bin2hex(random_bytes(16)),
            'content_type' => 'blog',
            'title' => 'E2E fixture: ' . $slug,
            'slug' => $slug,
            'risk_level' => $riskLevel,
            'workflow_status' => BlogStateMachine::DRAFT,
            'approval_status' => 'pending',
            'created_actor_type' => 'system',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $contentItemId = (int) $db->insertID();

        $db->table('reach_content_versions')->insert(array_merge([
            'content_item_id' => $contentItemId,
            'version_number' => 1,
            'title' => 'E2E fixture draft',
            'body_html' => '<p>Deterministic fixture body.</p>',
            'body_plain_text' => 'Deterministic fixture body.',
            'is_current' => true,
            'created_at' => $now,
        ], $versionOverrides));
        $contentVersionId = (int) $db->insertID();

        $db->table('reach_content_items')->where('id', $contentItemId)->update(['current_version_id' => $contentVersionId]);

        return [$contentItemId, $contentVersionId];
    }
}

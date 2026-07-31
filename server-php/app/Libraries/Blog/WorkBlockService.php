<?php

namespace App\Libraries\Blog;

use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\ImageProviderRegistry;
use App\Libraries\Blog\Roadmap\RoadmapOptimizerService;
use App\Libraries\Blog\Verification\ClaimExtractor;
use App\Libraries\Blog\Verification\FactVerificationService;
use App\Libraries\JobService;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Work-block planning and execution layer for blog automation.
 */
class WorkBlockService
{
    public const TYPE_OPTIMIZE_ROADMAP     = 'OPTIMIZE_ROADMAP';
    public const TYPE_DISCOVER_TOPICS      = 'DISCOVER_TOPICS';
    public const TYPE_SCORE_TOPIC          = 'SCORE_TOPIC';
    public const TYPE_GENERATE_BRIEF       = 'GENERATE_BRIEF';
    public const TYPE_GENERATE_OUTLINE     = 'GENERATE_OUTLINE';
    public const TYPE_GENERATE_DRAFT       = 'GENERATE_DRAFT';
    public const TYPE_FACT_VERIFY          = 'FACT_VERIFY';
    public const TYPE_GENERATE_IMAGE       = 'GENERATE_IMAGE';
    public const TYPE_SEO_OPTIMIZE         = 'SEO_OPTIMIZE';
    public const TYPE_INTERNAL_LINK        = 'INTERNAL_LINK';
    public const TYPE_CROSS_REVIEW         = 'CROSS_REVIEW';
    public const TYPE_HUMAN_REVIEW_GATE    = 'HUMAN_REVIEW_GATE';
    public const TYPE_SCHEDULE_PUBLISH     = 'SCHEDULE_PUBLISH';
    public const TYPE_PUBLISH_BLOG         = 'PUBLISH_BLOG';
    public const TYPE_VERIFY_PUBLICATION   = 'VERIFY_PUBLICATION';
    public const TYPE_REFRESH_CONTENT      = 'REFRESH_CONTENT';
    public const TYPE_UNPUBLISH_BLOG       = 'UNPUBLISH_BLOG';
    public const TYPE_RENDER_FILES         = 'RENDER_FILES';
    public const TYPE_BACKFILL_STORAGE     = 'BACKFILL_STORAGE';
    public const TYPE_CONSOLIDATE_CONTENT  = 'CONSOLIDATE_CONTENT';
    public const TYPE_ROADMAP_PLAN         = 'ROADMAP_PLAN';

    private BaseConnection $db;
    private JobService $jobService;
    private BlogFeatureFlags $flags;

    public function __construct(?JobService $jobService = null, ?BlogFeatureFlags $flags = null)
    {
        $this->db         = Database::connect();
        $this->jobService = $jobService ?? new JobService();
        $this->flags      = $flags ?? new BlogFeatureFlags();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $row = [
            'block_type'         => (string) ($data['block_type'] ?? ''),
            'scope'              => $data['scope'] ?? null,
            'content_item_id'    => $data['content_item_id'] ?? null,
            'content_version_id' => $data['content_version_id'] ?? null,
            'input_json'         => isset($data['input_json']) ? json_encode($data['input_json'], JSON_UNESCAPED_SLASHES) : null,
            'eligibility_status' => $data['eligibility_status'] ?? 'pending',
            'idempotency_key'    => $data['idempotency_key'] ?? null,
            'priority'           => (int) ($data['priority'] ?? 0),
            'scheduled_at'       => $data['scheduled_at'] ?? null,
            'dependency_ids'     => isset($data['dependency_ids']) ? json_encode($data['dependency_ids'], JSON_UNESCAPED_SLASHES) : null,
            'provider_route'     => $data['provider_route'] ?? null,
            'cost_ceiling_cents' => $data['cost_ceiling_cents'] ?? null,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        $this->db->table('reach_work_blocks')->insert($row);
        return (int) $this->db->insertID();
    }

    public function markEligible(int $id): void
    {
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status' => 'eligible',
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{enqueued:int, skipped:int, work_block_ids:int[]}
     */
    public function enqueueEligibleBatch(int $limit = 25): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = $this->db->table('reach_work_blocks')
            ->where('eligibility_status', 'eligible')
            ->groupStart()
                ->where('scheduled_at IS NULL', null, false)
                ->orWhere('scheduled_at <=', $now)
            ->groupEnd()
            ->orderBy('priority', 'DESC')
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get()
            ->getResultArray();

        $enqueued = 0;
        $skipped  = 0;
        $ids      = [];

        foreach ($rows as $row) {
            $blockId = (int) $row['id'];
            $key     = $row['idempotency_key'] ?? ('work-block-' . $blockId);

            try {
                $jobId = $this->jobService->enqueue(
                    'reach.blog_work_block',
                    ['work_block_id' => $blockId],
                    [
                        'queue'           => 'blog',
                        'idempotency_key' => $key,
                        'priority'        => (int) ($row['priority'] ?? 0),
                    ],
                );

                $this->db->table('reach_work_blocks')->where('id', $blockId)->update([
                    'eligibility_status' => 'queued',
                    'job_id'             => $jobId,
                    'updated_at'         => $now,
                ]);

                $enqueued++;
                $ids[] = $blockId;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return ['enqueued' => $enqueued, 'skipped' => $skipped, 'work_block_ids' => $ids];
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(int $id): array
    {
        $block = $this->db->table('reach_work_blocks')->where('id', $id)->get()->getRowArray();
        if (! $block) {
            throw new \RuntimeException("Work block {$id} not found");
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status' => 'processing',
            'started_at'         => $now,
            'attempt_count'      => (int) $block['attempt_count'] + 1,
            'updated_at'         => $now,
        ]);

        $blockType = (string) $block['block_type'];

        if ($blockType === self::TYPE_PUBLISH_BLOG) {
            return $this->executePublishBlog($id, $block);
        }

        if (! $this->flags->isEnabled('automation')) {
            return $this->completeDeferred($id, $blockType, 'automation_disabled');
        }

        return match ($blockType) {
            self::TYPE_OPTIMIZE_ROADMAP => $this->executeOptimizeRoadmap($id, $block),
            self::TYPE_FACT_VERIFY      => $this->executeFactVerify($id, $block),
            self::TYPE_GENERATE_IMAGE   => $this->executeGenerateImage($id, $block),
            default                     => $this->completeDeferred($id, $blockType, 'handler_not_implemented'),
        };
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executeOptimizeRoadmap(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('roadmap_optimizer')) {
            return $this->completeDeferred($id, self::TYPE_OPTIMIZE_ROADMAP, 'roadmap_optimizer_disabled');
        }

        $input   = $this->decodeInput($block);
        $forDate = (string) ($input['run_for_date'] ?? date('Y-m-d'));

        $summary = (new RoadmapOptimizerService())->run($forDate, [
            'limit'    => isset($input['limit']) ? (int) $input['limit'] : null,
            'dry_run'  => (bool) ($input['dry_run'] ?? false),
        ]);

        $this->markCompleted($id, $summary);

        return $summary;
    }

    /**
     * Fact verification is fail-closed: an unavailable verifier never yields a pass,
     * and unsupported high-risk claims force the item into human review.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executeFactVerify(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('fact_verification')) {
            return $this->completeDeferred($id, self::TYPE_FACT_VERIFY, 'fact_verification_disabled');
        }

        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        if ($contentVersionId <= 0) {
            throw new \RuntimeException('FACT_VERIFY work block requires content_version_id');
        }

        $version = $this->db->table('reach_content_versions')
            ->where('id', $contentVersionId)
            ->get()
            ->getRowArray();

        if (! $version) {
            throw new \RuntimeException("Content version {$contentVersionId} not found");
        }

        // Plain text first: claim extraction should not trip over markup.
        $body = (string) (
            ($version['body_plain_text'] ?? '')
            ?: ($version['body_markdown'] ?? '')
            ?: ($version['body_html'] ?? '')
        );

        $claims       = (new ClaimExtractor())->extractHeuristic($body);
        $verification = new FactVerificationService(null, $this->flags);
        $result       = $verification->verify($claims);

        $output = [
            'status'      => $result['status'],
            'pass_rate'   => $result['pass_rate'],
            'threshold'   => $result['threshold'],
            'claim_count' => count($claims),
            'unsupported' => $result['unsupported'],
            'publishable' => $verification->isPublishable($result),
        ];

        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executeGenerateImage(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('image_generation')) {
            return $this->completeDeferred($id, self::TYPE_GENERATE_IMAGE, 'image_generation_disabled');
        }

        $input  = $this->decodeInput($block);
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \RuntimeException('GENERATE_IMAGE work block requires input_json.prompt');
        }

        $registry     = new ImageProviderRegistry();
        $requestedKey = (string) ($input['provider'] ?? $block['provider_route'] ?? '');
        $configured   = array_keys($registry->configuredProviders());

        if ($configured === []) {
            return $this->completeDeferred($id, self::TYPE_GENERATE_IMAGE, 'no_image_provider_configured');
        }

        $providerKey = in_array($requestedKey, $configured, true) ? $requestedKey : $configured[0];
        $provider    = $registry->get($providerKey);

        $result = $provider->generateImage(new ImageGenerationInput(
            prompt: $prompt,
            modelKey: (string) ($input['model'] ?? ''),
            width: (int) ($input['width'] ?? 1536),
            height: (int) ($input['height'] ?? 1024),
        ));

        // Binary payloads are deliberately not persisted in output_json.
        $output = [
            'provider_key'  => $result->providerKey,
            'model_key'     => $result->modelKey,
            'image_count'   => $result->imageCount,
            'duration_ms'   => $result->durationMs,
        ];

        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function decodeInput(array $block): array
    {
        $raw = $block['input_json'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function executePublishBlog(int $id, array $block): array
    {
        if (! $this->flags->isEnabled('public_publisher') || ! $this->flags->isEnabled('auto_publish')) {
            return $this->completeDeferred($id, self::TYPE_PUBLISH_BLOG, 'publish_flags_disabled');
        }

        $contentItemId    = (int) ($block['content_item_id'] ?? 0);
        $contentVersionId = (int) ($block['content_version_id'] ?? 0);
        if ($contentItemId <= 0 || $contentVersionId <= 0) {
            throw new \RuntimeException('PUBLISH_BLOG work block requires content_item_id and content_version_id');
        }

        $item = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray();
        $risk = strtoupper((string) ($item['risk_level'] ?? 'LOW'));
        $this->flags->assertHighRiskAutoPublishForbidden($risk);

        $pub = new PublicationDeploymentService($this->jobService);
        $deploymentId = $pub->enqueuePublication(
            $contentItemId,
            $contentVersionId,
            'aicountly_com',
            'publish',
            null,
            null,
        );

        $output = ['deployment_id' => $deploymentId, 'status' => 'queued'];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * @return array<string,mixed>
     */
    private function completeDeferred(int $id, string $blockType, string $reason): array
    {
        $output = [
            'deferred'   => true,
            'reason'     => $reason,
            'block_type' => $blockType,
        ];
        $this->markCompleted($id, $output);

        return $output;
    }

    /**
     * @param array<string,mixed> $output
     */
    private function markCompleted(int $id, array $output): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')->where('id', $id)->update([
            'eligibility_status' => 'completed',
            'output_json'        => json_encode($output, JSON_UNESCAPED_SLASHES),
            'completed_at'       => $now,
            'updated_at'         => $now,
        ]);
    }
}

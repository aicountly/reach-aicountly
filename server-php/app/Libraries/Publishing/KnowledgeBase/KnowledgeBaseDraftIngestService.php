<?php

declare(strict_types=1);

namespace App\Libraries\Publishing\KnowledgeBase;

use App\Libraries\ContentVersionService;
use App\Libraries\HtmlSanitizer;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Claude-routine Knowledge Base ingestion. KB articles are generated ONLY by
 * the routine (Opus writes, a different model verifies — same two-model rule
 * as blogs). KB items bypass the blog state machine entirely: with
 * KB_AUTO_APPROVE=true a valid draft is approved (audited) and a publication
 * deployment is enqueued straight away.
 */
class KnowledgeBaseDraftIngestService
{
    private const ARTICLE_TYPES = ['concept', 'how_to', 'troubleshooting', 'faq', 'release_guide', 'configuration', 'integration', 'reference', 'best_practice'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Today's writing plan for the routine: per-product remaining quota plus
     * un-ingested topic seeds from content-base/knowledge-base/index.json.
     *
     * @return array<string,mixed>
     */
    public function plan(): array
    {
        $cadences = $this->db->table('reach_kb_product_cadence')
            ->where('enabled', true)
            ->orderBy('daily_quota', 'DESC')
            ->get()->getResultArray();

        $index    = (new \App\Libraries\Blog\ContentBaseService($this->db))->knowledgeBaseIndex();
        $byProduct = [];
        foreach ((array) ($index['products'] ?? []) as $productEntry) {
            $byProduct[(string) ($productEntry['product_slug'] ?? '')] = (array) ($productEntry['topics'] ?? []);
        }

        $plan = [];
        foreach ($cadences as $cadence) {
            $slug  = (string) $cadence['product_slug'];
            $quota = (int) $cadence['daily_quota'];

            $publishedToday = (int) ($this->db->query(
                "SELECT COUNT(*) AS c
                 FROM reach_content_items i
                 JOIN reach_kb_publication_profiles p ON p.content_item_id = i.id
                 JOIN reach_products pr ON pr.id = p.product_id
                 WHERE i.content_type = 'knowledge_base'
                   AND i.created_by_service = 'reach:automation_ingest_kb'
                   AND i.created_at >= ?
                   AND pr.slug = ?",
                [date('Y-m-d 00:00:00'), $slug]
            )->getRowArray()['c'] ?? 0);

            $topics = [];
            foreach ($byProduct[$slug] ?? [] as $topic) {
                $key = (string) ($topic['key'] ?? '');
                if ($key === '' || strtolower((string) ($topic['status'] ?? 'planned')) === 'retired') {
                    continue;
                }
                $ingested = $this->db->table('reach_content_items')
                    ->where('content_base_key', $key)
                    ->countAllResults() > 0;
                if (! $ingested) {
                    $topics[] = $topic;
                }
            }

            $plan[] = [
                'product_slug'    => $slug,
                'tier'            => $cadence['tier'],
                'daily_quota'     => $quota,
                'published_today' => $publishedToday,
                'remaining_today' => max(0, $quota - $publishedToday),
                'pending_topics'  => $topics,
            ];
        }

        return [
            'date'     => date('Y-m-d'),
            'products' => $plan,
            'notes'    => (string) ($index['notes'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string, content_item_id:int, content_version_id:int, slug:string, deployment_id?:int, existing?:bool}
     * @throws \InvalidArgumentException
     */
    public function ingestKbDraft(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 10) {
            throw new \InvalidArgumentException('title is required (10+ chars)');
        }

        $productSlug = trim((string) ($payload['product_slug'] ?? ''));
        if ($productSlug === '') {
            throw new \InvalidArgumentException('product_slug is required');
        }
        $cadence = $this->db->table('reach_kb_product_cadence')
            ->where('product_slug', $productSlug)
            ->where('enabled', true)
            ->get()->getRowArray();
        if (! $cadence) {
            throw new \InvalidArgumentException("product_slug '{$productSlug}' is not an enabled KB product");
        }

        $bodyHtml = (string) ($payload['body_html'] ?? '');
        if (trim($bodyHtml) === '') {
            throw new \InvalidArgumentException('body_html is required');
        }
        $bodyHtml = (new HtmlSanitizer())->purify($bodyHtml);
        $plain    = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_word_count($plain) < 150) {
            throw new \InvalidArgumentException('body too short: need at least 150 words');
        }

        $this->assertVerificationReport($payload);

        $articleType = strtolower(trim((string) ($payload['article_type'] ?? 'concept')));
        $articleType = match ($articleType) {
            'getting_started', 'getting-started' => 'how_to',
            default => in_array($articleType, self::ARTICLE_TYPES, true) ? $articleType : 'concept',
        };

        $steps = array_values((array) ($payload['steps'] ?? []));
        if ($articleType === 'how_to' && $steps === []) {
            throw new \InvalidArgumentException('how_to articles require steps[]');
        }

        $contentBaseKey = trim((string) ($payload['content_base_key'] ?? ''));
        if ($contentBaseKey !== '') {
            $existing = $this->db->table('reach_content_items')
                ->select('id, slug, current_version_id')
                ->where('content_base_key', $contentBaseKey)
                ->get()->getRowArray();
            if ($existing) {
                return [
                    'status'             => 'already_ingested',
                    'content_item_id'    => (int) $existing['id'],
                    'content_version_id' => (int) ($existing['current_version_id'] ?? 0),
                    'slug'               => (string) $existing['slug'],
                    'existing'           => true,
                ];
            }
        }

        $autoApprove = filter_var(env('KB_AUTO_APPROVE', 'false'), FILTER_VALIDATE_BOOL);
        $productId   = $this->ensureProduct($productSlug);
        $slug        = $this->uniqueSlug(trim((string) ($payload['seo']['slug'] ?? $payload['slug_hint'] ?? '')) ?: $title);
        $now         = date('Y-m-d H:i:s');
        $provenance  = (array) ($payload['provenance'] ?? []);

        $this->db->transStart();

        $this->db->table('reach_content_items')->insert([
            'uuid'               => $this->uuid(),
            'content_type'       => 'knowledge_base',
            'title'              => mb_substr($title, 0, 300),
            'slug'               => $slug,
            'content_base_key'   => $contentBaseKey !== '' ? $contentBaseKey : null,
            'workflow_status'    => $autoApprove ? 'approved' : 'draft',
            'approval_status'    => $autoApprove ? 'approved' : 'pending',
            'created_actor_type' => 'system',
            'created_by_service' => 'reach:automation_ingest_kb',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $contentItemId = (int) $this->db->insertID();

        $this->db->table('reach_content_knowledge_base_details')->insert([
            'content_item_id'  => $contentItemId,
            'article_type'     => $articleType,
            'help_category'    => $payload['help_category'] ?? null,
            'difficulty_level' => $payload['difficulty_level'] ?? null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $version = (new ContentVersionService())->createVersion($contentItemId, [
            'title'           => $title,
            'summary'         => $payload['summary'] ?? null,
            'body_html'       => $bodyHtml,
            'body_markdown'   => $payload['body_markdown'] ?? null,
            'body_plain_text' => $plain,
        ], [
            'type'    => 'bot',
            'service' => 'reach:automation_ingest_kb:' . (string) ($provenance['generated_by_model'] ?? 'claude'),
        ], 'Claude routine KB draft (verified by ' . (string) ($payload['verification_report']['model'] ?? 'reviewer') . ')');
        $versionId = (int) $version['id'];

        $this->db->table('reach_kb_publication_profiles')->insert([
            'content_item_id'          => $contentItemId,
            'article_type'             => $articleType,
            'product_id'               => $productId,
            'applicable_versions_json' => json_encode($payload['applicable_versions'] ?? ['type' => 'all'], JSON_UNESCAPED_SLASHES),
            'prerequisites_json'       => json_encode(array_values((array) ($payload['prerequisites'] ?? [])), JSON_UNESCAPED_SLASHES),
            'steps_json'               => json_encode($steps, JSON_UNESCAPED_SLASHES),
            'troubleshooting_json'     => json_encode(array_values((array) ($payload['troubleshooting'] ?? [])), JSON_UNESCAPED_SLASHES),
            'difficulty_level'         => in_array($payload['difficulty_level'] ?? '', ['beginner', 'intermediate', 'advanced', 'expert'], true)
                ? $payload['difficulty_level'] : null,
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        $seo = (array) ($payload['seo'] ?? []);
        $this->db->table('reach_content_seo_profiles')->insert([
            'content_item_id'    => $contentItemId,
            'content_version_id' => $versionId,
            'primary_keyword'    => mb_substr((string) ($seo['primary_keyword'] ?? $title), 0, 255),
            'meta_title'         => mb_substr((string) ($seo['meta_title'] ?? $title), 0, 200),
            'meta_description'   => mb_substr((string) ($seo['meta_description'] ?? $payload['summary'] ?? $title), 0, 320),
            'slug'               => $slug,
            'seo_status'         => 'ready',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('kb ingest transaction failed');
        }

        \App\Libraries\AuditLogger::record(
            $autoApprove ? 'kb.auto_approved_routine' : 'kb.ingested_pending_approval',
            [
                'content_item_id' => $contentItemId,
                'product_slug'    => $productSlug,
                'article_type'    => $articleType,
                'generated_by'    => $provenance['generated_by_model'] ?? null,
                'reviewed_by'     => $payload['verification_report']['model'] ?? null,
            ]
        );

        $result = [
            'status'             => 'ingested',
            'content_item_id'    => $contentItemId,
            'content_version_id' => $versionId,
            'slug'               => $slug,
        ];

        if ($autoApprove) {
            $deploymentId = (new PublicationDeploymentService())->enqueuePublication(
                $contentItemId,
                $versionId,
                (string) env('AICOUNTLY_PUBLICATION_CONNECTION_KEY', 'aicountly_com'),
                'publish',
            );
            $result['deployment_id'] = $deploymentId;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertVerificationReport(array $payload): void
    {
        $report    = $payload['verification_report'] ?? null;
        $reviewer  = is_array($report) ? strtolower(trim((string) ($report['model'] ?? ''))) : '';
        $generator = strtolower(trim((string) ($payload['provenance']['generated_by_model'] ?? '')));

        if (! is_array($report) || $report === [] || $reviewer === '') {
            throw new \InvalidArgumentException('verification_report with model is required');
        }
        if ($generator === '') {
            throw new \InvalidArgumentException('provenance.generated_by_model is required');
        }
        if ($reviewer === $generator) {
            throw new \InvalidArgumentException('verification_report.model must differ from provenance.generated_by_model (two-model rule)');
        }
    }

    private function ensureProduct(string $slug): int
    {
        $row = $this->db->table('reach_products')
            ->where('slug', $slug)
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        $this->db->table('reach_products')->insert([
            'slug'               => $slug,
            'name'               => ucwords(str_replace('-', ' ', $slug)),
            'status'             => 'approved',
            'created_actor_type' => 'system',
            'created_by_service' => 'reach:automation_ingest_kb',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function uniqueSlug(string $source): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $source) ?? '', '-'));
        $slug = mb_substr($slug !== '' ? $slug : 'kb-article', 0, 280);

        $candidate = $slug;
        $i         = 2;
        while ($this->db->table('reach_content_items')->where('slug', $candidate)->countAllResults() > 0) {
            $candidate = $slug . '-' . $i;
            $i++;
        }

        return $candidate;
    }

    private function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

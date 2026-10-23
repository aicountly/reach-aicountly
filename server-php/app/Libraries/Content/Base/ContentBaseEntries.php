<?php

declare(strict_types=1);

namespace App\Libraries\Content\Base;

use App\Libraries\Automation\Routine\LaunchApps;
use App\Libraries\Blog\AutomationDraftIngestService;
use App\Libraries\Blog\BlogTaxonomy;
use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The living content base: topics per content type in
 * reach_content_base_entries, seeded from the git files and grown by the
 * routines through v1/automation/content-base/*.
 *
 * Git keeps the strategy baseline (blog/base.md and the seed files). Seeds are
 * imported idempotently — a deploy adds new seeds and can retire one, but it
 * never resets a topic's progress, and nothing a routine adds is lost when the
 * files are rsynced with --delete.
 *
 * Statuses (one vocabulary for every surface):
 *   planned   waiting to be written — what GET …/next hands out
 *   queued    a routine submitted it and Reach is vetting it (or, for a
 *             community seed, it is an open question waiting for the desk)
 *   produced  vetting approved it and Reach is publishing it
 *   published live on aicountly.com
 *   retired   dropped: retired in git, rejected by vetting twice, or the
 *             routine reported the feature it is about does not exist
 *
 * Status is derived from the pipeline tables by reconcile(), never from what a
 * routine says it did.
 */
final class ContentBaseEntries
{
    public const TYPES    = ['blog', 'knowledge_base', 'community'];
    public const STATUSES = ['planned', 'queued', 'produced', 'published', 'retired'];

    /** Blog states of Reach's retired in-house drafting that never got past planning. */
    public const LEGACY_PRE_DRAFT = ['idea', 'topic_candidate', 'topic_scored', 'roadmap_planned', 'brief_draft', 'brief_ready',
        'outline_draft', 'outline_ready', 'draft_generating'];

    private const KEY_PATTERN   = '/^[a-z0-9][a-z0-9._-]{2,119}$/';
    private const MAX_ATTEMPTS  = 2;
    private const PAYLOAD_FIELDS = ['slug_hint', 'seed_keyword', 'funnel_stage', 'search_intent', 'audience', 'portfolio_stream',
        'cover_prompt', 'article_type', 'context', 'tier', 'difficulty_level', 'topic_category'];

    private BaseConnection $db;
    private ContentBaseService $files;

    public function __construct(?BaseConnection $db = null, ?ContentBaseService $files = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->files = $files ?? new ContentBaseService($this->db);
    }

    public static function normalizeType(string $type): ?string
    {
        $t = strtolower(trim(str_replace('-', '_', $type)));

        return match ($t) {
            'blog', 'blogs'                                   => 'blog',
            'kb', 'knowledge_base', 'knowledgebase', 'help'   => 'knowledge_base',
            'community', 'community_qa', 'faq', 'qa'           => 'community',
            default                                            => null,
        };
    }

    public function available(): bool
    {
        return \App\Libraries\Database\SchemaGuard::hasTable($this->db, 'reach_content_base_entries');
    }

    public function isEmpty(): bool
    {
        return $this->db->table('reach_content_base_entries')->countAllResults() === 0;
    }

    // ───────────────────────────────────────────────────────────── seeds

    /**
     * Import the git seed files. New keys are inserted as planned; a seed
     * still planned picks up edits to its title or brief; a seed marked
     * retired in git is retired unless it already went further. Rows the
     * files no longer mention are left alone — git is a baseline, not a mirror.
     *
     * @return array<string, array{seen:int, created:int, updated:int, retired:int}>
     */
    public function importSeeds(): array
    {
        $out = [];
        foreach ($this->seeds() as $type => [$fileKey, $raw, $seeds]) {
            $counts = ['seen' => count($seeds), 'created' => 0, 'updated' => 0, 'retired' => 0];
            foreach ($seeds as $seed) {
                $result = $this->importSeed($type, $seed);
                if ($result !== null) {
                    $counts[$result]++;
                }
            }
            $out[$type] = $counts;

            // One audit row per change, not per hourly pass.
            $last = $this->db->table('reach_content_base_sync_runs')->select('file_checksum')
                ->where('file_key', $fileKey)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            $changed = $counts['created'] + $counts['updated'] + $counts['retired'] > 0
                || ($last['file_checksum'] ?? null) !== hash('sha256', $raw);
            if ($raw !== '' && $changed) {
                $this->db->table('reach_content_base_sync_runs')->insert([
                    'file_key'      => $fileKey,
                    'file_checksum' => hash('sha256', $raw),
                    'entries_seen'  => $counts['seen'],
                    'created_count' => $counts['created'],
                    'updated_count' => $counts['updated'],
                    'retired_count' => $counts['retired'],
                    'ran_at'        => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:list<array<string,mixed>>}>
     */
    private function seeds(): array
    {
        $base = $this->files->basePath();
        $read = static fn (string $p): string => is_readable($p) ? (string) file_get_contents($p) : '';

        $blogRaw = $read($base . '/blog/index.json');
        $blog    = [];
        foreach ((array) ((json_decode($blogRaw, true) ?: [])['entries'] ?? []) as $e) {
            $blog[] = [
                'key'          => (string) ($e['key'] ?? ''),
                'title'        => (string) ($e['title'] ?? ''),
                'product_slug' => $e['product_slug'] ?? null,
                'category'     => $e['portfolio_stream'] ?? null,
                'brief'        => $e['brief_prompt'] ?? null,
                'target_date'  => $e['target_date'] ?? null,
                'status'       => $e['status'] ?? 'planned',
                'payload'      => $e,
            ];
        }

        $kbRaw = $read($base . '/knowledge-base/index.json');
        $kb    = [];
        foreach ((array) ((json_decode($kbRaw, true) ?: [])['products'] ?? []) as $p) {
            foreach ((array) ($p['topics'] ?? []) as $t) {
                $kb[] = [
                    'key'          => (string) ($t['key'] ?? ''),
                    'title'        => (string) ($t['title'] ?? ''),
                    'product_slug' => $p['product_slug'] ?? null,
                    'category'     => $t['article_type'] ?? null,
                    'brief'        => $t['brief_prompt'] ?? $t['prompt'] ?? null,
                    'target_date'  => $t['target_date'] ?? null,
                    'status'       => $t['status'] ?? 'planned',
                    'payload'      => $t + ['tier' => $p['tier'] ?? null],
                ];
            }
        }

        $cqRaw = $read($base . '/community/question-seeds.json');
        $cq    = [];
        foreach ((array) ((json_decode($cqRaw, true) ?: [])['seeds'] ?? []) as $s) {
            $cq[] = [
                'key'          => (string) ($s['key'] ?? ''),
                'title'        => (string) ($s['question'] ?? ''),
                'product_slug' => $s['product_slug'] ?? null,
                'category'     => $s['category'] ?? null,
                'brief'        => $s['context'] ?? null,
                'target_date'  => $s['target_date'] ?? null,
                'status'       => $s['status'] ?? 'planned',
                'payload'      => $s,
            ];
        }

        return [
            'blog'           => ['seed_blog', $blogRaw, $blog],
            'knowledge_base' => ['seed_knowledge_base', $kbRaw, $kb],
            'community'      => ['seed_community', $cqRaw, $cq],
        ];
    }

    /** @param array<string,mixed> $seed */
    private function importSeed(string $type, array $seed): ?string
    {
        $key   = strtolower(trim((string) $seed['key']));
        $title = trim((string) $seed['title']);
        if ($key === '' || $title === '') {
            return null;
        }

        $retiredInGit = in_array(strtolower(trim((string) $seed['status'])), ['retired', 'archived', 'dropped'], true);
        $checksum     = hash('sha256', json_encode($seed['payload'], JSON_UNESCAPED_SLASHES) ?: '');
        $now          = date('Y-m-d H:i:s');
        $fields       = [
            'title'        => mb_substr($title, 0, 500),
            'product_slug' => self::strOrNull($seed['product_slug'], 100),
            'category'     => self::strOrNull($seed['category'], 120),
            'brief'        => self::strOrNull($seed['brief'], 4000),
            'payload_json' => json_encode(array_intersect_key((array) $seed['payload'], array_flip(self::PAYLOAD_FIELDS)), JSON_UNESCAPED_SLASHES),
            'target_date'  => self::dateOrNull($seed['target_date']),
            'seed_checksum'=> $checksum,
            'updated_at'   => $now,
        ];

        $existing = $this->find($type, $key);
        if ($existing === null) {
            $this->db->table('reach_content_base_entries')->insert($fields + [
                'content_type'  => $type,
                'entry_key'     => $key,
                'origin'        => 'seed',
                'status'        => $retiredInGit ? 'retired' : 'planned',
                'status_reason' => $retiredInGit ? 'Retired in the git seed file.' : null,
                'retired_at'    => $retiredInGit ? $now : null,
                'created_at'    => $now,
            ]);

            return 'created';
        }

        if ($existing['origin'] !== 'seed' || $existing['seed_checksum'] === $checksum) {
            return null;
        }

        if ($retiredInGit && in_array($existing['status'], ['planned', 'queued'], true) && empty($existing['content_item_id'])) {
            $this->db->table('reach_content_base_entries')->where('id', $existing['id'])->update($fields + [
                'status' => 'retired', 'status_reason' => 'Retired in the git seed file.', 'retired_at' => $now,
            ]);

            return 'retired';
        }

        if ($existing['status'] === 'planned') {
            $this->db->table('reach_content_base_entries')->where('id', $existing['id'])->update($fields);

            return 'updated';
        }

        // Further along than planned: only remember the file version.
        $this->db->table('reach_content_base_entries')->where('id', $existing['id'])->update(['seed_checksum' => $checksum]);

        return null;
    }

    // ───────────────────────────────────────────────────────── reconcile

    /**
     * Derive every open entry's status from the pipeline tables.
     *
     * @return array<string,int> transitions by new status
     */
    public function reconcile(?string $type = null): array
    {
        $builder = $this->db->table('reach_content_base_entries')->whereNotIn('status', ['retired', 'published']);
        if ($type !== null) {
            $builder->where('content_type', $type);
        }

        $changes = [];
        foreach ($builder->get()->getResultArray() as $entry) {
            $new = $this->reconcileOne($entry);
            if ($new !== null) {
                $changes[$new] = ($changes[$new] ?? 0) + 1;
            }
        }

        return $changes;
    }

    /**
     * @param array<string,mixed> $entry
     * @return string|null the new status when it changed
     */
    public function reconcileOne(array $entry): ?string
    {
        $verdict = match ($entry['content_type']) {
            'community' => $this->communityVerdict($entry),
            default     => $this->itemVerdict($entry),
        };
        if ($verdict === null) {
            return null;
        }

        $now    = date('Y-m-d H:i:s');
        $status = $verdict['status'];
        $update = array_filter([
            'content_item_id'       => $verdict['content_item_id'] ?? null,
            'community_question_id' => $verdict['community_question_id'] ?? null,
        ], static fn ($v) => $v !== null);

        if ($status === 'rejected') {
            $attempts = (int) $entry['attempts'] + 1;
            $reason   = 'Rejected by vetting: ' . ($verdict['reason'] ?? 'no reason given');
            if ($attempts >= self::MAX_ATTEMPTS) {
                $update += ['status' => 'retired', 'attempts' => $attempts, 'status_reason' => mb_substr($reason . ' (twice — retired)', 0, 2000), 'retired_at' => $now];
                $status  = 'retired';
            } else {
                // A rejected key is spent (re-sending it returns the rejected
                // item), so the next attempt writes under a fresh key.
                $update += [
                    'status'          => 'planned',
                    'attempts'        => $attempts,
                    'content_key'     => mb_substr($entry['entry_key'], 0, 115) . '-r' . ($attempts + 1),
                    'content_item_id' => null,
                    'community_question_id' => null,
                    'status_reason'   => mb_substr($reason . ' Write it again, fixing that, under the new content_base_key.', 0, 2000),
                ];
                $status = 'planned';
            }
        } else {
            $sameLinks = ! isset($update['content_item_id']) || (int) $update['content_item_id'] === (int) ($entry['content_item_id'] ?? 0);
            $sameLinks = $sameLinks && (! isset($update['community_question_id']) || (int) $update['community_question_id'] === (int) ($entry['community_question_id'] ?? 0));
            if ($status === $entry['status'] && ($verdict['reason'] ?? null) === $entry['status_reason'] && $sameLinks) {
                return null;
            }
            if ($status === 'planned') {
                $update['content_item_id']       = null;
                $update['community_question_id'] = null;
            }
            $update['status']        = $status;
            $update['status_reason'] = isset($verdict['reason']) ? mb_substr((string) $verdict['reason'], 0, 2000) : null;
            if ($status === 'queued' && empty($entry['queued_at'])) {
                $update['queued_at'] = $now;
            }
            if ($status === 'produced' && empty($entry['produced_at'])) {
                $update['produced_at'] = $now;
            }
            if ($status === 'published') {
                $update['published_at'] = $verdict['published_at'] ?? $now;
            }
        }

        $update['updated_at'] = $now;
        $this->db->table('reach_content_base_entries')->where('id', $entry['id'])->update($update);

        return $status !== $entry['status'] ? $status : null;
    }

    /**
     * Blog / KB: the content item carrying the key, and its routine submission.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>|null
     */
    private function itemVerdict(array $entry): ?array
    {
        $key  = (string) ($entry['content_key'] ?: $entry['entry_key']);
        $item = $this->db->table('reach_content_items')
            ->select('id, workflow_status, published_at, created_by_service')
            ->where('content_type', $entry['content_type'])
            ->where('content_base_key', $key)
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();

        if ($item === null && $entry['content_type'] === 'blog') {
            $item = $this->db->table('reach_topic_candidates c')
                ->join('reach_content_items i', 'i.id = c.content_item_id')
                ->select('i.id, i.workflow_status, i.published_at, i.created_by_service')
                ->where('c.content_base_key', $key)
                ->where('i.deleted_at IS NULL', null, false)
                ->get()->getRowArray();
        }

        if ($item === null) {
            // Nothing carries the key (any more): the topic is open again.
            return $entry['status'] === 'planned' && empty($entry['content_item_id'])
                ? null
                : ['status' => 'planned', 'reason' => $entry['status'] === 'planned' ? $entry['status_reason'] : 'The content it was linked to no longer exists; open again.'];
        }

        $itemId     = (int) $item['id'];
        $workflow   = (string) $item['workflow_status'];
        $submission = $this->db->table('reach_routine_submissions')
            ->select('status, reasons_json, published_at')
            ->where('content_item_id', $itemId)
            ->get()->getRowArray();

        if (in_array($workflow, ['published', 'live'], true) || ($submission['status'] ?? null) === 'live') {
            return ['status' => 'published', 'content_item_id' => $itemId, 'reason' => null,
                'published_at' => $submission['published_at'] ?? $item['published_at'] ?? null];
        }

        if ($submission !== null) {
            $reasons = json_decode((string) ($submission['reasons_json'] ?? '[]'), true) ?: [];
            $reason  = implode('; ', array_slice(array_map('strval', $reasons), 0, 3));

            return match ((string) $submission['status']) {
                'rejected'        => ['status' => 'rejected', 'reason' => $reason],
                'pending_vetting' => ['status' => 'queued', 'content_item_id' => $itemId, 'reason' => 'Submitted; Reach is vetting it.'],
                'held_high_risk'  => ['status' => 'produced', 'content_item_id' => $itemId, 'reason' => 'Approved but held as high risk' . ($reason !== '' ? ': ' . $reason : '.')],
                'publish_failed', 'publish_blocked' => ['status' => 'produced', 'content_item_id' => $itemId, 'reason' => 'Approved; publishing is being retried (' . $submission['status'] . ').'],
                'awaiting_cover'  => ['status' => 'produced', 'content_item_id' => $itemId, 'reason' => 'Approved; waiting for a cover image.'],
                default           => ['status' => 'produced', 'content_item_id' => $itemId, 'reason' => 'Approved; publishing.'],
            };
        }

        if (in_array($workflow, ['rejected', 'archived', 'unpublished'], true)) {
            // A legacy item that was closed (e.g. released by RoadmapRelease):
            // the topic goes back to the routine queue.
            return ['status' => 'planned', 'reason' => 'The earlier in-Reach item was ' . $workflow . '; open for the routine.'];
        }

        // An item from Reach's own (retired) drafting pipeline, never submitted
        // by a routine. RoadmapRelease hands it back after a few idle days.
        return ['status' => 'queued', 'content_item_id' => $itemId,
            'reason' => "Held by Reach's retired in-house drafting at '{$workflow}'; released to the routine queue once idle for "
                . RoadmapRelease::IDLE_DAYS . ' days.'];
    }

    /**
     * Community: a routine FAQ under the key, or the open question
     * community:agents-run curated from the seed and the desk's answer to it.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>|null
     */
    private function communityVerdict(array $entry): ?array
    {
        $key = (string) ($entry['content_key'] ?: $entry['entry_key']);
        $sub = $this->db->table('reach_community_automation_submissions')
            ->select('status, reasons_json, question_id, published_at')
            ->where('external_key', $key)
            ->get()->getRowArray();

        $questionId = null;
        if ($sub === null) {
            $question = $this->db->table('reach_community_questions')
                ->select('id')
                ->where('external_question_id', $entry['entry_key'])
                ->get()->getRowArray();
            if ($question === null) {
                return $entry['status'] === 'planned' ? null : ['status' => 'planned', 'reason' => null];
            }
            $questionId = (int) $question['id'];
            $sub = $this->db->table('reach_community_automation_submissions')
                ->select('status, reasons_json, question_id, published_at')
                ->where('question_id', $questionId)
                ->whereNotIn('status', ['withdrawn', 'blocked', 'failed'])
                ->orderBy('id', 'DESC')->limit(1)
                ->get()->getRowArray();
            if ($sub === null) {
                return ['status' => 'queued', 'community_question_id' => $questionId,
                    'reason' => 'Curated as an open question; the community routine answers it from community-plan open_questions.'];
            }
        }

        $questionId ??= isset($sub['question_id']) ? (int) $sub['question_id'] : null;
        $reasons = json_decode((string) ($sub['reasons_json'] ?? '[]'), true) ?: [];

        return match ((string) $sub['status']) {
            'published' => ['status' => 'published', 'community_question_id' => $questionId, 'reason' => null, 'published_at' => $sub['published_at'] ?? null],
            'approved'  => ['status' => 'produced', 'community_question_id' => $questionId, 'reason' => 'Approved; publishing.'],
            'pending'   => ['status' => 'queued', 'community_question_id' => $questionId, 'reason' => 'Submitted; Reach is vetting it.'],
            default     => ['status' => 'rejected', 'reason' => implode('; ', array_slice(array_map('strval', $reasons), 0, 3)) ?: (string) $sub['status']],
        };
    }

    // ────────────────────────────────────────────────────────────── next

    /**
     * Planned entries of one type, in the order a routine should take them.
     * KB: launch apps first, only products with an enabled cadence row.
     *
     * @return list<array<string,mixed>>
     */
    public function planned(string $type, int $limit = 20): array
    {
        $rows = $this->db->table('reach_content_base_entries')
            ->where('content_type', $type)
            ->where('status', 'planned')
            ->orderBy('priority', 'DESC')
            ->orderBy('target_date IS NULL', 'ASC', false)
            ->orderBy('target_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        if ($type === 'knowledge_base') {
            $enabled = $this->enabledKbProducts();
            $rows    = array_values(array_filter($rows, static fn (array $r): bool => isset($enabled[(string) $r['product_slug']])));
            $launch  = LaunchApps::slugs();
            usort($rows, static function (array $a, array $b) use ($launch): int {
                $ia = array_search($a['product_slug'], $launch, true);
                $ib = array_search($b['product_slug'], $launch, true);

                return [$ia === false ? 99 : $ia, (int) $a['id']] <=> [$ib === false ? 99 : $ib, (int) $b['id']];
            });
        }

        return array_map(fn (array $r): array => $this->present($r), array_slice($rows, 0, max(1, $limit)));
    }

    /** @return array<string, true> */
    public function enabledKbProducts(): array
    {
        $out = [];
        try {
            foreach ($this->db->table('reach_kb_product_cadence')->select('product_slug')->where('enabled', true)->get()->getResultArray() as $r) {
                $out[(string) $r['product_slug']] = true;
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function present(array $row): array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];

        return [
            'kind'             => 'content_base',
            'key'              => (string) $row['entry_key'],
            'content_base_key' => (string) ($row['content_key'] ?: $row['entry_key']),
            'title'            => (string) $row['title'],
            'product_slug'     => $row['product_slug'],
            'category'         => $row['category'],
            'brief'            => $row['brief'],
            'target_date'      => $row['target_date'],
            'origin'           => $row['origin'],
            'status'           => $row['status'],
            'status_reason'    => $row['status_reason'],
            'attempts'         => (int) $row['attempts'],
            'details'          => $payload,
        ] + (($row['content_type'] ?? '') === 'blog'
            // For blogs `category` is the portfolio stream; `taxonomy` says
            // which blog category and app (if any) the topic is filed under.
            ? ['taxonomy' => BlogTaxonomy::queueHints($row['category'] ?? null, $row['product_slug'] ?? null, $payload)]
            : []);
    }

    // ─────────────────────────────────────────────────────────── propose

    /**
     * New topics from a routine (from product changes, the compliance
     * calendar, member questions…). Each needs evidence; duplicates of an
     * existing topic or published title are refused.
     *
     * @param list<array<string,mixed>> $items
     * @return array{results: list<array<string,mixed>>, accepted: int, remaining_today: int}
     * @throws \InvalidArgumentException on a malformed request
     */
    public function propose(string $type, array $items, ?string $runId): array
    {
        if ($items === [] || count($items) > 10) {
            throw new \InvalidArgumentException('items must hold 1–10 topics');
        }

        $limit     = max(0, (int) ReachConfig::get('automation.content_base.propose_daily_limit', 20));
        $usedToday = $this->db->table('reach_content_base_entries')
            ->where('content_type', $type)->where('origin', 'routine')
            ->where('created_at >=', \App\Libraries\Automation\Routine\RoutineSubmissions::istMidnight())
            ->countAllResults();
        $remaining = max(0, $limit - $usedToday);
        $kbEnabled = $type === 'knowledge_base' ? $this->enabledKbProducts() : [];
        $results   = [];
        $accepted  = 0;

        foreach ($items as $i => $item) {
            $item = is_array($item) ? $item : [];
            $key  = strtolower(trim((string) ($item['key'] ?? '')));
            $res  = ['index' => $i, 'key' => $key];

            $error = $this->validateProposal($type, $item, $key, $kbEnabled);
            if ($error !== null) {
                $results[] = $res + ['status' => 'invalid', 'reason' => $error];
                continue;
            }
            if ($this->find($type, $key) !== null) {
                $results[] = $res + ['status' => 'exists', 'reason' => 'A topic with this key already exists.'];
                continue;
            }
            $dup = $this->duplicateTitle($type, (string) $item['title']);
            if ($dup !== null) {
                $results[] = $res + ['status' => 'duplicate', 'reason' => $dup];
                continue;
            }
            if ($remaining <= 0) {
                $results[] = $res + ['status' => 'limit_reached', 'reason' => "Today's proposal limit for this type ({$limit}) is used."];
                continue;
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('reach_content_base_entries')->insert([
                'content_type'    => $type,
                'entry_key'       => $key,
                'title'           => mb_substr(trim((string) $item['title']), 0, 500),
                'product_slug'    => self::strOrNull($item['product_slug'] ?? null, 100),
                'category'        => self::strOrNull($item['category'] ?? $item['portfolio_stream'] ?? $item['article_type'] ?? null, 120),
                'brief'           => mb_substr(trim((string) $item['brief']), 0, 4000),
                'payload_json'    => json_encode(array_intersect_key($item, array_flip(self::PAYLOAD_FIELDS)), JSON_UNESCAPED_SLASHES),
                'target_date'     => self::dateOrNull($item['target_date'] ?? null),
                'origin'          => 'routine',
                'status'          => 'planned',
                'evidence_json'   => json_encode(array_values((array) ($item['evidence'] ?? [])), JSON_UNESCAPED_SLASHES),
                'proposed_by_run' => self::strOrNull($runId, 191),
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            if ($type === 'blog') {
                $this->offerToRoadmap($key, $item, $now);
            }

            $accepted++;
            $remaining--;
            $results[] = $res + ['status' => 'accepted'];
        }

        return ['results' => $results, 'accepted' => $accepted, 'remaining_today' => $remaining];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,true>  $kbEnabled
     */
    private function validateProposal(string $type, array $item, string $key, array $kbEnabled): ?string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return 'key: 3–120 chars of a-z, 0-9, ".", "_" or "-"';
        }
        $title = trim((string) ($item['title'] ?? ''));
        if (mb_strlen($title) < 10 || mb_strlen($title) > 300) {
            return 'title: 10–300 characters';
        }
        $brief = trim((string) ($item['brief'] ?? ''));
        if (mb_strlen($brief) < 30) {
            return 'brief: 30+ characters saying what the piece must cover and why it is timely';
        }
        $product = trim((string) ($item['product_slug'] ?? ''));
        if ($type === 'knowledge_base' && ! isset($kbEnabled[$product])) {
            return "product_slug: '{$product}' is not an enabled KB product";
        }
        if ($type === 'blog' && trim((string) ($item['topic_category'] ?? '')) !== '' && BlogTaxonomy::canonical((string) $item['topic_category']) === null) {
            return 'topic_category: one of ' . BlogTaxonomy::allowedList();
        }
        if ($type === 'community' && trim((string) ($item['category'] ?? '')) === '') {
            return 'category: the community category slug is required';
        }
        if (isset($item['target_date']) && $item['target_date'] !== '' && self::dateOrNull($item['target_date']) === null) {
            return 'target_date: YYYY-MM-DD';
        }

        // Product topics must point at what the routine read; compliance /
        // marketing topics may cite an official source instead.
        $evidence = array_values(array_filter((array) ($item['evidence'] ?? []), static fn ($e): bool => is_array($e)
            && (trim((string) ($e['repo'] ?? '')) !== '' || preg_match('#^https://#', (string) ($e['url'] ?? '')) === 1)));
        if ($evidence === []) {
            return 'evidence: at least one {repo, ref|commits, file, note} you read, or {url, note} for an official source';
        }
        if (($type === 'knowledge_base' || $product !== '') && array_filter($evidence, static fn ($e) => trim((string) ($e['repo'] ?? '')) !== '') === []) {
            return 'evidence: a product topic needs the product repository you read ({repo, ref|commits, file})';
        }

        return null;
    }

    private function duplicateTitle(string $type, string $title): ?string
    {
        $norm = AutomationDraftIngestService::normalizeTitle($title);
        foreach ($this->db->table('reach_content_base_entries')->select('entry_key, title')
            ->where('content_type', $type)->where('status <>', 'retired')->get()->getResultArray() as $r) {
            if (AutomationDraftIngestService::normalizeTitle((string) $r['title']) === $norm) {
                return 'Same title as topic ' . $r['entry_key'] . '.';
            }
        }

        if ($type === 'community') {
            $q = $this->db->table('reach_community_questions')->select('id, title')->orderBy('id', 'DESC')->limit(2000)->get()->getResultArray();
            foreach ($q as $r) {
                if (AutomationDraftIngestService::normalizeTitle((string) ($r['title'] ?? '')) === $norm) {
                    return 'A community question with this title already exists (#' . $r['id'] . ').';
                }
            }

            return null;
        }

        foreach ($this->db->table('reach_content_items')->select('id, title, slug')
            ->where('content_type', $type)->where('deleted_at IS NULL', null, false)
            ->whereNotIn('workflow_status', ['rejected', 'archived'])
            ->orderBy('id', 'DESC')->limit(2000)->get()->getResultArray() as $r) {
            if (AutomationDraftIngestService::normalizeTitle((string) $r['title']) === $norm) {
                return 'An article with this title already exists (' . $r['slug'] . ').';
            }
        }

        return null;
    }

    /**
     * A blog proposal also becomes a roadmap candidate, so the optimiser
     * scores it alongside the rest of the backlog.
     *
     * @param array<string,mixed> $item
     */
    private function offerToRoadmap(string $key, array $item, string $now): void
    {
        if ($this->db->table('reach_topic_candidates')->where('content_base_key', $key)->countAllResults() > 0) {
            return;
        }
        $title = trim((string) $item['title']);
        $this->db->table('reach_topic_candidates')->insert([
            'candidate_uuid'   => bin2hex(random_bytes(16)),
            'content_base_key' => $key,
            'title'            => mb_substr($title, 0, 300),
            'normalized_title' => mb_substr(mb_strtolower($title), 0, 300),
            'slug_hint'        => self::strOrNull($item['slug_hint'] ?? null, 300),
            'portfolio_stream' => self::strOrNull($item['portfolio_stream'] ?? null, 50),
            'funnel_stage'     => self::strOrNull($item['funnel_stage'] ?? null, 20),
            'search_intent'    => self::strOrNull($item['search_intent'] ?? null, 50),
            'seed_keyword'     => self::strOrNull($item['seed_keyword'] ?? null, 200),
            'source'           => 'routine_proposal',
            'status'           => 'candidate',
            'is_human_pinned'  => false,
            'is_locked'        => false,
            'evidence_ready'   => true,
            'notes'            => '[brief] ' . mb_substr(trim((string) $item['brief']), 0, 2000),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    // ────────────────────────────────────────────────────────── produced

    /**
     * A routine reports what it did with a topic it took from …/next:
     *   submitted       it sent the content (blog-drafts / kb-drafts /
     *                   community-qa) under content_base_key; Reach links it
     *                   and reports the status the pipeline gives it.
     *   not_applicable  the product does not do what the topic assumes (or the
     *                   topic is obsolete); the topic is retired with the reason.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws \InvalidArgumentException 422
     * @throws \DomainException          404 unknown key / 409 nothing submitted
     */
    public function report(string $type, array $input, ?string $runId): array
    {
        $key     = strtolower(trim((string) ($input['key'] ?? '')));
        $outcome = strtolower(trim((string) ($input['outcome'] ?? 'submitted')));
        if ($key === '') {
            throw new \InvalidArgumentException('key is required (the topic key from GET v1/automation/content-base/next)');
        }
        if (! in_array($outcome, ['submitted', 'not_applicable'], true)) {
            throw new \InvalidArgumentException('outcome must be "submitted" or "not_applicable"');
        }

        $entry = $this->find($type, $key)
            ?? $this->db->table('reach_content_base_entries')->where('content_type', $type)->where('content_key', $key)->get()->getRowArray();
        if ($entry === null) {
            return $this->reportRoadmapCandidate($type, $key, $outcome, $input);
        }

        $now = date('Y-m-d H:i:s');
        if ($outcome === 'not_applicable') {
            $reason = trim((string) ($input['reason'] ?? ''));
            if (mb_strlen($reason) < 10) {
                throw new \InvalidArgumentException('reason is required for not_applicable (what you checked and why the topic does not apply)');
            }
            if ($entry['status'] !== 'planned') {
                throw new \DomainException('Only a planned topic can be retired as not applicable; this one is ' . $entry['status'] . '.', 409);
            }
            $this->db->table('reach_content_base_entries')->where('id', $entry['id'])->update([
                'status' => 'retired', 'status_reason' => mb_substr('Routine: ' . $reason, 0, 2000),
                'retired_at' => $now, 'produced_by_run' => self::strOrNull($runId, 191), 'updated_at' => $now,
            ]);

            return ['key' => $entry['entry_key'], 'status' => 'retired'];
        }

        $contentKey = strtolower(trim((string) ($input['content_base_key'] ?? ($entry['content_key'] ?: $entry['entry_key']))));
        if (preg_match(self::KEY_PATTERN, $contentKey) !== 1) {
            throw new \InvalidArgumentException('content_base_key: 3–120 chars of a-z, 0-9, ".", "_" or "-"');
        }
        $this->db->table('reach_content_base_entries')->where('id', $entry['id'])->update([
            'content_key' => $contentKey, 'produced_by_run' => self::strOrNull($runId, 191), 'updated_at' => $now,
        ]);

        $fresh = $this->find($type, (string) $entry['entry_key']);
        $this->reconcileOne($fresh);
        $fresh = $this->find($type, (string) $entry['entry_key']);
        if ($fresh['status'] === 'planned') {
            throw new \DomainException("Nothing was submitted under content_base_key '{$contentKey}'. Submit the content first (blog-drafts / kb-drafts / community-qa), then report it.", 409);
        }

        return ['key' => $fresh['entry_key'], 'content_base_key' => $contentKey, 'status' => $fresh['status'], 'status_reason' => $fresh['status_reason']];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function reportRoadmapCandidate(string $type, string $key, string $outcome, array $input): array
    {
        $candidate = $type === 'blog'
            ? $this->db->table('reach_topic_candidates')->where('content_base_key', $key)->get()->getRowArray()
            : null;
        if ($candidate === null) {
            throw new \DomainException("No topic with key '{$key}'.", 404);
        }

        if ($outcome === 'not_applicable') {
            $reason = trim((string) ($input['reason'] ?? ''));
            if (mb_strlen($reason) < 10) {
                throw new \InvalidArgumentException('reason is required for not_applicable');
            }
            $this->db->table('reach_topic_candidates')->where('id', $candidate['id'])->update([
                'status'     => 'rejected',
                'notes'      => trim((string) ($candidate['notes'] ?? '') . "\n[routine] not applicable: " . mb_substr($reason, 0, 500)),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return ['key' => $key, 'status' => 'retired'];
        }

        if (empty($candidate['content_item_id'])) {
            throw new \DomainException("Nothing was submitted under content_base_key '{$key}'. Submit the article first, then report it.", 409);
        }
        $sub = $this->db->table('reach_routine_submissions')->select('status')->where('content_item_id', $candidate['content_item_id'])->get()->getRowArray();

        return ['key' => $key, 'content_base_key' => $key, 'status' => match ($sub['status'] ?? '') {
            'live' => 'published', 'pending_vetting' => 'queued', 'rejected' => 'planned', '' => 'queued', default => 'produced',
        }];
    }

    // ───────────────────────────────────────────────────────── read side

    /** @return array<string,mixed>|null */
    public function find(string $type, string $key): ?array
    {
        return $this->db->table('reach_content_base_entries')
            ->where('content_type', $type)->where('entry_key', strtolower($key))
            ->get()->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $type): array
    {
        return $this->db->table('reach_content_base_entries')
            ->where('content_type', $type)
            ->orderBy("CASE status WHEN 'planned' THEN 0 WHEN 'queued' THEN 1 WHEN 'produced' THEN 2 WHEN 'published' THEN 3 ELSE 4 END", '', false)
            ->orderBy('target_date IS NULL', 'ASC', false)
            ->orderBy('target_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,int> */
    public function counts(string $type): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->table('reach_content_base_entries')->select('status, COUNT(*) AS c')
            ->where('content_type', $type)->groupBy('status')->get()->getResultArray() as $r) {
            $counts[(string) $r['status']] = (int) $r['c'];
        }

        return ['total' => array_sum($counts)] + $counts;
    }

    /** @return array<string,mixed> last proposal and last status change per type */
    public function activity(string $type): array
    {
        $row = $this->db->query(
            "SELECT MAX(created_at) FILTER (WHERE origin = 'routine') AS last_proposal_at,
                    MAX(GREATEST(COALESCE(queued_at, 'epoch'), COALESCE(produced_at, 'epoch'), COALESCE(published_at, 'epoch'))) AS last_progress_at
             FROM reach_content_base_entries WHERE content_type = ?",
            [$type]
        )->getRowArray() ?: [];
        $progress = $row['last_progress_at'] ?? null;

        return [
            'last_proposal_at' => $row['last_proposal_at'] ?? null,
            'last_progress_at' => ($progress !== null && ! str_starts_with((string) $progress, '1970-01-01')) ? $progress : null,
        ];
    }

    private static function strOrNull(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private static function dateOrNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));

        return checkdate($m, $d, $y) ? $s : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads the deployed content-base files (repo-versioned, rsynced with the
 * API) and syncs the blog index into reach_topic_candidates so the existing
 * roadmap machinery consumes repo-defined topics. Edits happen ONLY in git —
 * this class never writes the files.
 */
class ContentBaseService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function basePath(): string
    {
        $configured = trim((string) env('CONTENT_BASE_PATH', ''));

        return $configured !== '' ? rtrim($configured, '/') : rtrim(ROOTPATH, '/') . '/content-base';
    }

    /** @return array<string,mixed> */
    public function blogIndex(): array
    {
        return $this->readJson($this->basePath() . '/blog/index.json');
    }

    /** @return array<string,mixed> */
    public function knowledgeBaseIndex(): array
    {
        return $this->readJson($this->basePath() . '/knowledge-base/index.json');
    }

    /** @return array<string,mixed> */
    public function communityQuestionSeeds(): array
    {
        return $this->readJson($this->basePath() . '/community/question-seeds.json');
    }

    public function baseMarkdown(): string
    {
        $path = $this->basePath() . '/blog/base.md';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Upsert blog index entries into reach_topic_candidates (pinned,
     * evidence-ready, source=content_base). Retired entries reject their
     * candidate unless it already produced a content item.
     *
     * @return array{entries_seen:int, created:int, updated:int, retired:int, checksum:string}
     */
    public function syncBlogIndex(): array
    {
        $path  = $this->basePath() . '/blog/index.json';
        $raw   = is_readable($path) ? (string) file_get_contents($path) : '';
        $index = $raw !== '' ? (json_decode($raw, true) ?: []) : [];

        $entries  = is_array($index['entries'] ?? null) ? $index['entries'] : [];
        $checksum = hash('sha256', $raw);
        $now      = date('Y-m-d H:i:s');
        $created  = 0;
        $updated  = 0;
        $retired  = 0;

        foreach ($entries as $entry) {
            $key   = trim((string) ($entry['key'] ?? ''));
            $title = trim((string) ($entry['title'] ?? ''));
            if ($key === '' || $title === '') {
                continue;
            }

            $existing = $this->db->table('reach_topic_candidates')
                ->where('content_base_key', $key)
                ->get()->getRowArray();

            $status = strtolower((string) ($entry['status'] ?? 'planned'));
            if ($status === 'retired') {
                if ($existing && empty($existing['content_item_id']) && $existing['status'] !== 'rejected') {
                    $this->db->table('reach_topic_candidates')->where('id', $existing['id'])->update([
                        'status'     => 'rejected',
                        'notes'      => trim((string) ($existing['notes'] ?? '')) . "\n[content-base] retired in index.json",
                        'updated_at' => $now,
                    ]);
                    $retired++;
                }
                continue;
            }

            $row = [
                'title'            => mb_substr($title, 0, 300),
                'normalized_title' => mb_substr(mb_strtolower($title), 0, 300),
                'slug_hint'        => mb_substr((string) ($entry['slug_hint'] ?? ''), 0, 300) ?: null,
                'portfolio_stream' => $entry['portfolio_stream'] ?? null,
                'funnel_stage'     => $entry['funnel_stage'] ?? null,
                'audience'         => $entry['audience'] ?? null,
                'search_intent'    => $entry['search_intent'] ?? null,
                'seed_keyword'     => mb_substr((string) ($entry['seed_keyword'] ?? ''), 0, 200) ?: null,
                'source'           => 'content_base',
                'is_human_pinned'  => true,
                'evidence_ready'   => true,
                'notes'            => $this->buildNotes($entry),
                'updated_at'       => $now,
            ];

            if ($existing) {
                // Never reopen a candidate that already advanced into content.
                if (empty($existing['content_item_id'])) {
                    $this->db->table('reach_topic_candidates')->where('id', $existing['id'])->update($row);
                    $updated++;
                }
                continue;
            }

            $this->db->table('reach_topic_candidates')->insert($row + [
                'candidate_uuid'   => bin2hex(random_bytes(16)),
                'content_base_key' => $key,
                'status'           => 'candidate',
                'created_at'       => $now,
            ]);
            $created++;
        }

        $this->db->table('reach_content_base_sync_runs')->insert([
            'file_key'      => 'blog_index',
            'file_checksum' => $checksum,
            'entries_seen'  => count($entries),
            'created_count' => $created,
            'updated_count' => $updated,
            'retired_count' => $retired,
            'ran_at'        => $now,
        ]);

        return [
            'entries_seen' => count($entries),
            'created'      => $created,
            'updated'      => $updated,
            'retired'      => $retired,
            'checksum'     => $checksum,
        ];
    }

    /** @return array<string,mixed>|null */
    public function lastSyncRun(string $fileKey = 'blog_index'): ?array
    {
        try {
            return $this->db->table('reach_content_base_sync_runs')
                ->where('file_key', $fileKey)
                ->orderBy('id', 'DESC')->limit(1)
                ->get()->getRowArray() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function buildNotes(array $entry): string
    {
        $parts = [];
        if (! empty($entry['brief_prompt'])) {
            $parts[] = '[brief] ' . trim((string) $entry['brief_prompt']);
        }
        if (! empty($entry['cover_prompt'])) {
            $parts[] = '[cover] ' . trim((string) $entry['cover_prompt']);
        }
        if (! empty($entry['product_slug'])) {
            $parts[] = '[product] ' . trim((string) $entry['product_slug']);
        }
        if (! empty($entry['target_date'])) {
            $parts[] = '[target] ' . trim((string) $entry['target_date']);
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use App\Libraries\AuditLogger;
use App\Libraries\Blog\BlogContentApprovalService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One-off data fix for the brand casing decision (23 Oct 2026): rewrites the
 * text Reach stores — blog and KB drafts and published versions, release
 * notes, workflow guides, community answers, unpublished social copy,
 * content-base entries, video scripts/titles, SEO/meta fields — through
 * BrandCasing. Reach is the source of that content; aicountly.com fixes its
 * own copy in parallel.
 *
 * Never sends anything: no publication, no job, no social call. Slugs, URLs
 * and identifier columns are not in the column list. Social posts already
 * posted (or being posted) are skipped — the networks keep what they have.
 *
 * Approval checksums are carried over for a casing-only change, so a fixed
 * version stays approved: the active reach_blog_content_approvals rows of a
 * blog/KB version, and a community answer's version checksum,
 * approved_version_checksum and approval rows. Deployment rows (what was
 * actually sent) stay as history.
 *
 * Dry run by default; apply() runs one transaction per table. Idempotent:
 * a second apply changes nothing.
 */
final class BrandCasingFixService
{
    /**
     * table => [columns => [column => text|html|markdown|json], where => raw SQL filter|null]
     *
     * @var array<string, array{columns: array<string, string>, where?: string}>
     */
    public const TARGETS = [
        'reach_content_items'                  => ['columns' => ['title' => 'text', 'summary' => 'text']],
        'reach_content_versions'               => ['columns' => ['title' => 'text', 'summary' => 'text', 'body_html' => 'html', 'body_markdown' => 'markdown', 'body_plain_text' => 'text', 'structured_payload' => 'json']],
        'reach_content_seo_profiles'           => ['columns' => ['meta_title' => 'text', 'meta_description' => 'text', 'primary_keyword' => 'text', 'secondary_keywords_json' => 'json']],
        'reach_content_aeo_profiles'           => ['columns' => ['answer_summary' => 'text', 'concise_answer' => 'text', 'questions_answered_json' => 'json', 'faq_candidates_json' => 'json', 'definition_blocks_json' => 'json', 'step_blocks_json' => 'json']],
        'reach_content_structured_data'        => ['columns' => ['schema_json' => 'json']],
        'reach_content_blog_details'           => ['columns' => ['seo_title' => 'text', 'meta_description' => 'text', 'schema_markup' => 'json']],
        'reach_blog_posts'                     => ['columns' => ['title' => 'text', 'excerpt' => 'text', 'content' => 'html', 'seo_title' => 'text', 'seo_description' => 'text', 'focus_keyword' => 'text', 'author' => 'text']],
        'reach_blog_publication_profiles'      => ['columns' => ['excerpt' => 'text', 'featured_image_alt' => 'text', 'author_reference' => 'text', 'cta_configuration_json' => 'json']],
        'reach_kb_publication_profiles'        => ['columns' => ['prerequisites_json' => 'json', 'steps_json' => 'json', 'troubleshooting_json' => 'json', 'support_escalation_json' => 'json']],
        'reach_structured_content_submissions' => ['columns' => ['title' => 'text', 'payload_json' => 'json', 'final_json' => 'json']],
        'reach_community_answer_versions'      => ['columns' => ['content' => 'html', 'excerpt' => 'text']],
        'reach_community_official_answers'     => ['columns' => ['public_correction_note' => 'text']],
        'reach_content_base_entries'           => ['columns' => ['title' => 'text', 'brief' => 'text', 'payload_json' => 'json']],
        // Not yet on a network only: posted / publishing / archived rows and
        // anything with a remote id are left as the network shows them.
        'reach_social_posts'                   => ['columns' => ['content' => 'text', 'title' => 'text'], 'where' => "status NOT IN ('posted', 'publishing', 'archived') AND remote_post_id IS NULL AND external_post_id IS NULL"],
        'reach_automation_social_submissions'  => ['columns' => ['submitted_json' => 'json', 'final_json' => 'json'], 'where' => "status = 'pending_vetting'"],
        'reach_video_pipeline_items'           => ['columns' => ['title' => 'text', 'script_json' => 'json']],
        'reach_video_publication_profiles'     => ['columns' => ['title' => 'text', 'description' => 'text', 'tags' => 'json']],
        'reach_video_script_versions'          => ['columns' => ['content_json' => 'json']],
    ];

    private const CHUNK = 500;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<string>|null $only tables to limit the run to
     * @return array<string, array{rows_scanned:int, rows_changed:int, fields_changed:int, checksums_carried:int, skipped:?string, samples:list<array<string,mixed>>}>
     */
    public function run(bool $apply, ?array $only = null): array
    {
        $report = [];
        foreach (self::TARGETS as $table => $target) {
            if ($only !== null && ! in_array($table, $only, true)) {
                continue;
            }
            $report[$table] = $this->table($table, $target, $apply);
        }

        return $report;
    }

    /**
     * @param array{columns: array<string, string>, where?: string} $target
     * @return array{rows_scanned:int, rows_changed:int, fields_changed:int, checksums_carried:int, skipped:?string, samples:list<array<string,mixed>>}
     */
    private function table(string $table, array $target, bool $apply): array
    {
        $out = ['rows_scanned' => 0, 'rows_changed' => 0, 'fields_changed' => 0, 'checksums_carried' => 0, 'skipped' => null, 'samples' => []];
        if (! $this->db->tableExists($table, false)) {
            $out['skipped'] = 'table not present';

            return $out;
        }
        $fields  = $this->db->getFieldNames($table);
        $columns = array_filter($target['columns'], static fn (string $c): bool => in_array($c, $fields, true), ARRAY_FILTER_USE_KEY);
        if ($columns === [] || ! in_array('id', $fields, true)) {
            $out['skipped'] = 'columns not present';

            return $out;
        }

        $filter = '(' . implode(' OR ', array_map(static fn (string $c): string => "CAST({$c} AS TEXT) ILIKE '%aicountly%'", array_keys($columns))) . ')';
        if (! empty($target['where'])) {
            $filter .= ' AND (' . $target['where'] . ')';
        }

        if ($apply) {
            $this->db->transBegin();
        }
        try {
            $lastId = 0;
            while (true) {
                $rows = $this->db->query(
                    'SELECT * FROM ' . $table . ' WHERE ' . $filter . ' AND id > ? ORDER BY id LIMIT ' . self::CHUNK,
                    [$lastId]
                )->getResultArray();
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $out['rows_scanned']++;
                    $update = [];
                    foreach ($columns as $col => $kind) {
                        $old = $row[$col];
                        if (! is_string($old) || $old === '') {
                            continue;
                        }
                        $new = self::fix($kind, $old);
                        if ($new !== $old) {
                            $update[$col] = $new;
                        }
                    }
                    if ($update === []) {
                        continue;
                    }
                    $out['rows_changed']++;
                    $out['fields_changed'] += count($update);
                    if (count($out['samples']) < 3) {
                        $col = (string) array_key_first($update);
                        $out['samples'][] = ['id' => (int) $row['id'], 'column' => $col, 'before' => self::snippet((string) $row[$col]), 'after' => self::snippet((string) $update[$col])];
                    }
                    if ($apply) {
                        $out['checksums_carried'] += $this->write($table, $row, $update);
                    }
                }
            }
            if ($apply) {
                $this->db->transCommit();
                if ($out['rows_changed'] > 0) {
                    AuditLogger::record('content.brand_casing_fixed', [
                        'table'             => $table,
                        'rows_changed'      => $out['rows_changed'],
                        'fields_changed'    => $out['fields_changed'],
                        'checksums_carried' => $out['checksums_carried'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            if ($apply) {
                $this->db->transRollback();
            }
            throw $e;
        }

        return $out;
    }

    public static function fix(string $kind, string $value): string
    {
        return match ($kind) {
            'html'     => BrandCasing::html($value),
            'markdown' => BrandCasing::markdown($value),
            'json'     => (string) BrandCasing::jsonString($value),
            default    => BrandCasing::text($value),
        };
    }

    /**
     * Writes one row and carries its approval checksums over.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $update
     */
    private function write(string $table, array $row, array $update): int
    {
        $id = (int) $row['id'];

        if ($table === 'reach_content_versions') {
            $approvals = new BlogContentApprovalService();
            $before    = $approvals->checksumForVersion($id);
            $this->db->table($table)->where('id', $id)->update($update);
            $after = $approvals->checksumForVersion($id);
            if ($before === $after || ! $this->db->tableExists('reach_blog_content_approvals', false)) {
                return 0;
            }
            $this->db->table('reach_blog_content_approvals')
                ->where('content_version_id', $id)
                ->where('version_checksum', $before)
                ->where('revoked_at IS NULL', null, false)
                ->update(['version_checksum' => $after, 'updated_at' => date('Y-m-d H:i:s')]);

            return $this->db->affectedRows();
        }

        if ($table === 'reach_community_answer_versions' && isset($update['content'])) {
            $before = (string) ($row['checksum'] ?? '');
            $after  = hash('sha256', mb_convert_encoding($update['content'], 'UTF-8', 'UTF-8'));
            $this->db->table($table)->where('id', $id)->update($update + ['checksum' => $after]);
            if ($before === '' || $before === $after) {
                return 0;
            }
            $carried = 0;
            $this->db->table('reach_community_official_answers')
                ->where('id', (int) $row['answer_id'])
                ->where('approved_version', (int) $row['version_number'])
                ->where('approved_version_checksum', $before)
                ->update(['approved_version_checksum' => $after]);
            $carried += $this->db->affectedRows();
            if ($this->db->tableExists('reach_community_answer_approvals', false)) {
                $this->db->table('reach_community_answer_approvals')
                    ->where('answer_id', (int) $row['answer_id'])
                    ->where('answer_version_number', (int) $row['version_number'])
                    ->where('version_checksum', $before)
                    ->update(['version_checksum' => $after]);
                $carried += $this->db->affectedRows();
            }

            return $carried;
        }

        $this->db->table($table)->where('id', $id)->update($update);

        return 0;
    }

    private static function snippet(string $s): string
    {
        $s   = trim((string) preg_replace('/\s+/u', ' ', strip_tags($s)));
        $pos = stripos($s, 'aicountly');
        $pos = $pos === false ? 0 : max(0, $pos - 40);

        return mb_substr($s, $pos, 120);
    }
}

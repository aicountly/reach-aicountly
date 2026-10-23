<?php

declare(strict_types=1);

namespace App\Libraries\Content\Quality;

use App\Libraries\AuditLogger;
use App\Libraries\Automation\Routine\KbSteps;
use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Content\ContentQualityHeuristics;
use App\Libraries\ContentVersionService;
use App\Libraries\HtmlSanitizer;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Puts the vetting model's approved correction of a LIVE blog / KB article
 * on aicountly.com, at the same URL (reach:content-revet and the daily
 * quality sweep, through ContentRevetService).
 *
 * Input is RoutineVetting::run()'s verdict for the item's current version.
 * A `revised_approved` verdict carries in `final` the complete draft Gemini
 * corrected and then approved on the second pass, in the shape
 * VettableContent::forItem() sent it: title, summary, body_html, meta_title,
 * meta_description (+ slug for a blog; prerequisites, steps, troubleshooting
 * for a KB article). ContentVettingService::mergeRevision() has already
 * dropped empty or re-shaped fields, so `final` falls back to the live value
 * field by field.
 *
 * When that text differs from what is live it is applied:
 *
 *   - a new current content version (ContentVersionService, the history keeps
 *     the uncorrected one), SEO meta and KB step profile updated;
 *   - approved under the SystemApprover with the approving vetting run bound to
 *     the approval (the same record RoutineContentPipeline writes), so the
 *     publish gate (BlogPublishVetGate) passes without another Gemini call;
 *   - republished through PublicationDeploymentService::enqueuePublication(),
 *     like BlogRecategoriseService::republish(): the publishing worker updates
 *     the SAME public record, the workflow state stays live, category and
 *     product_slug are untouched.
 *
 * URL safety: the slug is never changed (Gemini's `slug` is ignored). Gemini's
 * title is taken only when it is still the same topic — it keeps at least
 * half of the live title's significant words (or the live title is a
 * placeholder) and is reflected in the corrected body; otherwise the live
 * title stays and the body/meta corrections are applied under it. A body that
 * shrank to under half of the live text is treated as truncated and not
 * applied. Nothing here takes a page down.
 *
 * Not applied (the caller keeps flagged_for_owner): no changed text, a
 * revise that stayed unresolved, release notes / workflow guides, a
 * HIGH_RISK verdict (RoutineVetting's hold rule), no public record to
 * update, auto-publish switched off. An error while applying rolls the
 * whole change back and reports revision_failed; the live page is unchanged.
 */
final class LiveRevisionApplier
{
    public const TYPES   = ['blog', 'knowledge_base'];
    public const SERVICE = 'reach:content-revet:revision';

    public const APPLIED   = 'revision_applied+republish_queued';
    public const FAILED    = 'revision_failed';
    public const NOT_TAKEN = 'flagged_for_owner';

    private const TEXT_FIELDS = ['title', 'summary', 'body_html', 'meta_title', 'meta_description'];
    private const KB_FIELDS   = ['prerequisites', 'steps', 'troubleshooting'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly ?PublicationDeploymentService $deployments = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * What the verdict would change, or why it cannot be applied.
     *
     * @param array{id: int, type: string, slug: string, deployment_id: ?int} $c
     * @param array<string, mixed> $content VettableContent::forItem()
     * @param array<string, mixed> $verdict RoutineVetting::run()
     * @return array{applicable: bool, note: string, fields: list<string>, values: array<string, mixed>, title_kept: bool}
     */
    public function plan(array $c, array $content, array $verdict): array
    {
        try {
            return $this->buildPlan($c, $content, $verdict);
        } catch (\Throwable $e) {
            return ['applicable' => false, 'note' => 'Revision not applied: the revised draft could not be read (' . mb_substr($e->getMessage(), 0, 200) . ').',
                    'fields' => [], 'values' => [], 'title_kept' => false];
        }
    }

    /** @return array{applicable: bool, note: string, fields: list<string>, values: array<string, mixed>, title_kept: bool} */
    private function buildPlan(array $c, array $content, array $verdict): array
    {
        $no = static fn (string $why): array => ['applicable' => false, 'note' => 'Revision not applied: ' . $why, 'fields' => [], 'values' => [], 'title_kept' => false];

        if (($verdict['decision'] ?? '') !== 'revised_approved' || empty($verdict['approved'])) {
            return $no('the vetting model did not approve a corrected version.');
        }
        if (! in_array($c['type'], self::TYPES, true)) {
            return $no('corrections are applied automatically to blogs and KB articles only.');
        }
        if (($verdict['high_risk'] ?? null) !== null) {
            return $no('the vetting model marked the corrected text high risk (' . $verdict['high_risk'] . '), which is never published automatically.');
        }
        if (($c['deployment_id'] ?? null) === null) {
            return $no('live by workflow only; there is no public record to update.');
        }
        $live  = (array) ($content['draft'] ?? []);
        $final = (array) ($verdict['final'] ?? []);
        if ($final === [] || $live === []) {
            return $no('the verdict carries no revised text.');
        }

        $values = [];
        $fields = [];
        foreach (self::TEXT_FIELDS as $f) {
            $was = trim((string) ($live[$f] ?? ''));
            $now = trim((string) ($final[$f] ?? ''));
            if ($now === '' || $now === $was) {
                continue;
            }
            if ($f === 'body_html') {
                $now = (new HtmlSanitizer())->purify($now);
                if ($now === $was || trim($now) === '') {
                    continue;
                }
            }
            $values[$f] = $now;
            $fields[]   = $f;
        }
        if ($c['type'] === 'knowledge_base') {
            foreach (self::KB_FIELDS as $f) {
                if (is_array($final[$f] ?? null) && $final[$f] !== [] && json_encode($final[$f]) !== json_encode($live[$f] ?? [])) {
                    $values[$f] = $final[$f];
                    $fields[]   = $f;
                }
            }
        }

        $body = (string) ($values['body_html'] ?? $live['body_html'] ?? '');
        if (isset($values['body_html'])) {
            $before = self::words((string) ($live['body_html'] ?? ''));
            $after  = self::words($values['body_html']);
            if ($before > 0 && $after * 2 < $before) {
                return $no("the corrected body has {$after} words against {$before} live; it looks truncated.");
            }
        }

        $titleKept = false;
        if (isset($values['title'])) {
            if (! self::sameTopic((string) ($live['title'] ?? ''), $values['title'], $body)) {
                unset($values['title']);
                $fields    = array_values(array_diff($fields, ['title']));
                $titleKept = true;
            } else {
                $values['title'] = mb_substr($values['title'], 0, 300);
            }
        }
        foreach (['meta_title' => 120, 'meta_description' => 320] as $f => $max) {
            if (isset($values[$f])) {
                if (self::placeholder($values[$f])) {
                    unset($values[$f]);
                    $fields = array_values(array_diff($fields, [$f]));
                } else {
                    $values[$f] = mb_substr($values[$f], 0, $max);
                }
            }
        }

        if ($fields === []) {
            return $no($titleKept
                ? 'the only change was a new title that drifts from the topic; the live title is kept.'
                : 'the vetting model asked for a revision but returned no changed text.');
        }

        $note = 'Corrected ' . implode(', ', $fields) . ' approved by the vetting model'
            . ($titleKept ? '; its new title drifted from the topic, so the live title is kept' : '') . '.';

        return ['applicable' => true, 'note' => $note, 'fields' => $fields, 'values' => $values, 'title_kept' => $titleKept];
    }

    /**
     * Apply the verdict's correction to the live item and queue its republish.
     *
     * @param array{id: int, type: string, slug: string, deployment_id: ?int} $c
     * @param array<string, mixed> $content
     * @param array<string, mixed> $verdict
     * @return array{action: string, note: string, version_id: ?int, deployment_id: ?int}
     */
    public function apply(array $c, array $content, array $verdict): array
    {
        $plan = $this->plan($c, $content, $verdict);
        if (! $plan['applicable']) {
            return ['action' => self::NOT_TAKEN, 'note' => $plan['note'], 'version_id' => null, 'deployment_id' => null];
        }
        try {
            $flag = RoutineFlags::explain($c['type'] . '.auto_publish');
        } catch (\Throwable $e) {
            return $this->failed($c, $verdict, 'the auto-publish switch could not be read: ' . mb_substr($e->getMessage(), 0, 200));
        }
        if (! $flag['enabled']) {
            return $this->failed($c, $verdict, 'auto-publish is off (' . ($flag['kill_switch'] ?? 'Settings → AI & Automation') . '), so the corrected text was not republished.');
        }

        $itemId = $c['id'];
        $now    = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $item = $this->db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray();
            $prev = $this->db->table('reach_content_versions')->where('id', (int) ($content['version_id'] ?? 0))->get()->getRowArray();
            if (! $item || ! $prev) {
                throw new \RuntimeException('the item or its current version is missing');
            }
            $v      = $plan['values'];
            $title  = (string) ($v['title'] ?? $prev['title'] ?? $item['title']);
            $body   = (string) ($v['body_html'] ?? $prev['body_html'] ?? '');
            $bodyChanged = isset($v['body_html']);

            $version = (new ContentVersionService())->createVersion($itemId, [
                'title'              => $title,
                'summary'            => (string) ($v['summary'] ?? $prev['summary'] ?? ''),
                'body_html'          => $body,
                // The markdown no longer matches a body the vetter corrected.
                'body_markdown'      => $bodyChanged ? null : ($prev['body_markdown'] ?? null),
                'body_plain_text'    => $bodyChanged ? trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : ($prev['body_plain_text'] ?? null),
                'structured_payload' => $prev['structured_payload'] ?? null,
            ], ['type' => 'bot', 'service' => self::SERVICE], mb_substr(sprintf(
                'Live correction of v%d by the vetting model %s (run #%s, revised_approved): %s Reasons: %s',
                (int) $prev['version_number'], (string) ($verdict['model'] ?? ''), $verdict['run_id'] ?? 'n/a',
                $plan['note'], implode(' ', array_slice((array) ($verdict['reasons'] ?? []), 0, 3)),
            ), 0, 900));
            $versionId = (int) $version['id'];

            if (isset($v['title'])) {
                // The slug is deliberately left alone: the post stays at its URL.
                $this->db->table('reach_content_items')->where('id', $itemId)->update(['title' => $title, 'updated_at' => $now]);
            }
            $seo = array_intersect_key($v, array_flip(['meta_title', 'meta_description']));
            if ($seo !== []) {
                $this->db->table('reach_content_seo_profiles')->where('content_item_id', $itemId)->update($seo + ['updated_at' => $now]);
            }
            if ($c['type'] === 'knowledge_base') {
                $kb = [];
                foreach (['prerequisites', 'troubleshooting'] as $f) {
                    if (isset($v[$f])) {
                        $kb[$f . '_json'] = json_encode(array_values($v[$f]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
                if (isset($v['steps'])) {
                    $kb['steps_json'] = json_encode(KbSteps::normalize($v['steps']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($kb !== []) {
                    $this->db->table('reach_kb_publication_profiles')->where('content_item_id', $itemId)->update($kb + ['updated_at' => $now]);
                }
            }

            $this->approve($c, $versionId, $verdict, $now);

            $deploymentId = ($this->deployments ?? new PublicationDeploymentService())
                ->enqueuePublication($itemId, $versionId, 'aicountly_com', 'publish');

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            return $this->failed($c, $verdict, mb_substr($e->getMessage(), 0, 400));
        }

        AuditLogger::record('content.quality_revision_applied', [
            'content_item_id' => $itemId, 'content_type' => $c['type'], 'slug' => $c['slug'],
            'replaced_version_id' => (int) $content['version_id'], 'new_version_id' => $versionId, 'deployment_id' => $deploymentId,
            'fields' => $plan['fields'], 'title_kept' => $plan['title_kept'], 'vetting_run_id' => $verdict['run_id'] ?? null,
        ]);

        return ['action' => self::APPLIED, 'note' => $plan['note'] . " New version #{$versionId}; republish queued as deployment #{$deploymentId}.",
                'version_id' => $versionId, 'deployment_id' => $deploymentId];
    }

    /** Approval of record for the corrected version, under the system bot. Workflow state is not touched: the item stays live. */
    private function approve(array $c, int $versionId, array $verdict, string $now): void
    {
        $actorId = SystemApprover::actorId();
        $reason  = sprintf('Live correction approved by the vetting model %s (run #%s, revised_approved). Recorded under the Reach System Bot; no person reviewed it.',
            (string) ($verdict['model'] ?? ''), $verdict['run_id'] ?? 'n/a');

        if ($c['type'] === 'blog') {
            $approval = (new BlogContentApprovalService())->approve($c['id'], $versionId, $actorId, 'standard', $reason);
            if (($verdict['run_id'] ?? null) !== null) {
                $this->db->table('reach_blog_content_approvals')->where('id', $approval['approval_id'])->update(['vetting_run_id' => (int) $verdict['run_id']]);
            }

            return;
        }
        $this->db->table('reach_content_items')->where('id', $c['id'])->update([
            'approval_status' => 'approved', 'approved_by' => $actorId, 'approved_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return array{action: string, note: string, version_id: null, deployment_id: null} */
    private function failed(array $c, array $verdict, string $why): array
    {
        $note = 'Revision failed: ' . $why . ' The live page is unchanged.';
        log_message('error', "[ContentRevet] applying the vetted revision of {$c['type']} #{$c['id']} failed: {$why}");
        AuditLogger::record('content.quality_revision_failed', [
            'content_item_id' => $c['id'], 'content_type' => $c['type'], 'slug' => $c['slug'],
            'reason' => $why, 'vetting_run_id' => $verdict['run_id'] ?? null,
        ]);

        return ['action' => self::FAILED, 'note' => $note, 'version_id' => null, 'deployment_id' => null];
    }

    /**
     * The new title is still the article's topic: it keeps at least half of
     * the live title's significant words (any title replaces a placeholder),
     * and the corrected body reflects it as the heuristics require.
     */
    public static function sameTopic(string $live, string $revised, string $bodyHtml): bool
    {
        $len = mb_strlen($revised);
        if ($len < 10 || $len > 300 || self::placeholder($revised)) {
            return false;
        }
        if (! self::placeholder($live)) {
            $kept = ContentQualityHeuristics::titleCoverage($live, $revised) ?? ContentQualityHeuristics::titleCoverage($revised, $live);
            if ($kept === null || $kept < 0.5) {
                return false;
            }
        }
        $body = html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $inBody = ContentQualityHeuristics::titleCoverage($revised, $body);

        return $inBody === null || $inBody >= 0.34;
    }

    private static function placeholder(string $text): bool
    {
        foreach (ContentQualityHeuristics::assess('', $text, '')['flags'] as $flag) {
            if (str_starts_with($flag, 'meta_text:')) {
                return true;
            }
        }

        return false;
    }

    private static function words(string $html): int
    {
        return str_word_count(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

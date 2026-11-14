<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard;

use App\Libraries\AuditLogger;
use App\Libraries\Bot\BotActionException;
use App\Libraries\Bot\BotCommandService;
use App\Libraries\Content\Base\ContentBaseEntries;
use App\Libraries\Content\Quality\RegenerationQueue;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * "Let the bot fix it" for the Command centre's "What to do next" cards —
 * pressed by a person, or done by the bot itself in AUTO mode (autoFix(),
 * hourly, within the Settings → Bot fixes limits).
 *
 * Reach does not write content: each fix hands the work to the routine that
 * writes it, and Gemini vets the result like any other article.
 *
 *   search_topic  "Push <query> onto page one" → a content-base topic for a
 *                 focused article, first in the blog routine's queue.
 *   retitle       "Retitle a page people see but do not click" → a same-URL
 *                 rewrite by the blog / help routine (reach_content_regenerations)
 *                 aimed at the title and description, with the queries the page
 *                 shows for. Only for pages Reach published.
 *   insight       an AI Insights recommendation → one reach-command run, which
 *                 does what the automation API allows and reports back.
 *
 * The card to fix is looked up again on the server from live data, so the
 * request carries only its id. Every fix is recorded in
 * reach_recommendation_fixes; a card is not fixed twice within its cooldown
 * (Search Console reports 28 days at a time, so a rewritten title needs weeks
 * to show).
 */
final class RecommendationFixer
{
    public const COOLDOWN_DAYS = ['search_topic' => 45, 'retitle' => 45, 'insight' => 3650];

    public const LABELS = [
        'search_topic' => 'Let the bot write a focused article',
        'retitle'      => 'Let the bot rewrite the title and description',
        'insight'      => 'Let the bot act on it',
    ];

    private BaseConnection $db;
    private \DateTimeImmutable $now;

    public function __construct(?BaseConnection $db = null, private ?BotCommandService $commands = null, ?\DateTimeImmutable $now = null)
    {
        $this->db  = $db ?? Database::connect();
        $this->now = $now ?? new \DateTimeImmutable('now');
    }

    public static function available(BaseConnection $db): bool
    {
        return SchemaGuard::hasTable($db, 'reach_recommendation_fixes');
    }

    /**
     * Fix one card now.
     *
     * @param array{id?: int|string|null, name?: string|null, email?: string|null} $user
     * @return array{status: string, message: string, http: int, ref_type?: ?string, ref_id?: ?string, fix?: array<string,mixed>}
     */
    public function fix(string $cardId, array $user, string $actor = 'operator', ?array $card = null): array
    {
        $card ??= $this->findCard($cardId);
        if ($card === null) {
            return ['status' => 'refused', 'http' => 404, 'message' => 'This recommendation is no longer current — refresh the Command centre.'];
        }
        $fix = $card['fix'] ?? null;
        if (! is_array($fix) || ! isset(self::COOLDOWN_DAYS[$fix['kind'] ?? ''])) {
            return ['status' => 'refused', 'http' => 422, 'message' => 'The bot has no fix for this recommendation; use Open.'];
        }
        $recent = $this->recentFix($cardId, (string) $fix['kind']);
        if ($recent !== null) {
            return ['status' => 'already', 'http' => 200, 'message' => 'Already done on ' . substr((string) $recent['created_at'], 0, 16) . ': ' . $recent['message'],
                'ref_type' => $recent['ref_type'], 'ref_id' => $recent['ref_id']];
        }

        $params = (array) ($fix['params'] ?? []);
        try {
            $result = match ($fix['kind']) {
                'search_topic' => $this->searchTopic($params),
                'retitle'      => $this->retitle($params),
                'insight'      => $this->insight($params, $user, $actor),
            };
        } catch (\Throwable $e) {
            $result = ['status' => 'failed', 'message' => 'The fix could not be queued: ' . mb_substr($e->getMessage(), 0, 400)];
        }

        $userId = isset($user['id']) && $user['id'] !== null ? (int) $user['id'] : null;
        $this->db->table('reach_recommendation_fixes')->insert([
            'card_id'      => mb_substr($cardId, 0, 80),
            'kind'         => $fix['kind'],
            'actor'        => $actor === 'bot' ? 'bot' : 'operator',
            'requested_by' => $userId,
            'status'       => $result['status'] === 'already' ? 'queued' : $result['status'],
            'message'      => mb_substr($result['message'], 0, 2000),
            'ref_type'     => $result['ref_type'] ?? null,
            'ref_id'       => isset($result['ref_id']) ? (string) $result['ref_id'] : null,
            'params_json'  => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at'   => $this->now->format('Y-m-d H:i:sP'),
        ]);
        AuditLogger::record('command_centre.recommendation_fix', [
            'card_id' => $cardId, 'kind' => $fix['kind'], 'actor' => $actor, 'status' => $result['status'],
            'ref_type' => $result['ref_type'] ?? null, 'ref_id' => $result['ref_id'] ?? null,
        ], $userId);

        return $result + ['http' => match ($result['status']) { 'queued', 'already' => 200, 'refused' => 409, default => 502 }];
    }

    /**
     * The bot's own pass in AUTO mode: fix the cards it may, within the daily
     * / weekly limits, most valuable first (the cards are already ranked).
     *
     * @return array<string, mixed>
     */
    public function autoFix(): array
    {
        if (! (bool) ReachConfig::get('automation.remediation.auto_recommendations', true)) {
            return ['skipped' => 'automation.remediation.auto_recommendations is off'];
        }
        $mode = SchemaGuard::hasTable($this->db, 'reach_bot_settings') ? (new \App\Models\BotSettingModel())->currentMode() : 'confirm';
        if ($mode !== 'auto') {
            return ['skipped' => 'bot mode is ' . $mode . ' — the bot acts on cards only in AUTO mode'];
        }

        $budget = [
            'search_topic' => max(0, (int) ReachConfig::get('automation.remediation.search_topics_per_day', 1)) - $this->botFixesSince('search_topic', '-1 day'),
            'retitle'      => max(0, (int) ReachConfig::get('automation.remediation.retitles_per_week', 2)) - $this->botFixesSince('retitle', '-7 days'),
            'insight'      => max(0, (int) ReachConfig::get('automation.remediation.insight_commands_per_day', 1)) - $this->botFixesSince('insight', '-1 day'),
        ];
        $done = [];
        foreach ($this->cards() as $card) {
            $kind = $card['fix']['kind'] ?? null;
            if ($kind === null || ($budget[$kind] ?? 0) <= 0 || $this->recentFix((string) $card['id'], $kind, true) !== null) {
                continue;
            }
            $r = $this->fix((string) $card['id'], ['id' => null, 'name' => 'Reach bot (AUTO mode)', 'email' => null], 'bot', $card);
            if ($r['status'] === 'queued') {
                $budget[$kind]--;
            }
            $done[] = ['card_id' => $card['id'], 'kind' => $kind, 'status' => $r['status'], 'message' => $r['message']];
        }

        return ['fixed' => $done, 'budget_left' => $budget];
    }

    /**
     * Latest fix per card, for the cards' "the bot is on it" line.
     *
     * @param list<string> $cardIds
     * @return array<string, array<string, mixed>>
     */
    public function lastFixes(array $cardIds): array
    {
        if ($cardIds === [] || ! self::available($this->db)) {
            return [];
        }
        $out = [];
        foreach ($this->db->query(
            'SELECT DISTINCT ON (card_id) card_id, kind, actor, status, message, ref_type, ref_id, created_at
               FROM reach_recommendation_fixes WHERE card_id IN ? ORDER BY card_id, created_at DESC',
            [array_values(array_unique($cardIds))]
        )->getResultArray() as $r) {
            $days = self::COOLDOWN_DAYS[$r['kind']] ?? 30;
            $out[(string) $r['card_id']] = [
                'status'     => $r['status'],
                'actor'      => $r['actor'],
                'message'    => $r['message'],
                'ref_type'   => $r['ref_type'],
                'ref_id'     => $r['ref_id'],
                'at'         => $r['created_at'],
                // A failed or refused attempt may be tried again at once.
                'active'     => $r['status'] === 'queued' && strtotime((string) $r['created_at']) > $this->now->getTimestamp() - $days * 86400,
            ];
        }

        return $out;
    }

    // ── the fixes ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $p {query, impressions, clicks, position, pages}
     * @return array<string, mixed>
     */
    private function searchTopic(array $p): array
    {
        $query = trim((string) ($p['query'] ?? ''));
        if (mb_strlen($query) < 3) {
            return ['status' => 'refused', 'message' => 'No search query to write for.'];
        }
        $slug  = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($query)), '-');
        $key   = 'gsc-' . substr($slug !== '' ? $slug : md5($query), 0, 100);
        $pages = array_values(array_filter(array_map('strval', (array) ($p['pages'] ?? []))));
        $title = 'Search topic: ' . mb_substr($query, 0, 280);
        $brief = sprintf(
            'Search Console shows aicountly.com at average position %.1f for "%s" (%d impressions, %d clicks in 28 days) — close to page one but not on it. '
            . 'Write one focused, genuinely useful article that answers exactly what someone searching "%s" wants to know, and give it your own title that contains that wording naturally ("%s" is only a working title). '
            . '%s Add internal links between the new article and the page(s) that already rank. Facts must be current; say "usually" for due dates and point to the official portal.',
            (float) ($p['position'] ?? 0), $query, (int) ($p['impressions'] ?? 0), (int) ($p['clicks'] ?? 0), $query, $title,
            $pages !== [] ? 'Pages that already show for it: ' . implode(', ', array_slice($pages, 0, 3)) . ' — go deeper on the question; do not repeat them.' : '',
        );

        $r = (new ContentBaseEntries($this->db))->queueSignalTopic('blog', $key, $title, $brief, [
            'seed_keyword'  => mb_substr($query, 0, 200),
            'search_intent' => 'informational',
            'context'       => 'search_console_opportunity',
            'evidence'      => [['url' => 'https://search.google.com/search-console', 'note' => sprintf('position %.1f, %d impressions, %d clicks (28 days)', (float) ($p['position'] ?? 0), (int) ($p['impressions'] ?? 0), (int) ($p['clicks'] ?? 0))]],
        ]);

        return match ($r['status']) {
            'accepted'  => ['status' => 'queued', 'ref_type' => 'content_base', 'ref_id' => $key,
                'message' => "Queued \"{$query}\" as the next blog topic (content base {$key}); the blog routine writes it on its next hourly run and Gemini vets it before it publishes."],
            'exists'    => ['status' => 'queued', 'ref_type' => 'content_base', 'ref_id' => $key, 'message' => (string) $r['reason']],
            default     => ['status' => 'refused', 'message' => 'Not queued: ' . (string) ($r['reason'] ?? $r['status'])],
        };
    }

    /**
     * @param array<string, mixed> $p {page_url, impressions, clicks, item_id, queries}
     * @return array<string, mixed>
     */
    private function retitle(array $p): array
    {
        $itemId = (int) ($p['item_id'] ?? 0);
        if ($itemId <= 0) {
            return ['status' => 'refused', 'message' => 'Reach did not publish this page, so the bot cannot rewrite it; change its title in the aicountly.com code.'];
        }
        $queue = new RegenerationQueue($this->db);
        if (($open = $queue->openForItem($itemId)) !== null) {
            return ['status' => 'queued', 'ref_type' => 'regeneration', 'ref_id' => (string) $open['id'],
                'message' => "A rewrite of this page is already queued (regeneration #{$open['id']})."];
        }
        $imp     = (int) ($p['impressions'] ?? 0);
        $clk     = (int) ($p['clicks'] ?? 0);
        $queries = array_values(array_filter(array_map('strval', (array) ($p['queries'] ?? []))));
        $reasons = [
            sprintf('Search Console: %d impressions but %d clicks (CTR %.1f%%) in 28 days — people see this page in Google and do not click it. The topic and URL stay; the job is to win the click.',
                $imp, $clk, 100 * $clk / max(1, $imp)),
            $queries !== []
                ? 'It shows for: ' . implode('; ', array_slice($queries, 0, 5)) . '. Write a title (≤ 60 characters) and meta description (≤ 155 characters) that answer those searches directly, and open the article with the answer.'
                : 'Write a sharper title (≤ 60 characters) and meta description (≤ 155 characters) that say exactly what the reader gets, and open the article with the answer.',
            'Keep everything that is still accurate; refresh dates and figures that are out of date. Gemini vets the rewrite like any new article.',
        ];
        $id = $queue->enqueue($itemId, $reasons, true);
        if ($id === null) {
            return ['status' => 'refused', 'message' => 'Only blog posts and help articles can be rewritten by the routine.'];
        }
        $type = (string) ($this->db->table('reach_content_items')->select('content_type')->where('id', $itemId)->get()->getRowArray()['content_type'] ?? 'blog');

        return ['status' => 'queued', 'ref_type' => 'regeneration', 'ref_id' => (string) $id,
            'message' => sprintf('Queued a same-URL rewrite (regeneration #%d) for the %s; it writes it on its next run with a sharper title and description, and Gemini vets it before it replaces the live page.',
                $id, $type === 'blog' ? 'blog routine' : 'help-article routine')];
    }

    /**
     * @param array<string, mixed> $p {id, title, detail}
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function insight(array $p, array $user, string $actor): array
    {
        $id          = (int) ($p['id'] ?? 0);
        $instruction = trim(sprintf(
            "AI Insights recommendation #%d: %s\n\n%s\n\n"
            . "Act on this recommendation with what the Reach automation API allows today: propose content-base topics, write a blog or help article through the normal routine endpoints (their rules and quotas apply), or a social post. "
            . "Do only what the evidence supports and what the product code shows exists. If part of it needs work outside Reach (code or markup changes on aicountly.com, product changes), do not attempt it — say in your report exactly what a person must do.",
            $id, (string) ($p['title'] ?? ''), mb_substr((string) ($p['detail'] ?? ''), 0, 2500),
        ));
        try {
            $result = ($this->commands ?? new BotCommandService($this->db))->submit($instruction, [], [], $user, "cc-insight-{$id}");
        } catch (BotActionException $e) {
            return ['status' => 'refused', 'message' => $e->getMessage()];
        }
        $cmd = $result['command'];
        if (! in_array($cmd['status'], ['submitted', 'outcome_unknown'], true)) {
            return ['status' => 'failed', 'ref_type' => 'bot_command', 'ref_id' => (string) ($cmd['uuid'] ?? $cmd['id']),
                'message' => 'The bot was not started: ' . ($cmd['fire_error'] ?? $cmd['status'])];
        }

        return ['status' => 'queued', 'ref_type' => 'bot_command', 'ref_id' => (string) ($cmd['uuid'] ?? $cmd['id']),
            'message' => ($result['replayed'] ? 'Already sent' : 'Sent') . ' to the bot (reach-command); it reports what it did in Bot → Ask the bot.'];
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> live cards, ranked */
    private function cards(): array
    {
        $built = (new CommandCentreService($this->db, $this->now))->build(['health', 'recommendations'], true);

        return (array) ($built['recommendations']['cards'] ?? []);
    }

    /** @return array<string, mixed>|null */
    private function findCard(string $cardId): ?array
    {
        foreach ($this->cards() as $card) {
            if ((string) $card['id'] === $cardId) {
                return $card;
            }
        }

        return null;
    }

    /**
     * A queued fix of this card within its cooldown. For the bot, any earlier
     * attempt (refused or failed too) counts, so it does not retry a refusal
     * every hour; a person may try again.
     *
     * @return array<string, mixed>|null
     */
    private function recentFix(string $cardId, string $kind, bool $anyStatus = false): ?array
    {
        $since = $this->now->modify('-' . (self::COOLDOWN_DAYS[$kind] ?? 30) . ' days')->format('Y-m-d H:i:sP');
        $day   = $this->now->modify('-1 day')->format('Y-m-d H:i:sP');
        $b     = $this->db->table('reach_recommendation_fixes')->where('card_id', $cardId);
        if ($anyStatus) {
            // Refused / failed attempts are retried by the bot once a day at most.
            $b->groupStart()
                ->groupStart()->where('status', 'queued')->where('created_at >=', $since)->groupEnd()
                ->orWhere('created_at >=', $day)
              ->groupEnd();
        } else {
            $b->where('status', 'queued')->where('created_at >=', $since);
        }

        return $b->orderBy('created_at', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    private function botFixesSince(string $kind, string $modify): int
    {
        return $this->db->table('reach_recommendation_fixes')->where('kind', $kind)->where('actor', 'bot')->where('status', 'queued')
            ->where('created_at >=', $this->now->modify($modify)->format('Y-m-d H:i:sP'))->countAllResults();
    }
}

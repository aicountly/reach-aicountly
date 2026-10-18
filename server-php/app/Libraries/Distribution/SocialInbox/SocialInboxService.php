<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\SocialInbox;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiRedactor;
use App\Libraries\Gateways\Mailer;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\VideoProviders;
use App\Models\LeadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * The social inbox bot: social-api.ai comments / messages → Gemini triage →
 * a vetted reply, a Flow ticket, a Reach lead — or nothing, with the reason.
 *
 *   poll()     every connected account of social.inbox.platforms: GET the inbox
 *              since just before the newest stored message (first run:
 *              social.inbox.lookback_hours); store new items (unique per network +
 *              id, so polling is idempotent). Our own replies are not stored.
 *   process()  each new item once:
 *                question / praise / other → Gemini draft → vetting ('social_reply')
 *                     → posted only when social-api.ai accepts it → `replied`
 *                complaint  → Flow ticket (idempotent on the message id) → fixed
 *                     acknowledgement quoting the ticket → `ticket_created`; high
 *                     severity also alerts the owner by e-mail at once
 *                sales_lead → Reach lead pushed to Engage → vetted reply → `lead_created`
 *                sensitive  → fixed privacy nudge (never repeats the data) → `replied`
 *                spam_abuse → never answered: `hidden` (when hiding is on and
 *                     social-api.ai accepted it) or `ignored`
 *              Guards: one reply per conversation (network + thread + author),
 *              social.inbox.daily_reply_cap per IST day, no reply under
 *              social.inbox.min_confidence or after social.inbox.reply_max_age_hours,
 *              SocialReplyPolicy (length, sign-off, no personal data, aicountly.com
 *              links only). A transient failure (Gemini, Flow) is retried up to
 *              MAX_ATTEMPTS times; a reply is never re-posted after an unknown outcome.
 *
 * Runs as reach.social_inbox_poll (reach:schedule every 10 minutes, queue
 * `distribution`) and `php spark reach:social-inbox`.
 * docs/operations/SOCIAL_API.md → Social inbox bot.
 */
final class SocialInboxService
{
    public const JOB_TYPE        = 'reach.social_inbox_poll';
    public const MAX_ATTEMPTS    = 5;
    public const MAX_PAGES       = 3;
    public const PAGE_LIMIT      = 50;
    public const OVERLAP_SECS    = 3600;
    public const ALERTS_PER_DAY  = 10;
    private const TZ             = 'Asia/Kolkata';

    private BaseConnection $db;
    /** @var callable(array<string, mixed>): array{lead_id: int, engage_status: string} */
    private $leadCreator;
    /** @var callable(array<string, mixed>): array{sent: bool, reason: ?string} */
    private $alert;
    /** @var callable(): int */
    private $clock;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?SocialApiAiClient $client = null,
        private ?SocialApiAccounts $accounts = null,
        private ?SocialInboxClassifier $classifier = null,
        private ?ContentVettingService $vetting = null,
        private ?FlowTicketClient $flow = null,
        ?callable $leadCreator = null,
        ?callable $alert = null,
        ?callable $clock = null,
    ) {
        $this->db          = $db ?? \Config\Database::connect();
        $this->accounts  ??= $client !== null ? new SocialApiAccounts($client) : null;
        $this->classifier ??= new SocialInboxClassifier();
        $this->vetting    ??= new ContentVettingService();
        $this->flow       ??= FlowTicketClient::fromConfig();
        $this->leadCreator = $leadCreator ?? self::defaultLeadCreator(...);
        $this->alert       = $alert ?? self::defaultAlert(...);
        $this->clock       = $clock ?? static fn (): int => time();
    }

    /** The service wired to the saved settings (no client when the social-api.ai key is missing). */
    public static function fromConfig(): self
    {
        $key    = ReachConfig::secret('social.socialapi.api_key');
        $client = $key !== null ? new SocialApiAiClient($key, (string) ReachConfig::get('social.socialapi.base_url'), VideoProviders::http()) : null;

        return new self(null, $client, null, null, null, FlowTicketClient::fromConfig(VideoProviders::http()));
    }

    /** @return list<string> */
    public static function platforms(): array
    {
        $raw = array_map(static fn ($p) => SocialApiPlatforms::forChannel(trim((string) $p)), explode(',', (string) ReachConfig::get('social.inbox.platforms', '')));
        return array_values(array_unique(array_filter($raw)));
    }

    /**
     * poll() then process(), unless the bot is switched off.
     *
     * @return array<string, mixed>
     */
    public function run(int $limit = 25): array
    {
        if (! (bool) ReachConfig::get('social.inbox.enabled', true)) {
            return ['status' => 'disabled', 'reason' => 'social.inbox.enabled is off.'];
        }
        $poll = $this->poll();
        return ['status' => $poll['status'] === 'ok' ? 'ok' : $poll['status'], 'poll' => $poll, 'process' => $this->process($limit)];
    }

    // ── poll ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function poll(): array
    {
        if ($this->client === null || $this->accounts === null) {
            return ['status' => 'not_configured', 'reason' => 'social-api.ai API key is not set (Settings → Social, or SOCIALAPI_API_KEY). Nothing was read.'];
        }
        $out  = ['status' => 'ok', 'accounts' => [], 'fetched' => 0, 'stored' => 0, 'own' => 0, 'dropped' => 0, 'errors' => []];
        $path = (string) ReachConfig::get('social.inbox.list_path', SocialApiAiClient::INBOX_LIST_PATH);

        foreach (self::platforms() as $platform) {
            $chk = $this->accounts->check($platform);
            if (! $chk['ok']) {
                $out['accounts'][$platform] = 'skipped — ' . $chk['reason'];
                if ($chk['kind'] === SocialApiException::AUTH) {
                    $out['status'] = 'error';
                    $out['errors'][$platform] = $chk['reason'];
                    break;
                }
                continue;
            }
            $accountId = (string) $chk['account']['id'];
            $since     = $this->since($platform, $accountId);
            $cursor    = null;
            $stored    = 0;
            $fatal     = false;
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                try {
                    $json = $this->client->inbox(['account_id' => $accountId, 'since' => $since, 'limit' => self::PAGE_LIMIT, 'cursor' => $cursor], $path);
                } catch (SocialApiException $e) {
                    $out['status'] = 'error';
                    $out['errors'][$platform] = self::pollError($e, $path);
                    $fatal = $e->httpStatus === 404 || $e->kind === SocialApiException::AUTH;
                    break;
                }
                $parsed = SocialInboxParser::page($json, $platform, $accountId);
                if (! $parsed['recognised']) {
                    $out['status'] = 'error';
                    $out['errors'][$platform] = 'social-api.ai answered the inbox list with a shape Reach does not recognise (top-level keys: ' . implode(', ', $parsed['keys']) . '). Nothing was stored.';
                    $this->log('warning', 'GET ' . $path . ': unrecognised inbox shape, keys ' . implode(', ', $parsed['keys']));
                    $fatal = true;
                    break;
                }
                if ($parsed['skipped'] !== []) {
                    $out['dropped'] += count($parsed['skipped']);
                    $this->log('warning', 'GET ' . $path . ' (' . $platform . '): ' . implode('; ', array_slice($parsed['skipped'], 0, 5)));
                }
                foreach ($parsed['items'] as $item) {
                    $out['fetched']++;
                    if ($item['is_own'] || $item['is_deleted']) {
                        $out['own'] += $item['is_own'] ? 1 : 0;
                        continue;
                    }
                    $stored += $this->store($item, $platform, $accountId) ? 1 : 0;
                }
                $cursor = $parsed['next'];
                if ($cursor === null || $parsed['items'] === []) {
                    break;
                }
            }
            $out['stored'] += $stored;
            $out['accounts'][$platform] = isset($out['errors'][$platform]) ? 'error — ' . $out['errors'][$platform] : sprintf('read %s since %s: %d new', $accountId, $since, $stored);
            if ($fatal) {
                break; // the same path / key answers every account the same way
            }
        }
        return $out;
    }

    private static function pollError(SocialApiException $e, string $path): string
    {
        if ($e->httpStatus === 404) {
            return "social-api.ai has no inbox list at {$path} (HTTP 404). That path is an assumption — read docs.social-api.ai/guides/inbox and set social.inbox.list_path.";
        }
        return $e->getMessage();
    }

    private function since(string $platform, string $accountId): string
    {
        $row = $this->db->query('SELECT MAX(received_at) AS m FROM reach_social_inbox WHERE network = ? AND account_id = ?', [$platform, $accountId])->getRowArray();
        $max = isset($row['m']) && $row['m'] !== null ? strtotime((string) $row['m']) : false;
        $ts  = $max !== false ? $max - self::OVERLAP_SECS : ($this->clock)() - max(1, (int) ReachConfig::get('social.inbox.lookback_hours', 24)) * 3600;

        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** @param array<string, mixed> $item */
    private function store(array $item, string $platform, string $accountId): bool
    {
        $network = in_array($item['platform'] ?? null, SocialApiPlatforms::PLATFORMS, true) ? (string) $item['platform'] : $platform;
        $raw     = $this->client !== null ? $this->client->redactor()->json($item['raw'] ?? []) : (string) json_encode($item['raw'] ?? []);
        $res = $this->db->query(
            'INSERT INTO reach_social_inbox
                (network, account_id, external_id, kind, thread_id, parent_id, author_name, author_handle, author_external_id,
                 text, post_url, received_at, raw_json, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, \'new\')
             ON CONFLICT (network, external_id) DO NOTHING
             RETURNING id',
            [$network, $item['account_id'] ?? $accountId, $item['external_id'], $item['kind'], $item['thread_id'], $item['parent_id'],
                $item['author_name'], $item['author_handle'], $item['author_external_id'], $item['text'], $item['post_url'],
                $item['received_at'] ?? gmdate('Y-m-d H:i:s', ($this->clock)()) . '+00', $raw]
        );
        return $res->getRowArray() !== null;
    }

    // ── process ─────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    public function process(int $limit = 25): array
    {
        $rows = $this->db->query(
            "SELECT * FROM reach_social_inbox
             WHERE (status IN ('new','retry','deferred') OR reply_status = 'pending') AND attempts < ?
             ORDER BY (status IN ('deferred') OR reply_status = 'pending') ASC, received_at ASC NULLS LAST, id ASC
             LIMIT ?",
            [self::MAX_ATTEMPTS, max(1, $limit)]
        )->getResultArray();

        $out = ['processed' => 0, 'replied' => 0, 'ticket_created' => 0, 'lead_created' => 0, 'ignored' => 0, 'hidden' => 0, 'deferred' => 0, 'retry' => 0, 'failed' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            try {
                $status = $this->handle($row);
            } catch (\Throwable $e) {
                $out['errors']++;
                $this->log('error', 'item ' . $row['id'] . ': ' . $e->getMessage());
                $this->retry($row, 'Unexpected error: ' . mb_substr($e->getMessage(), 0, 300));
                continue;
            }
            $out['processed']++;
            if (isset($out[$status])) {
                $out[$status]++;
            }
        }
        return $out;
    }

    /** Process one stored item now (CLI / retry button). */
    public function processOne(int $id): ?string
    {
        $row = $this->db->table('reach_social_inbox')->where('id', $id)->get()->getRowArray();
        return $row === null ? null : $this->handle($row);
    }

    /**
     * Handle one row; returns the row's status afterwards.
     *
     * @param array<string, mixed> $row
     */
    private function handle(array $row): string
    {
        if ($row['classification'] === null) {
            try {
                $c = $this->classifier->classify([
                    'network' => (string) $row['network'], 'kind' => (string) $row['kind'], 'text' => (string) $row['text'],
                    'author_handle' => $row['author_handle'], 'post_url' => $row['post_url'],
                ]);
            } catch (VettingUnavailableException $e) {
                return $this->retry($row, 'Classification unavailable: ' . $e->getMessage());
            }
            $fields = [
                'classification' => $c['category'], 'confidence' => $c['confidence'], 'severity' => $c['severity'],
                'severity_reason' => $c['severity_reason'] !== '' ? $c['severity_reason'] : null, 'personal_data' => $c['personal_data'],
                'summary' => $c['summary'] !== '' ? $c['summary'] : null, 'classifier_model' => $c['model'],
                'classified_at' => $this->nowSql(), 'reply_draft' => $c['reply'] !== '' ? $c['reply'] : null,
            ];
            $this->update((int) $row['id'], $fields);
            $row = $fields + $row;
        }

        return match ((string) $row['classification']) {
            'spam_abuse' => $this->spam($row),
            'complaint'  => $this->complaint($row),
            'sales_lead' => $this->lead($row),
            'sensitive'  => $this->answer($row, SocialReplyPolicy::privacyNudge((string) $row['kind'])),
            'other'      => trim((string) ($row['reply_draft'] ?? '')) === ''
                ? $this->finish($row, 'ignored', 'Classified as other — nothing to answer.')
                : $this->answer($row, null),
            default      => $this->answer($row, null), // question, praise
        };
    }

    /** question / praise / other / sensitive: the reply is the outcome. */
    private function answer(array $row, ?string $template): string
    {
        $r = $this->attemptReply($row, $template);
        $this->update((int) $row['id'], $r['fields']);
        return match ($r['outcome']) {
            'sent'    => $this->finish($row, 'replied', null),
            'pending' => $this->finish($row, 'deferred', $r['fields']['reply_reason'] ?? null),
            'retry'   => $this->retry($row, (string) ($r['fields']['reply_reason'] ?? 'Reply not ready.')),
            'failed'  => $this->finish($row, 'failed', $r['fields']['reply_reason'] ?? 'Reply failed.'),
            default   => $this->finish($row, 'ignored', $r['fields']['reply_reason'] ?? 'Not answered.'),
        };
    }

    private function complaint(array $row): string
    {
        $id = (int) $row['id'];
        if (($row['ticket_number'] ?? null) === null) {
            $res = $this->flow->lodge([
                'source_message_id' => (string) $row['external_id'],
                'network'           => (string) $row['network'],
                'account'           => (string) ($row['account_id'] ?? ''),
                'author_handle'     => (string) ($row['author_handle'] ?? ''),
                'author_name'       => (string) ($row['author_name'] ?? ''),
                'text'              => (string) $row['text'],
                'post_url'          => (string) ($row['post_url'] ?? ''),
                'received_at'       => $row['received_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $row['received_at'])) : null,
                'thread_id'         => (string) ($row['thread_id'] ?? ''),
                'severity'          => (string) ($row['severity'] ?? 'medium'),
                'severity_reason'   => (string) ($row['severity_reason'] ?? ''),
                'category'          => 'complaint',
                'summary'           => (string) ($row['summary'] ?? ''),
                'confidence'        => $row['confidence'] !== null ? (int) $row['confidence'] : null,
                'reach_inbox_id'    => $id,
            ]);
            if (! $res['ok']) {
                $this->update($id, ['ticket_error' => $res['message']]);
                $this->maybeAlert($row + ['ticket_error' => $res['message']]);
                return match ($res['kind']) {
                    'not_configured', 'auth' => $this->finish($row, 'deferred', 'Complaint not lodged in Flow yet: ' . $res['message'] . ' It is lodged automatically once Flow accepts it.'),
                    'transient'              => $this->retry($row, 'Flow unavailable: ' . $res['message']),
                    default                  => $this->finish($row, 'failed', 'Flow refused the complaint: ' . $res['message']),
                };
            }
            $this->update($id, ['ticket_number' => $res['ticket_number'], 'ticket_id' => $res['ticket_id'], 'ticket_error' => null]);
            $row['ticket_number'] = $res['ticket_number'];
            $this->audit('social.inbox.complaint_lodged', $id, ['network' => $row['network'], 'ticket_number' => $res['ticket_number'], 'flow' => $res['kind'], 'severity' => $row['severity']]);
        }

        $this->maybeAlert($row);
        $r = $this->attemptReply($row, SocialReplyPolicy::complaintAck((string) $row['ticket_number'], (string) $row['kind']), true);
        $this->update($id, $r['fields']);
        $reason = 'Lodged in Flow as ' . $row['ticket_number'] . '. ' . match ($r['outcome']) {
            'sent'    => 'Acknowledged publicly.',
            'pending' => 'Acknowledgement waiting: ' . ($r['fields']['reply_reason'] ?? ''),
            default   => 'Not acknowledged on the network: ' . ($r['fields']['reply_reason'] ?? ''),
        };
        return $this->finish($row, 'ticket_created', trim($reason));
    }

    private function lead(array $row): string
    {
        $id = (int) $row['id'];
        if (($row['lead_id'] ?? null) === null) {
            try {
                $lead = ($this->leadCreator)($row);
            } catch (\Throwable $e) {
                return $this->retry($row, 'Could not record the lead: ' . mb_substr($e->getMessage(), 0, 300));
            }
            $this->update($id, ['lead_id' => $lead['lead_id']]);
            $row['lead_id'] = $lead['lead_id'];
            $row['engage_status'] = $lead['engage_status'];
            $this->audit('social.inbox.lead_created', $id, ['network' => $row['network'], 'lead_id' => $lead['lead_id'], 'engage_push_status' => $lead['engage_status']]);
        }
        $r = $this->attemptReply($row, null);
        if ($r['outcome'] === 'retry') {
            $attempts = (int) $row['attempts'] + 1;
            $r['fields'] += ['attempts' => $attempts];
            if ($attempts >= self::MAX_ATTEMPTS) {
                $r['fields']['reply_status'] = 'failed';
            }
        }
        $this->update($id, $r['fields']);
        $reason = 'Recorded as lead #' . $row['lead_id'] . (isset($row['engage_status']) ? ' (Engage: ' . $row['engage_status'] . ')' : '') . '. '
            . ($r['outcome'] === 'sent' ? 'Answered.' : 'Reply: ' . ($r['fields']['reply_reason'] ?? $r['outcome']));
        return $this->finish($row, 'lead_created', trim($reason));
    }

    private function spam(array $row): string
    {
        if ((bool) ReachConfig::get('social.inbox.hide_spam', false) && $this->client !== null) {
            $res = $this->client->hideInboxItem((string) $row['external_id'], (string) ReachConfig::get('social.inbox.hide_path', SocialApiAiClient::INBOX_HIDE_PATH));
            if ($res['accepted']) {
                $this->audit('social.inbox.hidden', (int) $row['id'], ['network' => $row['network']]);
                return $this->finish($row, 'hidden', 'Spam / abuse — hidden on the network (social-api.ai accepted it). Never answered.');
            }
            return $this->finish($row, 'ignored', 'Spam / abuse — never answered. Hiding failed: ' . $res['message']);
        }
        return $this->finish($row, 'ignored', 'Spam / abuse — never answered' . ((bool) ReachConfig::get('social.inbox.hide_spam', false) ? '.' : ' (hiding is off).'));
    }

    /**
     * Decide and, when allowed, post the reply.
     *
     * @return array{outcome: string, fields: array<string, mixed>} outcome sent | pending | retry | skipped | failed
     */
    private function attemptReply(array $row, ?string $template, bool $isAck = false): array
    {
        $skip = fn (string $why): array => ['outcome' => 'skipped', 'fields' => ['reply_status' => 'skipped', 'reply_reason' => $why]];

        if (($row['reply_status'] ?? 'none') === 'sent') {
            return ['outcome' => 'sent', 'fields' => []];
        }
        if (! (bool) ReachConfig::get('social.inbox.auto_reply', true)) {
            return $skip('Auto-reply is off (social.inbox.auto_reply).');
        }
        $floor = (int) ReachConfig::get('social.inbox.min_confidence', 70);
        if ((int) ($row['confidence'] ?? 0) < $floor) {
            return $skip(sprintf('Classified as %s with %d%% confidence, below the %d%% floor — not answered automatically.', $row['classification'], (int) $row['confidence'], $floor));
        }
        $maxAge   = max(1, (int) ReachConfig::get('social.inbox.reply_max_age_hours', 48));
        $received = $row['received_at'] !== null ? strtotime((string) $row['received_at']) : false;
        if ($received !== false && $received < ($this->clock)() - $maxAge * 3600) {
            return $skip(sprintf('Older than %d hours — too late to answer.', $maxAge));
        }
        if ($this->alreadyAnswered($row)) {
            return $skip('Already answered in this conversation (one reply per thread).');
        }
        $cap = (int) ReachConfig::get('social.inbox.daily_reply_cap', 40);
        if ($this->repliesToday() >= $cap) {
            return ['outcome' => 'pending', 'fields' => ['reply_status' => 'pending', 'reply_reason' => "Daily reply cap ({$cap}) reached — answered on a later run if still within {$maxAge} hours."]];
        }
        if ($this->client === null) {
            return ['outcome' => 'failed', 'fields' => ['reply_status' => 'failed', 'reply_reason' => 'social-api.ai API key is not set — cannot reply.']];
        }

        $fields = [];
        if ($template !== null) {
            $text = $template;
        } else {
            $draft = trim((string) ($row['reply_draft'] ?? ''));
            if ($draft === '') {
                return $skip('No reply was drafted.');
            }
            try {
                $v = $this->vetting->vet('social_reply', ['reply' => $draft], [
                    'network' => $row['network'], 'kind' => $row['kind'], 'category' => $row['classification'],
                    'message' => mb_substr((string) $row['text'], 0, 2000), 'author_handle' => $row['author_handle'],
                ], 'social_inbox:' . $row['id']);
            } catch (VettingUnavailableException $e) {
                return ['outcome' => 'retry', 'fields' => ['reply_status' => 'pending', 'reply_reason' => 'Reply vetting unavailable: ' . $e->getMessage()]];
            }
            $fields = ['vetting_decision' => $v->decision, 'vetting_run_id' => $v->runId, 'vetting_reasons_json' => json_encode($v->reasons, JSON_UNESCAPED_UNICODE)];
            if ($v->rejected()) {
                return ['outcome' => 'skipped', 'fields' => $fields + ['reply_status' => 'skipped', 'reply_reason' => 'Vetting rejected the reply' . ($v->reasons !== [] ? ': ' . implode(' ', array_slice($v->reasons, 0, 3)) : '.')]];
            }
            $text = (string) ($v->final['reply'] ?? $draft);
        }

        $final = SocialReplyPolicy::finalize($text, (string) $row['network']);
        if (! $final['ok']) {
            return ['outcome' => 'skipped', 'fields' => $fields + ['reply_status' => 'skipped', 'reply_text' => $final['text'], 'reply_reason' => (string) $final['reason']]];
        }

        $res = $this->client->replyToInboxItem((string) $row['external_id'], $final['text'], (string) ReachConfig::get('social.inbox.reply_path', SocialApiAiClient::INBOX_REPLY_PATH), 'reach-social-inbox:' . $row['id']);
        if ($res['accepted']) {
            $this->audit('social.inbox.reply_sent', (int) $row['id'], ['network' => $row['network'], 'classification' => $row['classification'], 'acknowledgement' => $isAck]);
            return ['outcome' => 'sent', 'fields' => $fields + [
                'reply_status' => 'sent', 'reply_text' => $final['text'], 'reply_reason' => null,
                'reply_external_id' => $res['reply_id'], 'replied_at' => $this->nowSql(),
            ]];
        }
        return ['outcome' => 'failed', 'fields' => $fields + ['reply_status' => 'failed', 'reply_text' => $final['text'], 'reply_reason' => (string) $res['message']]];
    }

    private function alreadyAnswered(array $row): bool
    {
        $author = (string) ($row['author_external_id'] ?? '') !== '' ? ['author_external_id', (string) $row['author_external_id']]
            : ((string) ($row['author_handle'] ?? '') !== '' ? ['author_handle', (string) $row['author_handle']] : null);
        if ($author === null) {
            return false;
        }
        if (($row['thread_id'] ?? null) === null) {
            // No thread id: the conversation is this author on this network (and kind) for a day.
            $hit = $this->db->query(
                "SELECT 1 FROM reach_social_inbox WHERE network = ? AND kind = ? AND {$author[0]} = ? AND id <> ? AND reply_status = 'sent' AND replied_at >= ? LIMIT 1",
                [$row['network'], $row['kind'], $author[1], $row['id'], gmdate('Y-m-d H:i:s', ($this->clock)() - 86400) . '+00']
            )->getRowArray();
            return $hit !== null;
        }
        $hit = $this->db->query(
            "SELECT 1 FROM reach_social_inbox WHERE network = ? AND thread_id = ? AND {$author[0]} = ? AND id <> ? AND reply_status = 'sent' LIMIT 1",
            [$row['network'], $row['thread_id'], $author[1], $row['id']]
        )->getRowArray();
        return $hit !== null;
    }

    private function repliesToday(): int
    {
        $start = (new \DateTimeImmutable('@' . ($this->clock)()))->setTimezone(new \DateTimeZone(self::TZ))->setTime(0, 0);
        return (int) ($this->db->query("SELECT COUNT(*) AS c FROM reach_social_inbox WHERE reply_status = 'sent' AND replied_at >= ?", [$start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP')])->getRowArray()['c'] ?? 0);
    }

    private function maybeAlert(array $row): void
    {
        if (($row['severity'] ?? null) !== 'high' || ($row['owner_alerted_at'] ?? null) !== null) {
            return;
        }
        $start = (new \DateTimeImmutable('@' . ($this->clock)()))->setTimezone(new \DateTimeZone(self::TZ))->setTime(0, 0);
        $today = (int) ($this->db->query('SELECT COUNT(*) AS c FROM reach_social_inbox WHERE owner_alerted_at >= ?', [$start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP')])->getRowArray()['c'] ?? 0);
        if ($today >= self::ALERTS_PER_DAY) {
            return; // the rest are in the daily report
        }
        try {
            $sent = ($this->alert)($row);
        } catch (\Throwable $e) {
            $this->log('error', 'owner alert for item ' . $row['id'] . ' failed: ' . $e->getMessage());
            return;
        }
        if ($sent['sent']) {
            $this->update((int) $row['id'], ['owner_alerted_at' => $this->nowSql()]);
        } elseif ($sent['reason'] !== null) {
            $this->log('warning', 'owner alert for item ' . $row['id'] . ' not sent: ' . $sent['reason']);
        }
    }

    private function retry(array $row, string $reason): string
    {
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->update((int) $row['id'], ['attempts' => $attempts, 'last_error' => $reason]);
            return $this->finish($row, 'failed', "Gave up after {$attempts} attempts: {$reason}");
        }
        $this->update((int) $row['id'], ['attempts' => $attempts, 'last_error' => $reason, 'status' => 'retry', 'status_reason' => $reason]);
        return 'retry';
    }

    private function finish(array $row, string $status, ?string $reason): string
    {
        $this->update((int) $row['id'], ['status' => $status, 'status_reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null]);
        return $status;
    }

    /** @param array<string, mixed> $fields */
    private function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        if (array_key_exists('personal_data', $fields)) {
            $fields['personal_data'] = $fields['personal_data'] ? 't' : 'f';
        }
        $fields['updated_at'] = $this->nowSql();
        $this->db->table('reach_social_inbox')->where('id', $id)->update($fields);
    }

    private function nowSql(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)()) . '+00';
    }

    private function audit(string $action, int $id, array $data): void
    {
        try {
            (new AuditLogger())->log(null, $action, 'social_inbox', $id, null, $data, null, 'system', 'reach:social-inbox');
        } catch (\Throwable) {
        }
    }

    private function log(string $level, string $message): void
    {
        if (function_exists('log_message')) {
            log_message($level, '[SocialInbox] ' . ($this->client?->redactor() ?? new SocialApiRedactor())->string($message));
        }
    }

    // ── defaults ────────────────────────────────────────────────────────────

    /**
     * A Reach lead from a sales enquiry, pushed to Engage at once (the same
     * path as public lead capture). There is usually no e-mail or phone: the
     * handle, network and post link are in the notes for the sales team.
     *
     * @return array{lead_id: int, engage_status: string}
     */
    public static function defaultLeadCreator(array $row): array
    {
        $handle = (string) ($row['author_handle'] ?? '');
        $name   = trim((string) ($row['author_name'] ?? '')) !== '' ? (string) $row['author_name'] : ($handle !== '' ? '@' . $handle : 'Social media enquiry');
        $label  = SocialApiPlatforms::label((string) $row['network']);
        $m      = new LeadModel();
        $m->insert([
            'name'        => mb_substr($name, 0, 190),
            'source_kind' => 'social_inbox',
            'priority'    => 'high',
            'notes'       => mb_substr("Sales enquiry from a {$label} {$row['kind']}" . ($handle !== '' ? " by @{$handle}" : '') . ":\n\n" . $row['text']
                . (($row['post_url'] ?? '') !== '' ? "\n\nPost: " . $row['post_url'] : '') . "\n\nAnswer them on {$label} (no e-mail / phone was shared).", 0, 4000),
            'raw_payload' => ['social_inbox_id' => (int) $row['id'], 'network' => $row['network'], 'external_id' => $row['external_id'], 'author_handle' => $handle, 'post_url' => $row['post_url'] ?? null],
            'utm_source'  => (string) $row['network'],
            'utm_medium'  => 'social_inbox',
            'landing_url' => preg_match('#^https://#i', (string) ($row['post_url'] ?? '')) ? mb_substr((string) $row['post_url'], 0, 2000) : null,
        ]);
        $id   = (int) $m->getInsertID();
        $lead = $m->find($id) ?? ['id' => $id];
        try {
            $status = (string) (Services::engageClient()->pushLead($lead)['status'] ?? 'retry_scheduled');
        } catch (\Throwable $e) {
            log_message('error', '[SocialInbox] Engage push for lead ' . $id . ' failed: ' . $e->getMessage());
            $status = 'retry_scheduled';
        }
        return ['lead_id' => $id, 'engage_status' => $status];
    }

    /**
     * Immediate e-mail to the owner for a high-severity complaint (legal threat,
     * data / privacy issue, fraud allegation). Informational: nothing to approve.
     *
     * @return array{sent: bool, reason: ?string}
     */
    public static function defaultAlert(array $row): array
    {
        $to = trim((string) ReachConfig::get('social.inbox.alert_recipients', ''));
        if ($to === '') {
            $to = trim((string) ReachConfig::get('reports.daily.recipients', ''));
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $to))));
        if ($list === []) {
            return ['sent' => false, 'reason' => 'No alert recipients configured.'];
        }
        $label   = SocialApiPlatforms::label((string) $row['network']);
        $ticket  = ($row['ticket_number'] ?? null) !== null ? (string) $row['ticket_number'] : null;
        $subject = '[Reach] High-severity social complaint on ' . $label . ($ticket !== null ? ' — ' . $ticket : '');
        $lines   = [
            'A high-severity complaint arrived on ' . $label . ' (' . ($row['severity_reason'] ?? 'high severity') . ').',
            'From: ' . trim(($row['author_name'] ?? '') . (($row['author_handle'] ?? '') !== '' ? ' @' . $row['author_handle'] : '')),
            'Summary: ' . ($row['summary'] ?? '—'),
            'Post: ' . ($row['post_url'] ?? '—'),
            $ticket !== null ? 'Flow ticket: ' . $ticket : 'Flow ticket: not lodged yet — ' . ($row['ticket_error'] ?? 'pending'),
            '',
            'Message:',
            (string) $row['text'],
            '',
            'For information — the bot has already handled it (Reach → Social → Inbox). No action is required in Reach.',
        ];
        $text = implode("\n", $lines);
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</div>';
        $ok   = Mailer::send($list, $subject, $html, $text);

        return ['sent' => $ok, 'reason' => $ok ? null : (Mailer::getLastError() ?? 'mail not sent')];
    }

    // ── read side (API, daily report) ───────────────────────────────────────

    /**
     * Counts for a window (rows received in it) plus what is waiting now.
     *
     * @return array<string, mixed>
     */
    public static function stats(BaseConnection $db, string $startUtc, string $endUtc): array
    {
        $by = static function (string $col) use ($db, $startUtc, $endUtc): array {
            $out = [];
            foreach ($db->query("SELECT {$col} AS k, COUNT(*) AS c FROM reach_social_inbox WHERE fetched_at >= ? AND fetched_at < ? GROUP BY {$col}", [$startUtc, $endUtc])->getResultArray() as $r) {
                $out[(string) ($r['k'] ?? 'unclassified')] = (int) $r['c'];
            }
            return $out;
        };
        $one = static fn (string $sql, array $b = []): int => (int) ($db->query($sql, $b)->getRowArray()['c'] ?? 0);

        return [
            'received'          => $one('SELECT COUNT(*) AS c FROM reach_social_inbox WHERE fetched_at >= ? AND fetched_at < ?', [$startUtc, $endUtc]),
            'by_classification' => $by('classification'),
            'by_status'         => $by('status'),
            'replies_sent'      => $one("SELECT COUNT(*) AS c FROM reach_social_inbox WHERE reply_status = 'sent' AND replied_at >= ? AND replied_at < ?", [$startUtc, $endUtc]),
            'reply_failures'    => $one("SELECT COUNT(*) AS c FROM reach_social_inbox WHERE reply_status = 'failed' AND updated_at >= ? AND updated_at < ?", [$startUtc, $endUtc]),
            'tickets'           => $one("SELECT COUNT(*) AS c FROM reach_social_inbox WHERE ticket_number IS NOT NULL AND fetched_at >= ? AND fetched_at < ?", [$startUtc, $endUtc]),
            'leads'             => $one('SELECT COUNT(*) AS c FROM reach_social_inbox WHERE lead_id IS NOT NULL AND fetched_at >= ? AND fetched_at < ?', [$startUtc, $endUtc]),
            'waiting_for_flow'  => $one("SELECT COUNT(*) AS c FROM reach_social_inbox WHERE classification = 'complaint' AND ticket_number IS NULL AND status IN ('deferred','retry')"),
            'failed_open'       => $one("SELECT COUNT(*) AS c FROM reach_social_inbox WHERE status = 'failed' AND updated_at >= ?", [$startUtc]),
            'high_severity'     => $db->query(
                "SELECT id, network, author_handle, summary, ticket_number, post_url, received_at FROM reach_social_inbox
                 WHERE severity = 'high' AND fetched_at >= ? AND fetched_at < ? ORDER BY received_at DESC LIMIT 5",
                [$startUtc, $endUtc]
            )->getResultArray(),
        ];
    }
}

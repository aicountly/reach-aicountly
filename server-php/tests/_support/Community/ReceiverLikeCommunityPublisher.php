<?php

declare(strict_types=1);

namespace Tests\Support\Community;

use App\Libraries\Community\CommunityPublisherInterface;

/**
 * A fake aicountly.com community receiver that keeps state and applies the
 * same rules as the real one (aicountly-com
 * includes/publisher/CommunityReceiverController.php), where those rules
 * decide whether a Reach publication works:
 *
 *   - idempotency: a write's X-Idempotency-Key is remembered with a hash of
 *     its body, forever; the same key with the same body replays the first
 *     response, the same key with another body is refused with 409
 *     idempotency_conflict (Reach classifies 409 as version_conflict);
 *   - identity / question / answer creates are upserts by Reach UUID; an
 *     answer upsert rewrites the body of an existing answer in place and keeps
 *     its status (so a published answer shows the new text at once);
 *   - publish / unpublish / withdraw / restore change the status of an answer
 *     that exists (404 otherwise) and may carry a new body;
 *   - a correction swaps the body of an existing answer, keeps the old one as
 *     a revision, bumps revision_number, records the note, keeps the status;
 *   - official-bot content must say ai_assisted = true (422 otherwise);
 *   - status / verification report the stored status and the checksum of the
 *     latest successful write for that UUID; 404 when the answer is unknown.
 *
 * Responses are shaped as CommunityPublicSitePublisher returns them (success
 * flag merged in; failures as error_category / safe_error_message /
 * remote_error_code).
 */
final class ReceiverLikeCommunityPublisher implements CommunityPublisherInterface
{
    /** @var array<string, array{hash: string, response: array}> */
    private array $idempotency = [];

    /** @var array<string, array<string, mixed>> */
    public array $identities = [];

    /** @var array<string, array<string, mixed>> keyed by Reach question uuid */
    public array $questions = [];

    /** @var array<string, array<string, mixed>> keyed by Reach answer uuid */
    public array $answers = [];

    /** @var array<string, string> latest successful payload checksum per uuid */
    private array $checksums = [];

    /** @var list<array{method: string, args: array, response: array}> */
    private array $calls = [];

    /** @var array<string, array{category: string, message: string, code: ?string}> */
    private array $errors = [];

    private int $nextId = 500;

    // ------------------------------------------------------------ test helpers

    /** Make every call to $method fail like the site would (until cleared). */
    public function failNext(string $method, string $category = 'server_error', string $message = 'Internal error on the public site.', ?string $code = null): void
    {
        $this->errors[$method] = ['category' => $category, 'message' => $message, 'code' => $code];
    }

    public function clearFailures(): void
    {
        $this->errors = [];
    }

    /** An earlier request used this key with another body (e.g. an older envelope format). */
    public function rememberKey(string $key, string $otherBody = '{"older":"envelope"}'): void
    {
        $this->idempotency[$key] = ['hash' => hash('sha256', $otherBody), 'response' => ['success' => true]];
    }

    /** @return list<string> every idempotency key the site has seen */
    public function seenKeys(): array
    {
        return array_keys($this->idempotency);
    }

    /** The text a reader sees for this answer right now, or null when it is not public. */
    public function liveBody(string $answerUuid): ?string
    {
        $a = $this->answers[$answerUuid] ?? null;

        return $a !== null && $a['status'] === 'published' ? $a['body'] : null;
    }

    public function getCalls(): array
    {
        return $this->calls;
    }

    public function getCallsFor(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === $method));
    }

    public function wasCalledWith(string $method): bool
    {
        return $this->getCallsFor($method) !== [];
    }

    // ------------------------------------------------------------ writes

    public function createIdentity(array $envelope): array
    {
        return $this->write(__FUNCTION__, [$envelope], $envelope, function () use ($envelope): array {
            $uuid = (string) $envelope['reach_identity_uuid'];
            $this->identities[$uuid] = ($this->identities[$uuid] ?? ['id' => $this->nextId++]) + [];
            $this->identities[$uuid] = array_merge($this->identities[$uuid], (array) ($envelope['payload'] ?? []));

            return ['operation' => 'create_identity', 'public_identity_id' => $this->identities[$uuid]['id'],
                'identity_slug' => (string) ($envelope['payload']['slug'] ?? '')];
        });
    }

    public function updateIdentity(string $externalId, array $envelope): array
    {
        return $this->write(__FUNCTION__, [$externalId, $envelope], $envelope, fn (): array => ['operation' => 'update_identity']);
    }

    public function createQuestion(array $envelope): array
    {
        return $this->write(__FUNCTION__, [$envelope], $envelope, function () use ($envelope): ?array {
            if ($e = $this->disclosureError($envelope)) {
                return $e;
            }
            $uuid     = (string) $envelope['reach_question_uuid'];
            $existing = $this->questions[$uuid] ?? null;
            $p        = (array) $envelope['payload'];
            $this->questions[$uuid] = [
                'id'       => $existing['id'] ?? $this->nextId++,
                'slug'     => $existing['slug'] ?? ('q-' . substr($uuid, 0, 8)),
                'title'    => (string) $p['title'],
                'body'     => (string) $p['body'],
                'category' => (string) ($p['category_slug'] ?? 'gst'),
                'status'   => (string) ($p['status'] ?? 'published'),
            ];

            return ['operation' => $existing ? 'update_question' : 'create_question', 'public_question_id' => $this->questions[$uuid]['id'],
                'public_question_slug' => $this->questions[$uuid]['slug'], 'public_status' => $this->questions[$uuid]['status'],
                'canonical_url' => $this->questionUrl($uuid)];
        });
    }

    public function createAnswer(array $envelope): array
    {
        return $this->write(__FUNCTION__, [$envelope], $envelope, function () use ($envelope): ?array {
            if ($e = $this->disclosureError($envelope)) {
                return $e;
            }
            $q = (string) $envelope['reach_question_uuid'];
            if (! isset($this->questions[$q]) && isset($envelope['public_question_id'])) {
                $this->questions[$q] = ['id' => (int) $envelope['public_question_id'], 'slug' => 'member-' . $envelope['public_question_id'],
                    'title' => 'member question', 'body' => '', 'category' => 'gst', 'status' => 'published'];
            }
            if (! isset($this->questions[$q])) {
                return $this->error('not_found', 'Parent question not found on the public site.', 'not_found');
            }
            $uuid    = (string) $envelope['reach_answer_uuid'];
            $version = (int) ($envelope['reach_content_version_number'] ?? 1);
            if (isset($this->answers[$uuid])) {
                // UPDATE … SET body = :body — the status is left as it is.
                $this->answers[$uuid]['body']    = (string) $envelope['payload']['body'];
                $this->answers[$uuid]['version'] = $version;
                $op = 'update_answer';
            } else {
                $this->answers[$uuid] = ['id' => $this->nextId++, 'question' => $q, 'body' => (string) $envelope['payload']['body'],
                    'status' => 'draft', 'version' => $version, 'revision' => 1, 'correction_note' => null, 'revisions' => [],
                    'ai_assisted' => (bool) ($envelope['payload']['ai_assisted'] ?? false)];
                $op = 'create_answer';
            }

            return $this->answerResponse($uuid, $op);
        });
    }

    public function updateAnswer(string $answerUuid, array $envelope): array
    {
        return $this->write(__FUNCTION__, [$answerUuid, $envelope], $envelope, function () use ($answerUuid, $envelope): array {
            if (! isset($this->answers[$answerUuid])) {
                return $this->error('not_found', 'Answer not found on the public site.', 'not_found');
            }
            $this->answers[$answerUuid]['body'] = (string) $envelope['payload']['body'];

            return $this->answerResponse($answerUuid, 'update_answer');
        });
    }

    public function publishAnswer(string $answerUuid, array $envelope): array
    {
        return $this->transition(__FUNCTION__, $answerUuid, $envelope, 'published', 'publish');
    }

    public function unpublishAnswer(string $answerUuid, array $envelope): array
    {
        return $this->transition(__FUNCTION__, $answerUuid, $envelope, 'unpublished', 'unpublish');
    }

    public function withdrawAnswer(string $answerUuid, array $envelope): array
    {
        return $this->transition(__FUNCTION__, $answerUuid, $envelope, 'withdrawn', 'withdraw');
    }

    public function restoreAnswer(string $answerUuid, array $envelope): array
    {
        return $this->transition(__FUNCTION__, $answerUuid, $envelope, 'published', 'restore');
    }

    public function applyCorrection(array $envelope): array
    {
        return $this->write(__FUNCTION__, [$envelope], $envelope, function () use ($envelope): array {
            $uuid = (string) ($envelope['reach_answer_uuid'] ?? '');
            $p    = (array) ($envelope['payload'] ?? []);
            if (trim((string) ($p['body'] ?? '')) === '' || trim((string) ($p['correction_note'] ?? '')) === '') {
                return $this->error('validation_error', "Field 'correction_note' is required.", 'validation_error');
            }
            if (! isset($this->answers[$uuid])) {
                return $this->error('not_found', 'Answer not found on the public site.', 'not_found');
            }
            $a = &$this->answers[$uuid];
            $a['revisions'][$a['revision']] = $a['body'];
            $previous           = $a['revision'];
            $a['revision']++;
            $a['body']            = (string) $p['body'];
            $a['correction_note'] = (string) $p['correction_note'];
            $a['version']         = (int) ($envelope['reach_content_version_number'] ?? $a['version']);
            unset($a);

            return $this->answerResponse($uuid, 'correct') + ['previous_revision' => $previous];
        });
    }

    public function createComment(array $envelope): array
    {
        return $this->write(__FUNCTION__, [$envelope], $envelope, fn (): array => ['operation' => 'create_comment', 'public_comment_id' => $this->nextId++, 'public_status' => 'published']);
    }

    public function applyModerationAction(array $envelope): array
    {
        return $this->write(__FUNCTION__, [$envelope], $envelope, fn (): array => ['operation' => 'moderation_action']);
    }

    // ------------------------------------------------------------ reads

    public function getAnswerStatus(string $answerUuid): array
    {
        return $this->read(__FUNCTION__, [$answerUuid], fn (): array => $this->statusOf($answerUuid, 'status'));
    }

    public function getAnswerVerification(string $answerUuid): array
    {
        return $this->read(__FUNCTION__, [$answerUuid], fn (): array => $this->statusOf($answerUuid, 'verify'));
    }

    public function getRecord(string $externalId): array
    {
        return $this->read(__FUNCTION__, [$externalId], fn (): array => ['success' => true, 'entity_type' => 'answer']);
    }

    public function reconcile(array $externalIds): array
    {
        return $this->read(__FUNCTION__, [$externalIds], fn (): array => ['success' => true, 'records' => [], 'missing' => []]);
    }

    public function getChanges(string $sinceIso8601, int $limit = 500): array
    {
        return $this->read(__FUNCTION__, [$sinceIso8601, $limit], fn (): array => ['success' => true, 'questions' => [], 'answers' => [], 'comments' => []]);
    }

    public function getMemberQuestions(string $sinceIso8601, int $afterId = 0, int $limit = 200): array
    {
        return $this->read(__FUNCTION__, [$sinceIso8601, $afterId, $limit], fn (): array => ['success' => true, 'questions' => [], 'next_cursor' => ['since' => $sinceIso8601, 'after_id' => $afterId], 'has_more' => false]);
    }

    public function healthCheck(): bool
    {
        return true;
    }

    // ------------------------------------------------------------ internals

    private function transition(string $method, string $uuid, array $envelope, string $status, string $op): array
    {
        return $this->write($method, [$uuid, $envelope], $envelope, function () use ($uuid, $envelope, $status, $op): ?array {
            if (! isset($this->answers[$uuid])) {
                return $this->error('not_found', 'Answer not found on the public site.', 'not_found');
            }
            if ($e = $this->disclosureError($envelope)) {
                return $e;
            }
            $body = trim((string) ($envelope['payload']['body'] ?? ''));
            if ($body !== '') {
                $this->answers[$uuid]['body'] = $body;
            }
            $this->answers[$uuid]['status']  = $status;
            $this->answers[$uuid]['version'] = (int) ($envelope['reach_content_version_number'] ?? $this->answers[$uuid]['version']);

            return $this->answerResponse($uuid, $op) + ['sitemap_status' => $status === 'published' ? 'included' : 'excluded'];
        });
    }

    /** The receiver's handle(): idempotency first, then the route; a 2xx is remembered. */
    private function write(string $method, array $args, array $envelope, callable $route): array
    {
        if (isset($this->errors[$method])) {
            $e = $this->errors[$method];

            return $this->log($method, $args, $this->error($e['category'], $e['message'], $e['code']));
        }

        $key  = (string) ($envelope['idempotency_key'] ?? '');
        $hash = hash('sha256', (string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($key !== '' && isset($this->idempotency[$key])) {
            if (! hash_equals($this->idempotency[$key]['hash'], $hash)) {
                return $this->log($method, $args, $this->error('version_conflict', 'Idempotency key was already used with a different request body.', 'idempotency_conflict'));
            }

            return $this->log($method, $args, $this->idempotency[$key]['response'] + ['idempotent_replay' => true]);
        }

        $response = $route();
        if (($response['success'] ?? true) !== false) {
            $response = ['success' => true] + $response;
            if ($key !== '') {
                $this->idempotency[$key] = ['hash' => $hash, 'response' => $response];
            }
            $uuid = (string) ($envelope['reach_answer_uuid'] ?? $envelope['reach_question_uuid'] ?? '');
            if ($uuid !== '') {
                $this->checksums[$uuid] = (string) ($envelope['payload_checksum'] ?? hash('sha256', (string) ($envelope['payload']['body'] ?? '')));
            }
        }

        return $this->log($method, $args, $response);
    }

    private function read(string $method, array $args, callable $route): array
    {
        if (isset($this->errors[$method])) {
            $e = $this->errors[$method];

            return $this->log($method, $args, $this->error($e['category'], $e['message'], $e['code']));
        }

        return $this->log($method, $args, $route());
    }

    private function statusOf(string $uuid, string $op): array
    {
        if (! isset($this->answers[$uuid])) {
            return $this->error('not_found', 'Answer not found on the public site.', 'not_found');
        }
        $a = $this->answers[$uuid];

        return [
            'success'          => true,
            'operation'        => $op,
            'public_answer_id' => $a['id'],
            'public_status'    => $a['status'],
            'payload_checksum' => $this->checksums[$uuid] ?? '',
            'canonical_url'    => $this->questionUrl($a['question']) . '#answer-' . $a['id'],
            'robots_directive' => $a['status'] === 'published' ? 'index,follow' : 'noindex,follow',
            'sitemap_status'   => $a['status'] === 'published' ? 'included' : 'excluded',
            'public_version'   => $a['version'],
            'revision_number'  => $a['revision'],
        ];
    }

    private function answerResponse(string $uuid, string $op): array
    {
        $a = $this->answers[$uuid];

        return [
            'operation'          => $op,
            'public_answer_id'   => $a['id'],
            'public_answer_uuid' => $uuid,
            'public_status'      => $a['status'],
            'canonical_url'      => $this->questionUrl($a['question']) . '#answer-' . $a['id'],
            'public_version'     => $a['version'],
            'revision_number'    => $a['revision'],
        ];
    }

    private function questionUrl(string $questionUuid): string
    {
        return 'https://www.aicountly.com/community/question/' . ($this->questions[$questionUuid]['slug'] ?? 'unknown');
    }

    /** guardDisclosure(): official-bot content must declare ai_assisted = true. */
    private function disclosureError(array $envelope): ?array
    {
        $payload = (array) ($envelope['payload'] ?? []);
        $author  = strtolower((string) ($payload['author_type'] ?? $envelope['author_type'] ?? ''));
        if ($author === '') {
            $author = ($envelope['official_identity_slug'] ?? '') !== '' ? 'official_bot' : 'human';
        }
        if ($author === 'official_bot' && empty($payload['ai_assisted'])) {
            return $this->error('validation_error', 'Official bot content must declare ai_assisted = true.', 'disclosure_required');
        }

        return null;
    }

    private function error(string $category, string $message, ?string $code): array
    {
        return ['success' => false, 'error_category' => $category, 'safe_error_message' => $message, 'remote_error_code' => $code];
    }

    private function log(string $method, array $args, array $response): array
    {
        $this->calls[] = ['method' => $method, 'args' => $args, 'response' => $response];

        return $response;
    }
}

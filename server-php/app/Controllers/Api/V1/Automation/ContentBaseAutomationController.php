<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Automation;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Content\Base\ContentBaseEntries;
use App\Libraries\Content\Base\ContentBaseSync;
use App\Libraries\Content\Base\RoutineTopicQueue;

/**
 * The living content base, for the Claude routines (X-Automation-Token).
 *
 *   GET  v1/automation/content-base/next?type=blog|kb|community[&limit=10]
 *        the next topics to write, in order (blog: regeneration → roadmap →
 *        content base), each with the content_base_key to submit under
 *   POST v1/automation/content-base/propose  {type, items:[…], routine_run_id}
 *        new topics derived from real product changes / official sources
 *   POST v1/automation/content-base/produced {type, key, outcome, content_base_key?, reason?, routine_run_id}
 *        "I submitted it" (Reach links and reports the real status) or
 *        "not applicable" (the product does not do this — retire it)
 *
 * Nothing here touches git: topics and their progress live in
 * reach_content_base_entries, so routines never push and deploys never reset
 * progress. Responses: 200, 201 (proposal accepted), 404 unknown key,
 * 409 nothing submitted under that key, 422 invalid input, 503 not migrated.
 */
class ContentBaseAutomationController extends BaseApiController
{
    public function next()
    {
        $type = $this->type((string) $this->request->getGet('type'));
        if ($type === null) {
            return $this->fail('type must be blog, kb or community', 422);
        }
        $entries = new ContentBaseEntries();
        if (! $entries->available()) {
            return $this->fail('Content base is not migrated on this server (php spark migrate).', 503);
        }
        if ($entries->isEmpty()) {
            (new ContentBaseSync())->run();
        }
        $entries->reconcile($type);

        return $this->ok((new RoutineTopicQueue(null, $entries))->next($type, (int) ($this->request->getGet('limit') ?: 10)));
    }

    public function propose()
    {
        $input = $this->input();
        $type  = $this->type((string) ($input['type'] ?? ''));
        if ($type === null) {
            return $this->fail('type must be blog, kb or community', 422);
        }
        $entries = new ContentBaseEntries();
        if (! $entries->available()) {
            return $this->fail('Content base is not migrated on this server (php spark migrate).', 503);
        }

        try {
            $result = $entries->propose($type, array_values((array) ($input['items'] ?? [])), $this->runId($input));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($result + ['type' => $type], $result['accepted'] > 0 ? 201 : 200);
    }

    public function produced()
    {
        $input = $this->input();
        $type  = $this->type((string) ($input['type'] ?? ''));
        if ($type === null) {
            return $this->fail('type must be blog, kb or community', 422);
        }
        $entries = new ContentBaseEntries();
        if (! $entries->available()) {
            return $this->fail('Content base is not migrated on this server (php spark migrate).', 503);
        }

        try {
            return $this->ok($entries->report($type, $input, $this->runId($input)) + ['type' => $type]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage(), in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 409);
        }
    }

    private function type(string $raw): ?string
    {
        return ContentBaseEntries::normalizeType($raw);
    }

    /** @param array<string,mixed> $input */
    private function runId(array $input): ?string
    {
        $id = $input['routine_run_id'] ?? ($input['provenance']['routine_run_id'] ?? null);

        return $id !== null ? mb_substr(trim((string) $id), 0, 191) : null;
    }
}

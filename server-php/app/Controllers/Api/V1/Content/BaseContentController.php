<?php

namespace App\Controllers\Api\V1\Content;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\ContentWorkflowService;
use App\Libraries\HtmlSanitizer;
use App\Models\Content\ContentItemModel;

/**
 * Shared scaffolding for all Phase 2 content resource controllers.
 *
 * Provides helpers for: actor extraction, content-item lookup, workflow
 * transition, and audit logging. CRUD controllers extend this class.
 */
abstract class BaseContentController extends BaseApiController
{
    protected ContentItemModel     $contentItems;
    protected ContentWorkflowService $workflow;
    protected HtmlSanitizer        $sanitizer;
    protected AuditLogger          $auditLogger;

    public function __construct()
    {
        $this->contentItems = new ContentItemModel();
        $this->workflow     = new ContentWorkflowService();
        $this->sanitizer    = new HtmlSanitizer();
        $this->auditLogger  = new AuditLogger();
    }

    /** Extract actor array from the current request context. */
    protected function actor(): array
    {
        return [
            'id'      => $this->userId(),
            'type'    => 'human',
            'service' => 'reach:api',
            'role'    => $this->user()['role'] ?? null,
        ];
    }

    /** Find content item or return 404 response. */
    protected function findItem(int|string $id): array|\CodeIgniter\HTTP\ResponseInterface
    {
        $item = is_numeric($id) ? $this->contentItems->find((int) $id) : $this->contentItems->findBySlug((string) $id);
        if (!$item) {
            return $this->fail('Content item not found.', 404);
        }
        return $item;
    }

    /** Helper: transition with unified error handling. */
    protected function transitionItem(int $id, string $newStatus, string $reason = ''): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $item = $this->workflow->transition($id, $newStatus, $this->actor(), $reason);
            return $this->ok($item);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /** Sanitise HTML fields in an input array. */
    protected function sanitiseHtmlFields(array $data, array $fields = ['body_html']): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->sanitizer->purify($data[$field]);
            }
        }
        return $data;
    }
}

<?php

namespace App\Libraries;

use App\Models\Content\ContentCommentModel;
use App\Libraries\HtmlSanitizer;

/**
 * Manages threaded editorial comments on content items.
 *
 * All body text is sanitised via HtmlSanitizer before storage.
 * Comments are internal-only by default.
 */
class ContentCommentService
{
    private ContentCommentModel $comments;
    private HtmlSanitizer       $sanitizer;
    private AuditLogger         $audit;

    public function __construct()
    {
        $this->comments  = new ContentCommentModel();
        $this->sanitizer = new HtmlSanitizer();
        $this->audit     = new AuditLogger();
    }

    public function addComment(
        int $contentItemId,
        string $body,
        array $options = [],
        array $actor = []
    ): array {
        $clean = $this->sanitizer->purify($body);

        $id = $this->comments->insert([
            'content_item_id'    => $contentItemId,
            'version_id'         => $options['version_id'] ?? null,
            'parent_comment_id'  => $options['parent_comment_id'] ?? null,
            'body'               => $clean,
            'internal_only'      => $options['internal_only'] ?? true,
            'created_by'         => $actor['id'] ?? null,
            'created_actor_type' => $actor['type'] ?? 'human',
        ], true);

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_COMMENTED, 'content', $contentItemId, null, null, [
            'comment_id'        => $id,
            'parent_comment_id' => $options['parent_comment_id'] ?? null,
        ]);

        return $this->comments->find($id);
    }

    public function resolve(int $commentId, array $actor = []): array
    {
        $comment = $this->comments->find($commentId);
        if (!$comment) {
            throw new \RuntimeException("Comment {$commentId} not found.");
        }
        if ($comment['resolved_at']) {
            return $comment;
        }

        $this->comments->update($commentId, [
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolved_by' => $actor['id'] ?? null,
        ]);

        return $this->comments->find($commentId);
    }

    public function getThread(int $contentItemId, bool $includeResolved = false): array
    {
        $roots = $this->comments->threadForItem($contentItemId, $includeResolved);
        foreach ($roots as &$root) {
            $root['replies'] = $this->comments->repliesFor($root['id']);
        }
        return $roots;
    }

    public function delete(int $commentId, array $actor = []): void
    {
        $this->comments->delete($commentId);
        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_COMMENT_DELETED, 'content', null, null, null, [
            'comment_id' => $commentId,
        ]);
    }
}

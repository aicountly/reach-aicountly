<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalModel;
use App\Models\BlogPostModel;
use App\Models\BlogVersionModel;
use Config\Services;

class BlogController extends BaseApiController
{
    /** Ordered blog workflow states. */
    public const WORKFLOW = [
        'idea', 'draft', 'seo_review', 'internal_review',
        'approved', 'scheduled', 'published', 'rejected', 'archived',
    ];

    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q      = new BlogPostModel();
        $status = trim((string) $this->request->getGet('status'));
        $search = trim((string) $this->request->getGet('search'));
        if ($status !== '') {
            $q->where('status', $status);
        }
        if ($search !== '') {
            $q->groupStart()
                ->like('title', $search)
                ->orLike('slug', $search)
                ->orLike('focus_keyword', $search)
              ->groupEnd();
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('updated_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new BlogPostModel())->find($id);
        if (! $row) {
            return $this->fail('Blog post not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $blog = new BlogPostModel();
        $slug = $this->uniqueSlug($blog, (string) ($body['slug'] ?? $body['title'] ?? 'untitled'));
        $row  = [
            'title'           => (string) ($body['title'] ?? 'Untitled'),
            'slug'            => $slug,
            'excerpt'         => $body['excerpt']         ?? null,
            'content'         => (string) ($body['content'] ?? ''),
            'category'        => $body['category']        ?? null,
            'tags'            => isset($body['tags']) ? json_encode($body['tags']) : null,
            'seo_title'       => $body['seo_title']       ?? null,
            'seo_description' => $body['seo_description'] ?? null,
            'canonical_url'   => $body['canonical_url']   ?? null,
            'focus_keyword'   => $body['focus_keyword']   ?? null,
            'author'          => $body['author']          ?? null,
            'featured_image'  => $body['featured_image']  ?? null,
            'status'          => in_array($body['status'] ?? 'draft', self::WORKFLOW, true) ? $body['status'] : 'draft',
            'scheduled_at'    => $body['scheduled_at']    ?? null,
            'bot_generated'   => (bool) ($body['bot_generated'] ?? false),
            'current_version' => 1,
            'created_by'      => $this->userId(),
        ];
        $blog->insert($row);
        $id = (int) $blog->db->insertID();
        $this->snapshot($id, 1, 'initial', $row);
        $this->audit('blog.create', 'blog', $id, null, $row);
        return $this->ok($blog->find($id), 201);
    }

    public function update(int $id)
    {
        $blog = new BlogPostModel();
        $row  = $blog->find($id);
        if (! $row) {
            return $this->fail('Blog post not found.', 404);
        }
        $body   = $this->input();
        $update = array_intersect_key($body, array_flip([
            'title', 'slug', 'excerpt', 'content', 'category', 'tags',
            'seo_title', 'seo_description', 'canonical_url', 'focus_keyword',
            'author', 'featured_image', 'scheduled_at',
        ]));
        if (isset($update['slug'])) {
            $update['slug'] = $this->uniqueSlug($blog, (string) $update['slug'], $id);
        }
        if (isset($update['tags']) && is_array($update['tags'])) {
            $update['tags'] = json_encode($update['tags']);
        }
        $newVersion = ((int) ($row['current_version'] ?? 1)) + 1;
        $update['current_version'] = $newVersion;
        $blog->update($id, $update);
        $this->snapshot($id, $newVersion, (string) ($body['change_reason'] ?? 'edit'), $update);
        $this->audit('blog.update', 'blog', $id, $row, $update);
        return $this->ok($blog->find($id));
    }

    public function destroy(int $id)
    {
        $blog = new BlogPostModel();
        $row  = $blog->find($id);
        if (! $row) {
            return $this->fail('Blog post not found.', 404);
        }
        $blog->update($id, ['status' => 'archived']);
        $this->audit('blog.archive', 'blog', $id, $row, ['status' => 'archived']);
        return $this->ok(['message' => 'Archived.']);
    }

    public function transition(int $id)
    {
        $blog = new BlogPostModel();
        $row  = $blog->find($id);
        if (! $row) {
            return $this->fail('Blog post not found.', 404);
        }
        $target = (string) ($this->input()['status'] ?? '');
        if (! in_array($target, self::WORKFLOW, true)) {
            return $this->fail('Invalid workflow status.', 422);
        }
        $blog->update($id, ['status' => $target]);
        $this->audit('blog.transition', 'blog', $id, ['status' => $row['status']], ['status' => $target]);
        return $this->ok($blog->find($id));
    }

    public function approve(int $id)
    {
        $blog = new BlogPostModel();
        $row  = $blog->find($id);
        if (! $row) {
            return $this->fail('Blog post not found.', 404);
        }
        $blog->update($id, [
            'approval_status' => 'approved',
            'approved_by'     => $this->userId(),
            'approved_at'     => date('Y-m-d H:i:s'),
            'status'          => 'approved',
        ]);
        (new ApprovalModel())->insert([
            'subject_type' => 'blog',
            'subject_id'   => $id,
            'summary'      => 'Blog approved',
            'requested_by' => $row['created_by'],
            'decision'     => 'approved',
            'decided_by'   => $this->userId(),
            'decided_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->audit('blog.approve', 'blog', $id, ['status' => $row['status']], ['status' => 'approved']);
        return $this->ok($blog->find($id));
    }

    public function reject(int $id)
    {
        $blog = new BlogPostModel();
        $row  = $blog->find($id);
        if (! $row) {
            return $this->fail('Blog post not found.', 404);
        }
        $note = (string) ($this->input()['note'] ?? '');
        $blog->update($id, [
            'approval_status' => 'rejected',
            'status'          => 'rejected',
        ]);
        (new ApprovalModel())->insert([
            'subject_type' => 'blog',
            'subject_id'   => $id,
            'summary'      => 'Blog rejected: ' . $note,
            'requested_by' => $row['created_by'],
            'decision'     => 'rejected',
            'decided_by'   => $this->userId(),
            'decided_at'   => date('Y-m-d H:i:s'),
            'note'         => $note,
        ]);
        $this->audit('blog.reject', 'blog', $id, ['status' => $row['status']], ['status' => 'rejected', 'note' => $note]);
        return $this->ok($blog->find($id));
    }

    public function publish(int $id)
    {
        $blog = new BlogPostModel();
        $row  = $blog->find($id);
        if (! $row) {
            return $this->fail('Blog post not found.', 404);
        }
        if (($row['status'] ?? '') !== 'approved' && ($row['status'] ?? '') !== 'scheduled') {
            return $this->fail('Only approved or scheduled blogs can be published.', 422);
        }
        // Placeholder publish. Falls back to pending_publishing if not configured.
        $result = Services::sitePublisher()->publish($row);
        $this->audit('publish.blog', 'blog', $id, ['publishing_status' => $row['publishing_status']], $result);
        return $this->ok($blog->find($id));
    }

    private function snapshot(int $blogPostId, int $version, string $reason, array $data): void
    {
        (new BlogVersionModel())->insert([
            'blog_post_id'  => $blogPostId,
            'version'       => $version,
            'snapshot'      => json_encode($data, JSON_UNESCAPED_SLASHES),
            'changed_by'    => $this->userId(),
            'change_reason' => $reason,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    private function uniqueSlug(BlogPostModel $blog, string $base, ?int $excludeId = null): string
    {
        $slug = strtolower(trim($base));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-') ?: 'post';
        $try  = $slug;
        $n    = 1;
        while (true) {
            $q = $blog->where('slug', $try);
            if ($excludeId) {
                $q->where('id !=', $excludeId);
            }
            if ($q->countAllResults() === 0) {
                return $try;
            }
            $try = $slug . '-' . (++$n);
        }
    }
}

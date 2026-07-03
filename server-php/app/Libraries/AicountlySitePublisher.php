<?php

namespace App\Libraries;

use App\Models\BlogPostModel;

/**
 * Blog publisher for AICOUNTLY.com.
 *
 * AICOUNTLY.com has no write API today (blog rows live in a shared
 * PostgreSQL table written by Flow). Reach uses a **placeholder** HTTP
 * publisher: if `AICOUNTLY_SITE_API_BASE_URL` and `AICOUNTLY_SITE_API_TOKEN`
 * are both set we POST to `{base}/blog/posts`; otherwise we mark the blog
 * post as `publishing_status = pending_publishing` and leave a hint for the
 * superadmin to configure publishing.
 *
 * Callers should never assume the post reached AICOUNTLY.com — inspect the
 * returned publishing_status and publishing_error fields on the blog row.
 */
class AicountlySitePublisher
{
    private string $baseUrl;
    private string $token;
    private BlogPostModel $blog;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env('AICOUNTLY_SITE_API_BASE_URL', ''), '/');
        $this->token   = (string) env('AICOUNTLY_SITE_API_TOKEN', '');
        $this->blog    = new BlogPostModel();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * @return array{
     *   status:string,          // pending_publishing|published|failed
     *   publishing_error:?string,
     *   external_post_id:?string,
     *   response?:array,
     * }
     */
    public function publish(array $post): array
    {
        $postId = (int) ($post['id'] ?? 0);

        if (! $this->isConfigured()) {
            $this->blog->update($postId, [
                'publishing_status' => 'pending_publishing',
                'publishing_error'  => 'AICOUNTLY_SITE_API_BASE_URL / AICOUNTLY_SITE_API_TOKEN not configured; approved post held for manual publishing.',
            ]);
            return [
                'status'           => 'pending_publishing',
                'publishing_error' => 'Publisher not configured',
                'external_post_id' => null,
            ];
        }

        // Mark as publishing (in-flight).
        $this->blog->update($postId, [
            'publishing_status' => 'publishing',
            'publishing_error'  => null,
        ]);

        $body = $this->buildBody($post);
        $ch   = curl_init($this->baseUrl . '/blog/posts');
        if ($ch === false) {
            $this->blog->update($postId, [
                'publishing_status' => 'failed',
                'publishing_error'  => 'curl_init failed',
            ]);
            return ['status' => 'failed', 'publishing_error' => 'curl_init failed', 'external_post_id' => null];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'X-Source: reach.aicountly.org',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $ok      = $raw !== false && $status >= 200 && $status < 300;

        if (! $ok) {
            $errorMsg = $err !== '' ? $err : (is_string($raw) ? substr($raw, 0, 500) : ('HTTP ' . $status));
            $this->blog->update($postId, [
                'publishing_status' => 'failed',
                'publishing_error'  => $errorMsg,
            ]);
            return [
                'status'           => 'failed',
                'publishing_error' => $errorMsg,
                'external_post_id' => null,
                'response'         => is_array($decoded) ? $decoded : [],
            ];
        }

        $externalId = null;
        if (is_array($decoded)) {
            $externalId = (string) (
                $decoded['data']['id']
                ?? $decoded['data']['post']['id']
                ?? $decoded['id']
                ?? ''
            );
            $externalId = $externalId !== '' ? $externalId : null;
        }

        $this->blog->update($postId, [
            'publishing_status' => 'published',
            'publishing_error'  => null,
            'external_post_id'  => $externalId,
            'published_at'      => date('Y-m-d H:i:s'),
            'status'            => 'published',
        ]);

        return [
            'status'           => 'published',
            'publishing_error' => null,
            'external_post_id' => $externalId,
            'response'         => is_array($decoded) ? $decoded : [],
        ];
    }

    private function buildBody(array $post): array
    {
        return array_filter([
            'title'           => $post['title']           ?? '',
            'slug'            => $post['slug']            ?? '',
            'excerpt'         => $post['excerpt']         ?? null,
            'content'         => $post['content']         ?? '',
            'category'        => $post['category']        ?? null,
            'tags'            => $post['tags']            ?? null,
            'seo_title'       => $post['seo_title']       ?? null,
            'seo_description' => $post['seo_description'] ?? null,
            'canonical_url'   => $post['canonical_url']   ?? null,
            'focus_keyword'   => $post['focus_keyword']   ?? null,
            'author'          => $post['author']          ?? null,
            'featured_image'  => $post['featured_image']  ?? null,
            'published_at'    => $post['scheduled_at']    ?? date(DATE_ATOM),
            'source'          => 'reach.aicountly.org',
        ], static fn ($v) => $v !== null && $v !== '');
    }
}

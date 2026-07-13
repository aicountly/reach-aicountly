<?php

namespace App\Libraries\Publishing\Connector;

/**
 * Phase 4 — Test/mock publisher.
 *
 * Used in all automated tests and when REACH_PUB_MOCK=true.
 * Never makes real HTTP calls.
 * Stores calls in memory for assertion.
 */
class MockPublicSitePublisher implements PublicSitePublisherInterface
{
    /** @var array<int, array> */
    private array $calls = [];

    /** Next mocked public_content_id counter */
    private int $nextId = 1;

    /** Can be set to simulate failures */
    private ?string $forceErrorCategory = null;

    public function forceError(?string $category): void
    {
        $this->forceErrorCategory = $category;
    }

    public function getCalls(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->nextId = 1;
        $this->forceErrorCategory = null;
    }

    public function createDraft(array $envelope): array
    {
        $this->record('createDraft', $envelope);

        if ($this->forceErrorCategory) {
            return $this->err($this->forceErrorCategory);
        }

        $id   = $this->nextId++;
        $uuid = 'mock-' . $id . '-' . substr(md5($id), 0, 8);

        return [
            'success'                 => true,
            'operation'               => 'create_draft',
            'public_content_id'       => $id,
            'public_content_uuid'     => $uuid,
            'public_status'           => 'draft',
            'received_reach_version'  => $envelope['reach_content_version_number'] ?? 1,
            'payload_checksum'        => $envelope['payload_checksum'] ?? '',
            'request_id'              => $envelope['request_id'] ?? 'mock-req',
            'idempotent_replay'       => false,
        ];
    }

    public function updateDraft(int $publicContentId, array $envelope): array
    {
        $this->record('updateDraft', ['public_content_id' => $publicContentId, 'envelope' => $envelope]);

        if ($this->forceErrorCategory) {
            return $this->err($this->forceErrorCategory);
        }

        return [
            'success'          => true,
            'operation'        => 'update_draft',
            'public_content_id'=> $publicContentId,
            'public_status'    => 'draft',
        ];
    }

    public function publish(int $publicContentId, array $envelope): array
    {
        $this->record('publish', ['public_content_id' => $publicContentId, 'envelope' => $envelope]);

        if ($this->forceErrorCategory) {
            return $this->err($this->forceErrorCategory);
        }

        return [
            'success'          => true,
            'operation'        => 'publish',
            'public_content_id'=> $publicContentId,
            'public_status'    => 'published',
            'canonical_url'    => 'https://aicountly.com/blog/mock-' . $publicContentId,
            'public_version'   => 1,
            'published_at'     => date('Y-m-d\TH:i:s\Z'),
            'sitemap_status'   => 'included',
        ];
    }

    public function schedule(int $publicContentId, array $envelope, string $scheduledAt): array
    {
        $this->record('schedule', ['public_content_id' => $publicContentId, 'scheduled_at' => $scheduledAt]);

        if ($this->forceErrorCategory) {
            return $this->err($this->forceErrorCategory);
        }

        return [
            'success'          => true,
            'operation'        => 'schedule',
            'public_content_id'=> $publicContentId,
            'public_status'    => 'scheduled',
            'scheduled_at'     => $scheduledAt,
        ];
    }

    public function unpublish(int $publicContentId, string $reason): array
    {
        $this->record('unpublish', ['public_content_id' => $publicContentId, 'reason' => $reason]);

        if ($this->forceErrorCategory) {
            return $this->err($this->forceErrorCategory);
        }

        return ['success' => true, 'operation' => 'unpublish', 'public_content_id' => $publicContentId, 'public_status' => 'draft'];
    }

    public function restore(int $publicContentId, array $envelope): array
    {
        $this->record('restore', ['public_content_id' => $publicContentId]);

        if ($this->forceErrorCategory) {
            return $this->err($this->forceErrorCategory);
        }

        return ['success' => true, 'operation' => 'restore', 'public_content_id' => $publicContentId, 'public_status' => 'published'];
    }

    public function getStatus(int $publicContentId): array
    {
        $this->record('getStatus', ['public_content_id' => $publicContentId]);

        return [
            'success'          => true,
            'public_content_id'=> $publicContentId,
            'public_status'    => 'published',
        ];
    }

    public function getVerification(int $publicContentId): array
    {
        $this->record('getVerification', ['public_content_id' => $publicContentId]);

        return [
            'success'              => true,
            'operation'            => 'verify',
            'public_content_id'    => $publicContentId,
            'public_status'        => 'published',
            'canonical_url'        => 'https://aicountly.com/blog/mock-' . $publicContentId,
            'public_version'       => 1,
            'payload_checksum'     => 'mock-checksum',
            'reach_content_version'=> 1,
            'title'                => 'Mock Article',
            'body_hash'            => 'mock-body-hash',
            'structured_data_types'=> ['BlogPosting'],
            'sitemap_status'       => 'included',
            'robots_directive'     => 'index,follow',
            'updated_at'           => date('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function triggerVerification(int $publicContentId): array
    {
        return $this->getVerification($publicContentId);
    }

    public function healthCheck(): bool
    {
        $this->record('healthCheck', []);
        return true;
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args, 'at' => microtime(true)];
    }

    private function err(string $category): array
    {
        return [
            'success'           => false,
            'error_category'    => $category,
            'safe_error_message'=> 'Mock forced error: ' . $category,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Publishing\Structured;

/**
 * Payload for the structured receiver types (`release_note`,
 * `workflow_guide`; aicountly-com docs/REACH_RECEIVER_CONTENT_TYPES.md §4–§5).
 *
 * Unlike blog/KB there is nothing to assemble: the approved content version
 * stores, in structured_payload, the exact vetted and validated object to
 * send (StructuredSubmissionService writes it). Building just reads it back,
 * so what was approved is byte-for-byte what is published.
 */
class StructuredPublicationPayloadBuilder
{
    public const TYPES = ['release_note', 'workflow_guide'];

    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public static function handles(string $contentType): bool
    {
        return in_array($contentType, self::TYPES, true);
    }

    /**
     * @return array<string, mixed>
     * @throws \RuntimeException when the version holds no payload
     */
    public function build(int $contentItemId, int $contentVersionId): array
    {
        $row = $this->db->table('reach_content_versions')
            ->select('structured_payload')
            ->where('id', $contentVersionId)
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        $payload = $row['structured_payload'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (! is_array($payload) || $payload === []) {
            throw new \RuntimeException("Content version {$contentVersionId} has no structured payload to publish");
        }

        return $payload;
    }

    /** Same encoding the receiver hashes (docs §1, payload_checksum). */
    public function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

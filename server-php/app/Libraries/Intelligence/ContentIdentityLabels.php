<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;

/**
 * What a list row needs to name a content identity: its type, URL and — for
 * blogs, whose source_id is a reach_content_items id — the item's title and
 * id, so a row can open /content/:id. One query for a whole page of rows.
 */
final class ContentIdentityLabels
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    /**
     * @param list<int|string|null> $ids
     * @return array<int, array{content_identity_id:int, content_type:?string, canonical_url:?string, content_item_id:?int, title:?string}>
     */
    public function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || ! SchemaGuard::hasTable($this->db(), 'reach_content_identities')) {
            return [];
        }

        $hasItems = SchemaGuard::hasTable($this->db(), 'reach_content_items');
        $builder  = $this->db()->table('reach_content_identities ci')
            ->select('ci.id, ci.content_type, ci.canonical_url, ci.source_id' . ($hasItems ? ', ct.id AS item_id, ct.title' : ''))
            ->whereIn('ci.id', $ids);
        if ($hasItems) {
            // RawSql: a string ON clause would escape the 'blog' literal as an identifier.
            $builder->join('reach_content_items ct', new RawSql("ct.id = ci.source_id AND ci.content_type = 'blog'"), 'left');
        }

        $out = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $out[(int) $row['id']] = [
                'content_identity_id' => (int) $row['id'],
                'content_type'        => $row['content_type'] ?? null,
                'canonical_url'       => $row['canonical_url'] ?? null,
                'content_item_id'     => isset($row['item_id']) ? (int) $row['item_id'] : null,
                'title'               => $row['title'] ?? null,
            ];
        }

        return $out;
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}

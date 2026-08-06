<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Jobs\PublicationRollbackService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Taking an article down must take its URL out of the internal-link registry.
 *
 * PublicationDeploymentService records a `/blogs/<slug>` entry on publish, and
 * BlogPublicationPayloadBuilder feeds active registry paths into the "Related
 * reading" block of every article it publishes next. LinkRegistryService has
 * always had retire(), and nothing ever called it — so an unpublished article
 * stayed 'active' and newly published posts could link straight to a dead URL.
 *
 * Rollback is deliberately excluded: a rolled-back article is still live at
 * the same URL, showing a prior version.
 */
final class LinkRegistryRetirementTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['REACH_PUB_MOCK'] = 'true';
    }

    /** @return array{item:int, deployment:int} */
    private function seedPublished(string $title, string $slug, string $contentType = 'blog'): array
    {
        $db = Database::connect();

        $db->table('reach_content_items')->insert([
            'title'           => $title,
            'slug'            => $slug,
            'content_type'    => $contentType,
            'workflow_status' => 'published',
        ]);
        $itemId = (int) $db->insertID();

        $db->table('reach_content_versions')->insert([
            'content_item_id' => $itemId,
            'version_number'  => 1,
            'title'           => $title,
            'is_current'      => true,
        ]);
        $versionId = (int) $db->insertID();

        $db->table('reach_publication_connections')->insert([
            'connection_key' => 'test-' . bin2hex(random_bytes(4)),
            'display_name'   => 'Test Site',
            'base_url'       => 'https://example.test',
        ]);
        $connectionId = (int) $db->insertID();

        $db->table('reach_publication_deployments')->insert([
            'content_item_id'    => $itemId,
            'content_version_id' => $versionId,
            'connection_id'      => $connectionId,
            'operation'          => 'publish',
            'status'             => 'published',
            'idempotency_key'    => bin2hex(random_bytes(12)),
            'public_content_id'  => 4242,
        ]);
        $deploymentId = (int) $db->insertID();

        $prefix = $contentType === 'knowledge_base' ? '/help/' : '/blogs/';
        $db->table('reach_link_registry')->insert([
            'url_path'        => $prefix . $slug,
            'title'           => $title,
            'link_type'       => $contentType === 'knowledge_base' ? 'knowledge_base' : 'blog',
            'content_item_id' => $itemId,
        ]);

        return ['item' => $itemId, 'deployment' => $deploymentId];
    }

    private function registryStatus(int $itemId): ?string
    {
        $row = Database::connect()->table('reach_link_registry')
            ->where('content_item_id', $itemId)->get()->getRowArray();

        return $row['status'] ?? null;
    }

    public function testUnpublishRetiresTheRegistryEntry(): void
    {
        $seed = $this->seedPublished('MCA annual filing calendar', 'mca-annual-filing-calendar');

        $this->assertSame('active', $this->registryStatus($seed['item']));

        $ok = (new PublicationRollbackService())->unpublish($seed['deployment'], 'Taken down for review');

        $this->assertTrue($ok);
        $this->assertSame(
            'retired',
            $this->registryStatus($seed['item']),
            'A dead URL must stop being suggested as related reading',
        );
    }

    public function testRollbackLeavesTheRegistryEntryActive(): void
    {
        // A rollback reverts the version; the article stays live at that URL.
        $seed = $this->seedPublished('GST filing guide', 'gst-filing-guide');

        (new PublicationRollbackService())->rollback($seed['deployment'], 'Reverting a bad edit');

        $this->assertSame('active', $this->registryStatus($seed['item']));
    }

    public function testKnowledgeBaseEntriesRetireOnTheirOwnPrefix(): void
    {
        $seed = $this->seedPublished('KB article', 'kb-article', 'knowledge_base');

        (new PublicationRollbackService())->unpublish($seed['deployment'], 'Taken down');

        $this->assertSame('retired', $this->registryStatus($seed['item']));
    }

    public function testARetiredEntryIsNoLongerSuggestedAsRelatedReading(): void
    {
        $seed = $this->seedPublished('MCA annual filing calendar', 'mca-annual-filing-calendar');
        Database::connect()->table('reach_link_registry')
            ->where('content_item_id', $seed['item'])
            ->update(['category' => 'compliance']);

        $registry = new \App\Libraries\Publishing\LinkRegistryService(Database::connect());
        $path     = '/blogs/mca-annual-filing-calendar';

        $before = array_column($registry->suggestFor(null, 'compliance', null), 'url_path');
        $this->assertContains($path, $before);

        (new PublicationRollbackService())->unpublish($seed['deployment'], 'Taken down');

        // suggestFor() backfills with evergreen product/guide pages, so the
        // assertion is that this path is gone — not that nothing is suggested.
        $after = array_column($registry->suggestFor(null, 'compliance', null), 'url_path');
        $this->assertNotContains(
            $path,
            $after,
            'The whole point: a taken-down article stops being linkable',
        );
    }

    public function testAMissingRegistryEntryDoesNotBreakTheTakedown(): void
    {
        $seed = $this->seedPublished('No registry row', 'no-registry-row');
        Database::connect()->table('reach_link_registry')
            ->where('content_item_id', $seed['item'])->delete();

        $this->assertTrue(
            (new PublicationRollbackService())->unpublish($seed['deployment'], 'Taken down'),
            'The takedown must never fail because of registry bookkeeping',
        );
    }
}

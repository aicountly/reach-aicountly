<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\LinkRegistryService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Migrations 2026-10-23-240001 / 240002: the Aicountly Academy course pages
 * join the link registry (suggested only for academy articles, never as
 * evergreen "Related reading" under a GST post), and seeded brand text is
 * re-cased to "Aicountly".
 */
final class AcademyLinkRegistryTest extends DatabaseTestCase
{
    public function testTheCoursePagesAreRegisteredAsAcademyLinks(): void
    {
        $rows = Database::connect()->table('reach_link_registry')
            ->whereIn('url_path', ['/academy', '/edge', '/aspire'])
            ->orderBy('url_path')->get()->getResultArray();

        $this->assertSame(['/academy', '/aspire', '/edge'], array_column($rows, 'url_path'));
        foreach ($rows as $row) {
            $this->assertSame('product', $row['link_type']);
            $this->assertSame('academy', $row['category']);
            $this->assertSame('active', $row['status']);
            $this->assertStringStartsWith('Aicountly ', $row['title']);
        }
        $this->assertSame(['academy', 'aspire', 'edge'], array_column($rows, 'product_slug'));
    }

    public function testCoursePagesFollowCourseArticlesOnly(): void
    {
        $svc = new LinkRegistryService(Database::connect());

        $edge = array_column($svc->suggestFor('edge', 'accounting', null, 3), 'url_path');
        $this->assertSame('/edge', $edge[0]);

        foreach ([[null, 'gst'], ['books', 'accounting'], [null, null]] as [$product, $category]) {
            $paths = array_column($svc->suggestFor($product, $category, null, 6), 'url_path');
            $this->assertEmpty(array_intersect(['/academy', '/edge', '/aspire'], $paths), json_encode([$product, $category, $paths]));
        }
    }

    public function testSeededBrandTextIsRecased(): void
    {
        $db = Database::connect();

        $desk = $db->table('reach_community_official_identities')->where('slug', 'aicountly-gst-guide')->get()->getRowArray();
        $this->assertSame('Aicountly GST Guide', $desk['display_name']);
        $this->assertSame('AI-assisted Aicountly community contributor', $desk['ai_disclosure']);
        $this->assertSame(0, $db->table('reach_community_official_identities')->like('display_name', 'AICOUNTLY')->countAllResults());

        $community = $db->table('reach_link_registry')->where('url_path', '/community')->get()->getRowArray();
        $this->assertSame('Aicountly community Q&A', $community['title']);
    }
}

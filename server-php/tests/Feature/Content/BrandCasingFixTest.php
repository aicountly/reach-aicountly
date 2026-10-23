<?php

namespace Tests\Feature\Content;

use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Content\BrandCasingFixService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * reach:brand-casing over real tables: dry run writes nothing, apply re-cases
 * the stored text and keeps the version's approval valid, posted social
 * copy is left as the network has it, and a second apply changes nothing.
 */
final class BrandCasingFixTest extends DatabaseTestCase
{
    /** @return array{item:int, version:int} */
    private function seedRows(): array
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Why AICOUNTLY', 'slug' => 'why-aicountly']);
        $item = (int) $db->insertID();
        $db->table('reach_content_versions')->insert([
            'content_item_id' => $item, 'version_number' => 1, 'is_current' => true,
            'title'     => 'Why AICOUNTLY',
            'body_html' => '<p>AICOUNTLY by AICOUNTLY INTERACTIVE SERVICES PRIVATE LIMITED. <a href="https://AICOUNTLY.com/AICOUNTLY" title="AICOUNTLY">Visit AICOUNTLY.com</a></p>',
        ]);
        $version = (int) $db->insertID();
        $checksum = (new BlogContentApprovalService())->checksumForVersion($version);
        $db->table('reach_blog_content_approvals')->insert([
            'content_item_id' => $item, 'content_version_id' => $version, 'version_checksum' => $checksum,
            'approval_type' => 'standard', 'outcome' => 'approved',
        ]);

        $db->table('reach_social_posts')->insert(['channel' => 'linkedin', 'content' => 'AICOUNTLY draft', 'status' => 'draft']);
        $db->table('reach_social_posts')->insert(['channel' => 'linkedin', 'content' => 'AICOUNTLY live', 'status' => 'posted', 'remote_post_id' => 'urn:li:1']);

        return ['item' => $item, 'version' => $version];
    }

    public function testDryRunThenApplyThenNothingLeft(): void
    {
        $ids = $this->seedRows();
        $db  = Database::connect();
        $tables = ['reach_content_items', 'reach_content_versions', 'reach_social_posts'];

        $dry = (new BrandCasingFixService($db))->run(false, $tables);
        $this->assertSame(1, $dry['reach_content_items']['rows_changed']);
        $this->assertSame(1, $dry['reach_content_versions']['rows_changed']);
        $this->assertSame(2, $dry['reach_content_versions']['fields_changed']);
        $this->assertSame(1, $dry['reach_social_posts']['rows_changed'], 'the posted row is not even scanned');
        $this->assertSame('Why AICOUNTLY', $db->table('reach_content_versions')->where('id', $ids['version'])->get()->getRowArray()['title'], 'dry run writes nothing');

        $applied = (new BrandCasingFixService($db))->run(true, $tables);
        $this->assertSame(1, $applied['reach_content_versions']['checksums_carried']);

        $v = $db->table('reach_content_versions')->where('id', $ids['version'])->get()->getRowArray();
        $this->assertSame('Why Aicountly', $v['title']);
        $this->assertSame('<p>Aicountly by Aicountly Interactive Services Private Limited. <a href="https://AICOUNTLY.com/AICOUNTLY" title="Aicountly">Visit aicountly.com</a></p>', $v['body_html']);
        $this->assertSame('why-aicountly', $db->table('reach_content_items')->where('id', $ids['item'])->get()->getRowArray()['slug']);

        $approval = $db->table('reach_blog_content_approvals')->where('content_version_id', $ids['version'])->get()->getRowArray();
        $this->assertSame((new BlogContentApprovalService())->checksumForVersion($ids['version']), $approval['version_checksum'], 'the approval still matches the version');

        $posts = array_column($db->table('reach_social_posts')->get()->getResultArray(), 'content', 'status');
        $this->assertSame('Aicountly draft', $posts['draft']);
        $this->assertSame('AICOUNTLY live', $posts['posted'], 'already on the network: left alone');

        $again = (new BrandCasingFixService($db))->run(true, $tables);
        foreach ($tables as $t) {
            $this->assertSame(0, $again[$t]['rows_changed'], $t);
        }
    }

    public function testEveryTargetTableAndColumnExists(): void
    {
        $db = Database::connect();
        foreach (BrandCasingFixService::TARGETS as $table => $target) {
            $this->assertTrue($db->tableExists($table, false), $table);
            $fields = $db->getFieldNames($table);
            foreach (array_keys($target['columns']) as $col) {
                $this->assertContains($col, $fields, "{$table}.{$col}");
            }
        }
        $report = (new BrandCasingFixService($db))->run(false);
        $this->assertCount(count(BrandCasingFixService::TARGETS), $report);
        foreach ($report as $table => $r) {
            $this->assertNull($r['skipped'], $table);
        }
    }
}

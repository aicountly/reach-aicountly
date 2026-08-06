<?php

declare(strict_types=1);

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityPublicRecordProvisioner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Community\CommunityPublicRecordProvisioner
 */
class CommunityPublicRecordProvisionerTest extends CIUnitTestCase
{
    /**
     * Reach names operational roles after the desk; the public receiver names
     * them after the function, and rejects anything outside its own enum with a
     * 422. Every Reach role must therefore land on a value the receiver accepts.
     */
    public function testEveryReachRoleMapsToAReceiverRole(): void
    {
        $receiverRoles = ['answering', 'facilitation', 'moderation', 'curation', 'editorial'];

        $expected = [
            'expert_answer_assistant' => 'answering',
            'thread_facilitator'      => 'facilitation',
            'community_steward'       => 'moderation',
            'question_curator'        => 'curation',
            'review_objection_desk'   => 'editorial',
        ];

        foreach ($expected as $reachRole => $publicRole) {
            $mapped = CommunityPublicRecordProvisioner::publicRoleFor($reachRole);
            $this->assertSame($publicRole, $mapped);
            $this->assertContains($mapped, $receiverRoles);
        }
    }

    /**
     * Reach files questions under its own category names; aicountly.com
     * publishes a different set. An unmapped category is rejected with
     * "Unknown community category.", and the fallback for that drops the
     * category entirely — filing a tax-audit question under GST, the first
     * category by sort order. These are the divergences that exist today.
     */
    public function testMapsReachCategoriesToPublicSlugs(): void
    {
        $publicSlugs = [
            'gst', 'income-tax', 'tds-tcs', 'mca-company-law', 'audit-accounting',
            'payroll', 'banking-brs', 'saas-product-help', 'technical-api',
        ];

        $expected = [
            'audit'          => 'audit-accounting',
            'accounting'     => 'audit-accounting',
            'payroll-hr'     => 'payroll',
            'product-guides' => 'saas-product-help',
            'books'          => 'saas-product-help',
            'mca'            => 'mca-company-law',
            'tds'            => 'tds-tcs',
        ];

        foreach ($expected as $reach => $public) {
            $mapped = CommunityPublicRecordProvisioner::publicCategoryFor($reach);
            $this->assertSame($public, $mapped, "'{$reach}' should map to '{$public}'");
            $this->assertContains($mapped, $publicSlugs);
        }
    }

    /**
     * Slugs the public site already knows must survive untouched — the map
     * only bridges the divergences, it is not an allow-list.
     */
    public function testPassesThroughSlugsThePublicSiteAlreadyKnows(): void
    {
        foreach (['gst', 'income-tax', 'tds-tcs', 'payroll', 'technical-api'] as $slug) {
            $this->assertSame($slug, CommunityPublicRecordProvisioner::publicCategoryFor($slug));
        }

        $this->assertSame('', CommunityPublicRecordProvisioner::publicCategoryFor(null));
        $this->assertSame('audit-accounting', CommunityPublicRecordProvisioner::publicCategoryFor('  AUDIT '));
    }

    /**
     * An unmapped or missing role must still produce a value the receiver
     * accepts — refusing to publish over a vocabulary gap would be worse than
     * posting under the most common role.
     */
    public function testUnknownRoleFallsBackToAnAcceptedRole(): void
    {
        foreach ([null, '', 'something_new', 'QUESTION_CURATOR '] as $role) {
            $mapped = CommunityPublicRecordProvisioner::publicRoleFor($role);
            $this->assertContains($mapped, ['answering', 'facilitation', 'moderation', 'curation', 'editorial']);
        }

        $this->assertSame('answering', CommunityPublicRecordProvisioner::publicRoleFor('something_new'));
        // Case and whitespace differences must not silently fall back.
        $this->assertSame('curation', CommunityPublicRecordProvisioner::publicRoleFor('QUESTION_CURATOR '));
    }
}

<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityOperationalAgentService;
use PHPUnit\Framework\TestCase;

/**
 * Pure, DB-free tests of the role -> action authorization map, the
 * window/cap classification static helpers and the category -> answer desk
 * routing. The role map is the one that stands between "an official
 * identity" and "an arbitrary community mutation" —
 * every one of the five roles must resolve to exactly its own action set,
 * with no overlap that would let e.g. a thread_facilitator draft an answer.
 */
final class CommunityOperationalAgentServiceTest extends TestCase
{
    public function testEachRoleHasItsOwnDistinctActionSet(): void
    {
        $roles = [
            'question_curator'        => ['curate_question'],
            'expert_answer_assistant' => ['draft_answer'],
            'community_steward'       => ['categorize_question', 'link_related_questions', 'flag_moderation_hint'],
            'thread_facilitator'      => ['post_comment'],
            'review_objection_desk'   => ['flag_objection', 'request_revision', 'escalate_risk'],
        ];

        foreach ($roles as $role => $expectedActions) {
            $this->assertSame($expectedActions, CommunityOperationalAgentService::actionsForRole($role));
        }
    }

    public function testUnknownRoleHasNoActions(): void
    {
        $this->assertSame([], CommunityOperationalAgentService::actionsForRole('not_a_real_role'));
    }

    public function testNoActionAppearsUnderMoreThanOneRole(): void
    {
        $roles = ['question_curator', 'expert_answer_assistant', 'community_steward', 'thread_facilitator', 'review_objection_desk'];
        $seen  = [];
        foreach ($roles as $role) {
            foreach (CommunityOperationalAgentService::actionsForRole($role) as $action) {
                $this->assertArrayNotHasKey($action, $seen, "Action '{$action}' is claimed by more than one role");
                $seen[$action] = $role;
            }
        }
        $this->assertNotEmpty($seen);
    }

    public function testNoActionGrantsVotingLikingFollowingOrViewInflation(): void
    {
        $forbidden = ['/vote/i', '/like/i', '/follow/i', '/view.*count/i', '/inflate/i'];
        $roles = ['question_curator', 'expert_answer_assistant', 'community_steward', 'thread_facilitator', 'review_objection_desk'];

        foreach ($roles as $role) {
            foreach (CommunityOperationalAgentService::actionsForRole($role) as $action) {
                foreach ($forbidden as $pattern) {
                    $this->assertDoesNotMatchRegularExpression($pattern, $action, "Action '{$action}' (role {$role}) looks like an engagement-inflation action");
                }
            }
        }
    }

    public function testOnlyContentCreatingActionsAreWindowGated(): void
    {
        $this->assertTrue(CommunityOperationalAgentService::isWindowGated('curate_question'));
        $this->assertTrue(CommunityOperationalAgentService::isWindowGated('draft_answer'));
        $this->assertTrue(CommunityOperationalAgentService::isWindowGated('post_comment'));

        $this->assertFalse(CommunityOperationalAgentService::isWindowGated('categorize_question'));
        $this->assertFalse(CommunityOperationalAgentService::isWindowGated('link_related_questions'));
        $this->assertFalse(CommunityOperationalAgentService::isWindowGated('flag_moderation_hint'));
        $this->assertFalse(CommunityOperationalAgentService::isWindowGated('flag_objection'));
        $this->assertFalse(CommunityOperationalAgentService::isWindowGated('request_revision'));
        $this->assertFalse(CommunityOperationalAgentService::isWindowGated('escalate_risk'));
    }

    public function testSeedDailyCapsMatchThePlanDefaults(): void
    {
        $this->assertSame(4, CommunityOperationalAgentService::dailyCapFor('curate_question'));
        $this->assertSame(4, CommunityOperationalAgentService::dailyCapFor('draft_answer'));
        $this->assertSame(5, CommunityOperationalAgentService::dailyCapFor('post_comment'));
    }

    public function testActionsWithoutASeedCapReturnNull(): void
    {
        $this->assertNull(CommunityOperationalAgentService::dailyCapFor('categorize_question'));
        $this->assertNull(CommunityOperationalAgentService::dailyCapFor('flag_objection'));
    }

    /** @dataProvider categoryDeskProvider */
    public function testEachMappedCategoryRoutesToItsDesk(string $category, string $desk): void
    {
        $this->assertSame($desk, CommunityOperationalAgentService::deskForCategory($category));
    }

    public static function categoryDeskProvider(): array
    {
        return [
            ['accounting', 'aicountly-accounting-guide'],
            ['gst', 'aicountly-gst-guide'],
            ['income-tax', 'aicountly-income-tax-desk'],
            ['tds-tcs', 'aicountly-income-tax-desk'],
            ['payroll-hr', 'aicountly-payroll-desk'],
            ['product-guides', 'aicountly-smart-books-guide'],
            ['books', 'aicountly-smart-books-guide'],
        ];
    }

    public function testCategoryDeskRoutingIgnoresCase(): void
    {
        $this->assertSame('aicountly-gst-guide', CommunityOperationalAgentService::deskForCategory('GST'));
        $this->assertSame('aicountly-income-tax-desk', CommunityOperationalAgentService::deskForCategory('Income-Tax'));
        $this->assertSame('aicountly-payroll-desk', CommunityOperationalAgentService::deskForCategory('PAYROLL-HR'));
    }

    public function testUnmappedOrMissingCategoryRoutesToTheComplianceDesk(): void
    {
        foreach (['company-law', 'general', '', null] as $category) {
            $this->assertSame(
                'aicountly-compliance-desk',
                CommunityOperationalAgentService::deskForCategory($category),
                var_export($category, true)
            );
        }
    }

    public function testAnswerDesksListsEachRoutableDeskOnce(): void
    {
        $this->assertSame([
            'aicountly-accounting-guide',
            'aicountly-gst-guide',
            'aicountly-income-tax-desk',
            'aicountly-payroll-desk',
            'aicountly-smart-books-guide',
            'aicountly-compliance-desk',
        ], CommunityOperationalAgentService::answerDesks());
    }
}

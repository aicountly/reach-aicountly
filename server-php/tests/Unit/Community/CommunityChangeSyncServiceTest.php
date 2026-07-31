<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityChangeSyncService;
use PHPUnit\Framework\TestCase;

/**
 * CommunityChangeSyncService's drift classifiers are the pure decision
 * functions behind the sync-changes automation tick: they decide which rows
 * from the public site's /community/changes feed are worth an audit-log
 * entry versus routine "still fine" noise.
 */
final class CommunityChangeSyncServiceTest extends TestCase
{
    public function testAnswerDriftDetectedForUnpublished(): void
    {
        $this->assertTrue(CommunityChangeSyncService::isAnswerDrift(['status' => 'unpublished']));
    }

    public function testAnswerDriftDetectedForWithdrawn(): void
    {
        $this->assertTrue(CommunityChangeSyncService::isAnswerDrift(['status' => 'withdrawn']));
    }

    public function testAnswerDriftDetectedForRemoved(): void
    {
        $this->assertTrue(CommunityChangeSyncService::isAnswerDrift(['status' => 'removed']));
    }

    public function testNoAnswerDriftForPublished(): void
    {
        $this->assertFalse(CommunityChangeSyncService::isAnswerDrift(['status' => 'published']));
    }

    public function testNoAnswerDriftWhenStatusMissing(): void
    {
        $this->assertFalse(CommunityChangeSyncService::isAnswerDrift([]));
    }

    public function testCommentDriftDetectedForRemoved(): void
    {
        $this->assertTrue(CommunityChangeSyncService::isCommentDrift(['status' => 'removed']));
    }

    public function testCommentDriftDetectedForHidden(): void
    {
        $this->assertTrue(CommunityChangeSyncService::isCommentDrift(['status' => 'hidden']));
    }

    public function testCommentDriftDetectedForFlagged(): void
    {
        $this->assertTrue(CommunityChangeSyncService::isCommentDrift(['status' => 'flagged']));
    }

    public function testNoCommentDriftForPublished(): void
    {
        $this->assertFalse(CommunityChangeSyncService::isCommentDrift(['status' => 'published']));
    }
}

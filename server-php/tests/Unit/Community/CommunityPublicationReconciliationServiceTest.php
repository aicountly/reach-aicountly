<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityPublicationReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * CommunityPublicationReconciliationService::determineOutcome() is the pure
 * decision function behind Reach's drift-detection guarantee: a published
 * answer is only ever trusted as "really published" once the public site's
 * own record agrees on both status and content checksum.
 */
final class CommunityPublicationReconciliationServiceTest extends TestCase
{
    public function testPassesWhenPublishedAndChecksumMatches(): void
    {
        $outcome = CommunityPublicationReconciliationService::determineOutcome('abc123', [
            'public_status'    => 'published',
            'payload_checksum' => 'abc123',
        ]);
        $this->assertSame('passed', $outcome);
    }

    public function testMismatchWhenPublicStatusIsNotPublished(): void
    {
        $outcome = CommunityPublicationReconciliationService::determineOutcome('abc123', [
            'public_status'    => 'unpublished',
            'payload_checksum' => 'abc123',
        ]);
        $this->assertSame('mismatch', $outcome);
    }

    public function testMismatchWhenChecksumDiffers(): void
    {
        $outcome = CommunityPublicationReconciliationService::determineOutcome('abc123', [
            'public_status'    => 'published',
            'payload_checksum' => 'different-checksum',
        ]);
        $this->assertSame('mismatch', $outcome);
    }

    public function testMismatchWhenPublicStatusMissing(): void
    {
        $outcome = CommunityPublicationReconciliationService::determineOutcome('abc123', [
            'payload_checksum' => 'abc123',
        ]);
        $this->assertSame('mismatch', $outcome);
    }

    public function testMismatchWhenChecksumMissing(): void
    {
        $outcome = CommunityPublicationReconciliationService::determineOutcome('abc123', [
            'public_status' => 'published',
        ]);
        $this->assertSame('mismatch', $outcome);
    }
}

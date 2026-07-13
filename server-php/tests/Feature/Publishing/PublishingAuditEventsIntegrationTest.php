<?php

namespace Tests\Feature\Publishing;

use App\Libraries\AuditLogger;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests verifying all Phase 4 audit events are defined.
 *
 * @group publishing
 */
class PublishingAuditEventsIntegrationTest extends CIUnitTestCase
{
    public function testPhase4AuditEventCountMeetsMinimum(): void
    {
        $constants = (new \ReflectionClass(AuditLogger::class))->getConstants();
        $phase4    = array_filter($constants, fn($v) => is_string($v) && (
            str_starts_with($v, 'publishing.') ||
            str_starts_with($v, 'seo.') ||
            str_starts_with($v, 'aeo.') ||
            str_starts_with($v, 'structured_data.') ||
            str_starts_with($v, 'blog_profile.') ||
            str_starts_with($v, 'kb_profile.') ||
            str_starts_with($v, 'kb_structure.')
        ));
        $this->assertGreaterThanOrEqual(20, count($phase4),
            'Phase 4 should define at least 20 audit event constants'
        );
    }

    public function testPublishingQueuedAuditEventString(): void
    {
        $this->assertSame('publishing.queued', AuditLogger::PUBLISHING_QUEUED);
    }

    public function testPublishingVerifiedAuditEventString(): void
    {
        $this->assertSame('publishing.verified', AuditLogger::PUBLISHING_VERIFIED);
    }

    public function testPublishingRolledBackAuditEventString(): void
    {
        $this->assertSame('publishing.rolled_back', AuditLogger::PUBLISHING_ROLLED_BACK);
    }

    public function testSeoProfileUpdatedAuditEventString(): void
    {
        $this->assertSame('seo.profile_updated', AuditLogger::SEO_PROFILE_UPDATED);
    }

    public function testStructuredDataGeneratedEventString(): void
    {
        $this->assertSame('structured_data.generated', AuditLogger::STRUCTURED_DATA_GENERATED);
    }

    public function testKbProfileUpdatedEventString(): void
    {
        $this->assertSame('kb_profile.updated', AuditLogger::KB_PROFILE_UPDATED);
    }

    public function testBlogProfileUpdatedEventString(): void
    {
        $this->assertSame('blog_profile.updated', AuditLogger::BLOG_PROFILE_UPDATED);
    }

    public function testIndexingReadyEventString(): void
    {
        $this->assertSame('publishing.indexing_ready', AuditLogger::PUBLISHING_INDEXING_READY);
    }
}

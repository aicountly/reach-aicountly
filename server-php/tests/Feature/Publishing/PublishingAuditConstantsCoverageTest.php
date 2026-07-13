<?php

namespace Tests\Feature\Publishing;

use App\Libraries\AuditLogger;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers all Phase 4 audit event constants in AuditLogger.
 *
 * @group publishing
 */
class PublishingAuditConstantsCoverageTest extends CIUnitTestCase
{
    /** @dataProvider publishingEventProvider */
    public function testPublishingEventIsDefined(string $constantName, string $expectedValue): void
    {
        $constants = (new \ReflectionClass(AuditLogger::class))->getConstants();
        $this->assertArrayHasKey($constantName, $constants, "Constant {$constantName} must be defined");
        $this->assertSame($expectedValue, $constants[$constantName]);
    }

    public static function publishingEventProvider(): array
    {
        return [
            ['PUBLISHING_QUEUED',              'publishing.queued'],
            ['PUBLISHING_ACCEPTED',            'publishing.accepted'],
            ['PUBLISHING_FAILED',              'publishing.failed'],
            ['PUBLISHING_VERIFIED',            'publishing.verified'],
            ['PUBLISHING_ROLLED_BACK',         'publishing.rolled_back'],
            ['PUBLISHING_ROLLBACK_FAILED',     'publishing.rollback_failed'],
            ['PUBLISHING_CANCELLED',           'publishing.cancelled'],
            ['PUBLISHING_RETRY_SCHEDULED',     'publishing.retry_scheduled'],
            ['PUBLISHING_MAX_RETRIES',         'publishing.max_retries_reached'],
            ['PUBLISHING_HEALTH_CHECKED',      'publishing.health_checked'],
            ['PUBLISHING_REFRESH_REQUESTED',   'publishing.refresh_requested'],
            ['PUBLISHING_SITEMAP_VERIFIED',    'publishing.sitemap_verified'],
            ['PUBLISHING_INDEXING_READY',      'publishing.indexing_ready'],
            ['SEO_PROFILE_UPDATED',            'seo.profile_updated'],
            ['SEO_REVIEW_STARTED',             'seo.review_started'],
            ['SEO_REVIEWED',                   'seo.reviewed'],
            ['SEO_BLOCKED',                    'seo.blocked'],
            ['AEO_PROFILE_UPDATED',            'aeo.profile_updated'],
            ['AEO_REVIEWED',                   'aeo.reviewed'],
            ['STRUCTURED_DATA_GENERATED',      'structured_data.generated'],
            ['STRUCTURED_DATA_VALIDATED',      'structured_data.validated'],
            ['STRUCTURED_DATA_BLOCKED',        'structured_data.blocked'],
            ['BLOG_PROFILE_UPDATED',           'blog_profile.updated'],
            ['KB_PROFILE_UPDATED',             'kb_profile.updated'],
            ['KB_STRUCTURE_VALIDATED',         'kb_structure.validated'],
        ];
    }
}

<?php

namespace Tests\Unit\Publishing;

use App\Libraries\AuditLogger;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests that all Phase 4 audit event constants are declared in AuditLogger.
 *
 * @covers \App\Libraries\AuditLogger
 */
class PublishingAuditEventConstantsTest extends CIUnitTestCase
{
    public function testPublishingAuditEventsAreDefined(): void
    {
        $required = [
            'publishing.queued',
            'publishing.accepted',
            'publishing.failed',
            'publishing.verified',
            'publishing.rolled_back',
            'publishing.cancelled',
            'publishing.retry_scheduled',
            'publishing.health_checked',
            'publishing.sitemap_verified',
            'publishing.indexing_ready',
        ];

        $constants = (new \ReflectionClass(AuditLogger::class))->getConstants();
        $values    = array_values($constants);

        foreach ($required as $event) {
            $this->assertContains($event, $values, "Audit event '{$event}' must be a constant in AuditLogger");
        }
    }

    public function testSeoAuditEventsAreDefined(): void
    {
        $required = [
            'seo.profile_updated',
            'seo.review_started',
            'seo.reviewed',
            'seo.blocked',
        ];

        $constants = (new \ReflectionClass(AuditLogger::class))->getConstants();
        $values    = array_values($constants);

        foreach ($required as $event) {
            $this->assertContains($event, $values, "Audit event '{$event}' must be a constant in AuditLogger");
        }
    }

    public function testAeoAuditEventsAreDefined(): void
    {
        $required = [
            'aeo.profile_updated',
            'aeo.reviewed',
        ];

        $constants = (new \ReflectionClass(AuditLogger::class))->getConstants();
        $values    = array_values($constants);

        foreach ($required as $event) {
            $this->assertContains($event, $values, "Audit event '{$event}' must be a constant in AuditLogger");
        }
    }

    public function testStructuredDataAuditEventsAreDefined(): void
    {
        $required = [
            'structured_data.generated',
            'structured_data.validated',
        ];

        $constants = (new \ReflectionClass(AuditLogger::class))->getConstants();
        $values    = array_values($constants);

        foreach ($required as $event) {
            $this->assertContains($event, $values, "Audit event '{$event}' must be a constant in AuditLogger");
        }
    }

    public function testRecordMethodExists(): void
    {
        $this->assertTrue(method_exists(AuditLogger::class, 'record'),
            'AuditLogger::record() convenience method must exist'
        );
    }
}

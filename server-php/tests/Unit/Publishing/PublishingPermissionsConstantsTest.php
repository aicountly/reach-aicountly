<?php

namespace Tests\Unit\Publishing;

use Config\Permissions;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests verifying Phase 4 publishing permission constants.
 *
 * @covers \Config\Permissions
 */
class PublishingPermissionsConstantsTest extends CIUnitTestCase
{
    public function testPublishingViewPermissionIsDefined(): void
    {
        $this->assertSame('publishing.view', Permissions::PUBLISHING_VIEW);
    }

    public function testPublishingPublishPermissionIsDefined(): void
    {
        $this->assertSame('publishing.publish', Permissions::PUBLISHING_PUBLISH);
    }

    public function testPublishingSchedulePermissionIsDefined(): void
    {
        $this->assertSame('publishing.schedule', Permissions::PUBLISHING_SCHEDULE);
    }

    public function testPublishingUnpublishPermissionIsDefined(): void
    {
        $this->assertSame('publishing.unpublish', Permissions::PUBLISHING_UNPUBLISH);
    }

    public function testPublishingRollbackPermissionIsDefined(): void
    {
        $this->assertSame('publishing.rollback', Permissions::PUBLISHING_ROLLBACK);
    }

    public function testPublishingVerifyPermissionIsDefined(): void
    {
        $this->assertSame('publishing.verify', Permissions::PUBLISHING_VERIFY);
    }

    public function testPublishingManageConnectionsPermissionIsDefined(): void
    {
        $this->assertSame('publishing.manage_connections', Permissions::PUBLISHING_MANAGE_CONNECTIONS);
    }

    public function testSeoViewPermissionIsDefined(): void
    {
        $this->assertSame('seo.view', Permissions::SEO_VIEW);
    }

    public function testSeoManagePermissionIsDefined(): void
    {
        $this->assertSame('seo.manage', Permissions::SEO_MANAGE);
    }

    public function testAeoViewPermissionIsDefined(): void
    {
        $this->assertSame('aeo.view', Permissions::AEO_VIEW);
    }

    public function testAeoManagePermissionIsDefined(): void
    {
        $this->assertSame('aeo.manage', Permissions::AEO_MANAGE);
    }

    public function testStructuredDataViewPermissionIsDefined(): void
    {
        $this->assertSame('structured_data.view', Permissions::STRUCTURED_DATA_VIEW);
    }

    public function testStructuredDataManagePermissionIsDefined(): void
    {
        $this->assertSame('structured_data.manage', Permissions::STRUCTURED_DATA_MANAGE);
    }

    public function testKbPublishingPermissionsAreDefined(): void
    {
        $this->assertSame('kb_publishing.view', Permissions::KB_PUBLISHING_VIEW);
        $this->assertSame('kb_publishing.publish', Permissions::KB_PUBLISHING_PUBLISH);
        $this->assertSame('kb_publishing.manage', Permissions::KB_PUBLISHING_MANAGE);
    }

    public function testAllPermissionsReturnNonEmptyArray(): void
    {
        $all = Permissions::all();
        $this->assertIsArray($all);
        $this->assertGreaterThan(50, count($all));
    }
}

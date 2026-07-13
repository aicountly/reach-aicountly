<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for publication lifecycle state transitions.
 * Tests the status lifecycle: draft → published → unpublished.
 *
 * @group publishing
 */
class PublicationLifecycleStatesTest extends CIUnitTestCase
{
    private MockPublicSitePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new MockPublicSitePublisher();
    }

    public function testDraftStateAfterCreation(): void
    {
        $result = $this->publisher->createDraft($this->env());
        $this->assertSame('draft', $result['public_status']);
    }

    public function testDraftStateAfterUpdate(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $u = $this->publisher->updateDraft($d['public_content_id'], $this->env());
        $this->assertSame('draft', $u['public_status']);
    }

    public function testPublishedStateAfterPublish(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $p = $this->publisher->publish($d['public_content_id'], $this->env());
        $this->assertSame('published', $p['public_status']);
    }

    public function testScheduledStateAfterSchedule(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $s = $this->publisher->schedule($d['public_content_id'], $this->env(), '2027-01-01T09:00:00Z');
        $this->assertSame('scheduled', $s['public_status']);
    }

    public function testDraftStateAfterUnpublish(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $this->publisher->publish($d['public_content_id'], $this->env());
        $u = $this->publisher->unpublish($d['public_content_id'], 'Rollback test');
        $this->assertSame('draft', $u['public_status']);
    }

    public function testPublishedStateAfterRestore(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $r = $this->publisher->restore($d['public_content_id'], $this->env());
        $this->assertSame('published', $r['public_status']);
    }

    public function testVerificationStatusAfterPublish(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $this->publisher->publish($d['public_content_id'], $this->env());

        $v = $this->publisher->getVerification($d['public_content_id']);
        $this->assertSame('published', $v['public_status']);
        $this->assertSame('included', $v['sitemap_status']);
    }

    public function testPublicVersionBumpsOnPublish(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $p = $this->publisher->publish($d['public_content_id'], $this->env());
        $this->assertSame(1, $p['public_version']);
    }

    public function testSitemapStatusIncludedAfterPublish(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $p = $this->publisher->publish($d['public_content_id'], $this->env());
        $this->assertSame('included', $p['sitemap_status']);
    }

    public function testCanonicalUrlPresentAfterPublish(): void
    {
        $d = $this->publisher->createDraft($this->env());
        $p = $this->publisher->publish($d['public_content_id'], $this->env());
        $this->assertNotEmpty($p['canonical_url']);
        $this->assertStringStartsWith('https://', $p['canonical_url']);
    }

    private function env(): array
    {
        return [
            'reach_content_version_number' => 1,
            'payload_checksum' => 'chk',
            'request_id' => 'req-' . uniqid(),
            'idempotency_key' => 'ikey-' . uniqid(),
        ];
    }
}

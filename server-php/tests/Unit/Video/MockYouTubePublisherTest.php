<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Libraries\Video\Providers\MockYouTubePublisher;
use App\Libraries\Video\Providers\YouTubeUploadReceipt;
use App\Libraries\Video\Providers\YouTubeVideoStatus;
use CodeIgniter\Test\CIUnitTestCase;

final class MockYouTubePublisherTest extends CIUnitTestCase
{
    private MockYouTubePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new MockYouTubePublisher();
    }

    private function payload(string $projectUuid = 'test-proj-uuid', string $key = ''): array
    {
        return [
            'project_uuid'    => $projectUuid,
            'video_asset_url' => 'mock://asset/video.mp4',
            'idempotency_key' => $key ?: 'key-' . uniqid(),
            'connection_id'   => 1,
        ];
    }

    public function testUploadReturnsReceipt(): void
    {
        $receipt = $this->publisher->upload($this->payload());
        $this->assertInstanceOf(YouTubeUploadReceipt::class, $receipt);
        $this->assertStringStartsWith('yt-mock-', $receipt->remoteVideoId);
        $this->assertSame('uploaded', $receipt->uploadStatus);
    }

    public function testUploadIdempotencyReturnsSameReceipt(): void
    {
        $key      = 'idem-key-' . uniqid();
        $receipt1 = $this->publisher->upload($this->payload('proj-a', $key));
        $receipt2 = $this->publisher->upload($this->payload('proj-a', $key));
        $this->assertSame($receipt1->remoteVideoId, $receipt2->remoteVideoId);
    }

    public function testSetMetadataReturnsTrue(): void
    {
        $this->assertTrue($this->publisher->setMetadata('yt-mock-id', [
            'title'          => 'Test Video',
            'privacy_status' => 'private',
        ]));
    }

    public function testUploadCaptionReturnsTrackId(): void
    {
        $trackId = $this->publisher->uploadCaption('yt-mock-id', [
            'language' => 'en',
            'name'     => 'English',
            'content'  => "1\n00:00:01,000 --> 00:00:03,000\nHello world\n",
        ]);
        $this->assertSame('yt-mock-caption-en', $trackId);
    }

    public function testSetThumbnailReturnsTrue(): void
    {
        $this->assertTrue($this->publisher->setThumbnail('yt-mock-id', 'mock://thumb.jpg'));
    }

    public function testGetStatusReturnsSucceeded(): void
    {
        $status = $this->publisher->getStatus('yt-mock-test-id');
        $this->assertInstanceOf(YouTubeVideoStatus::class, $status);
        $this->assertSame('succeeded', $status->processingStatus);
        $this->assertSame('yt-mock-test-id', $status->remoteVideoId);
    }

    public function testGetReceiptNormalizedStripsTokens(): void
    {
        $raw = [
            'video_id'      => 'yt-mock-123',
            'access_token'  => 'secret-token-should-be-removed',
            'refresh_token' => 'refresh-secret',
            'client_secret' => 'client-secret',
        ];
        $normalized = $this->publisher->getReceiptNormalized($raw);
        $this->assertSame('yt-mock-123', $normalized['video_id']);
        $this->assertArrayNotHasKey('access_token', $normalized);
        $this->assertArrayNotHasKey('refresh_token', $normalized);
        $this->assertArrayNotHasKey('client_secret', $normalized);
    }
}

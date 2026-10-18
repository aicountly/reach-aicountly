<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Libraries\Distribution\ManualQueueReleaser;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Bot-generated social posts parked in manual_queue (no social-api.ai account
 * for the channel yet) are released once the channel is ready; posts a person
 * approved, old posts and not-ready channels stay where they are.
 *
 * @internal
 */
final class ManualQueueReleaserTest extends DatabaseTestCase
{
    private function post(string $channel, bool $bot, string $createdAt = 'now'): int
    {
        $db = Database::connect();
        $db->table('reach_social_posts')->insert([
            'channel' => $channel, 'content' => 'New Short: GST in 60 seconds', 'status' => 'manual_queue',
            'approval_status' => 'approved', 'bot_generated' => $bot, 'tenant_id' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime($createdAt)), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function postStatus(int $id): string
    {
        return (string) Database::connect()->table('reach_social_posts')->where('id', $id)->get()->getRowArray()['status'];
    }

    public function testReleasesOnlyRecentBotPostsWhoseChannelIsReady(): void
    {
        $linkedin = $this->post('linkedin', true);
        $facebook = $this->post('facebook', true);
        $human    = $this->post('linkedin', false);
        $old      = $this->post('linkedin', true, '-30 days');

        $queued   = [];
        $releaser = new ManualQueueReleaser(
            null,
            static fn (string $channel): bool => $channel === 'linkedin',
            static function (int $postId, int $tenantId) use (&$queued): void { $queued[] = [$postId, $tenantId]; },
        );

        $this->assertSame(['released' => 1, 'waiting' => 1], $releaser->release());
        $this->assertSame([[$linkedin, 1]], $queued);
        $this->assertSame('approved', $this->postStatus($linkedin));
        $this->assertSame('manual_queue', $this->postStatus($facebook), 'channel not connected yet');
        $this->assertSame('manual_queue', $this->postStatus($human), 'a person may post it by hand');
        $this->assertSame('manual_queue', $this->postStatus($old));

        $this->assertSame(['released' => 0, 'waiting' => 1], $releaser->release(), 'idempotent');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\SocialInbox;

use App\Libraries\Distribution\SocialInbox\SocialInboxParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The inbox answer shape is an assumption until the first live run, so the
 * parser is pinned against every shape it claims to read — and against what
 * it must refuse to guess.
 *
 * @covers \App\Libraries\Distribution\SocialInbox\SocialInboxParser
 *
 * @internal
 */
final class SocialInboxParserTest extends CIUnitTestCase
{
    public function testAFlatListUnderDataWithNestedAuthor(): void
    {
        $p = SocialInboxParser::page(['data' => [[
            'id' => 'cmt_1', 'type' => 'comment', 'platform' => 'LinkedIn', 'account_id' => 'acc_li',
            'text' => "  How do I file GSTR-1\x07 in AICOUNTLY? ", 'author' => ['name' => 'Asha Verma', 'username' => '@asha_v', 'id' => 'u1'],
            'post' => ['id' => 'post_9', 'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:9'],
            'created_at' => '2026-10-18T09:15:00+05:30',
        ]], 'next_cursor' => 'abc'], 'linkedin', 'acc_li');

        $this->assertTrue($p['recognised']);
        $this->assertSame('abc', $p['next']);
        $i = $p['items'][0];
        $this->assertSame('cmt_1', $i['external_id']);
        $this->assertSame('comment', $i['kind']);
        $this->assertSame('linkedin', $i['platform']);
        $this->assertSame('How do I file GSTR-1 in AICOUNTLY?', $i['text'], 'control characters stripped, trimmed');
        $this->assertSame(['Asha Verma', 'asha_v', 'u1'], [$i['author_name'], $i['author_handle'], $i['author_external_id']]);
        $this->assertSame('post_9', $i['thread_id']);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:activity:9', $i['post_url']);
        $this->assertSame('2026-10-18 03:45:00+00', $i['received_at']);
        $this->assertFalse($i['is_own']);
        $this->assertSame('cmt_1', $i['raw']['id']);
    }

    public function testConversationsAreFlattenedAndOwnMessagesFlagged(): void
    {
        $p = SocialInboxParser::page(['conversations' => [[
            'id' => 'conv_7', 'platform' => 'instagram', 'type' => 'direct_message',
            'messages' => [
                ['message_id' => 'm1', 'message' => 'Hi, what is the price?', 'from' => ['handle' => 'raj'], 'timestamp' => 1_792_310_400],
                ['message_id' => 'm2', 'message' => 'Thanks for writing!', 'direction' => 'outbound'],
                ['message_id' => 'm3', 'body' => ['text' => 'Ok'], 'is_from_me' => true],
            ],
        ]], 'pagination' => ['next' => 'https://api.social-api.ai/v1/inbox?cursor=p2&limit=50']]);

        $this->assertCount(3, $p['items']);
        [$a, $b, $c] = $p['items'];
        $this->assertSame(['m1', 'message', 'instagram', 'conv_7', 'raj'], [$a['external_id'], $a['kind'], $a['platform'], $a['thread_id'], $a['author_handle']]);
        $this->assertSame('2026-10-18 08:00:00+00', $a['received_at'], 'epoch seconds');
        $this->assertTrue($b['is_own'], 'direction outbound = ours');
        $this->assertTrue($c['is_own']);
        $this->assertSame('Ok', $c['text'], 'body.text read');
        $this->assertSame('p2', $p['next'], 'cursor taken from a next URL');
    }

    public function testATopLevelListEpochMillisAndKinds(): void
    {
        $p = SocialInboxParser::page([
            ['id' => 'a', 'text' => 'x', 'created_at' => 1_792_310_400_000, 'type' => 'MENTION'],
            ['id' => 'b', 'text' => 'y', 'kind' => 'review'],
            ['id' => 'c', 'text' => 'z', 'type' => 'dm'],
            ['id' => 'd', 'text' => 'z', 'type' => 'admin_note'],
            ['id' => 'e', 'text' => 'z', 'conversation_id' => 'cv'],
        ]);
        $this->assertSame(['mention', 'review', 'message', 'other', 'message'], array_column($p['items'], 'kind'));
        $this->assertSame('2026-10-18 08:00:00+00', $p['items'][0]['received_at'], 'epoch milliseconds');
        $this->assertNull($p['next']);
    }

    public function testItemsWithoutIdOrTextAreDroppedAndExplainedNotGuessed(): void
    {
        $p = SocialInboxParser::page(['items' => [
            ['text' => 'no id here'],
            ['id' => 'x1', 'attachments' => [['type' => 'image']]],
            ['id' => 'x2', 'text' => 'kept', 'status' => 'deleted'],
        ]]);
        $this->assertCount(1, $p['items']);
        $this->assertTrue($p['items'][0]['is_deleted']);
        $this->assertSame(['item without an id (keys: text)', 'item without text (keys: id, attachments)'], $p['skipped']);
    }

    public function testAnUnknownShapeIsNotRecognised(): void
    {
        $p = SocialInboxParser::page(['total' => 3, 'unexpected' => ['a' => 1]]);
        $this->assertFalse($p['recognised']);
        $this->assertSame(['total', 'unexpected'], $p['keys']);
        $this->assertSame([], $p['items']);

        $this->assertTrue(SocialInboxParser::page([])['recognised'], 'an empty list is a valid empty page');
        $this->assertTrue(SocialInboxParser::page(['data' => ['items' => []]])['recognised'], 'a list one level under data');
    }

    public function testCursorVariantsAndHasMoreFalse(): void
    {
        $this->assertSame('n1', SocialInboxParser::cursor(['meta' => ['next_cursor' => 'n1']]));
        $this->assertSame('n2', SocialInboxParser::cursor(['paging' => ['cursors' => ['after' => 'n2']]]));
        $this->assertNull(SocialInboxParser::cursor(['next_cursor' => 'n3', 'has_more' => false]));
        $this->assertNull(SocialInboxParser::cursor(['next' => true]));
        $this->assertNull(SocialInboxParser::cursor(['next' => 'https://x.example/inbox?limit=5']), 'a next URL without a cursor parameter is not followed');
    }

    public function testNonHttpsLinksAreDropped(): void
    {
        $i = SocialInboxParser::item(['id' => '1', 'text' => 't', 'permalink' => 'javascript:alert(1)', 'url' => 'http://insecure.example/x']);
        $this->assertNull($i['post_url']);
    }
}

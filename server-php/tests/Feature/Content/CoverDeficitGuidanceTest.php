<?php

namespace Tests\Feature\Content;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The deficit report is the operator's work list: it has to say what to
 * generate AND how to tag the result, because an untagged upload can never be
 * matched to an article no matter how good the artwork is.
 */
final class CoverDeficitGuidanceTest extends ApiTestCase
{
    private function deficit(): array
    {
        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/media/gallery/deficit');
        $this->assertSame(200, $response->response()->getStatusCode());

        return json_decode((string) $response->getJSON(), true)['data'];
    }

    public function testEveryUpcomingEntryCarriesAPasteReadyPromptAndTags(): void
    {
        $data = $this->deficit();
        $this->assertNotEmpty($data['upcoming'], 'The repo content base ships upcoming blog entries.');

        foreach ($data['upcoming'] as $entry) {
            $this->assertStringContainsString(
                'Generate one landscape image',
                $entry['image_prompt'],
                'The prompt must be an instruction, not the bare scene note.',
            );
            $this->assertStringContainsString($entry['title'], $entry['image_prompt']);
            $this->assertNotEmpty($entry['suggested_tags'], 'A cover with no tags can never be assigned.');
        }
    }

    public function testSuggestedTagsUseTheScorersOwnVocabulary(): void
    {
        foreach ($this->deficit()['upcoming'] as $entry) {
            foreach ($entry['suggested_tags'] as $tag) {
                // Sub-3-character tokens are dropped by the scorer (except a
                // short whitelist), so suggesting them would be advice that
                // silently does nothing.
                $this->assertTrue(
                    mb_strlen($tag) >= 3 || in_array($tag, ['gst', 'tds', 'itr', 'roc', 'kyc'], true),
                    "Suggested tag '{$tag}' would be discarded by the matcher.",
                );
                $this->assertNotContains($tag, ['guide', 'complete', 'indian', 'business', 'steps']);
            }
        }
    }

    public function testTaggedUploadsMatchTheArticleTheyWereSuggestedFor(): void
    {
        $entry = $this->deficit()['upcoming'][0];

        // Round-trip the advice: tag exactly as suggested, then confirm the
        // scorer clears its own floor for that article.
        $keywords = (new \App\Libraries\Media\CoverRelevanceScorer())->articleKeywords($entry['title']);
        $result   = (new \App\Libraries\Media\CoverRelevanceScorer())->score(
            ['category_tags' => $entry['suggested_tags'], 'portfolio_stream' => $entry['stream']],
            $keywords,
            '',
            $entry['stream'],
        );

        $this->assertGreaterThanOrEqual(3, $result['score'], 'Following the console\'s own advice must produce a matchable cover.');
    }

    public function testRetiredAssetsStopReportingAsMissingFiles(): void
    {
        $db  = Database::connect();
        $key = bin2hex(random_bytes(8));

        // A row reconcile has already dealt with: file gone, status retired.
        $db->table('reach_media_gallery_assets')->insert([
            'asset_uuid'      => 'retired-' . $key,
            'kind'            => 'gallery_upload',
            'file_path'       => '/nonexistent/' . $key . '.webp',
            'mime'            => 'image/webp',
            'bytes'           => 0,
            'checksum_sha256' => hash('sha256', $key),
            'category_tags'   => json_encode(['gst']),
            'status'          => 'retired',
        ]);

        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/media/gallery');
        $data     = json_decode((string) $response->getJSON(), true)['data'];

        $this->assertSame(
            0,
            $data['files_missing'],
            'A retired asset is settled business; counting it keeps telling the operator to re-run reconcile.',
        );
    }
}

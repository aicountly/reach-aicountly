<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Ai\Images\ImageProviderInterface;
use App\Libraries\Ai\Images\OpenAiImageProvider;
use App\Libraries\Media\CoverGenerator;
use App\Libraries\Media\CoverSelectionService;
use App\Libraries\Publishing\Blog\CoverAltTextBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Cover for a vetted routine blog — CoverSelectionService's rule: a relevant,
 * preferably unused gallery cover first; otherwise a fresh GPT Image cover
 * (`ai.image.model`) drawn from the article's own title, summary and headings,
 * branded with the AICOUNTLY mark and added to the gallery. Either way the
 * profile gets the image and descriptive alt text. Generation is gated by
 * automation.blog.ai_cover (BLOG_IMAGE_GENERATION_ENABLED kill switch).
 */
class RoutineCoverService
{
    private BaseConnection $db;
    private ImageProviderInterface $provider;

    public function __construct(?ImageProviderInterface $provider = null, ?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->provider = $provider ?? new OpenAiImageProvider();
    }

    /**
     * @return array{ok: bool, source: ?string, url: ?string, alt: ?string, asset_id: ?int, reason: ?string, generation_error: ?string}
     */
    public function provide(int $contentItemId, ?string $sceneHint = null): array
    {
        $item    = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray() ?? [];
        $version = $this->db->table('reach_content_versions')->where('id', (int) ($item['current_version_id'] ?? 0))->get()->getRowArray() ?? [];
        $profile = $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];
        $title   = (string) ($version['title'] ?? $item['title'] ?? '');
        $alt     = CoverAltTextBuilder::build($title, (string) ($profile['category'] ?? ''));

        $generate = RoutineFlags::enabled('blog.ai_cover');
        $result   = (new CoverSelectionService(new CoverGenerator($this->provider, $this->db), $this->db))->coverFor($contentItemId, [
            'allow_generate'        => $generate,
            'scene_hint'            => $sceneHint,
            'generation_off_reason' => 'ai_cover_disabled',
        ]);

        if ($result['ok']) {
            $this->apply($contentItemId, (string) $result['url'], $alt);

            return ['ok' => true, 'source' => (string) $result['source'], 'url' => (string) $result['url'], 'alt' => $alt,
                'asset_id' => $result['asset_id'], 'reason' => null, 'generation_error' => $result['generation_error']];
        }

        $generationError = $generate ? ($result['generation_error'] ?? 'generation unavailable') : 'ai_cover_disabled';
        if ($result['generation_error'] !== null) {
            log_message('warning', "[RoutineCover] item {$contentItemId}: no gallery match and generation failed: {$generationError}");
        }

        return ['ok' => false, 'source' => null, 'url' => null, 'alt' => null, 'asset_id' => null,
            'reason' => 'no_cover: gallery has no relevant cover; ' . $generationError,
            'generation_error' => $generationError];
    }

    private function apply(int $contentItemId, string $url, string $alt): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->update([
                'featured_image_reference' => mb_substr($url, 0, 512),
                'featured_image_alt'       => mb_substr($alt, 0, 512),
                'updated_at'               => $now,
            ]);

        // Readiness gate 12 reads alt text from any featured-image requirement.
        $this->db->table('reach_content_media_requirements')
            ->where('content_item_id', $contentItemId)
            ->where('media_type', 'featured_image')
            ->groupStart()->where('alt_text IS NULL', null, false)->orWhere('alt_text', '')->groupEnd()
            ->update(['alt_text' => mb_substr($alt, 0, 500), 'updated_at' => $now]);
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Ai\Images\CoverPromptBuilder;
use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\ImageProviderInterface;
use App\Libraries\Ai\Images\OpenAiImageProvider;
use App\Libraries\Media\MediaAssetStore;
use App\Libraries\Media\MediaGalleryAssignmentService;
use App\Libraries\Publishing\Blog\CoverAltTextBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Cover for a vetted routine blog: a fresh image from the OpenAI images API
 * (DALL·E — `ai.image.model`), drawn from the article's own title, summary and
 * category with no text in the image; the curated gallery only when that
 * fails. Either way the profile gets the image and descriptive alt text.
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
        $item     = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray() ?? [];
        $version  = $this->db->table('reach_content_versions')->where('id', (int) ($item['current_version_id'] ?? 0))->get()->getRowArray() ?? [];
        $profile  = $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];
        $details  = $this->db->table('reach_content_blog_details')->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];
        $category = trim((string) ($profile['category'] ?? '')) ?: trim((string) ($details['portfolio_stream'] ?? ''));
        $title    = (string) ($version['title'] ?? $item['title'] ?? '');
        $alt      = CoverAltTextBuilder::build($title, (string) ($profile['category'] ?? ''));

        $generationError = null;
        if (RoutineFlags::enabled('blog.ai_cover')) {
            try {
                $url = $this->generate($contentItemId, $title, (string) ($version['summary'] ?? ''), $category, $sceneHint, $details);
                $this->apply($contentItemId, $url, $alt);

                return ['ok' => true, 'source' => 'generated', 'url' => $url, 'alt' => $alt, 'asset_id' => null, 'reason' => null, 'generation_error' => null];
            } catch (\Throwable $e) {
                $generationError = mb_substr($e->getMessage(), 0, 200);
                log_message('warning', "[RoutineCover] item {$contentItemId}: generation failed, trying the gallery: {$generationError}");
            }
        } else {
            $generationError = 'ai_cover_disabled';
        }

        try {
            $assignment = (new MediaGalleryAssignmentService($this->db))->assignFor($contentItemId);
        } catch (\Throwable $e) {
            $assignment = null;
            $generationError = trim(($generationError ?? '') . '; gallery_error: ' . mb_substr($e->getMessage(), 0, 120), '; ');
        }
        if ($assignment !== null) {
            $this->apply($contentItemId, (string) $assignment['public_url'], $alt);

            return ['ok' => true, 'source' => 'gallery', 'url' => (string) $assignment['public_url'], 'alt' => $alt,
                'asset_id' => (int) $assignment['asset_id'], 'reason' => null, 'generation_error' => $generationError];
        }

        return ['ok' => false, 'source' => null, 'url' => null, 'alt' => null, 'asset_id' => null,
            'reason' => 'no_cover: ' . ($generationError ?? 'generation unavailable') . '; gallery has no relevant cover',
            'generation_error' => $generationError];
    }

    /** @param array<string, mixed> $details */
    private function generate(int $contentItemId, string $title, string $summary, string $category, ?string $sceneHint, array $details): string
    {
        if (! $this->provider->isConfigured()) {
            throw new \RuntimeException('OpenAI API key is not set (Settings → AI & Automation)');
        }

        $prompt = (new CoverPromptBuilder())->build($title, $summary, $category, $sceneHint);
        $result = $this->provider->generateImage(new ImageGenerationInput(
            prompt: $prompt,
            modelKey: '',
            width: 1792,
            height: 1024,
            timeoutSeconds: 120,
        ));

        $binary = null;
        foreach ($result->images as $image) {
            if (! empty($image['b64'])) {
                $decoded = base64_decode((string) $image['b64'], true);
                if ($decoded !== false && $decoded !== '') {
                    $binary = $decoded;
                    break;
                }
            }
        }
        if ($binary === null) {
            throw new \RuntimeException('image_provider_returned_no_usable_image');
        }

        $store = new MediaAssetStore($this->db);
        $asset = $store->storeBinary($binary, [
            'kind'             => 'ai_generated',
            'content_item_id'  => $contentItemId,
            'prompt_used'      => $prompt,
            'portfolio_stream' => $details['portfolio_stream'] ?? null,
        ]);

        return $store->publicUrl($asset);
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

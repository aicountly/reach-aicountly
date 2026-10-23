<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Ai\Images\CoverPromptBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reuse or generate: the cover decision for every new blog, routine and
 * calendar alike (RoutineCoverService, WorkBlockService GENERATE_IMAGE).
 *
 * The rule
 *   1. A relevant gallery cover wins — MediaGalleryAssignmentService scores
 *      operator uploads and Reach's own stocked covers against the article
 *      (content-base entry key, category, tags, title; topical score must reach
 *      CoverRelevanceScorer::minScore(), a stream match alone never counts),
 *      prefers a never-used cover, honours the reuse cooldown, and brands a
 *      legacy upload with the Aicountly mark on the way out.
 *   2. Otherwise a fresh cover is generated with GPT Image from the article's
 *      own title, summary and section headings (CoverPromptBuilder), branded,
 *      and added to the gallery (kind ai_gallery, tagged with the article's
 *      keywords) so a later article on the same topic can reuse it.
 *   3. Neither → ok=false with the reason. The caller records the cover as
 *      missing; nothing pretends there is one.
 */
class CoverSelectionService
{
    private BaseConnection $db;
    private CoverGenerator $generator;
    private MediaGalleryAssignmentService $gallery;

    public function __construct(?CoverGenerator $generator = null, ?BaseConnection $db = null, ?MediaGalleryAssignmentService $gallery = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->generator = $generator ?? new CoverGenerator(null, $this->db);
        $this->gallery   = $gallery ?? new MediaGalleryAssignmentService($this->db);
    }

    /**
     * @param array{allow_generate?:bool, prefer_gallery?:bool, scene_hint?:?string, prompt?:?string, purpose?:string, generation_off_reason?:string} $opts
     * @return array{ok:bool, source:?string, url:?string, asset_id:?int, asset_uuid:?string, relevance_score:?int, match_reasons:list<string>,
     *               branded:bool, prompt:?string, model_key:?string, gallery_miss:?string, generation_error:?string, reason:?string}
     */
    public function coverFor(int $contentItemId, array $opts = []): array
    {
        $allowGenerate = (bool) ($opts['allow_generate'] ?? true);
        $preferGallery = (bool) ($opts['prefer_gallery'] ?? true);
        $out = [
            'ok' => false, 'source' => null, 'url' => null, 'asset_id' => null, 'asset_uuid' => null,
            'relevance_score' => null, 'match_reasons' => [], 'branded' => false, 'prompt' => null, 'model_key' => null,
            'gallery_miss' => null, 'generation_error' => null, 'reason' => null,
        ];

        if ($preferGallery) {
            try {
                $assignment = $this->gallery->assignFor($contentItemId);
            } catch (\Throwable $e) {
                $assignment = null;
                $out['gallery_miss'] = 'gallery_assignment_error: ' . mb_substr($e->getMessage(), 0, 160);
            }
            if ($assignment !== null) {
                return [
                    'ok'              => true,
                    'source'          => 'gallery',
                    'url'             => (string) $assignment['public_url'],
                    'asset_id'        => (int) $assignment['asset_id'],
                    'asset_uuid'      => (string) $assignment['asset_uuid'],
                    'relevance_score' => (int) $assignment['relevance_score'],
                    'match_reasons'   => $assignment['match_reasons'],
                    'branded'         => (bool) ($assignment['branded'] ?? false),
                ] + $out;
            }
            $out['gallery_miss'] ??= 'gallery_no_relevant_cover';
        }

        if (! $allowGenerate) {
            $out['reason'] = trim(($out['gallery_miss'] ?? '') . '; ' . ($opts['generation_off_reason'] ?? 'cover generation is off'), '; ');

            return $out;
        }

        $prompt = trim((string) ($opts['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $this->articlePrompt($contentItemId, $opts['scene_hint'] ?? null);
        }
        $context = $this->gallery->articleContext($contentItemId);
        $tags    = array_slice(array_values(array_unique(array_filter(array_merge(
            $context['category'] !== '' ? [mb_strtolower($context['category'])] : [],
            $context['keywords'],
        )))), 0, 8);

        try {
            $asset = $this->generator->generate($prompt, [
                'content_item_id'  => $contentItemId,
                'category_tags'    => $tags,
                'portfolio_stream' => $context['stream'] !== '' ? $context['stream'] : null,
            ], (string) ($opts['purpose'] ?? CoverGenerator::PURPOSE_ARTICLE));
        } catch (CoverGenerationException $e) {
            $out['generation_error'] = $e->getMessage();
            $out['prompt']           = $prompt;
            $out['reason']           = trim(($out['gallery_miss'] ?? '') . '; ' . $e->getMessage(), '; ');

            return $out;
        }

        $store = new MediaAssetStore($this->db);
        $store->markUsed((int) $asset['id'], $contentItemId);

        return [
            'ok'         => true,
            'source'     => 'generated',
            'url'        => $store->publicUrl($asset),
            'asset_id'   => (int) $asset['id'],
            'asset_uuid' => (string) $asset['asset_uuid'],
            'branded'    => MediaAssetStore::isBranded($asset),
            'prompt'     => $prompt,
            'model_key'  => $asset['model_key'] ?? null,
        ] + $out;
    }

    /**
     * The article's own cover prompt: current title, summary, published
     * category and section headings. The portfolio stream is deliberately not
     * the theme ("marketing" puts megaphones on a GST article).
     */
    public function articlePrompt(int $contentItemId, ?string $sceneHint = null): string
    {
        $item    = $this->db->table('reach_content_items')->where('id', $contentItemId)->get()->getRowArray() ?? [];
        $version = (int) ($item['current_version_id'] ?? 0) > 0
            ? ($this->db->table('reach_content_versions')->where('id', (int) $item['current_version_id'])->get()->getRowArray() ?? [])
            : [];
        $profile = $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];

        return (new CoverPromptBuilder())->build(
            (string) (($version['title'] ?? '') !== '' ? $version['title'] : ($item['title'] ?? '')),
            (string) (($version['summary'] ?? '') !== '' ? $version['summary'] : ($profile['excerpt'] ?? '')),
            trim((string) ($profile['category'] ?? '')),
            $sceneHint,
            CoverPromptBuilder::keyPointsFrom($version['body_markdown'] ?? null, $version['body_html'] ?? null),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\ImageProviderInterface;
use App\Libraries\Ai\Images\OpenAiImageProvider;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The one place Reach pays for a cover image.
 *
 * Calls the configured OpenAI GPT Image model (ai.image.model, ai.image.size,
 * ai.image.quality — OpenAiImageProvider), checks what came back (decodes as
 * an image, sensible dimensions, not empty), composites the Aicountly corner
 * mark (CoverBranding; the unbranded original is kept), stores it in the Cover
 * Gallery as an active `ai_gallery` asset with the topic's tags and records the
 * attempt in reach_cover_generation_attempts — success or failure — which is
 * what the daily cap, the daily report and the failure alert read.
 *
 * Honest by construction: an unconfigured or failing provider throws
 * CoverGenerationException and nothing is stored, so no caller can mark an
 * article as having a cover it does not have.
 *
 * There is no image-vision vetting path in Reach, so none is invented here;
 * the checks are deliberately basic.
 */
class CoverGenerator
{
    public const PURPOSE_GALLERY = 'gallery_stock';
    public const PURPOSE_ARTICLE = 'article';
    public const PURPOSE_REFRESH = 'refresh';

    public const MIN_WIDTH  = 512;
    public const MIN_HEIGHT = 256;

    private BaseConnection $db;
    private ImageProviderInterface $provider;
    private MediaAssetStore $store;
    private CoverBranding $branding;

    public function __construct(?ImageProviderInterface $provider = null, ?BaseConnection $db = null, ?MediaAssetStore $store = null, ?CoverBranding $branding = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->provider = $provider ?? new OpenAiImageProvider();
        $this->store    = $store ?? new MediaAssetStore($this->db);
        $this->branding = $branding ?? new CoverBranding();
    }

    public function provider(): ImageProviderInterface
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * @param array{content_item_id?:int|null, source_key?:string|null, category_tags?:list<string>, portfolio_stream?:string|null} $meta
     * @return array<string,mixed> the stored gallery asset row
     * @throws CoverGenerationException
     */
    public function generate(string $prompt, array $meta, string $purpose): array
    {
        $contentItemId = isset($meta['content_item_id']) && (int) $meta['content_item_id'] > 0 ? (int) $meta['content_item_id'] : null;
        $sourceKey     = isset($meta['source_key']) && trim((string) $meta['source_key']) !== '' ? trim((string) $meta['source_key']) : null;

        if (! $this->provider->isConfigured()) {
            $error = 'Image provider not configured: the OpenAI API key is not set (Settings → AI & Automation → AI providers).';
            $this->record($purpose, 'failed', $sourceKey, $contentItemId, null, null, $error);
            throw new CoverGenerationException($error, CoverGenerationException::NOT_CONFIGURED);
        }

        [$width, $height] = self::size();
        $modelKey = null;

        try {
            $result   = $this->provider->generateImage(new ImageGenerationInput(
                prompt: $prompt,
                modelKey: '',
                width: $width,
                height: $height,
                format: 'png',
                timeoutSeconds: 120,
            ));
            $modelKey = $result->modelKey !== '' ? mb_substr($result->modelKey, 0, 64) : null;
            $binary   = $this->firstImage($result->images);
            $this->check($binary);

            $branded = $this->branding->apply($binary);
            if (! $branded['branded']) {
                log_message('warning', "[CoverGenerator] cover stored without the logo: {$branded['reason']}");
            }

            $asset = $this->store->storeBinary($branded['bytes'], [
                'kind'             => 'ai_gallery',
                'content_item_id'  => $contentItemId,
                'prompt_used'      => $prompt,
                'category_tags'    => array_values(array_unique(array_filter(array_map(
                    static fn ($t) => mb_strtolower(trim((string) $t)),
                    (array) ($meta['category_tags'] ?? []),
                )))),
                'portfolio_stream' => $meta['portfolio_stream'] ?? null,
                'source_key'       => $sourceKey,
                'provenance'       => 'aicountly_generated',
                'branded'          => $branded['branded'],
                'model_key'        => $modelKey,
                'original_binary'  => $branded['branded'] ? $binary : null,
            ]);
        } catch (CoverGenerationException $e) {
            $this->record($purpose, 'failed', $sourceKey, $contentItemId, null, $modelKey, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 400);
            $this->record($purpose, 'failed', $sourceKey, $contentItemId, null, $modelKey, $message);
            throw new CoverGenerationException('Image generation failed: ' . $message, CoverGenerationException::PROVIDER_ERROR, $e);
        }

        $this->record($purpose, 'generated', $sourceKey, $contentItemId, (int) $asset['id'], $modelKey, null);

        return $asset;
    }

    /** Covers generated (paid, successful) since UTC midnight. */
    public function generatedToday(): int
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_cover_generation_attempts')) {
            return 0;
        }

        return $this->db->table('reach_cover_generation_attempts')
            ->where('status', 'generated')
            ->where('created_at >=', gmdate('Y-m-d 00:00:00') . '+00')
            ->countAllResults();
    }

    /** covers.auto.daily_limit minus what was generated today (never negative). */
    public function remainingToday(): int
    {
        return max(0, self::dailyLimit() - $this->generatedToday());
    }

    public static function dailyLimit(): int
    {
        try {
            return max(0, (int) ReachConfig::get('covers.auto.daily_limit', 10));
        } catch (\Throwable) {
            return 10;
        }
    }

    /** @return array{0:int, 1:int} width, height from ai.image.size (blog covers are landscape). */
    public static function size(): array
    {
        try {
            $configured = trim((string) ReachConfig::get('ai.image.size', '1536x1024'));
        } catch (\Throwable) {
            $configured = '1536x1024';
        }
        if (preg_match('/^(\d{3,4})x(\d{3,4})$/', $configured, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [1536, 1024];
    }

    /** @param list<array<string,mixed>> $images */
    private function firstImage(array $images): string
    {
        foreach ($images as $image) {
            if (! empty($image['b64'])) {
                $decoded = base64_decode((string) $image['b64'], true);
                if ($decoded !== false && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        throw new CoverGenerationException('The image provider returned no usable image.', CoverGenerationException::BAD_IMAGE);
    }

    /** Basic quality gate: decodes, not tiny. */
    private function check(string $binary): void
    {
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new CoverGenerationException('The image provider returned data that is not an image.', CoverGenerationException::BAD_IMAGE);
        }
        if ((int) $info[0] < self::MIN_WIDTH || (int) $info[1] < self::MIN_HEIGHT) {
            throw new CoverGenerationException(sprintf('The generated image is only %dx%d.', (int) $info[0], (int) $info[1]), CoverGenerationException::BAD_IMAGE);
        }
    }

    private function record(string $purpose, string $status, ?string $sourceKey, ?int $contentItemId, ?int $assetId, ?string $modelKey, ?string $error): void
    {
        try {
            if (! SchemaGuard::hasTable($this->db, 'reach_cover_generation_attempts')) {
                return;
            }
            $this->db->table('reach_cover_generation_attempts')->insert([
                'purpose'         => $purpose,
                'status'          => $status,
                'source_key'      => $sourceKey,
                'content_item_id' => $contentItemId,
                'asset_id'        => $assetId,
                'model_key'       => $modelKey,
                'error'           => $error !== null ? mb_substr($error, 0, 1000) : null,
                'created_at'      => gmdate('Y-m-d H:i:s') . '+00',
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[CoverGenerator] could not record the attempt: ' . $e->getMessage());
        }
    }
}

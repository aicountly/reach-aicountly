<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\HtmlSanitizer;
use App\Models\Distribution\CampaignChannelVariantModel;

class CampaignChannelVariantService
{
    private const EMAIL_MAX_CHARS   = 50000;
    private const SMS_MAX_CHARS     = 160;
    private const SOCIAL_MAX_CHARS  = 3000;
    private const WHATSAPP_MAX_CHARS = 1024;

    public function __construct(
        private readonly CampaignChannelVariantModel $model,
        private readonly AuditLogger                 $audit,
    ) {}

    public function create(int $versionId, string $channel, array $contentJson, ?int $actorId): array
    {
        $this->assertImmutableVersion($versionId);

        $id = $this->model->insert([
            'campaign_version_id' => $versionId,
            'channel'             => $channel,
            'content_json'        => json_encode($contentJson),
            'validation_status'   => 'pending',
            'created_by'          => $actorId,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        $variant = $this->model->find($id);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_VARIANT_CREATED, [
            'variant_id' => $id,
            'version_id' => $versionId,
            'channel'    => $channel,
        ], $actorId);

        return $variant;
    }

    public function validate(int $variantId, ?int $actorId): array
    {
        $variant = $this->model->find($variantId);
        if ($variant === null) {
            throw new \RuntimeException('Variant not found.', 404);
        }

        $content  = is_array($variant['content_json']) ? $variant['content_json'] : (json_decode($variant['content_json'] ?? '{}', true) ?? []);
        $findings = $this->runValidation((string) $variant['channel'], $content);
        $status   = empty($findings) ? 'valid' : 'invalid';

        $this->model->update($variantId, [
            'validation_status'   => $status,
            'validation_findings' => json_encode($findings),
        ]);

        $event = $status === 'valid'
            ? AuditLogger::DISTRIBUTION_CAMPAIGN_VARIANT_VALIDATED
            : AuditLogger::DISTRIBUTION_CAMPAIGN_VARIANT_INVALID;

        $this->audit->record($event, [
            'variant_id' => $variantId,
            'status'     => $status,
            'findings'   => $findings,
        ], $actorId);

        return $this->model->find($variantId);
    }

    public function listForVersion(int $versionId): array
    {
        return $this->model->findByVersion($versionId);
    }

    private function runValidation(string $channel, array $content): array
    {
        $findings = [];
        $body     = $content['body'] ?? $content['text'] ?? $content['message'] ?? '';
        $len      = mb_strlen((string) $body);

        $limit = match($channel) {
            'email'    => self::EMAIL_MAX_CHARS,
            'sms'      => self::SMS_MAX_CHARS,
            'whatsapp' => self::WHATSAPP_MAX_CHARS,
            default    => self::SOCIAL_MAX_CHARS,
        };

        if ($len === 0) {
            $findings[] = ['code' => 'BODY_EMPTY', 'message' => 'Content body is required.'];
        } elseif ($len > $limit) {
            $findings[] = ['code' => 'BODY_TOO_LONG', 'message' => "Content exceeds {$limit} character limit ({$len} characters)."];
        }

        if ($channel === 'email' && empty($content['subject'])) {
            $findings[] = ['code' => 'SUBJECT_MISSING', 'message' => 'Email subject is required.'];
        }

        if ($channel === 'whatsapp' && empty($content['template_id'])) {
            $findings[] = ['code' => 'TEMPLATE_REQUIRED', 'message' => 'WhatsApp requires a pre-approved template.'];
        }

        return $findings;
    }

    private function assertImmutableVersion(int $versionId): void
    {
        $db  = \Config\Database::connect();
        $row = $db->table('reach_campaign_versions')->select('approved_at')->where('id', $versionId)->get()->getRowArray();
        if ($row !== null && $row['approved_at'] !== null) {
            throw new \RuntimeException('Cannot modify variants of an approved version (immutable).', 409);
        }
    }
}

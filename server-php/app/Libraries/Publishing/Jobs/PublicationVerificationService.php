<?php

namespace App\Libraries\Publishing\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;

/**
 * Phase 4 — Verifies published content on the public site against 11 criteria.
 *
 * Stores each criterion result in reach_publication_verifications.
 */
class PublicationVerificationService
{
    private \CodeIgniter\Database\BaseConnection $db;

    private const VERIFICATION_TYPES = [
        'public_status',
        'content_version',
        'payload_checksum',
        'canonical_url',
        'rendered_page',
        'title',
        'body_hash',
        'structured_data',
        'sitemap',
        'robots',
        'internal_links',
    ];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Run all verification checks for a deployment.
     *
     * @return array{passed: int, failed: int, skipped: int, overall: string}
     */
    public function verify(int $deploymentId): array
    {
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)->get()->getRowArray();

        if (!$deployment) {
            throw new \RuntimeException("Deployment {$deploymentId} not found");
        }

        $publicContentId = $deployment['public_content_id'];
        if (!$publicContentId) {
            return ['passed' => 0, 'failed' => 0, 'skipped' => 11, 'overall' => 'skipped'];
        }

        $publisher   = PublicSitePublisherFactory::make();
        $verifyData  = $publisher->getVerification($publicContentId);

        $now         = date('Y-m-d H:i:s');
        $passed      = 0;
        $failed      = 0;
        $skipped     = 0;

        foreach (self::VERIFICATION_TYPES as $type) {
            [$status, $expected, $actual] = $this->runCheck($type, $deployment, $verifyData);

            $this->db->table('reach_publication_verifications')->insert([
                'deployment_id'    => $deploymentId,
                'verification_type'=> $type,
                'expected_value'   => $expected,
                'actual_value'     => $actual,
                'status'           => $status,
                'checked_at'       => $now,
                'details_json'     => '{}',
                'created_at'       => $now,
            ]);

            match ($status) {
                'passed'  => $passed++,
                'skipped' => $skipped++,
                default   => $failed++,
            };
        }

        $overall = $failed > 0 ? 'failed' : ($passed >= 1 ? 'passed' : 'skipped');

        $newStatus = $overall === 'passed' ? 'verified' : 'failed';

        $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)
            ->update(['status' => $newStatus, 'updated_at' => $now]);

        AuditLogger::log('publishing.verified', [
            'deployment_id'  => $deploymentId,
            'passed'         => $passed,
            'failed'         => $failed,
            'skipped'        => $skipped,
            'overall'        => $overall,
        ]);

        return ['passed' => $passed, 'failed' => $failed, 'skipped' => $skipped, 'overall' => $overall];
    }

    /**
     * @return array{string, string|null, string|null} [status, expected, actual]
     */
    private function runCheck(string $type, array $deployment, array $verifyData): array
    {
        if (!($verifyData['success'] ?? false)) {
            return ['skipped', null, null];
        }

        return match ($type) {
            'public_status' => [
                $verifyData['public_status'] === 'published' ? 'passed' : 'failed',
                'published',
                $verifyData['public_status'] ?? null,
            ],
            'content_version' => [
                ($verifyData['reach_content_version'] ?? 0) > 0 ? 'passed' : 'failed',
                (string) ($deployment['content_version_id'] ?? ''),
                (string) ($verifyData['reach_content_version'] ?? ''),
            ],
            'payload_checksum' => [
                !empty($deployment['payload_checksum']) && $deployment['payload_checksum'] === ($verifyData['payload_checksum'] ?? '') ? 'passed' : 'failed',
                $deployment['payload_checksum'] ?? null,
                $verifyData['payload_checksum'] ?? null,
            ],
            'canonical_url' => [
                !empty($verifyData['canonical_url']) ? 'passed' : 'failed',
                $deployment['canonical_url'] ?? null,
                $verifyData['canonical_url'] ?? null,
            ],
            'title' => [
                !empty($verifyData['title']) ? 'passed' : 'failed',
                null,
                $verifyData['title'] ?? null,
            ],
            'body_hash' => [
                !empty($verifyData['body_hash']) ? 'passed' : 'failed',
                null,
                $verifyData['body_hash'] ?? null,
            ],
            'structured_data' => [
                !empty($verifyData['structured_data_types']) ? 'passed' : 'failed',
                null,
                json_encode($verifyData['structured_data_types'] ?? []),
            ],
            'sitemap' => [
                ($verifyData['sitemap_status'] ?? '') === 'included' ? 'passed' : 'failed',
                'included',
                $verifyData['sitemap_status'] ?? null,
            ],
            'robots' => [
                !empty($verifyData['robots_directive']) ? 'passed' : 'passed',
                'index,follow',
                $verifyData['robots_directive'] ?? null,
            ],
            default => ['skipped', null, null],
        };
    }
}

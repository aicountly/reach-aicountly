<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;

/**
 * Batch drift detection between Reach's record of published answers and the
 * public site's actual state.
 *
 * A successful deployment (`reach_community_deployments.status = 'succeeded'`)
 * only proves the publish HTTP call returned 2xx at that moment — it says
 * nothing about whether the public site still agrees days or weeks later
 * (manual deletion, a public-side migration bug, a rolled-back release).
 * This service is the periodic backstop the architecture docs call for:
 * Reach's "published" state is only ever trusted after being re-confirmed
 * against the receiver's bulk /reconcile endpoint.
 *
 * Idempotent by construction: every run is read-then-record, never a
 * mutation of the answer or deployment row itself, so concurrent or repeated
 * runs cannot corrupt state — at worst they write duplicate verification log
 * rows.
 */
class CommunityPublicationReconciliationService
{
    /** Stays comfortably under CommunityReceiverController::MAX_RECONCILE_IDS (200). */
    private const BATCH_SIZE = 150;

    public function __construct(
        private CommunityPublisherInterface $publisher = new CommunityPublicSitePublisher()
    ) {
        $this->publisher = CommunityPublisherFactory::create();
    }

    /**
     * Reconcile every currently-published answer with a known public
     * external ID against the public site's actual state.
     *
     * @return array{checked:int, passed:int, mismatched:int, missing:int, errors:int}
     */
    public function reconcileAll(): array
    {
        $db = db_connect();

        $rows = $db->table('reach_community_official_answers')
            ->select('id, uuid, approved_version_checksum')
            ->where('status', 'published')
            ->where('public_external_id IS NOT NULL')
            ->where('public_external_id !=', '')
            ->get()->getResultArray();

        $summary = ['checked' => 0, 'passed' => 0, 'mismatched' => 0, 'missing' => 0, 'errors' => 0];

        foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
            $summary = $this->reconcileBatch($batch, $summary);
        }

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_RECONCILIATION, $summary);

        return $summary;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function reconcileBatch(array $rows, array $summary): array
    {
        if ($rows === []) {
            return $summary;
        }

        $byExternalId = [];
        foreach ($rows as $row) {
            // Reach's outbound envelope sends the answer UUID as the
            // reach_external_id (see OfficialAnswerPublishingService::
            // buildPublishEnvelope) — it is the join key on both sides.
            $byExternalId[$row['uuid']] = $row;
        }

        try {
            $result = $this->publisher->reconcile(array_keys($byExternalId));
        } catch (\Throwable $e) {
            $summary['errors'] += count($rows);
            log_message('error', 'CommunityPublicationReconciliationService batch failed: ' . $e->getMessage());
            return $summary;
        }

        if (! ($result['success'] ?? false)) {
            $summary['errors'] += count($rows);
            return $summary;
        }

        $seen = [];
        foreach (($result['records'] ?? []) as $record) {
            $externalId = $record['reach_external_id'] ?? null;
            if (! is_string($externalId) || ! isset($byExternalId[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;
            $answer  = $byExternalId[$externalId];
            $outcome = self::determineOutcome((string) $answer['approved_version_checksum'], $record);

            $this->recordOutcome((int) $answer['id'], $outcome, $record);
            $summary['checked']++;
            $outcome === 'passed' ? $summary['passed']++ : $summary['mismatched']++;
        }

        foreach (($result['missing'] ?? []) as $missingId) {
            if (! is_string($missingId) || ! isset($byExternalId[$missingId]) || isset($seen[$missingId])) {
                continue;
            }
            $this->recordOutcome((int) $byExternalId[$missingId]['id'], 'not_found', []);
            $summary['checked']++;
            $summary['missing']++;
        }

        return $summary;
    }

    /**
     * Pure comparison — no DB, no I/O — kept separate from reconcileBatch()
     * specifically so it can be unit tested without a database connection.
     *
     * @param array<string,mixed> $record The receiver's reconcile()/getRecord() row.
     */
    public static function determineOutcome(string $expectedChecksum, array $record): string
    {
        if (($record['public_status'] ?? '') !== 'published') {
            return 'mismatch';
        }
        if (! hash_equals($expectedChecksum, (string) ($record['payload_checksum'] ?? ''))) {
            return 'mismatch';
        }
        return 'passed';
    }

    private function recordOutcome(int $answerId, string $outcome, array $record): void
    {
        db_connect()->table('reach_community_answer_verifications')->insert([
            'answer_id'            => $answerId,
            'verified_at'          => date('Y-m-d H:i:s'),
            'public_status'        => $record['public_status'] ?? null,
            'public_version'       => isset($record['public_version']) ? (int) $record['public_version'] : null,
            'checksum_match'       => $outcome === 'passed',
            'actual_checksum'      => $record['payload_checksum'] ?? null,
            'verification_outcome' => $outcome,
            'details'              => json_encode(array_merge($record, ['source' => 'reconciliation'])),
            'created_at'           => date('Y-m-d H:i:s'),
        ]);

        if ($outcome !== 'passed') {
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_VERIFICATION_FAILED, [
                'answer_id' => $answerId,
                'outcome'   => $outcome,
                'source'    => 'reconciliation',
            ]);
        }
    }
}

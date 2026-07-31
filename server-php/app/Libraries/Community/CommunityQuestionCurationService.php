<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;

/**
 * question_curator operational role.
 *
 * Sourcing candidate questions from approved external feeds (forums, support
 * tickets, search-query gaps) is a data-integration and product-configuration
 * concern — which feeds are "approved sources" is a business decision, not
 * something this audit can safely invent. This service's responsibility
 * starts once a candidate has arrived (via CommunityQuestionIntakeService's
 * existing 'import'/'content_request' source types) and covers exactly what
 * the runtime can do deterministically: normalise, deduplicate, classify,
 * score, and record why the curator selected/prioritised it.
 */
class CommunityQuestionCurationService
{
    public function __construct(
        private readonly CommunityQuestionIntakeService       $intake  = new CommunityQuestionIntakeService(),
        private readonly CommunityQuestionClassificationService $classifier = new CommunityQuestionClassificationService(),
        private readonly CommunityTriageService                $triage  = new CommunityTriageService(),
        private readonly CommunityDuplicateDetectionService    $dupes   = new CommunityDuplicateDetectionService(),
    ) {}

    /**
     * @param array{title:string, body?:string, source_type?:string, source_url?:string, product?:string, jurisdiction?:string, language?:string} $candidate
     */
    public function curate(array $identity, array $candidate, ?int $actorId): array
    {
        if (trim((string) ($candidate['title'] ?? '')) === '') {
            throw new \InvalidArgumentException('CommunityQuestionCurationService: candidate title is required.');
        }

        $question   = $this->intake->intake($candidate, $actorId);
        $questionId = (int) $question['id'];

        $classification = $this->classifier->classifyById($questionId);
        $triageScore    = $this->triage->scoreById($questionId);
        $duplicates     = $this->dupes->checkInline($question);
        $duplicateCount = count($duplicates['duplicate_candidates'] ?? []);

        $selectionReason = $this->buildSelectionReason($triageScore, $duplicateCount, $classification);

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_INGESTED, [
            'question_id'       => $questionId,
            'question_uuid'     => $question['uuid'] ?? null,
            'curator_identity'  => $identity['slug'],
            'triage_score'      => $triageScore,
            'duplicate_count'   => $duplicateCount,
            'selection_reason'  => $selectionReason,
        ], $actorId);

        return [
            'question_id'      => $questionId,
            'question_uuid'    => $question['uuid'] ?? null,
            'triage_score'     => $triageScore,
            'duplicate_count'  => $duplicateCount,
            'selection_reason' => $selectionReason,
            'skip_duplicate'   => $duplicateCount > 0,
        ];
    }

    private function buildSelectionReason(float $triageScore, int $duplicateCount, array $classification): string
    {
        if ($duplicateCount > 0) {
            return "Deprioritised: {$duplicateCount} similar question(s) already tracked.";
        }

        $risk = $classification['risk_classification'] ?? 'low';

        return sprintf(
            'Selected: triage score %.1f, risk %s, no duplicates found.',
            $triageScore,
            $risk
        );
    }
}

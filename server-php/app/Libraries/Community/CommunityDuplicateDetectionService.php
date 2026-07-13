<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Models\CommunityOfficialAnswerModel;

/**
 * Detects duplicate and similar questions.
 *
 * Uses title-similarity heuristics for Phase 5. Production deployments should
 * swap in embedding-based cosine similarity via the AI provider.
 */
class CommunityDuplicateDetectionService
{
    private const SIMILARITY_THRESHOLD = 0.72;

    public function __construct(
        private readonly CommunityQuestionRepository $repo = new CommunityQuestionRepository()
    ) {}

    /**
     * Check for duplicates inline (synchronous, used for manual intake).
     */
    public function checkInline(array $question): array
    {
        $candidates = $this->findCandidates($question);
        if (!empty($candidates)) {
            AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_DUPLICATE_DETECTED, [
                'question_id'       => $question['id'],
                'candidate_count'   => count($candidates),
                'top_candidate_id'  => $candidates[0]['id'],
            ]);
        }
        return array_merge($question, ['duplicate_candidates' => $candidates]);
    }

    /**
     * Find potential duplicates for a given question.
     *
     * @return array<array{id: int, uuid: string, title: string, similarity: float}>
     */
    public function findCandidates(array $question): array
    {
        $similar = $this->repo->findSimilar($question['title'], $question['body'] ?? '', 20);

        $scored = [];
        foreach ($similar as $candidate) {
            if ((int) $candidate['id'] === (int) $question['id']) {
                continue;
            }
            $sim = $this->titleSimilarity($question['title'], $candidate['title']);
            if ($sim >= self::SIMILARITY_THRESHOLD) {
                $scored[] = array_merge($candidate, ['similarity' => round($sim, 3)]);
            }
        }

        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($scored, 0, 10);
    }

    /**
     * Merge a duplicate question into a cluster.
     */
    public function mergeDuplicate(int $duplicateQuestionId, int $canonicalQuestionId, int $clusterId, ?int $actorId = null): void
    {
        $db = db_connect();

        // Add to cluster
        $db->table('reach_community_questions')
            ->where('id', $duplicateQuestionId)
            ->update([
                'duplicate_cluster_id' => $clusterId,
                'status'               => 'duplicate_merged',
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);

        // Increment member count
        $db->query(
            'UPDATE reach_community_duplicate_clusters SET member_count = member_count + 1, updated_at = NOW() WHERE id = ?',
            [$clusterId]
        );

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_DUPLICATE_MERGED, [
            'duplicate_question_id' => $duplicateQuestionId,
            'canonical_question_id' => $canonicalQuestionId,
            'cluster_id'            => $clusterId,
        ], $actorId);
    }

    /**
     * Create a new duplicate cluster with a canonical question.
     */
    public function createCluster(int $canonicalQuestionId): int
    {
        $db = db_connect();
        $db->table('reach_community_duplicate_clusters')->insert([
            'canonical_question_id' => $canonicalQuestionId,
            'member_count'          => 1,
            'similarity_algorithm'  => 'title_similarity',
            'similarity_threshold'  => self::SIMILARITY_THRESHOLD,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }

    /**
     * Simple normalised title similarity using word overlap (Jaccard).
     */
    private function titleSimilarity(string $a, string $b): float
    {
        $wordsA = $this->tokenise($a);
        $wordsB = $this->tokenise($b);

        if (empty($wordsA) || empty($wordsB)) {
            return 0.0;
        }

        $intersection = array_intersect($wordsA, $wordsB);
        $union        = array_unique(array_merge($wordsA, $wordsB));

        return count($intersection) / count($union);
    }

    private function tokenise(string $text): array
    {
        $text  = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text) ?? '');
        $words = array_filter(explode(' ', $text), fn($w) => strlen($w) > 2);
        // Remove common stop words
        $stops = ['the', 'and', 'for', 'with', 'this', 'how', 'can', 'does', 'what', 'are', 'not', 'from'];
        return array_values(array_diff($words, $stops));
    }
}

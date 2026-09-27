<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Let reach_community_question_classifications record heuristic classifications.
 *
 * The table only accepted classified_by 'ai' or 'human', but the one classifier
 * that exists writes 'heuristic' whenever it is not handed a model — which is
 * every caller: curation, manual intake, the intake job and categorisation.
 * Each of them died on INSERT with a CHECK violation after the question row
 * was already saved, so every curated seed was stranded unclassified and the
 * curator never produced a question. The value and the constraint have to
 * move together.
 */
class AllowHeuristicQuestionClassification extends Migration
{
    private const TABLE      = 'reach_community_question_classifications';
    private const CONSTRAINT = 'reach_cq_classifications_classified_by_chk';

    public function up(): void
    {
        $this->replaceConstraint(['ai', 'human', 'heuristic']);
    }

    public function down(): void
    {
        // Classifications are derived data, and 'heuristic' has no honest
        // equivalent under the narrower check, so rolling back discards them.
        $this->db->query('DELETE FROM ' . self::TABLE . " WHERE classified_by = 'heuristic'");

        $this->replaceConstraint(['ai', 'human']);
    }

    /** @param list<string> $values */
    private function replaceConstraint(array $values): void
    {
        // The original CHECK was declared inline, so its name is whatever
        // Postgres generated; drop every CHECK on the column rather than guess.
        $existing = $this->db->query(
            "SELECT conname FROM pg_constraint
              WHERE conrelid = ?::regclass
                AND contype = 'c'
                AND pg_get_constraintdef(oid) LIKE '%classified_by%'",
            [self::TABLE]
        )->getResultArray();

        foreach ($existing as $row) {
            $this->db->query(
                'ALTER TABLE ' . self::TABLE . ' DROP CONSTRAINT ' . $this->db->escapeIdentifiers($row['conname'])
            );
        }

        $list = implode(',', array_map(fn (string $value) => $this->db->escape($value), $values));

        $this->db->query(
            'ALTER TABLE ' . self::TABLE . ' ADD CONSTRAINT ' . self::CONSTRAINT
            . ' CHECK (classified_by IN (' . $list . '))'
        );
    }
}

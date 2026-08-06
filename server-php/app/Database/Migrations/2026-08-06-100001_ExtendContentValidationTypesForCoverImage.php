<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the `cover_image` validation type.
 *
 * A blog with no topically suitable cover must not walk to publication. The
 * cover verdict is recorded as a normal content validation so it rides the
 * machinery that already exists: a `failed` row blocks BlogReadinessService
 * gate 6, shows on the content item's Validations tab, and can be waived —
 * with a mandatory reason, audited — by a superadmin who decides a particular
 * post may go out without a hero.
 */
class ExtendContentValidationTypesForCoverImage extends Migration
{
    private const TYPES = [
        'product_claim', 'fact_check', 'seo', 'readability', 'tone', 'brand_voice',
        'competitor_mention', 'pricing_claim', 'compliance', 'legal', 'technical_accuracy',
        'accessibility', 'plagiarism', 'word_count', 'cover_image',
    ];

    private const NARROW = [
        'product_claim', 'fact_check', 'seo', 'readability', 'tone', 'brand_voice',
        'competitor_mention', 'pricing_claim', 'compliance', 'legal', 'technical_accuracy',
        'accessibility', 'plagiarism', 'word_count',
    ];

    public function up(): void
    {
        $list = "'" . implode("','", self::TYPES) . "'";
        $this->db->query('ALTER TABLE reach_content_validations DROP CONSTRAINT IF EXISTS rcval_type_chk');
        $this->db->query(
            'ALTER TABLE reach_content_validations ADD CONSTRAINT rcval_type_chk '
            . "CHECK (validation_type IN ({$list}))"
        );
    }

    public function down(): void
    {
        // Feature tests migrate down between cases; rows written by this
        // release would violate the narrower CHECK and abort the rollback.
        // Drop the cover verdicts first, then restore the old constraint.
        $this->db->query('ALTER TABLE reach_content_validations DROP CONSTRAINT IF EXISTS rcval_type_chk');
        $this->db->query("DELETE FROM reach_content_validations WHERE validation_type = 'cover_image'");

        $list = "'" . implode("','", self::NARROW) . "'";
        $this->db->query(
            'ALTER TABLE reach_content_validations ADD CONSTRAINT rcval_type_chk '
            . "CHECK (validation_type IN ({$list}))"
        );
    }
}

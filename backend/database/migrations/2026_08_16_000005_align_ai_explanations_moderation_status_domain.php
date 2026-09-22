<?php

/**
 * Open Questions Resolution (OQ-3.3) — align `ai_explanations.moderation_status`
 * domain with the authoritative spec.
 *
 * `2026_08_16_000002_rebuild_ai_explanations_per_attempt.php` created the
 * column with a deviant domain (`auto_generated`/`pending`/`approved`/`rejected`,
 * default `auto_generated`) because the as-built moderation model is the
 * boolean `flagged` (ARCH-002 FR-030) rather than a status string. The authority is
 * unambiguous: BASELINE v1.2 §15.5 and ARCH-002 FR-027 mandate the domain
 * `none`/`flagged`/`manual_review` and the Required Verification "moderation_status
 * defaults `none`". This rider migration:
 *   - drops the CHECK, backfills `auto_generated` → `none`, resets the column
 *     default to `none`, and re-adds the CHECK on the spec domain;
 *   - leaves the boolean `flagged` model untouched (ARCH-002 FR-030) — nothing emits or
 *     consumes `pending`/`approved`/`rejected`, so no code behavior changes
 *     beyond the emitted literal and the test assertions.
 *
 * Down() restores the pre-rider domain for the common case (`none` rows map
 * back to `auto_generated`); rows already in `flagged`/`manual_review` cannot
 * be mapped back without losing information and are left as-is (documented
 * limitation).
 *
 * @Traced-To ARCH-002 FR-027 (BASELINE v1.2 §15.5, ARCH-002 FR-030, ARCH-002 FR-030)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE ai_explanations '
            . 'DROP CONSTRAINT IF EXISTS ai_explanations_moderation_status_check'
        );

        DB::statement(
            "UPDATE ai_explanations SET moderation_status = 'none' WHERE moderation_status = 'auto_generated'"
        );

        DB::statement(
            "ALTER TABLE ai_explanations ALTER COLUMN moderation_status SET DEFAULT 'none'"
        );

        DB::statement(
            'ALTER TABLE ai_explanations ADD CONSTRAINT ai_explanations_moderation_status_check '
            . "CHECK (moderation_status IN ('none', 'flagged', 'manual_review'))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE ai_explanations '
            . 'DROP CONSTRAINT IF EXISTS ai_explanations_moderation_status_check'
        );

        DB::statement(
            "UPDATE ai_explanations SET moderation_status = 'auto_generated' WHERE moderation_status = 'none'"
        );

        DB::statement(
            "ALTER TABLE ai_explanations ALTER COLUMN moderation_status SET DEFAULT 'auto_generated'"
        );

        DB::statement(
            'ALTER TABLE ai_explanations ADD CONSTRAINT ai_explanations_moderation_status_check '
            . "CHECK (moderation_status IN ('auto_generated', 'pending', 'approved', 'rejected'))"
        );
    }
};

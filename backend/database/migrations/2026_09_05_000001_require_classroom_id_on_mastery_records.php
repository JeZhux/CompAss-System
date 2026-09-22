<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * U-05 — Require classroom_id on mastery_records.
     *
     * classroom_id becomes NOT NULL with FK to classrooms(id) RESTRICT on
     * delete (existing content convention), CASCADE on update.
     * subject_section_id is retained as co-carried scope for pooled
     * analytics + latestPerPair continuity. Zero prod rows per plan, so
     * migrate:fresh is the migration path (no backfill).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE mastery_records ALTER COLUMN classroom_id SET NOT NULL');
        DB::statement('ALTER TABLE mastery_records DROP CONSTRAINT IF EXISTS mastery_records_classroom_id_foreign');
        DB::statement(
            'ALTER TABLE mastery_records ADD CONSTRAINT mastery_records_classroom_id_foreign '
            . 'FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mastery_records DROP CONSTRAINT IF EXISTS mastery_records_classroom_id_foreign');
        DB::statement(
            'ALTER TABLE mastery_records ADD CONSTRAINT mastery_records_classroom_id_foreign '
            . 'FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE SET NULL ON UPDATE CASCADE'
        );
        DB::statement('ALTER TABLE mastery_records ALTER COLUMN classroom_id DROP NOT NULL');
    }
};

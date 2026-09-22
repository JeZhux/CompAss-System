<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * U-04 — Require classroom_id on content tables.
     *
     * assessments / assignments / announcements classroom_id becomes NOT NULL.
     * FK to classrooms(id) is preserved as RESTRICT on delete (existing
     * convention from 2026_08_28_000002), CASCADE on update. Zero prod rows
     * per plan, so migrate:fresh is the migration path (no backfill).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE assessments ALTER COLUMN classroom_id SET NOT NULL');
        DB::statement('ALTER TABLE assignments ALTER COLUMN classroom_id SET NOT NULL');
        DB::statement('ALTER TABLE announcements ALTER COLUMN classroom_id SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE announcements ALTER COLUMN classroom_id DROP NOT NULL');
        DB::statement('ALTER TABLE assignments ALTER COLUMN classroom_id DROP NOT NULL');
        DB::statement('ALTER TABLE assessments ALTER COLUMN classroom_id DROP NOT NULL');
    }
};

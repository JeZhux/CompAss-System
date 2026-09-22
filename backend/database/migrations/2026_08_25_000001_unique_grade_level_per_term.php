<?php

/**
 * AUD-009 — UNIQUE(term_id, grade_level) on grade_levels.
 *
 * Nothing prevented creating the same grade level twice under one Term
 * (e.g. '7' twice), while every consumer assumes one row per configured
 * level — duplicates silently distorted the admin school-wide overview
 * (ARCH-004 §10) and rendered duplicate rows to admins. This migration enforces
 * the invariant at the schema level, mirroring the service-level guard in
 * OrgStructureService::createGradeLevel() (GRADE_LEVEL_ALREADY_EXISTS).
 *
 * Pre-existing duplicate handling: FAIL LOUDLY. The only child reference is
 * sections.grade_level_id with ON DELETE CASCADE, so automatically deleting
 * duplicate rows would cascade away real data (sections and everything
 * hanging off them), and a silent merge would re-parent child rows without
 * operator consent — both destructive inside a DDL step. up() therefore
 * aborts listing the exact duplicate (term_id, grade_level) pairs so the
 * operator can deduplicate deliberately (keeping the earliest row per pair)
 * and re-run.
 *
 * The index creation itself is guarded on pg_indexes so the migration stays
 * clean when re-run against a database that already carries the index
 * (same convention as the REM/WU-C rider migrations); down() drops it.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /** Laravel default unique-index name for unique(['term_id', 'grade_level']). */
    private const INDEX_NAME = 'grade_levels_term_id_grade_level_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicates = DB::table('grade_levels')
            ->select('term_id', 'grade_level', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('term_id', 'grade_level')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('term_id')
            ->orderBy('grade_level')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $pairs = $duplicates->map(
                fn (object $row): string => sprintf(
                    'term_id=%d grade_level=%s (%d rows)',
                    $row->term_id,
                    $row->grade_level,
                    $row->duplicate_count
                )
            )->implode('; ');

            throw new RuntimeException(
                'Cannot add UNIQUE(term_id, grade_level) on grade_levels: '
                . 'duplicate rows exist [' . $pairs . ']. Deduplicate manually '
                . '(keep the earliest row per pair; sections hang off these rows '
                . 'via ON DELETE CASCADE, so remove children first if deleting) '
                . 'and re-run this migration.'
            );
        }

        DB::statement(sprintf(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_indexes
                    WHERE schemaname = current_schema()
                      AND indexname = '%s'
                ) THEN
                    CREATE UNIQUE INDEX %s ON grade_levels (term_id, grade_level);
                END IF;
            END $$;
            SQL,
            self::INDEX_NAME,
            self::INDEX_NAME
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX_NAME);
    }
};

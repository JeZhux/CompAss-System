<?php

/**
 * Phase 3 — WU-7: learning_materials.extracted_text (ARCH-002 FR-026).
 *
 * RAG extraction moves to upload time (smalot/pdfparser, synchronous,
 * in-request — ARCH-002 QA-005). The extracted plain text is stored here so the
 * generation-time RAG read can SELECT from already-extracted text instead of
 * parsing the stored file on every explanation. NULL when the file is not
 * extractable (non-PDF upload, corrupt PDF) — NULL means "no RAG text
 * available", not "no extraction attempted" (ARCH-002 FR-021, FR-028).
 *
 * @Traced-To ARCH-002 FR-026, FR-028, QA-009, FR-021, QA-005 (BASELINE
 *   v1.2 §15, ARCH-004 §4.1)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('learning_materials', function (Blueprint $table) {
            $table->text('extracted_text')->nullable()->after('file_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('learning_materials', function (Blueprint $table) {
            $table->dropColumn('extracted_text');
        });
    }
};

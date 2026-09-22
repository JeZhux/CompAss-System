<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 4 — Grading module: `grade_entries` table (ARCH-004 §4.1 grade-ledger tables, ARCH-001 §5.1 grading class design).
     * The authoritative per-question grading ledger for the ARCH-002 FR-018 manual scoring via Pending Grading queue
     * workflow. Written by GradingService.recordManualGrade().
     *
     * @Traced-To ARCH-002 FR-018 (manual scoring via Pending Grading queue; resubmissions as new attempts), FR-019 (pending-grading gate blocks release) (ARCH-004 §4.1 grade-ledger tables, ARCH-001 §5.1 grading class design)
     */
    public function up(): void
    {
        Schema::create('grade_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_submission_id')
                ->constrained('assessment_submissions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_item_id')
                ->constrained('assessment_items')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->decimal('score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('graded_at')->nullable();
            $table->boolean('is_draft')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['assessment_submission_id', 'assessment_item_id']);
            $table->index('assessment_submission_id');
            $table->index('assessment_item_id');
            $table->index('graded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_entries');
    }
};

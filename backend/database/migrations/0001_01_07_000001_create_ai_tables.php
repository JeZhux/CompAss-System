<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 7 — AI Post-Assessment Support: `learning_materials` and `ai_explanations` tables
     * (ARCH-004 §4.1 AI + audit tables, ARCH-001 §5.1 AI-support class design).
     *
     * Both tables are **Term-independent** per ARCH-002 QA-010 (materials + explanations persist across terms, never purged) — no `term_id` FK.
     * They carry no cascade path from any Term-scoped deletion.
     *
     * `learning_materials` — teacher-uploaded RAG reference materials (PDF/DOCX only, ARCH-002 QA-009 upload caps).
     * Scope: teacher_id → users (RESTRICT), subject_section_id → subject_sections (RESTRICT),
     * competency_id → competency_reference (RESTRICT).
     * `file_size` has a DB-level CHECK constraint (file_size <= 15728640, 15 MB in bytes — ARCH-002 QA-009 upload caps).
     *
     * `ai_explanations` — AI-generated post-assessment explanations, one row per
     * student per assessment item (for items belonging to unmastered competencies).
     * Self-referencing via parent_explanation_id for "Explain Further" follow-ups
     * (max 2 turns per item, objective items only, per ARCH-002 FR-029 explain-further for objective items, 2-turn cap / no follow-ups for subjective items).
     * Append-only: no update/delete path exposed at the application layer
     * (only teacher_note and disabled_by fields are mutated, via service methods).
     *
     * @Traced-To ARCH-002 FR-027 (explicit student-initiated generation after release; explanation carries item + response + correct answer + concept; explanations stored immediately on receipt; release + explicit request, no pre-approval step; follow-ups apply to unrecorded practice exactly as recorded), FR-026 (competency-aligned learning-material upload), FR-028 (ungrounded explanation + distinct not-grounded notice; structured anonymized prompts; visible supplementary-aid disclaimer; identifiers withheld from prompts; ungrounded explanation + distinct disclaimer), FR-029 (explain-further for objective items, 2-turn cap; no follow-ups for subjective items),
     *   FR-030 (teacher moderation log: inspect/flag/note/disable; flagged explanations stay visible with teacher note warning; disabling follow-ups never retracts the original explanation), QA-009 (upload caps), QA-008 (explanations within 15 seconds; AI outage message),
     *   QA-003 (per-student request isolation; per-user limits), QA-002 (AI outage: non-AI features keep working normally), QA-010 (materials + explanations persist across terms, never purged) (ARCH-004 §4.1 AI + audit tables, ARCH-005 block 4.7 AI post-assessment support surface)
     */
    public function up(): void
    {
        // --- learning_materials (RAG source, Term-independent per ARCH-002 QA-010 materials + explanations persist across terms) ---
        Schema::create('learning_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('subject_section_id')
                ->constrained('subject_sections')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('competency_id')
                ->constrained('competency_reference')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedInteger('file_size');
            $table->timestamps();

            $table->index('teacher_id');
            $table->index('competency_id');
            $table->index('subject_section_id');
        });

        DB::statement(
            'ALTER TABLE learning_materials ADD CONSTRAINT learning_materials_file_size_check '
            . 'CHECK (file_size <= 15728640)'
        );

        // --- ai_explanations (self-FK for follow-ups, Term-independent per ARCH-002 QA-010 materials + explanations persist across terms) ---
        Schema::create('ai_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_id')
                ->constrained('assessments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_submission_id')
                ->constrained('assessment_submissions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('item_id')
                ->constrained('assessment_items')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->text('explanation_text');
            $table->boolean('is_ungrounded')->default(false);
            $table->boolean('is_follow_up')->default(false);
            $table->foreignId('parent_explanation_id')
                ->nullable()
                ->constrained('ai_explanations')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->smallInteger('turn_number')->default(0);
            $table->boolean('flagged')->default(false);
            $table->foreignId('flagged_by_teacher_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->text('teacher_note')->nullable();
            $table->timestamp('teacher_note_updated_at')->nullable();
            $table->boolean('explain_further_disabled')->default(false);
            $table->foreignId('disabled_by_teacher_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['student_id', 'assessment_id', 'item_id', 'is_follow_up', 'turn_number'],
                'uq_ae_stu_asg_item_turn'
            );

            $table->index('student_id');
            $table->index('assessment_id');
            $table->index('item_id');
            $table->index('parent_explanation_id');
            $table->index('flagged');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_explanations');
        Schema::dropIfExists('learning_materials');
    }
};

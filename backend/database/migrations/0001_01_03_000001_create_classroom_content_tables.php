<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 3 — Classroom Content tables per ARCH-004 §4.1 (organizational-spine / import + classroom-content tables).
     *
     * Created in FK-topology order (DevPlan §2):
     *   Level 5:  announcements, assignments, assessments
     *   Level 6:  announcement_attachments, assignment_attachments,
     *             assignment_submissions, assessment_items,
     *             assessment_auto_saves, assessment_attempts
     *   Level 7:  submission_files, assignment_feedback,
     *             assessment_item_attachments, assessment_submissions
     *   Level 8:  assessment_responses
     *
     * ON DELETE actions per ARCH-004 §4.1 FK specifications.
     * Phase 3 stubs the computeMastery() call site (Phase 5 replaces it).
     *
     * @Traced-To ARCH-002 FR-011 (teachers create announcements with attachments), FR-016 (post-release only metadata edits), FR-017 (full-screen distraction-free mode; exit without auto-submit) (Phase 3 coverage)
     */
    public function up(): void
    {
        // --- Enum types for Phase 3 (ARCH-004 §4.1 enum + lookup domains) ---

        // announcements carry no enum; attachments do not either.

        // assessments.type (ARCH-002 FR-015 Recorded/Unrecorded designation at creation): Recorded|Unrecorded
        DB::statement('DROP TYPE IF EXISTS assessments_type_enum CASCADE');
        DB::statement("CREATE TYPE assessments_type_enum AS ENUM ('Recorded', 'Unrecorded')");

        // assessments.status (ARCH-002 FR-016 publish/release requires at least one item): draft|released
        DB::statement('DROP TYPE IF EXISTS assessments_status_enum CASCADE');
        DB::statement("CREATE TYPE assessments_status_enum AS ENUM ('draft', 'released')");

        // assessment_items.item_type (ARCH-002 FR-015 objective + subjective item families with max points): multiple_choice|true_false|essay
        DB::statement('DROP TYPE IF EXISTS assessment_items_item_type_enum CASCADE');
        DB::statement("CREATE TYPE assessment_items_item_type_enum AS ENUM ('multiple_choice', 'true_false', 'essay')");

        // assessment_attempts.status (ARCH-002 FR-018 manual scoring via Pending Grading queue; resubmissions as new attempts; pending-grading queue blocks release): in_progress|submitted|pending_grading|scored
        DB::statement('DROP TYPE IF EXISTS assessment_attempts_status_enum CASCADE');
        DB::statement("CREATE TYPE assessment_attempts_status_enum AS ENUM ('in_progress', 'submitted', 'pending_grading', 'scored')");

        // assessment_submissions.status (ARCH-002 FR-017 submit on completion/expiry; FR-018 pending-grading queue blocks release): pending_grading|scored
        DB::statement('DROP TYPE IF EXISTS assessment_submissions_status_enum CASCADE');
        DB::statement("CREATE TYPE assessment_submissions_status_enum AS ENUM ('pending_grading', 'scored')");

        // assignment_submissions.status (ARCH-002 FR-013 on-time/late timestamp marking): on_time|late
        DB::statement('DROP TYPE IF EXISTS assignment_submissions_status_enum CASCADE');
        DB::statement("CREATE TYPE assignment_submissions_status_enum AS ENUM ('on_time', 'late')");

        // --- Level 5: announcements ---

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('subject_section_id')
                ->constrained('subject_sections')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
            $table->index(['subject_section_id', 'created_at']);
            $table->index('teacher_id');
        });

        // --- Level 5: assignments (assignments.term_id denormalized per ARCH-002 QA-010 term-scoped submission purge) ---

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('subject_section_id')
                ->constrained('subject_sections')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('term_id')
                ->constrained('terms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('due_date');
            $table->timestamps();
            $table->index('subject_section_id');
            $table->index('term_id');
            $table->index('teacher_id');
        });

        // --- Level 5: assessments (type Recorded/Unrecorded per ARCH-002 FR-015 Recorded/Unrecorded designation at creation) ---

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('subject_section_id')
                ->constrained('subject_sections')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('term_id')
                ->constrained('terms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('status');
            $table->integer('time_limit')->nullable();
            $table->timestamp('availability_starts_at')->nullable();
            $table->timestamp('availability_ends_at')->nullable();
            $table->timestamps();
            $table->index('subject_section_id');
            $table->index('teacher_id');
            $table->index('term_id');
            $table->index('status');
        });

        // Cast enums
        DB::statement(
            'ALTER TABLE assessments '
            . 'ALTER COLUMN type TYPE assessments_type_enum '
            . 'USING type::assessments_type_enum'
        );
        DB::statement('ALTER TABLE assessments ALTER COLUMN "type" SET DEFAULT \'Recorded\'::assessments_type_enum');
        DB::statement(
            'ALTER TABLE assessments '
            . 'ALTER COLUMN status TYPE assessments_status_enum '
            . 'USING status::assessments_status_enum'
        );
        DB::statement('ALTER TABLE assessments ALTER COLUMN "status" SET DEFAULT \'draft\'::assessments_status_enum');

        // --- Level 6: announcement_attachments ---

        Schema::create('announcement_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')
                ->constrained('announcements')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->integer('file_size');
            $table->timestamp('created_at')->nullable();
            $table->index('announcement_id');
        });

        DB::statement(
            'ALTER TABLE announcement_attachments '
            . 'ADD CONSTRAINT chk_ann_attachment_size CHECK (file_size <= 15728640)'
        );

        // --- Level 6: assignment_attachments ---

        Schema::create('assignment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')
                ->constrained('assignments')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->integer('file_size');
            $table->timestamp('created_at')->nullable();
            $table->index('assignment_id');
        });

        DB::statement(
            'ALTER TABLE assignment_attachments '
            . 'ADD CONSTRAINT chk_assignment_attachment_size CHECK (file_size <= 15728640)'
        );

        // --- Level 6: assignment_submissions (UNIQUE assignment_id,student_id) ---

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')
                ->constrained('assignments')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('submitted_at');
            $table->string('status');
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id']);
            $table->index('assignment_id');
            $table->index('student_id');
        });

        DB::statement(
            'ALTER TABLE assignment_submissions '
            . 'ALTER COLUMN status TYPE assignment_submissions_status_enum '
            . 'USING status::assignment_submissions_status_enum'
        );
        DB::statement('ALTER TABLE assignment_submissions ALTER COLUMN "status" SET DEFAULT \'on_time\'::assignment_submissions_status_enum');

        // --- Level 6: assessment_items (one competency_tag_id per item, ARCH-002 FR-015 exactly one competency tag per item) ---

        Schema::create('assessment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')
                ->constrained('assessments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('item_type');
            $table->text('prompt');
            $table->decimal('max_points', 8, 2);
            $table->text('correct_answer')->nullable();
            $table->foreignId('competency_tag_id')
                ->constrained('competency_reference')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->integer('sort_order');
            $table->timestamps();
            $table->index('assessment_id');
            $table->index('competency_tag_id');
        });

        DB::statement(
            'ALTER TABLE assessment_items '
            . 'ALTER COLUMN item_type TYPE assessment_items_item_type_enum '
            . 'USING item_type::assessment_items_item_type_enum'
        );

        // --- Level 6: assessment_auto_saves (UNIQUE student_id,assessment_id, ARCH-002 QA-007 auto-save every 60 seconds) ---

        Schema::create('assessment_auto_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_id')
                ->constrained('assessments')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->json('responses');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['student_id', 'assessment_id']);
            $table->index('student_id');
            $table->index('assessment_id');
        });

        // --- Level 6: assessment_attempts ---

        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')
                ->constrained('assessments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->integer('attempt_number');
            $table->string('status');
            $table->foreignId('grader_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('graded_at')->nullable();
            $table->json('response_history')->default('[]');
            $table->timestamps();
            $table->unique(['assessment_id', 'student_id', 'attempt_number']);
            $table->index('assessment_id');
            $table->index('student_id');
            $table->index('status');
            $table->index('grader_id');
        });

        DB::statement(
            'ALTER TABLE assessment_attempts '
            . 'ALTER COLUMN status TYPE assessment_attempts_status_enum '
            . 'USING status::assessment_attempts_status_enum'
        );
        DB::statement('ALTER TABLE assessment_attempts ALTER COLUMN "status" SET DEFAULT \'in_progress\'::assessment_attempts_status_enum');

        // --- Level 7: submission_files ---

        Schema::create('submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('assignment_submissions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->integer('file_size');
            $table->timestamp('created_at')->nullable();
            $table->index('submission_id');
        });

        DB::statement(
            'ALTER TABLE submission_files '
            . 'ADD CONSTRAINT chk_submission_file_size CHECK (file_size <= 15728640)'
        );

        // --- Level 7: assignment_feedback (UNIQUE submission_id) ---

        Schema::create('assignment_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('assignment_submissions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->text('feedback_text');
            $table->timestamps();
            $table->unique('submission_id');
            $table->index('teacher_id');
        });

        // --- Level 7: assessment_item_attachments ---

        Schema::create('assessment_item_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_item_id')
                ->constrained('assessment_items')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->integer('file_size');
            $table->timestamp('created_at')->nullable();
            $table->index('assessment_item_id');
        });

        DB::statement(
            'ALTER TABLE assessment_item_attachments '
            . 'ADD CONSTRAINT chk_item_attachment_size CHECK (file_size <= 15728640)'
        );

        // --- Level 7: assessment_submissions (UNIQUE attempt_id) ---

        Schema::create('assessment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')
                ->constrained('assessment_attempts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_id')
                ->constrained('assessments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('section_id')
                ->constrained('sections')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('term_id')
                ->constrained('terms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('submitted_at');
            $table->string('status');
            $table->boolean('is_results_released')->default(false);
            $table->timestamp('results_released_at')->nullable();
            $table->timestamps();
            $table->unique('attempt_id');
            $table->index('assessment_id');
            $table->index('student_id');
            $table->index('section_id');
            $table->index('status');
        });

        DB::statement(
            'ALTER TABLE assessment_submissions '
            . 'ALTER COLUMN status TYPE assessment_submissions_status_enum '
            . 'USING status::assessment_submissions_status_enum'
        );
        DB::statement('ALTER TABLE assessment_submissions ALTER COLUMN "status" SET DEFAULT \'pending_grading\'::assessment_submissions_status_enum');

        // --- Level 8: assessment_responses (UNIQUE submission_id,item_id) ---

        Schema::create('assessment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('assessment_submissions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('item_id')
                ->constrained('assessment_items')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->text('response_text')->nullable();
            $table->decimal('earned_points', 8, 2)->nullable();
            $table->boolean('is_auto_scored')->default(false);
            $table->foreignId('scored_by_teacher_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('created_at')->nullable();
            $table->unique(['submission_id', 'item_id']);
            $table->index('submission_id');
            $table->index('item_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drop in reverse FK-topology order.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_responses');
        Schema::dropIfExists('assessment_submissions');
        Schema::dropIfExists('assessment_item_attachments');
        Schema::dropIfExists('assignment_feedback');
        Schema::dropIfExists('submission_files');
        Schema::dropIfExists('assessment_auto_saves');
        Schema::dropIfExists('assessment_attempts');
        Schema::dropIfExists('assessment_items');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignment_attachments');
        Schema::dropIfExists('announcement_attachments');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('announcements');

        DB::statement('DROP TYPE IF EXISTS assessment_submissions_status_enum CASCADE');
        DB::statement('DROP TYPE IF EXISTS assessment_attempts_status_enum CASCADE');
        DB::statement('DROP TYPE IF EXISTS assessment_items_item_type_enum CASCADE');
        DB::statement('DROP TYPE IF EXISTS assessments_status_enum CASCADE');
        DB::statement('DROP TYPE IF EXISTS assessments_type_enum CASCADE');
        DB::statement('DROP TYPE IF EXISTS assignment_submissions_status_enum CASCADE');
    }
};

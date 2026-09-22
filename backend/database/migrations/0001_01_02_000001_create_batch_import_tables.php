<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 2 — Batch Import tables per ARCH-004 §4.2 (framework tables) and ARCH-004 §4.1 (competency-reference tables).
     * Created in FK-topology order (DevPlan §2):
     *   Level 4:  competency_reference → subjects (RESTRICT)
     *   Anytime after users: import_jobs, import_logs → users (CASCADE)
     *
     * ON DELETE actions per ARCH-004 §4.2 (framework tables) / §4.1 (competency-reference tables) FK specifications.
     */
    public function up(): void
    {
        DB::statement('DROP TYPE IF EXISTS competency_reference_grade_level_enum CASCADE');
        DB::statement("CREATE TYPE competency_reference_grade_level_enum AS ENUM ('7', '8', '9')");

        // Level 4 — competency_reference → subjects (RESTRICT)
        Schema::create('competency_reference', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->text('descriptor');
            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('grade_level');
            $table->timestamps();
            $table->index('subject_id');
        });

        DB::statement(
            'ALTER TABLE competency_reference '
            . 'ALTER COLUMN grade_level TYPE competency_reference_grade_level_enum '
            . 'USING grade_level::competency_reference_grade_level_enum'
        );

        // Create enum types BEFORE building import_jobs / import_logs tables.
        DB::statement('DROP TYPE IF EXISTS import_type_enum CASCADE');
        DB::statement("CREATE TYPE import_type_enum AS ENUM ('student_enrollment', 'competency_tags')");

        DB::statement('DROP TYPE IF EXISTS import_status_enum CASCADE');
        DB::statement("CREATE TYPE import_status_enum AS ENUM ('completed', 'failed')");

        // import_jobs — metadata for each .xlsx batch import (ARCH-002 FR-033 bulk enrollment upload with row-level validation / FR-031 competency-tag bulk import / FR-031 per-attempt downloadable error report)
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('import_type');
            $table->foreignId('admin_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->integer('total_rows')->default(0);
            $table->integer('imported_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->string('error_report_path')->nullable();
            $table->timestamps();
            $table->index('import_type');
            $table->index('admin_id');
            $table->index('created_at');
        });

        DB::statement(
            'ALTER TABLE import_jobs '
            . 'ALTER COLUMN import_type TYPE import_type_enum '
            . 'USING import_type::import_type_enum'
        );

        // import_logs — audit log for completed CSV imports (ARCH-002 FR-033 CSV bulk enrollment with per-row errors in response)
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('filename');
            $table->integer('total_rows')->default(0);
            $table->integer('valid_rows')->default(0);
            $table->integer('invalid_rows')->default(0);
            $table->integer('imported_rows')->default(0);
            $table->integer('skipped_rows')->default(0);
            $table->string('status');
            $table->json('error_details')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index('admin_id');
            $table->index('status');
            $table->index('created_at');
        });

        DB::statement(
            'ALTER TABLE import_logs '
            . 'ALTER COLUMN status TYPE import_status_enum '
            . 'USING status::import_status_enum'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_logs');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('competency_reference');
        DB::statement('DROP TYPE IF EXISTS import_status_enum');
        DB::statement('DROP TYPE IF EXISTS import_type_enum');
        DB::statement('DROP TYPE IF EXISTS competency_reference_grade_level_enum CASCADE');
    }
};

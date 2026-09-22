<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U2 — hand place/move history (ARCH-004 §4.1 classroom_enrollment_moves,
     * ARCH-003 ADR-011, ARCH-005 block 4.17).
     *
     * Append-only record of single-learner hand placements and moves:
     * actor, learner, destination classroom, and time for each change, plus a
     * nullable source classroom (null on place, set on move) so every row
     * carries source and destination. Rows are insert-only; no update or
     * delete path exists outside retention handling.
     */
    public function up(): void
    {
        if (Schema::hasTable('classroom_enrollment_moves')) {
            return;
        }

        Schema::create('classroom_enrollment_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')
                ->constrained('classrooms')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('source_classroom_id')
                ->nullable()
                ->constrained('classrooms')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('actor_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('action', 16);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('classroom_id', 'enrollment_moves_classroom_id_index');
            $table->index('student_id', 'enrollment_moves_student_id_index');
            $table->index('actor_id', 'enrollment_moves_actor_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_enrollment_moves');
    }
};

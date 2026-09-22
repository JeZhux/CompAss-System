<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U3 — Learner leave (ARCH-005 block 4.20).
     *
     * Records every removed enrollment (leave or teacher removal) so a
     * repeat leave converges with already_left=true while a caller with
     * no current or prior enrollment is refused 403 NOT_ENROLLED.
     */
    public function up(): void
    {
        if (! Schema::hasTable('classroom_leave_history')) {
            Schema::create('classroom_leave_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('classroom_id')
                    ->constrained('classrooms')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreignId('student_id')
                    ->constrained('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->timestampTz('left_at')->useCurrent();
                $table->unique(['classroom_id', 'student_id'], 'classroom_leave_history_classroom_student_unique');
                $table->index('student_id', 'classroom_leave_history_student_id_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classroom_leave_history');
    }
};

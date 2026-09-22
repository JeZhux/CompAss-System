<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U03 — Content becomes classroom-scoped: add nullable classroom_id FK to
     * announcements, assignments, assessments (RESTRICT not CASCADE, index).
     */
    public function up(): void
    {
        // announcements
        if (! Schema::hasColumn('announcements', 'classroom_id')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->foreignId('classroom_id')
                    ->nullable()
                    ->constrained('classrooms')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->index('classroom_id', 'announcements_classroom_id_index');
            });
        }

        // assignments
        if (! Schema::hasColumn('assignments', 'classroom_id')) {
            Schema::table('assignments', function (Blueprint $table) {
                $table->foreignId('classroom_id')
                    ->nullable()
                    ->constrained('classrooms')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->index('classroom_id', 'assignments_classroom_id_index');
            });
        }

        // assessments
        if (! Schema::hasColumn('assessments', 'classroom_id')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->foreignId('classroom_id')
                    ->nullable()
                    ->constrained('classrooms')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->index('classroom_id', 'assessments_classroom_id_index');
            });
        }
    }

    public function down(): void
    {
        // assessments
        if (Schema::hasColumn('assessments', 'classroom_id')) {
            Schema::table('assessments', function (Blueprint $table) {
                try {
                    $table->dropForeign(['classroom_id']);
                } catch (\Throwable $e) {
                }
                try {
                    $table->dropIndex('assessments_classroom_id_index');
                } catch (\Throwable $e) {
                }
                $table->dropColumn('classroom_id');
            });
        }

        // assignments
        if (Schema::hasColumn('assignments', 'classroom_id')) {
            Schema::table('assignments', function (Blueprint $table) {
                try {
                    $table->dropForeign(['classroom_id']);
                } catch (\Throwable $e) {
                }
                try {
                    $table->dropIndex('assignments_classroom_id_index');
                } catch (\Throwable $e) {
                }
                $table->dropColumn('classroom_id');
            });
        }

        // announcements
        if (Schema::hasColumn('announcements', 'classroom_id')) {
            Schema::table('announcements', function (Blueprint $table) {
                try {
                    $table->dropForeign(['classroom_id']);
                } catch (\Throwable $e) {
                }
                try {
                    $table->dropIndex('announcements_classroom_id_index');
                } catch (\Throwable $e) {
                }
                $table->dropColumn('classroom_id');
            });
        }
    }
};

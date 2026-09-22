<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U-01 — F-08 core: add nullable classroom_id FK to mastery_records.
     *
     * Additive only, nullable, nullOnDelete (never cascade). Legacy rows
     * remain with classroom_id = null; subject_section_id stays canonical.
     * Backfill strategy is deferred — column is nullable for legacy path.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('mastery_records', 'classroom_id')) {
            Schema::table('mastery_records', function (Blueprint $table) {
                $table->foreignId('classroom_id')
                    ->nullable()
                    ->constrained('classrooms')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
                $table->index('classroom_id', 'mastery_records_classroom_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mastery_records', 'classroom_id')) {
            Schema::table('mastery_records', function (Blueprint $table) {
                try {
                    $table->dropForeign(['classroom_id']);
                } catch (\Throwable $e) {
                }
                try {
                    $table->dropIndex('mastery_records_classroom_id_index');
                } catch (\Throwable $e) {
                }
                $table->dropColumn('classroom_id');
            });
        }
    }
};

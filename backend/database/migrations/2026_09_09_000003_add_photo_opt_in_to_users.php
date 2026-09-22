<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U3 — Learner people read (ARCH-005 block 4.18 / ADR-012).
     *
     * Adds the explicit family opt-in flag gating photo display (default
     * off per ARCH-004 §4.1) plus the photo URL itself. The people read
     * omits photo_url unless opt-in is recorded.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'photo_opt_in')) {
                $table->boolean('photo_opt_in')->default(false);
            }
            if (! Schema::hasColumn('users', 'photo_url')) {
                $table->text('photo_url')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'photo_url')) {
                $table->dropColumn('photo_url');
            }
            if (Schema::hasColumn('users', 'photo_opt_in')) {
                $table->dropColumn('photo_opt_in');
            }
        });
    }
};

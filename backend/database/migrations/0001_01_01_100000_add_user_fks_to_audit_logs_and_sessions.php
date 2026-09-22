<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 1 — FK backfill (Resolution Log §15.4):
     *
     * Phase 0 created the audit_logs and sessions tables without FK
     * constraints on user_id (deferred to Phase 1). Now that the full
     * users table exists, backfill the constraints.
     *
     * ON DELETE SET NULL — audit log entries and sessions must survive
     * user deactivation/deletion (ARCH-002 FR-004 deactivation revokes login, preserves history:
     * users are never hard-deleted, but the constraint is SET NULL for graceful cleanup). ON UPDATE
     * CASCADE keeps the FK valid if the users.id sequence is reseeded
     * (rare, but matches ARCH-004 §4.1 audit-log tables).
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id', 'fk_audit_logs_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null')
                ->onUpdate('cascade');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->foreign('user_id', 'fk_sessions_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign('fk_audit_logs_user_id');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropForeign('fk_sessions_user_id');
        });
    }
};

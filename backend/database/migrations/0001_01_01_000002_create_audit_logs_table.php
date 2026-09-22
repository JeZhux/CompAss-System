<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Audit logs table per ARCH-004 §4.1 (audit-log tables).
     * FK to users.id deferred to Phase 1 per §15.4 (ON DELETE SET NULL).
     * Append-only per ARCH-002 QA-006 (append-only audit trail + retention).
     */
    public function up(): void
    {
        // Drop any leftover custom type from a previous run — migrate:fresh
        // does not drop PostgreSQL custom types, which would cause a
        // "type already exists" error on re-migration.
        DB::statement('DROP TYPE IF EXISTS audit_event_type');

        DB::statement(
            'CREATE TYPE audit_event_type AS ENUM ('
            . "'login','logout','create','update','delete','export',"
            . "'release_results','flag_explanation','disable_explain_further',"
            . "'purge_terms','other'"
            . ')'
        );

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event_type')->nullable(false);
            $table->string('auditable_type', 255)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('description')->nullable(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('user_id', 'idx_audit_logs_user_id');
            $table->index('event_type', 'idx_audit_logs_event_type');
            $table->index(['auditable_type', 'auditable_id'], 'idx_audit_logs_auditable');
            $table->index('created_at', 'idx_audit_logs_created_at');
        });

        // Cast the column to the PostgreSQL enum type we just created
        DB::statement(
            'ALTER TABLE audit_logs '
            . 'ALTER COLUMN event_type TYPE audit_event_type '
            . 'USING event_type::audit_event_type'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP TYPE IF EXISTS audit_event_type');
    }
};

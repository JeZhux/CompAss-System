<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 1 — LMS Core: replaces the Phase 0 TEST-ONLY scaffold users table
     * with the full schema per ARCH-004 §4.1 (users + authentication tables: school_id, password_hash, role enum,
     * must_change_password, is_active).
     */
    public function up(): void
    {
        // Drop any leftover enum type from a previous run — migrate:fresh
        // does not drop PostgreSQL custom types, which would cause a
        // "type already exists" error on re-migration. Same pattern as the
        // audit_logs migration (Phase 0).
        DB::statement('DROP TYPE IF EXISTS users_role_enum CASCADE');

        DB::statement(
            "CREATE TYPE users_role_enum AS ENUM ('Admin', 'Teacher', 'Student')"
        );

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('school_id')->unique()->nullable();
            $table->string('password_hash');
            $table->string('role');
            $table->boolean('must_change_password')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('role');
        });

        // Cast the role column to the native PostgreSQL enum type.
        // ARCH-004 §4.1 (users + authentication tables): role has NO default — the value must be explicitly
        // provided on INSERT (ARCH-002 FR-001 exactly three roles).
        DB::statement(
            'ALTER TABLE users '
            . 'ALTER COLUMN role TYPE users_role_enum '
            . 'USING role::users_role_enum'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        DB::statement('DROP TYPE IF EXISTS users_role_enum CASCADE');
    }
};

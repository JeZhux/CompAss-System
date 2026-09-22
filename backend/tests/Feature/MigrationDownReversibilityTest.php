<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the documented down() limits of the CompAss rework migrations:
 *
 * - 2026_09_20 (CompAss-ID only): down() always restores the email column
 *   and drops the new CHECKs, but the legacy email-XOR CHECK is best-effort —
 *   skipped with a NOTICE on Phase A (school_id-only) rows that can never
 *   satisfy it, instead of failing closed with 23514.
 * - 2026_09_21 (full_name-only bulk): down() re-adds the dropped staged
 *   columns, but the import_type enum value 'teacher_application' is
 *   intentionally irreversible (Postgres cannot drop one enum value).
 */
class MigrationDownReversibilityTest extends TestCase
{
    use RefreshDatabase;

    private function constraintExists(string $name): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only constraint guard.');
        }

        return count(DB::select(
            'SELECT 1 FROM pg_constraint WHERE conname = ?',
            [$name]
        )) > 0;
    }

    private function enumValueExists(string $type, string $label): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only enum guard.');
        }

        return count(DB::select(
            'SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = ? AND e.enumlabel = ?',
            [$type, $label]
        )) > 0;
    }

    public function test_compass_id_only_down_is_safe_on_phase_a_rows(): void
    {
        // Phase A rows: school_id-only, no email — can never satisfy email-XOR.
        User::factory()->admin()->create(['must_change_password' => false]);
        User::factory()->student()->create(['must_change_password' => false]);

        $migration = require database_path('migrations/2026_09_20_000001_compass_id_only.php');
        $migration->down();

        // Reversible part: email column back (nullable), new CHECKs dropped,
        // school_id relaxed to nullable — with zero rows lost.
        $this->assertTrue(Schema::hasColumn('users', 'email'));
        $this->assertFalse($this->constraintExists('chk_users_school_id_format'));
        $this->assertFalse($this->constraintExists('chk_users_school_id_role_prefix'));
        $this->assertSame(2, User::count());

        // Best-effort part: legacy email-XOR CHECK is skipped (NOT restored)
        // because the surviving Phase A rows violate it.
        $this->assertFalse($this->constraintExists('chk_users_identifier'));

        // Restore Phase A state for a clean rollback boundary.
        $migration->up();
        $this->assertFalse(Schema::hasColumn('users', 'email'));
        $this->assertTrue($this->constraintExists('chk_users_school_id_format'));
        $this->assertTrue($this->constraintExists('chk_users_school_id_role_prefix'));
    }

    public function test_fullname_only_bulk_down_restores_columns_but_enum_persists(): void
    {
        // Up state: staged group/year columns gone, teacher enum value present.
        $this->assertFalse(Schema::hasColumn('enrollment_import_rows', 'group_assignment'));
        $this->assertFalse(Schema::hasColumn('enrollment_import_rows', 'year_level'));
        $this->assertTrue($this->enumValueExists('import_type_enum', 'teacher_application'));

        $migration = require database_path('migrations/2026_09_21_000001_fullname_only_bulk.php');
        $migration->down();

        // Reversible part: staged columns re-added as nullable.
        $this->assertTrue(Schema::hasColumn('enrollment_import_rows', 'group_assignment'));
        $this->assertTrue(Schema::hasColumn('enrollment_import_rows', 'year_level'));

        // Irreversible part (documented): the enum value is intentionally
        // left in place — Postgres cannot drop one enum value.
        $this->assertTrue($this->enumValueExists('import_type_enum', 'teacher_application'));

        // Restore Phase B state.
        $migration->up();
        $this->assertFalse(Schema::hasColumn('enrollment_import_rows', 'group_assignment'));
        $this->assertFalse(Schema::hasColumn('enrollment_import_rows', 'year_level'));
    }
}

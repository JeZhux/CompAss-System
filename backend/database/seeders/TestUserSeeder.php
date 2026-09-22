<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    /**
     * Seed placeholder users with fixed CompAss IDs.
     *
     * THROWAWAY TEST SCAFFOLDING — Phase 0 placeholder for auth testing.
     * Updated for Phase 1 schema (password_hash, role 'Admin', must_change_password=false).
     *
     * CompAss-ID rework: every role identifies by school_id
     * (<ROLE>-<4 digits>-<5 digits>), server-generated at creation. These
     * fixed harness IDs are the known login identifiers for tests.
     *
     * Note: The User model applies the 'hashed' cast on password_hash, so the
     * plain-text value is automatically hashed on save. Passing Hash::make()
     * here would cause a double-hash (ARCH-002 QA-004).
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['school_id' => 'ADM-1000-00001'],
            [
                'name' => 'Test Admin',
                'password_hash' => 'password123',
                'role' => 'Admin',
                'must_change_password' => false,
                'is_active' => true,
            ]
        );

        // Phase 3 classroom-content harness users (§3.1.1).
        // The 'hashed' cast on password_hash auto-hashes the plain-text value
        // on save — pass 'password123' directly, NOT Hash::make() (ARCH-002 QA-004).
        User::firstOrCreate(
            ['school_id' => 'TEA-2000-00001'],
            [
                'name' => 'Test Teacher',
                'password_hash' => 'password123',
                'role' => 'Teacher',
                'must_change_password' => false,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['school_id' => 'STU-3000-00001'],
            [
                'name' => 'Test Student',
                'password_hash' => 'password123',
                'role' => 'Student',
                'must_change_password' => false,
                'is_active' => true,
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Production seed path (ARCH-006 §5.1): BootstrapAdminSeeder (env-driven, NO-OPs
     * without BOOTSTRAP_ADMIN_* vars). EvaluationSeeder was removed
     * (CompAss-ID rework) — evaluation-scale data is no longer seeded.
     *
     * TestUserSeeder and TestOrgStructureSeeder are THROWAWAY TEST SCAFFOLDING —
     * gated behind app()->environment('production') being false so they run only
     * in dev/testing environments and never in production (ARCH-006 §5.1).
     */    public function run(): void
    {
        $seeders = [
            BootstrapAdminSeeder::class,
        ];

        if (! app()->environment('production')) {
            $seeders = array_merge($seeders, [
                // THROWAWAY TEST SCAFFOLDING — dev/testing only (ARCH-006 §5.1).
                // Phase 0 placeholder user + Phase 3 harness org structure
                // (SchoolYear → Semester → GradeLevel → Section → Subject
                // + Classroom) + U17 classroom smoke + DemoSeeder throwaway demo dataset.
                TestUserSeeder::class,
                TestOrgStructureSeeder::class,
                ClassroomSeeder::class,
                DemoSeeder::class,
            ]);
        }

        $this->call($seeders);
    }
}

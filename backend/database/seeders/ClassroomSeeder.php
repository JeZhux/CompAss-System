<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\ClassroomJoinKeyHistory;
use App\Models\Subject;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Database\Seeder;

class ClassroomSeeder extends Seeder
{
    /**
     * Seed one classroom + enroll one student for smoke E2E.
     *
     * THROWAWAY TEST SCAFFOLDING — dev/testing only (ARCH-006 §5.1), mirrors
     * TestOrgStructureSeeder. Idempotent: re-running does not create duplicates.
     *
     * Creates/finds:
     *  - classroom for TEA-2000-00001 → first subject/section found, for
     *    the current academic year, with auto name "{Section.name} {Subject.name}"
     *    and 6-char join_key (teacher scope derives from the classroom itself
     *    since the Semester restructure — no assignment row)
     *  - enrollment of student STU-3000-00001 into that classroom
     *
     * Smoke: login as student → Home shows 1 classroom without manual join.
     */
    public function run(): void
    {
        $teacher = User::where('school_id', 'TEA-2000-00001')->first()
            ?? User::where('role', 'Teacher')->first();
        // Student is seeded with school_id STU-3000-00001 (TestUserSeeder)
        $student = User::where('school_id', 'STU-3000-00001')->first()
            ?? User::where('role', 'Student')->first();

        $subject = Subject::with('gradeLevel.sections')->first();
        $section = $subject?->gradeLevel?->sections->first();

        if (! $teacher || ! $student || ! $subject || ! $section) {
            return;
        }

        $schoolYear = AcademicYear::current();

        // Find existing classroom for this triple or create one
        $classroom = Classroom::where('teacher_id', $teacher->id)
            ->where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('school_year', $schoolYear)
            ->first();

        if (! $classroom) {
            $joinKey = $this->generateUniqueJoinKey();
            $baseName = $section->name . ' ' . $subject->name;

            $classroom = Classroom::create([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'section_id' => $section->id,
                'school_year' => $schoolYear,
                'name' => $baseName,
                'suffix' => null,
                'join_key' => $joinKey,
                'is_join_enabled' => true,
            ]);
        }

        // Enroll student (idempotent)
        ClassroomEnrollment::firstOrCreate(
            [
                'classroom_id' => $classroom->id,
                'student_id' => $student->id,
            ],
            [
                'joined_at' => now(),
            ]
        );
    }

    private function generateUniqueJoinKey(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $key = '';
            for ($i = 0; $i < 6; $i++) {
                $key .= $chars[random_int(0, $max)];
            }

            $exists = Classroom::where('join_key', $key)->exists()
                || ClassroomJoinKeyHistory::where('old_join_key', $key)->exists();

            if (! $exists) {
                return $key;
            }
        }

        // Fallback: generate one more without history check (UNIQUE index is authoritative; will throw if collides)
        $fallback = '';
        for ($i = 0; $i < 6; $i++) {
            $fallback .= $chars[random_int(0, $max)];
        }

        return $fallback;
    }
}

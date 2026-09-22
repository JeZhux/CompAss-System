<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestOrgStructureSeeder extends Seeder
{
    /**
     * Seed a minimal org hierarchy for the Phase 3 harness:
     *   SchoolYear → Semester → GradeLevel → Section (+ Subject under the
     *   Grade Level) → Classroom → Student enrollment.
     *
     * THROWAWAY TEST SCAFFOLDING — makes the Phase 3 harness usable without
     * manual org setup. The seeded classroom is the usable input for the
     * harness form's "Classroom ID" field.
     *
     * Runs AFTER TestUserSeeder (which creates the teacher/student users).
     * Teacher scope derives from the classroom itself (teacher_id +
     * subject_id + school_year) — no separate assignment row exists since
     * the Semester restructure.
     */
    public function run(): void
    {
        $year = SchoolYear::firstOrCreate(['name' => 'SY 2026']);

        $semester = Semester::firstOrCreate(
            ['school_year_id' => $year->id, 'semester' => '1'],
            [
                'name' => 'Semester 1',
                'start_date' => '2026-09-01',
                'end_date' => '2026-12-31',
            ]
        );

        $gradeLevel = GradeLevel::firstOrCreate(
            ['semester_id' => $semester->id, 'grade_level' => '7'],
        );

        $section = Section::firstOrCreate(
            ['grade_level_id' => $gradeLevel->id, 'name' => '7-A'],
            ['name' => '7-A'],
        );

        $subject = Subject::firstOrCreate(
            ['grade_level_id' => $gradeLevel->id, 'code' => 'MATH7'],
            ['name' => 'Mathematics', 'description' => 'Grade 7 Mathematics'],
        );

        $teacher = User::where('school_id', 'TEA-2000-00001')->first()
            ?? User::where('role', 'Teacher')->first();
        $classroom = null;
        if ($teacher) {
            $classroom = Classroom::where('teacher_id', $teacher->id)
                ->where('subject_id', $subject->id)
                ->where('section_id', $section->id)
                ->where('school_year', '2026-2027')
                ->first();
            if (! $classroom) {
                $classroom = Classroom::create([
                    'teacher_id' => $teacher->id,
                    'subject_id' => $subject->id,
                    'section_id' => $section->id,
                    'school_year' => '2026-2027',
                    'name' => $section->name . ' ' . $subject->name,
                    'suffix' => null,
                    'join_key' => $this->generateUniqueJoinKey(),
                    'is_join_enabled' => true,
                ]);
            }
        }

        $student = User::where('school_id', 'STU-3000-00001')->first()
            ?? User::where('role', 'Student')->first();
        if ($student && $classroom) {
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

            if (! Classroom::where('join_key', $key)->exists()) {
                return $key;
            }
        }

        $fallback = '';
        for ($i = 0; $i < 6; $i++) {
            $fallback .= $chars[random_int(0, $max)];
        }

        return $fallback;
    }
}

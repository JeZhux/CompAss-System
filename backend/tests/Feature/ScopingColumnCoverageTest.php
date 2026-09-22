<?php

/**
 * SCOPING-COLUMN CHECKLIST: any column added to scope query must have test
 * asserting read path filters on it.
 *
 * Exemplars pinned here:
 *  (1) learning_materials.subject_id — AIService::retrieveRAGMaterials
 *      must filter excerpts to the requested subject.
 *  (2) mastery_records.classroom_id — AnalyticsService::getTeacherHeatmapForClassroom
 *      (public method) must count only rows for that classroom; the pooled
 *      heatmap counts rows across classrooms for the subject.
 *
 * Backend only, no production change (S-3).
 */

namespace Tests\Feature;

use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\LearningMaterial;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\AIService;
use App\Services\AnalyticsService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScopingColumnCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build three subjects under one Grade Level: A and B each own one
     * material, C owns none. Each material tags its own subject's
     * competency (the subject/grade/semester triple invariant).
     *
     * @return array{teacher: User, competency: CompetencyReference, subjectA: Subject, subjectB: Subject, subjectEmpty: Subject}
     */
    private function buildRagFixture(): array
    {
        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-SCOV']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $subjectA = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-SCOV-A', 'code' => 'MATH-SCOV-A']);
        $subjectB = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-SCOV-B', 'code' => 'MATH-SCOV-B']);
        $subjectEmpty = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-SCOV-C', 'code' => 'MATH-SCOV-C']);


        $coA = CompetencyReference::create(['semester' => '1', 'code' => 'C-SCOV-1',
            'descriptor' => 'Comp SCOV A',
            'grade_level' => '7',
            'subject_id' => $subjectA->id,
        ]);
        $coB = CompetencyReference::create(['semester' => '1', 'code' => 'C-SCOV-2',
            'descriptor' => 'Comp SCOV B',
            'grade_level' => '7',
            'subject_id' => $subjectB->id,
        ]);

        LearningMaterial::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subjectA->id,
            'competency_id' => $coA->id,
            'filename' => 'learning_materials/scov_m1.pdf',
            'original_filename' => 'SCOV M1',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'extracted_text' => 'SCOV-M1-UNIQUE-EXCERPT-ALPHA',
        ]);
        LearningMaterial::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subjectB->id,
            'competency_id' => $coB->id,
            'filename' => 'learning_materials/scov_m2.pdf',
            'original_filename' => 'SCOV M2',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'extracted_text' => 'SCOV-M2-UNIQUE-EXCERPT-BETA',
        ]);

        return ['teacher' => $teacher, 'competency' => $coA, 'subjectA' => $subjectA, 'subjectB' => $subjectB, 'subjectEmpty' => $subjectEmpty];
    }

    public function test_rag_materials_filtered_to_requested_subject(): void
    {
        $f = $this->buildRagFixture();

        $result = $this->app->make(AIService::class)->retrieveRAGMaterials($f['competency']->id, $f['subjectA']->id);

        $this->assertFalse($result['is_ungrounded']);
        $joined = implode("\n", $result['excerpts']);
        $this->assertStringContainsString('SCOV-M1-UNIQUE-EXCERPT-ALPHA', $joined);
        $this->assertStringNotContainsString('SCOV-M2-UNIQUE-EXCERPT-BETA', $joined);
    }

    public function test_rag_empty_subject_is_ungrounded_despite_other_subject_material(): void
    {
        $f = $this->buildRagFixture();

        $result = $this->app->make(AIService::class)->retrieveRAGMaterials($f['competency']->id, $f['subjectEmpty']->id);

        $this->assertSame([], $result['excerpts']);
        $this->assertTrue($result['is_ungrounded']);
    }

    public function test_classroom_heatmap_counts_only_classroom_rows_while_pooled_counts_both(): void
    {
        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $sClass = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $sC2 = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-SCOV-HM']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gl->id, 'name' => '7A-SCOV-HM']);
        $subject = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-SCOV-HM', 'code' => 'MATH-SCOV-HM']);
        $comp = CompetencyReference::create(['semester' => '1', 'code' => 'C-SCOV-HM-1', 'descriptor' => 'Comp SCOV HM', 'grade_level' => '7', 'subject_id' => $subject->id]);

        $c1 = app(ClassroomService::class)->createClassroom($teacher->id, $subject->id, $section->id, '2026-2027', 'C1');
        $c2 = app(ClassroomService::class)->createClassroom($teacher->id, $subject->id, $section->id, '2027-2028', 'C2');

        $assessC1 = DB::table('assessments')->insertGetId([
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'classroom_id' => $c1->id,
            'semester_id' => $term->id, 'title' => 'Assess C1', 'type' => 'Recorded', 'status' => 'released',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $assessC2 = DB::table('assessments')->insertGetId([
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'classroom_id' => $c2->id,
            'semester_id' => $term->id, 'title' => 'Assess C2', 'type' => 'Recorded', 'status' => 'released',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $attempt1 = DB::table('assessment_attempts')->insertGetId(['assessment_id' => $assessC1, 'student_id' => $sClass->id, 'attempt_number' => 1, 'status' => 'scored', 'created_at' => now(), 'updated_at' => now()]);
        $sub1 = DB::table('assessment_submissions')->insertGetId(['assessment_id' => $assessC1, 'student_id' => $sClass->id, 'attempt_id' => $attempt1, 'semester_id' => $term->id, 'section_id' => $section->id, 'submitted_at' => now(), 'status' => 'scored', 'is_results_released' => true, 'created_at' => now(), 'updated_at' => now()]);
        $attemptC2 = DB::table('assessment_attempts')->insertGetId(['assessment_id' => $assessC2, 'student_id' => $sC2->id, 'attempt_number' => 1, 'status' => 'scored', 'created_at' => now(), 'updated_at' => now()]);
        $subC2 = DB::table('assessment_submissions')->insertGetId(['assessment_id' => $assessC2, 'student_id' => $sC2->id, 'attempt_id' => $attemptC2, 'semester_id' => $term->id, 'section_id' => $section->id, 'submitted_at' => now(), 'status' => 'scored', 'is_results_released' => true, 'created_at' => now(), 'updated_at' => now()]);

        MasteryRecord::create(['student_id' => $sClass->id, 'subject_id' => $subject->id, 'classroom_id' => $c1->id, 'assessment_id' => $assessC1, 'assessment_submission_id' => $sub1, 'competency_id' => $comp->id, 'mastery_percent' => 90, 'mastery_status' => 'Mastered']);
        MasteryRecord::create(['student_id' => $sC2->id, 'subject_id' => $subject->id, 'classroom_id' => $c2->id, 'assessment_id' => $assessC2, 'assessment_submission_id' => $subC2, 'competency_id' => $comp->id, 'mastery_percent' => 40, 'mastery_status' => 'Not_Mastered']);

        $analytics = app(AnalyticsService::class);

        // Public method (not the private baseAggregateQuery).
        $scoped = $analytics->getTeacherHeatmapForClassroom($teacher->id, $c1->id);
        $this->assertSame($c1->id, $scoped['classroom_id']);
        $this->assertCount(1, $scoped['competencies']);
        $this->assertSame(1, $scoped['competencies'][0]['total_students_assessed']);
        $this->assertSame(1, $scoped['competencies'][0]['mastered_count']);

        // Pooled query counts both the C1 row and the C2 row.
        $pooled = $analytics->getTeacherHeatmap($teacher->id, $subject->id);
        $this->assertCount(1, $pooled['competencies']);
        $this->assertSame(2, $pooled['competencies'][0]['total_students_assessed']);
        $this->assertSame(1, $pooled['competencies'][0]['mastered_count']);
    }
}

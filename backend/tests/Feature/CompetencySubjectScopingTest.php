<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use App\Services\LearningMaterialService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompetencySubjectScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Subject $math;

    private Subject $english;

    private CompetencyReference $mathCompetency;

    private CompetencyReference $englishCompetency;

    private Classroom $classroomMath;

    private Classroom $classroomEnglish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-CSS']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gl->id, 'name' => '7A-CSS']);

        $this->math = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Mathematics-CSS', 'code' => 'MATH-CSS']);
        $this->english = Subject::create(['grade_level_id' => $gl->id, 'name' => 'English-CSS', 'code' => 'ENG-CSS']);


        $this->classroomMath = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->math->id, $section->id, '2026-2027', null);
        $this->classroomEnglish = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->english->id, $section->id, '2026-2027', null);

        $this->mathCompetency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-CSS-001',
            'descriptor' => 'Math competency',
            'subject_id' => $this->math->id,
            'grade_level' => '7',
        ]);
        $this->englishCompetency = CompetencyReference::create(['semester' => '1', 'code' => 'E7-CSS-001',
            'descriptor' => 'English competency',
            'subject_id' => $this->english->id,
            'grade_level' => '7',
        ]);
    }

    private function createDraftAssessment(int $subjectId): array
    {
        $classroomId = $subjectId === $this->math->id
            ? $this->classroomMath->id
            : $this->classroomEnglish->id;

        return $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$classroomId.'/assessments', [
                'title' => 'Scoping Assessment',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->assertCreated()
            ->json('data');
    }

    public function test_cross_subject_tag_on_create_is_rejected(): void
    {
        $assessment = $this->createDraftAssessment($this->english->id);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->mathCompetency->id,
                'sort_order' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPETENCY_MISMATCH');

        $this->assertDatabaseMissing('assessment_items', [
            'assessment_id' => $assessment['id'],
            'competency_tag_id' => $this->mathCompetency->id,
        ]);
    }

    public function test_same_subject_tag_on_create_persists(): void
    {
        $assessment = $this->createDraftAssessment($this->math->id);

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->mathCompetency->id,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('assessment_items', [
            'id' => $item['id'],
            'assessment_id' => $assessment['id'],
            'competency_tag_id' => $this->mathCompetency->id,
        ]);
    }

    public function test_cross_subject_tag_on_update_is_rejected_and_old_tag_unchanged(): void
    {
        $assessment = $this->createDraftAssessment($this->math->id);

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->mathCompetency->id,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$item['id']}", [
                'competency_tag_id' => $this->englishCompetency->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPETENCY_MISMATCH');

        $this->assertDatabaseHas('assessment_items', [
            'id' => $item['id'],
            'competency_tag_id' => $this->mathCompetency->id,
        ]);
    }

    public function test_learning_material_cross_subject_competency_is_rejected_without_row_or_file(): void
    {
        Storage::fake('local');

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->english->id,
                'competency_id' => $this->mathCompetency->id,
                'title' => 'Cross-subject Guide',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPETENCY_MISMATCH');

        $this->assertDatabaseMissing('learning_materials', [
            'subject_id' => $this->english->id,
            'competency_id' => $this->mathCompetency->id,
        ]);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_learning_material_same_subject_competency_persists(): void
    {
        Storage::fake('local');

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->english->id,
                'competency_id' => $this->englishCompetency->id,
                'title' => 'English Guide',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('learning_materials', [
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->english->id,
            'competency_id' => $this->englishCompetency->id,
            'original_filename' => 'English Guide',
        ]);
    }

    public function test_learning_material_unknown_competency_throws_not_found_without_row_or_file(): void
    {
        Storage::fake('local');

        try {
            app(LearningMaterialService::class)->storeLearningMaterial(
                $this->teacher->id,
                $this->english->id,
                999999,
                'Ghost Guide',
                UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf')
            );

            $this->fail('Expected ModelNotFoundException for unknown competency id');
        } catch (ModelNotFoundException $e) {
            // CompetencyReference::findOrFail renders as 404 over HTTP
            // (bootstrap/app.php). Called at the service layer here because
            // the FormRequest exists rule gates unknown ids with 422 first.
            $this->assertSame(CompetencyReference::class, $e->getModel());
        }

        $this->assertDatabaseCount('learning_materials', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_learning_material_non_owner_gets_403_before_subject_match_check(): void
    {
        Storage::fake('local');

        $outsider = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $this->actingAs($outsider, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->english->id,
                'competency_id' => $this->mathCompetency->id,
                'title' => 'Outsider Guide',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SUBJECT_NOT_ASSIGNED');

        $this->assertDatabaseCount('learning_materials', 0);
    }

    public function test_learning_material_long_client_filename_is_rejected_without_row_or_file(): void
    {
        Storage::fake('local');

        // 230-char client name → 33-char `learning_materials/<uniqid>_` prefix
        // pushes the stored path to 263 chars, past varchar(255).
        $longName = str_repeat('a', 226) . '.pdf';

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->english->id,
                'competency_id' => $this->englishCompetency->id,
                'title' => 'Long Name Guide',
                'file' => File::fake()->create($longName, 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FILENAME_TOO_LONG');

        $this->assertDatabaseCount('learning_materials', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_learning_material_unknown_section_throws_not_found_not_forbidden(): void
    {
        Storage::fake('local');

        try {
            app(LearningMaterialService::class)->storeLearningMaterial(
                $this->teacher->id,
                999999,
                $this->englishCompetency->id,
                'Ghost Guide',
                UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf')
            );

            $this->fail('Expected ModelNotFoundException for unknown subject-section id');
        } catch (ModelNotFoundException $e) {
            // Section existence is checked before the assignment (403) check,
            // so an unknown section 404s instead of masking as 403.
            $this->assertSame(Subject::class, $e->getModel());
        }

        $this->assertDatabaseCount('learning_materials', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}

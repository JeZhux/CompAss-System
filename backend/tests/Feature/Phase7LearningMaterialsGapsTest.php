<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\LearningMaterial;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 *
 * Covers the validation, pagination, and lifecycle gaps for the Teacher
 * Learning Materials endpoints (#83, #84, #86, API Interface Spec §3.9.1)
 * that Phase7LearningMaterialsTest does not assert: oversized uploads,
 * title length caps, DOCX acceptance, update-with-invalid-file atomicity,
 * empty-body updates, explicit pagination, created_at DESC ordering, and
 * the competency filter returning empty results.
 *
 * Error envelope: { "error": { "message", "code" } } (ARCH-002 QA-007); 422
 * VALIDATION_ERROR carries `fields` keyed per attribute.
 */
#[Group('phase7-learning-materials')]
class Phase7LearningMaterialsGapsTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;
        private ?Subject $subject = null;

    private ?Section $section = null;
    private ?CompetencyReference $competencyTag1 = null;
    private ?CompetencyReference $competencyTag2 = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->section = null;
        $this->competencyTag1 = null;
        $this->competencyTag2 = null;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-LMG']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7',
        ]);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-LMG',
        ]);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-LMG']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-01',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GEO-02',
            'descriptor' => 'Classify geometric figures',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        // Classroom-derived ownership (Semester canonical): teacher must own a
        // classroom for the subject or every materials call 403s.
        app(\App\Services\ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        Sanctum::actingAs($this->teacher);

    }

    /**
     * POST a valid learning material via the endpoint and return the
     * serialized `data` array (includes id, filename path, etc.).
     */
    private function storeMaterial(string $title = 'Test Material', ?File $file = null): array
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => $title,
                'file' => $file ?? File::fake()->create('guide.pdf', 100, 'application/pdf'),
            ]);

        return $response->json('data');
    }

    // ====================================================================
    // #84 — POST /api/teacher/learning-materials (validation gaps)
    // ====================================================================

    /** @test */
    public function test_84_post_learning_material_rejects_file_over_15mb()
    {
        $this->setUpOrg();
        Storage::fake('local');

        // Fake-file size is in kilobytes: 16000 KB = 15.6 MB > the 15360 KB
        // (15 MB) cap — the same working pattern as Phase3EdgeCaseTest.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Oversized Guide',
                'file' => File::fake()->create('big.pdf', 16000, 'application/pdf'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('file', $response->json('error.fields'));
        $this->assertDatabaseCount('learning_materials', 0);
    }

    /** @test */
    public function test_84_post_learning_material_rejects_title_over_255_chars()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => str_repeat('a', 256),
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('title', $response->json('error.fields'));
        $this->assertDatabaseCount('learning_materials', 0);
    }

    /** @test */
    public function test_84_post_learning_material_accepts_docx()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Lesson Notes',
                'file' => File::fake()->create('notes.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ]);

        $response->assertCreated();
        $this->assertEquals('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $response->json('data.mime_type'));
        $this->assertIsInt($response->json('data.file_size'));
        $this->assertDatabaseHas('learning_materials', [
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'competency_id' => $this->competencyTag1->id,
            'original_filename' => 'Lesson Notes',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    // ====================================================================
    // #86 — PUT /api/teacher/learning-materials/{id} (validation + lifecycle)
    // ====================================================================

    /** @test */
    public function test_86_put_learning_material_rejects_invalid_file_type_and_leaves_row_unchanged()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $data = $this->storeMaterial('Original Title');
        $materialId = $data['id'];
        $originalFilename = LearningMaterial::find($materialId)->filename;

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/learning-materials/' . $materialId, [
                'file' => File::fake()->create('notes.txt', 100, 'text/plain'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('file', $response->json('error.fields'));
        $this->assertDatabaseHas('learning_materials', [
            'id' => $materialId,
            'original_filename' => 'Original Title',
            'filename' => $originalFilename,
        ]);
    }

    /** @test */
    public function test_86_put_learning_material_rejects_title_over_255_chars()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $data = $this->storeMaterial('Original Title');
        $materialId = $data['id'];

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->putJson('/api/teacher/learning-materials/' . $materialId, [
                'title' => str_repeat('a', 256),
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('title', $response->json('error.fields'));
        $this->assertDatabaseHas('learning_materials', [
            'id' => $materialId,
            'original_filename' => 'Original Title',
        ]);
    }

    /** @test */
    public function test_86_put_learning_material_with_empty_body_returns_200_without_changes()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $data = $this->storeMaterial('Original Title');
        $materialId = $data['id'];
        $before = LearningMaterial::find($materialId);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->putJson('/api/teacher/learning-materials/' . $materialId, []);

        $response->assertOk();
        $this->assertEquals('Original Title', $response->json('data.original_filename'));
        $this->assertEquals('application/pdf', $response->json('data.mime_type'));

        $after = LearningMaterial::find($materialId);
        $this->assertEquals($before->original_filename, $after->original_filename);
        $this->assertEquals($before->filename, $after->filename);
    }

    // ====================================================================
    // #83 — GET /api/teacher/learning-materials (pagination + ordering)
    // ====================================================================

    /** @test */
    public function test_83_get_learning_materials_paginates_with_per_page()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $this->storeMaterial('Mat 1');
        $this->storeMaterial('Mat 2');
        $this->storeMaterial('Mat 3');

        $page1 = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id . '&per_page=1');

        $page1->assertOk();
        $this->assertCount(1, $page1->json('data'));
        $this->assertEquals(3, $page1->json('meta.total'));
        $this->assertEquals(3, $page1->json('meta.last_page'));
        $this->assertEquals(1, $page1->json('meta.per_page'));
        $this->assertEquals(1, $page1->json('meta.current_page'));

        $page2 = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id . '&per_page=1&page=2');

        $page2->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertEquals(2, $page2->json('meta.current_page'));
        $this->assertNotEquals($page1->json('data.0.id'), $page2->json('data.0.id'));
    }

    /** @test */
    public function test_83_get_learning_materials_orders_by_created_at_desc()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $dataA = $this->storeMaterial('Material A');
        $dataB = $this->storeMaterial('Material B');

        // Force B to be strictly newer than A so the tie-breaking by id cannot
        // mask a broken ordering (created_at DESC is the only sort key).
        LearningMaterial::where('id', $dataB['id'])->update(['created_at' => now()->addMinute()]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertEquals($dataB['id'], $response->json('data.0.id'));
    }

    /** @test */
    public function test_83_get_learning_materials_competency_filter_with_no_match_returns_empty()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $this->storeMaterial('Tag 1 Material');
        $this->storeMaterial('Tag 1 Material 2');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id . '&competency_id=' . $this->competencyTag2->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
        $this->assertEquals(0, $response->json('meta.total'));
    }

    /** @test */
    public function test_83_get_learning_materials_clamps_per_page_lower_bound()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $this->storeMaterial('Mat 1');

        // per_page=0 must not crash (pre-fix: division by zero) — clamped to
        // the 15 default (ARCH-005 §2 shared clamp, out-of-range → default).
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id . '&per_page=0');

        $response->assertOk();
        $this->assertEquals(15, $response->json('meta.per_page'));
    }

    /** @test */
    public function test_83_get_learning_materials_clamps_per_page_upper_bound()
    {
        $this->setUpOrg();
        Storage::fake('local');

        $this->storeMaterial('Mat 1');

        // per_page=999999 must be capped at the 100 ceiling.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id . '&per_page=999999');

        $response->assertOk();
        $this->assertEquals(100, $response->json('meta.per_page'));
    }

    // ====================================================================
    // DB CHECK constraint — learning_materials_file_size_check (ARCH-002 QA-009)
    // ====================================================================

    /** @test */
    public function test_learning_material_file_size_check_accepts_exactly_15mb()
    {
        $this->setUpOrg();

        // 15 MB in bytes is the exact boundary of the CHECK constraint.
        $material = LearningMaterial::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'competency_id' => $this->competencyTag1->id,
            'filename' => 'learning_materials/boundary.pdf',
            'original_filename' => 'Boundary Guide',
            'mime_type' => 'application/pdf',
            'file_size' => 15728640,
        ]);

        $this->assertNotNull($material->id);
    }

    /** @test */
    public function test_learning_material_file_size_check_rejects_over_15mb()
    {
        $this->setUpOrg();

        // Direct model create bypasses the FormRequest's max:15360 rule — the
        // DB-level CHECK must reject one byte over the 15 MB limit (ARCH-002 QA-009).
        $this->expectException(QueryException::class);

        LearningMaterial::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'competency_id' => $this->competencyTag1->id,
            'filename' => 'learning_materials/oversized.pdf',
            'original_filename' => 'Oversized Guide',
            'mime_type' => 'application/pdf',
            'file_size' => 15728641,
        ]);
    }
}

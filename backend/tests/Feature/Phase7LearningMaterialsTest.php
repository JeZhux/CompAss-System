<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\LearningMaterial;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 *
 * Closes the access-control, validation, file-lifecycle, and ownership gaps
 * the baseline Phase7Test does not assert for the Learning Materials endpoints
 * (#83–#86, API Interface Spec §3.9.1).
 *
 * Endpoints:
 *   #83 GET  /api/teacher/learning-materials
 *   #84 POST /api/teacher/learning-materials
 *   #85 DEL  /api/teacher/learning-materials/{id}
 *   #86 PUT  /api/teacher/learning-materials/{id}
 *
 * Middleware: auth:sanctum → password.change.required → role:teacher.
 * Error envelope: { "error": { "message", "code" } } (ARCH-002 QA-007).
 */
#[Group('phase7-learning-materials')]
class Phase7LearningMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;
    private ?User $otherTeacher = null;
    private ?User $student = null;
    private ?User $otherStudent = null;
    private ?User $admin = null;
    private ?User $mustChangeTeacher = null;

    private ?Subject $subject = null;
    private ?Section $section = null;
    private ?Classroom $classroom = null;
    private ?CompetencyReference $competencyTag1 = null;
    private ?CompetencyReference $competencyTag2 = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->otherTeacher = $this->student = null;
        $this->otherStudent = $this->admin = $this->mustChangeTeacher = null;
        $this->subject = $this->section = null;
        $this->classroom = null;
        $this->competencyTag1 = $this->competencyTag2 = null;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-LM']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-LM']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-LM']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-01',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GEO-02',
            'descriptor' => 'Classify geometric figures',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
    }

    private function ensureAllUsers(): void
    {
        $this->setUpOrg();

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->otherTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student']);
        $this->otherStudent = User::factory()->create(['role' => 'Student']);
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->mustChangeTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => true]);


        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'joined_at' => now()]);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id,
            'joined_at' => now()]);
    }

    /**
     * POST a valid learning material via the endpoint and return the
     * serialized `data` array (includes id, filename path, etc.).
     */
    private function storeMaterial(
        User $teacher,
        ?int $subjectId = null,
        ?int $competencyId = null,
        string $title = 'Test Material'
    ): array {
        $response = $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $subjectId ?? $this->subject->id,
                'competency_id' => $competencyId ?? $this->competencyTag1->id,
                'title' => $title,
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf')]);

        return $response->json('data');
    }

    /**
     * Minimal single-page PDF with correct xref offsets, built at runtime.
     * The xref table is byte-accurate because the body is assembled first and
     * offsets are recorded during assembly (ARCH-002 FR-026 upload-time extraction
     * fixture — smalot/pdfparser requires a resolvable startxref).
     */
    private function minimalPdf(string $text): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>'];
        $escaped = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);
        $stream = "BT\n/F1 12 Tf\n72 720 Td\n(" . $escaped . ") Tj\nET\n";
        $objects[4] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF";

        return $pdf;
    }

    /**
     * Minimal real DOCX built at runtime (ARCH-002 FR-026 upload-time extraction
     * fixture — SimpleXMLElement on word/document.xml requires a real zip
     * whose word/document.xml contains w:t runs). Each array entry becomes a
     * <w:p><w:r><w:t> paragraph; an empty array yields a body with no text.
     */
    private function minimalDocx(array $paragraphs): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Unable to create DOCX fixture zip.');
        }

        $body = '';
        foreach ($paragraphs as $paragraph) {
            $body .= '<w:p><w:r><w:t>' . htmlspecialchars((string) $paragraph, ENT_XML1) . '</w:t></w:r></w:p>';
        }

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '</w:body></w:document>');
        $zip->close();

        $content = (string) file_get_contents($tmp);
        unlink($tmp);

        return $content;
    }

    // ====================================================================
    // #83 — GET /api/teacher/learning-materials
    // ====================================================================

    /** @test */
    public function test_83_get_learning_materials_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_403_for_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_403_for_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_403_for_must_change_password_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_403_for_unassigned_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_400_when_subject_id_missing()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials');

        $response->assertStatus(400);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('BAD_REQUEST', $response->json('error.code'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_404_when_subject_not_found()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=999999');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_empty_data_when_no_materials_exist()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    /** @test */
    public function test_83_get_learning_materials_returns_pagination_meta_structure()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $this->storeMaterial($this->teacher);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['id', 'subject_id', 'competency_id', 'original_filename', 'mime_type', 'file_size', 'created_at']],
            'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total']]);
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(15, $response->json('meta.per_page'));
        $this->assertEquals(1, $response->json('meta.last_page'));
    }

    /** @test */
    public function test_83_get_learning_materials_competency_id_filter_returns_only_matching()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $this->storeMaterial($this->teacher, $this->subject->id, $this->competencyTag1->id, 'Mat for Tag 1');
        $this->storeMaterial($this->teacher, $this->subject->id, $this->competencyTag2->id, 'Mat for Tag 2');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id . '&competency_id=' . $this->competencyTag1->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->competencyTag1->id, $response->json('data.0.competency_id'));
    }

    // ====================================================================
    // #84 — POST /api/teacher/learning-materials
    // ====================================================================

    /** @test */
    public function test_84_post_learning_materials_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->postJson('/api/teacher/learning-materials', []);

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_403_for_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/teacher/learning-materials', []);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_403_for_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/teacher/learning-materials', []);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_403_for_must_change_password_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->postJson('/api/teacher/learning-materials', []);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_422_when_title_missing()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf'),
                // title intentionally omitted
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('title', $response->json('error.fields'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_422_when_file_type_invalid()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Notes',
                'file' => File::fake()->create('notes.txt', 100, 'text/plain')]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('file', $response->json('error.fields'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_422_when_competency_id_nonexistent()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => 999999,
                'title' => 'Guide',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf')]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('competency_id', $response->json('error.fields'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_422_when_subject_id_nonexistent()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => 999999,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Guide',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf')]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('subject_id', $response->json('error.fields'));
    }

    /** @test */
    public function test_84_post_learning_materials_returns_201_with_full_response_shape()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Fraction Guide',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf')]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => ['id', 'subject_id', 'competency_id', 'original_filename', 'mime_type', 'file_size', 'created_at']]);
        $this->assertNotNull($response->json('data.id'));
        $this->assertEquals($this->subject->id, $response->json('data.subject_id'));
        $this->assertEquals($this->competencyTag1->id, $response->json('data.competency_id'));
        $this->assertEquals('Fraction Guide', $response->json('data.original_filename'));
        $this->assertEquals('application/pdf', $response->json('data.mime_type'));
        $this->assertIsInt($response->json('data.file_size'));
        $this->assertNotNull($response->json('data.created_at'));

        $this->assertDatabaseHas('learning_materials', [
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'competency_id' => $this->competencyTag1->id,
            'original_filename' => 'Fraction Guide']);
    }

    // ====================================================================
    // #85 — DELETE /api/teacher/learning-materials/{id}
    // ====================================================================

    /** @test */
    public function test_85_delete_learning_material_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->deleteJson('/api/teacher/learning-materials/1');

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_85_delete_learning_material_returns_403_for_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/teacher/learning-materials/1');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_85_delete_learning_material_returns_403_for_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->deleteJson('/api/teacher/learning-materials/1');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_85_delete_learning_material_returns_403_for_must_change_password_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->deleteJson('/api/teacher/learning-materials/1');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_85_delete_learning_material_success_deletes_row_and_file()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $data = $this->storeMaterial($this->teacher);
        $materialId = $data['id'];
        $filename = LearningMaterial::find($materialId)->filename;

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->deleteJson('/api/teacher/learning-materials/' . $materialId);

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Learning material deleted.');
        $this->assertDatabaseMissing('learning_materials', ['id' => $materialId]);
        Storage::disk('local')->assertMissing($filename);
    }

    /** @test */
    public function test_85_delete_learning_material_returns_404_when_nonexistent()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->deleteJson('/api/teacher/learning-materials/99999');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_85_delete_learning_material_returns_404_for_non_owner_and_row_remains()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $data = $this->storeMaterial($this->teacher);
        $materialId = $data['id'];

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->deleteJson('/api/teacher/learning-materials/' . $materialId);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
        $this->assertDatabaseHas('learning_materials', ['id' => $materialId]);
    }

    // ====================================================================
    // #86 — PUT /api/teacher/learning-materials/{id}
    // ====================================================================

    /** @test */
    public function test_86_put_learning_material_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->putJson('/api/teacher/learning-materials/1', ['title' => 'Test']);

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_86_put_learning_material_returns_403_for_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/teacher/learning-materials/1', ['title' => 'Test']);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_86_put_learning_material_returns_403_for_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->putJson('/api/teacher/learning-materials/1', ['title' => 'Test']);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_86_put_learning_material_returns_403_for_must_change_password_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->putJson('/api/teacher/learning-materials/1', ['title' => 'Test']);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_86_put_learning_material_update_title_only_keeps_filename()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $data = $this->storeMaterial($this->teacher, $this->subject->id, $this->competencyTag1->id, 'Original Title');
        $materialId = $data['id'];
        $originalFilename = LearningMaterial::find($materialId)->filename;

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/learning-materials/' . $materialId, [
                'title' => 'Updated Title']);

        $response->assertOk();
        $this->assertDatabaseHas('learning_materials', [
            'id' => $materialId,
            'original_filename' => 'Updated Title',
            'filename' => $originalFilename]);
        Storage::disk('local')->assertExists($originalFilename);
    }

    /** @test */
    public function test_86_put_learning_material_update_file_only_deletes_old_and_stores_new()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $data = $this->storeMaterial($this->teacher, $this->subject->id, $this->competencyTag1->id, 'Original Title');
        $materialId = $data['id'];
        $material = LearningMaterial::find($materialId);
        $oldFilename = $material->filename;
        $originalFilename = $material->original_filename;

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/learning-materials/' . $materialId, [
                'file' => File::fake()->create('updated.pdf', 100, 'application/pdf')]);

        $response->assertOk();
        $updated = LearningMaterial::find($materialId);
        $this->assertNotEquals($oldFilename, $updated->filename);
        $this->assertEquals($originalFilename, $updated->original_filename);
        Storage::disk('local')->assertMissing($oldFilename);
        Storage::disk('local')->assertExists($updated->filename);
        $this->assertEquals('application/pdf', $updated->mime_type);
    }

    /** @test */
    public function test_86_put_learning_material_update_both_title_and_file()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $data = $this->storeMaterial($this->teacher, $this->subject->id, $this->competencyTag1->id, 'Original Title');
        $materialId = $data['id'];
        $oldFilename = LearningMaterial::find($materialId)->filename;

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/learning-materials/' . $materialId, [
                'title' => 'Brand New Title',
                'file' => File::fake()->create('replaced.pdf', 100, 'application/pdf')]);

        $response->assertOk();
        $updated = LearningMaterial::find($materialId);
        $this->assertEquals('Brand New Title', $updated->original_filename);
        $this->assertNotEquals($oldFilename, $updated->filename);
        Storage::disk('local')->assertMissing($oldFilename);
        Storage::disk('local')->assertExists($updated->filename);
    }

    /** @test */
    public function test_86_put_learning_material_returns_404_when_nonexistent()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->putJson('/api/teacher/learning-materials/99999', ['title' => 'Test']);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_86_put_learning_material_returns_404_for_non_owner_and_row_remains()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $data = $this->storeMaterial($this->teacher, $this->subject->id, $this->competencyTag1->id, 'Original Title');
        $materialId = $data['id'];

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->putJson('/api/teacher/learning-materials/' . $materialId, ['title' => 'Hacked Title']);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
        $this->assertDatabaseHas('learning_materials', [
            'id' => $materialId,
            'original_filename' => 'Original Title']);
    }

    // ====================================================================
    // ARCH-002 FR-026 — upload-time PDF/DOCX text extraction (WU-7, WU-7b)
    // ====================================================================

    /** @test */
    public function test_pdf_upload_extracts_text_at_upload_time()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $pdf = $this->minimalPdf('Fractions are parts of a whole');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Fraction Guide',
                'file' => File::fake()->createWithContent('guide.pdf', $pdf)]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNotNull($material->extracted_text);
        $this->assertStringContainsString('Fractions are parts of a whole', $material->extracted_text);
    }

    /** @test */
    public function test_docx_without_text_nodes_stores_null()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $docx = $this->minimalDocx([]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Notes',
                'file' => File::fake()->createWithContent(
                    'notes.docx',
                    $docx,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                )]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNull($material->extracted_text);
    }

    /** @test */
    public function test_corrupt_pdf_upload_does_not_fail()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Broken Guide',
                'file' => File::fake()->createWithContent('broken.pdf', "%PDF-1.4\n" . str_repeat('x', 300))]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNull($material->extracted_text);
    }

    /** @test */
    public function test_docx_upload_extracts_text_at_upload_time()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $docx = $this->minimalDocx([
            'Fractions are parts of a whole',
            'Simplify by dividing numerator and denominator']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Fraction Notes',
                'file' => File::fake()->createWithContent(
                    'notes.docx',
                    $docx,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                )]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNotNull($material->extracted_text);
        $this->assertStringContainsString('Fractions are parts of a whole', $material->extracted_text);
        $this->assertStringContainsString('Simplify by dividing numerator and denominator', $material->extracted_text);
    }

    /** @test */
    public function test_corrupt_docx_upload_does_not_fail()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Broken Notes',
                'file' => File::fake()->createWithContent(
                    'broken.docx',
                    str_repeat('x', 300),
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                )]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNull($material->extracted_text);
    }

    /** @test */
    public function test_put_replacing_file_re_extracts_text()
    {
        $this->ensureAllUsers();
        Storage::fake('local');

        $data = $this->storeMaterial($this->teacher);
        $materialId = $data['id'];

        $pdf = $this->minimalPdf('Replacement fractions text');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/learning-materials/' . $materialId, [
                'file' => File::fake()->createWithContent('replaced.pdf', $pdf)]);

        $response->assertOk();

        $material = LearningMaterial::findOrFail($materialId);
        $this->assertNotNull($material->extracted_text);
        $this->assertStringContainsString('Replacement fractions text', $material->extracted_text);
    }
}

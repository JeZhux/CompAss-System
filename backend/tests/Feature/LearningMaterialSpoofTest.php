<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Exceptions\BusinessRuleConflictException;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Services\LearningMaterialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F-07: a renamed spoof — `.pdf` extension with a `text/plain` MIME —
 * must be rejected with a 422. The HTTP path is gated first by the
 * FormRequest `mimes` rule (VALIDATION_ERROR); the service dual check
 * (LearningMaterialService 272-287: extension AND byte-detected MIME
 * must both be allowed) is pinned directly as INVALID_FILE_TYPE.
 * Verifies the dual check; no prod change.
 */
#[Group('learning-material-spoof')]
class LearningMaterialSpoofTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private function setUpOrg(): array
    {
        $year = SchoolYear::create(['name' => 'SY 2026-SPOOF']);
        $semester = \App\Models\Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-SPOOF']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-SPOOF']);
        $competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-SPOOF-01',
            'descriptor' => 'Spoof competency',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);
        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        // Classroom-derived ownership: teacher must own a classroom for the subject
        // or the service 403s before the file-type check (Semester canonical).
        app(\App\Services\ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);

        return [$teacher, $section, $competency];
    }

    public function test_post_pdf_extension_with_text_plain_mime_is_rejected_with_422(): void
    {
        [$teacher, $section, $competency] = $this->setUpOrg();
        Storage::fake('local');

        $response = $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $competency->id,
                'title' => 'Notes',
                'file' => File::fake()->create('notes.pdf', 10, 'text/plain'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseMissing('learning_materials', ['teacher_id' => $teacher->id]);
    }

    public function test_service_dual_check_rejects_pdf_extension_with_text_plain_mime(): void
    {
        [$teacher, $section, $competency] = $this->setUpOrg();
        Storage::fake('local');

        $sneaky = UploadedFile::fake()->create('notes.pdf', 10, 'text/plain');

        try {
            app(LearningMaterialService::class)->storeLearningMaterial(
                $teacher->id,
                $this->subject->id,
                $competency->id,
                'Notes',
                $sneaky
            );

            $this->fail('Expected BusinessRuleConflictException with code INVALID_FILE_TYPE');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('INVALID_FILE_TYPE', $e->errorCode);
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}

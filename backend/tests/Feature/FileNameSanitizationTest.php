<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\ClassroomEnrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use App\Support\SafeUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * F-03: unsanitized client filenames must not escape the intended storage
 * directory, storage keys stay unique per upload, and download disposition
 * must not permit header injection or path disclosure.
 */
#[Group('f-03')]
class FileNameSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private function setUpOrg(): array
    {
        $year = SchoolYear::create(['name' => 'SY F03']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-F03']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-F03']);
        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create(['classroom_id' => $classroom->id, 'student_id' => $student->id, 'joined_at' => now()]);

        return [$teacher, $student, $section, $classroom];
    }

    public function test_upload_key_confined_unique_and_download_safe(): void
    {
        [$teacher, $student, $section, $classroom] = $this->setUpOrg();
        Storage::fake('local');

        $res = $this->actingAs($teacher, 'sanctum')->post(
            '/api/teacher/classrooms/'.$classroom->id.'/announcements',
            [
                'subject_id' => $this->subject->id,
                'title' => 'F03 file',
                'body' => 'see file',
                'attachments' => [UploadedFile::fake()->create('note.pdf', 100, 'application/pdf')],
            ]
        );
        $res->assertCreated();
        $ann = Announcement::find($res->json('data.id'));
        $att = $ann->attachments->first();
        $this->assertTrue(SafeUpload::isConfined($att->filename, 'announcements'));
        $this->assertStringStartsWith('announcements/'.$ann->id.'/', $att->filename);
        $suffix = substr($att->filename, strlen('announcements/'.$ann->id.'/'));
        $this->assertStringNotContainsString('/', $suffix);
        $this->assertStringNotContainsString('\\', $suffix);
        $this->assertStringNotContainsString("\r", $suffix);
        $this->assertStringNotContainsString("\n", $suffix);
        Storage::disk('local')->assertExists($att->filename);

        $res2 = $this->actingAs($teacher, 'sanctum')->post(
            '/api/teacher/classrooms/'.$classroom->id.'/announcements',
            [
                'subject_id' => $this->subject->id,
                'title' => 'F03 file 2',
                'body' => 'see file',
                'attachments' => [UploadedFile::fake()->create('note.pdf', 100, 'application/pdf')],
            ]
        );
        $res2->assertCreated();
        $ann2 = Announcement::find($res2->json('data.id'));
        $this->assertNotSame($att->filename, $ann2->attachments->first()->filename);

        $dl = $this->actingAs($student, 'sanctum')->get(
            "/api/student/announcements/{$ann->id}/download/{$att->id}"
        );
        $dl->assertOk();
        $disp = $dl->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disp);
        $this->assertStringNotContainsString("\r", $disp);
        $this->assertStringNotContainsString("\n", $disp);

        $att->original_filename = "evil\r\nHeader: injected.pdf";
        $att->save();
        $dl2 = $this->actingAs($student, 'sanctum')->get(
            "/api/student/announcements/{$ann->id}/download/{$att->id}"
        );
        $dl2->assertOk();
        $disp2 = $dl2->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disp2);
        $this->assertStringNotContainsString("\n", $disp2);
        $this->assertSame('evilHeader: injected.pdf', SafeUpload::downloadName("evil\r\nHeader: injected.pdf"));
        $this->assertStringContainsString('evilHeader', $disp2);

        $att->original_filename = '../../etc/passwd.pdf';
        $att->save();
        $dl3 = $this->actingAs($student, 'sanctum')->get(
            "/api/student/announcements/{$ann->id}/download/{$att->id}"
        );
        $dl3->assertOk();
        $disp3 = $dl3->headers->get('Content-Disposition');
        $this->assertStringNotContainsString('../', $disp3);
        $this->assertStringContainsString('passwd.pdf', $disp3);

        $att->filename = 'assignments/1/other.pdf';
        $att->save();
        Storage::disk('local')->put('assignments/1/other.pdf', 'secret');
        $dl4 = $this->actingAs($student, 'sanctum')->get(
            "/api/student/announcements/{$ann->id}/download/{$att->id}"
        );
        $dl4->assertStatus(404);
    }

    public function test_long_client_basename_is_rejected_with_422_without_row_or_file(): void
    {
        [$teacher, , $section, $classroom] = $this->setUpOrg();
        Storage::fake('local');

        $longName = str_repeat('a', 296).'.pdf';

        $res = $this->actingAs($teacher, 'sanctum')->post(
            '/api/teacher/classrooms/'.$classroom->id.'/announcements',
            [
                'subject_id' => $this->subject->id,
                'title' => 'Long name',
                'body' => 'see file',
                'attachments' => [UploadedFile::fake()->create($longName, 100, 'application/pdf')],
            ]
        );

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'FILENAME_TOO_LONG');
        $this->assertDatabaseCount('announcements', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_same_batch_same_name_files_get_distinct_keys_without_overwrite(): void
    {
        [$teacher, , $section, $classroom] = $this->setUpOrg();
        Storage::fake('local');

        $res = $this->actingAs($teacher, 'sanctum')->post(
            '/api/teacher/classrooms/'.$classroom->id.'/announcements',
            [
                'subject_id' => $this->subject->id,
                'title' => 'Batch same name',
                'body' => 'see files',
                'attachments' => [
                    UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
                    UploadedFile::fake()->create('note.pdf', 200, 'application/pdf'),
                    UploadedFile::fake()->create('note.pdf', 300, 'application/pdf'),
                ],
            ]
        );

        $res->assertCreated();
        $ann = Announcement::find($res->json('data.id'));
        $filenames = $ann->attachments->pluck('filename')->all();
        $this->assertCount(3, $filenames);
        $this->assertCount(3, array_unique($filenames));
        $this->assertCount(3, Storage::disk('local')->allFiles());

        foreach ($filenames as $filename) {
            $this->assertTrue(SafeUpload::isConfined($filename, 'announcements'));
            Storage::disk('local')->assertExists($filename);
        }

        $sizes = $ann->attachments->pluck('file_size')->sort()->values()->all();
        $this->assertSame([100 * 1024, 200 * 1024, 300 * 1024], $sizes);
    }

    public function test_storage_key_is_unique_in_tight_loop(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('note.pdf', 10, 'application/pdf');
        $keys = [];

        for ($i = 0; $i < 20; $i++) {
            $keys[] = SafeUpload::storageKey('announcements/1', $file);
        }

        $this->assertCount(20, array_unique($keys));

        foreach ($keys as $key) {
            $this->assertTrue(SafeUpload::isConfined($key, 'announcements'));
            $this->assertLessThanOrEqual(SafeUpload::MAX_STORAGE_KEY_LENGTH, mb_strlen($key));
        }
    }
}

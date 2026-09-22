<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Classroom;
use App\Models\CompetencyReference;
use App\Models\LearningMaterial;
use App\Models\Subject;
use App\Support\BoundedZip;
use App\Support\SafeUpload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Manages teacher-uploaded learning materials for RAG retrieval.
 * Materials are scoped to a subject and aligned to a single
 * competency (ARCH-002 FR-026). Semester-independent per ARCH-002 QA-010 — no semester_id FK;
 * persists across Semester boundaries.
 *
 * File type restricted to PDF and DOCX only (ARCH-002 QA-009). 15 MB per-file
 * cap enforced by FormRequest + DB CHECK (ARCH-002 QA-009).
 *
 * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-006 (ARCH-001 §5.1, ARCH-004 §4.1)
 */
class LearningMaterialService
{
    /** Directory prefix for learning-material uploads. */
    private const DIRECTORY = 'learning_materials';

    /** File types permitted for learning materials (ARCH-002 QA-009). */
    public const ALLOWED_MIME_TYPES = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    /** File extensions permitted for learning materials, mirrored from ALLOWED_MIME_TYPES (ARCH-002 QA-009). */
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx'];

    /** 15 MB in bytes (ARCH-002 QA-009). */
    public const MAX_FILE_SIZE = 15 * 1024 * 1024;

    /**
     * Cap for the stored extracted text (200,000 chars — ARCH-002 FR-026). The spec
     * defines no bound; this keeps the RAG payload bounded for the 15 MB
     * upload cap (ARCH-002 QA-009) while preserving far more than the 2000-char
     * generation-time excerpt the old code produced.
     */
    public const EXTRACTED_TEXT_MAX_CHARS = 200000;

    /**
     * F-05 hardening: maximum PDF pages parsed for text extraction. The
     * parser library cannot stream (see the residual note on
     * extractUploadedText), so the page loop itself is capped — teaching
     * materials never legitimately approach this, and anything beyond it
     * stores NULL text instead of burning request time.
     */
    public const MAX_PDF_PAGES = 2000;

    /**
     * Audit logging of LearningMaterial CRUD (ARCH-001 §5.1 / ARCH-002 QA-006).
     */
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * Upload a learning material file aligned to a competency within a
     * subject-section (ARCH-002 FR-026). Validates teacher assignment (ARCH-002 FR-005).
     *
     * The request `title` field maps to `original_filename` per the
     * API response shape (the table has no separate title column).
     *
     * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function storeLearningMaterial(
        int $teacherId,
        int $subjectId,
        int $competencyId,
        string $title,
        UploadedFile $file
    ): LearningMaterial {
        // Unknown subject must 404 even for non-owners, so the existence
        // check runs before the ownership (403) check. Ownership still runs
        // before competency checks, so a non-owner mismatched upload stays
        // 403, never 422/404.
        $subject = Subject::with('gradeLevel.semester')->findOrFail($subjectId);

        $this->ensureTeacherOwnsSubject($teacherId, $subjectId);

        $competency = CompetencyReference::findOrFail($competencyId);

        // Competency must match the subject's (subject, grade, semester)
        // triple — same COMPETENCY_MISMATCH rule as assessment items.
        // Checked before any file is stored so a mismatch can never leave
        // an orphan file on disk.
        $this->assertCompetencyMatchesSubject($subject, $competency);

        $this->assertAllowedFileType($file);

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new BusinessRuleConflictException(
                'Each file must be at most 15 MB.',
                'FILE_TOO_LARGE'
            );
        }

        $this->assertStorableLengths($title, $file);

        // The filesystem write is NOT rolled back with the DB transaction —
        // track the just-stored file so a DB reject (e.g. a length overflow
        // the pre-check missed) can never orphan it on disk.
        $storedFilename = null;

        try {
            $material = DB::transaction(function () use ($teacherId, $subjectId, $competencyId, $title, $file, &$storedFilename): LearningMaterial {
                // Read once: the same bytes feed both the stored copy and
                // the text extractor (F-05: no double file_get_contents).
                $contents = file_get_contents($file->getRealPath());
                $storedFilename = $this->storeFile($file, $contents);

                return LearningMaterial::create([
                    'teacher_id' => $teacherId,
                    'subject_id' => $subjectId,
                    'competency_id' => $competencyId,
                    'filename' => $storedFilename,
                    'original_filename' => $title,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'extracted_text' => $this->extractUploadedText($file, $contents),
                ]);
            });
        } catch (\Throwable $e) {
            if ($storedFilename !== null && SafeUpload::isConfined($storedFilename, self::DIRECTORY)) {
                SafeUpload::disk()->delete($storedFilename);
            }

            throw $e;
        }

        $this->auditLogService->log(
            'create',
            'Learning material created: ' . $material->original_filename,
            Auth::id(),
            LearningMaterial::class,
            $material->id,
            ['title' => $material->original_filename]
        );

        return $material;
    }

    /**
     * Update title and/or file of an existing learning material (ARCH-002 FR-026).
     * Validates ownership — the teacher must have created the material (ARCH-002 FR-005 scoping).
     *
     * @param  string|null  $title  Maps to original_filename.
     * @Traced-To ARCH-002 FR-026, UC-46, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function updateLearningMaterial(
        int $teacherId,
        int $materialId,
        ?string $title = null,
        ?UploadedFile $file = null
    ): LearningMaterial {
        $material = LearningMaterial::query()
            ->where('id', $materialId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();

        // S-2b: competency is immutable on update — no competency_id is
        // accepted here, so file replacement needs no subject-match re-check.

        // Validate the replacement file BEFORE deleting the old one from
        // disk, so an invalid upload can never orphan the stored file (ARCH-002 QA-009).
        if ($file !== null) {
            $this->assertAllowedFileType($file);

            if ($file->getSize() > self::MAX_FILE_SIZE) {
                throw new BusinessRuleConflictException(
                    'Each file must be at most 15 MB.',
                    'FILE_TOO_LARGE'
                );
            }
        }

        if ($title !== null) {
            $material->original_filename = $title;
        }

        $this->assertStorableLengths($material->original_filename, $file);

        // Store the replacement BEFORE touching the old file, and delete the
        // old file only after the DB row is saved: a save failure must never
        // lose the previous file, and a failed save must not orphan the new
        // one either (compensated in the catch below).
        $oldFilename = $material->filename;
        $newFilename = null;

        try {
            if ($file !== null) {
                $contents = file_get_contents($file->getRealPath());
                $newFilename = $this->storeFile($file, $contents);
                $material->filename = $newFilename;
                $material->mime_type = $file->getMimeType();
                $material->file_size = $file->getSize();
                $material->extracted_text = $this->extractUploadedText($file, $contents);
            }

            $material->save();
        } catch (\Throwable $e) {
            if ($newFilename !== null && SafeUpload::isConfined($newFilename, self::DIRECTORY)) {
                SafeUpload::disk()->delete($newFilename);
            }

            throw $e;
        }

        if ($newFilename !== null) {
            if (! SafeUpload::isConfined($oldFilename, self::DIRECTORY)) {
                Log::warning('Learning material update skipped out-of-directory file key.', [
                    'file' => SafeUpload::loggable($oldFilename),
                ]);
            } else {
                SafeUpload::disk()->delete($oldFilename);
            }
        }

        $this->auditLogService->log(
            'update',
            'Learning material updated (id ' . $material->id . ')',
            Auth::id(),
            LearningMaterial::class,
            $material->id,
            ['title' => $material->original_filename]
        );

        return $material->fresh();
    }

    /**
     * Delete a learning material and its file from disk (ARCH-002 FR-026).
     * Validates ownership.
     *
     * @Traced-To ARCH-002 FR-026, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function deleteLearningMaterial(int $teacherId, int $materialId): void
    {
        $material = LearningMaterial::query()
            ->where('id', $materialId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();

        // The DB row is deleted first: a filesystem write cannot join the
        // DB transaction, so deleting the file first could lose the file
        // while the row survives. File removal is best-effort — a failure
        // here is logged and never fails the request.
        $filename = $material->filename;

        $material->delete();

        try {
            if (! SafeUpload::isConfined($filename, self::DIRECTORY)) {
                Log::warning('Learning material delete skipped out-of-directory file key.', [
                    'file' => SafeUpload::loggable($filename),
                ]);
            } else {
                SafeUpload::disk()->delete($filename);
            }
        } catch (\Throwable $e) {
            Log::warning('Learning material file could not be removed from disk after row delete.', [
                'learning_material_id' => $material->id,
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);
        }

        $this->auditLogService->log(
            'delete',
            'Learning material deleted (id ' . $material->id . ')',
            Auth::id(),
            LearningMaterial::class,
            $material->id,
            ['title' => $material->original_filename]
        );
    }

    /**
     * Paginated list of learning materials for a subject,
     * optionally scoped to a single competency (ARCH-002 FR-026).
     *
     * @return LengthAwarePaginator<int, LearningMaterial>
     *
     * @Traced-To ARCH-002 FR-026 (ARCH-001 §5.1)
     */
    public function getLearningMaterials(int $subjectId, ?int $competencyId = null, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        Subject::findOrFail($subjectId);

        $query = LearningMaterial::query()
            ->where('subject_id', $subjectId)
            ->when($competencyId !== null, fn ($q) => $q->where('competency_id', $competencyId));

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, page: $page);
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Validate that the teacher owns the subject package via at least one
     * classroom for that subject (classroom-derived scope, ARCH-002 FR-005).
     *
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    private function ensureTeacherOwnsSubject(int $teacherId, int $subjectId): void
    {
        $owns = Classroom::query()
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $owns) {
            throw new BusinessRuleConflictException(
                'You are not assigned to this subject.',
                'SUBJECT_NOT_ASSIGNED',
                403
            );
        }
    }

    /**
     * Competency must match the subject's (subject, grade, semester) triple.
     */
    private function assertCompetencyMatchesSubject(Subject $subject, CompetencyReference $competency): void
    {
        $subject->loadMissing('gradeLevel.semester');
        $expectedGrade = $subject->gradeLevel ? (string) $subject->gradeLevel->grade_level : null;
        $expectedSemester = $subject->gradeLevel?->semester ? (string) $subject->gradeLevel->semester->semester : null;

        $mismatch = (int) $competency->subject_id !== (int) $subject->id
            || ($expectedGrade !== null && (string) $competency->grade_level !== $expectedGrade)
            || ($expectedSemester !== null && (string) $competency->semester !== $expectedSemester);

        if ($mismatch) {
            throw new BusinessRuleConflictException(
                'The competency does not belong to the learning material subject, grade level, and semester.',
                'COMPETENCY_MISMATCH',
                422
            );
        }
    }

    /**
     * Persist an uploaded file to the default disk and return the storage path.
     *
     * @param string|false|null $contents Pre-read file bytes (F-05: callers
     *   read once and share with the extractor); read here when omitted.
     *
     * @Traced-To ARCH-002 QA-009, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    private function storeFile(UploadedFile $file, string|false|null $contents = null): string
    {
        $dir = self::DIRECTORY;
        $filename = SafeUpload::storageKey($dir, $file);

        SafeUpload::disk()->put($filename, $contents ?? file_get_contents($file->getRealPath()));

        return $filename;
    }

    /**
     * Reject files whose extension or detected MIME type is not PDF/DOCX
     * (ARCH-002 QA-009). Both the extension AND the byte-detected MIME must be
     * allowed, so a renamed file is rejected even when its name looks
     * valid (ARCH-002 QA-009 / ARCH-002 QA-009).
     *
     * @Traced-To ARCH-002 QA-009, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    private function assertAllowedFileType(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if (
            ! in_array($extension, self::ALLOWED_EXTENSIONS, true)
            || ! in_array($mime, self::ALLOWED_MIME_TYPES, true)
        ) {
            throw new BusinessRuleConflictException(
                'Learning material file must be a PDF or DOCX.',
                'INVALID_FILE_TYPE',
                422
            );
        }
    }

    /**
     * Reject a title or generated storage path that would exceed the
     * varchar(255) columns (original_filename, filename). Runs BEFORE any
     * file is stored so a DB length reject can never orphan a file on disk
     * (the stored path is `learning_materials/` + unique prefix + `_` + the
     * sanitized client name; measured via SafeUpload so the prediction uses
     * the same limit and sanitization as the real stored path).
     *
     * @Traced-To ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    private function assertStorableLengths(string $title, ?UploadedFile $file = null): void
    {
        if (mb_strlen($title) > 255) {
            throw new BusinessRuleConflictException(
                'The title must be at most 255 characters.',
                'TITLE_TOO_LONG',
                422
            );
        }

        if ($file !== null) {
            SafeUpload::assertFits(self::DIRECTORY, $file);
        }
    }

    /**
     * Synchronously extract the plain text of an uploaded PDF or DOCX at
     * upload time (ARCH-002 FR-026).
     *
     * smalot/pdfparser runs in-request for PDFs and PHP's built-in
     * ZipArchive + XMLReader read `word/document.xml` for DOCX — no
     * queues exist in this project (ARCH-002 QA-005/28, QUEUE_CONNECTION=sync).
     * A corrupt/unparseable file must NOT fail the upload — NULL is stored
     * and the failure is logged (warning). NULL/empty also when the file
     * parses to no readable text (e.g. scanned PDF, DOCX with no text
     * nodes), so RAG treats it as unavailable. The stored text is
     * whitespace-collapsed and capped at EXTRACTED_TEXT_MAX_CHARS.
     *
     * F-05: accumulation is bounded — both format readers stop once
     * EXTRACTED_TEXT_MAX_CHARS is reached and never materialize a larger
     * text intermediate (only the file-size-capped input is read). The final
     * mb_substr below is a backstop so the stored value is exactly capped.
     *
     * F-05 hardening residual (explicit): smalot/pdfparser cannot stream —
     * it builds the full object model from the ≤15 MB input before the
     * bounded per-page loop runs. Worst case is therefore bounded by the
     * 15 MB file cap (input bytes) + MAX_PDF_PAGES loop iterations +
     * EXTRACTED_TEXT_MAX_CHARS accumulated text; a single huge page is
     * truncated per-page and its full text never materializes. DOCX has no
     * such residual: document.xml streams through a byte-capped temp file
     * and parsing aborts at the char bound.
     *
     * @param string|false|null $contents Pre-read file bytes (F-05: callers
     *   read once and share with storage); read here when omitted.
     *
     * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 FR-021, ARCH-002 FR-028, ARCH-002 QA-005, ARCH-002 QA-005 (ARCH-001 §5.1)
     */
    private function extractUploadedText(UploadedFile $file, string|false|null $contents = null): ?string
    {
        $mime = strtolower((string) $file->getMimeType());

        try {
            if ($mime === 'application/pdf') {
                $parser = new Parser();
                $document = $parser->parseContent((string) ($contents ?? file_get_contents($file->getRealPath())));
                $pages = $document->getPages();
                if (count($pages) > self::MAX_PDF_PAGES) {
                    Log::warning('Learning material PDF exceeds page cap; stored without extracted text.', [
                        'file' => SafeUpload::loggable((string) $file->getClientOriginalName()),
                        'pages' => count($pages),
                    ]);

                    return null;
                }
                $text = $this->readPdfTextBounded($document, $pages);
            } elseif ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                $text = $this->readDocxText((string) $file->getRealPath(), (int) $file->getSize());
            } else {
                return null;
            }

            $text = trim((string) preg_replace('/\s+/', ' ', $text));
        } catch (\Throwable $e) {
            Log::warning('Learning material text extraction failed; stored without extracted text.', [
                'file' => SafeUpload::loggable((string) $file->getClientOriginalName()),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::EXTRACTED_TEXT_MAX_CHARS) {
            Log::warning('Learning material extracted text truncated to storage bound.', [
                'file' => SafeUpload::loggable((string) $file->getClientOriginalName()),
                'extracted_length' => mb_strlen($text),
                'stored_length' => self::EXTRACTED_TEXT_MAX_CHARS,
            ]);
        }

        return mb_substr($text, 0, self::EXTRACTED_TEXT_MAX_CHARS);
    }

    /**
     * Concatenate per-page PDF text with the F-05 bound (mirrors
     * Document::getText() — non-empty trimmed page texts joined with "\n\n"
     * — but stops accumulating once EXTRACTED_TEXT_MAX_CHARS is reached, so
     * a many-page document never materializes its full text).
     *
     * Length is tracked with a running counter (never mb_strlen on the
     * growing accumulator) and each page piece is truncated to the remaining
     * allowance, keeping the intermediate within the bound plus one page.
     *
     * @param array<int, mixed> $pages Pages from Document::getPages().
     *
     * @Traced-To ARCH-002 FR-026, ARCH-002 FR-028 (ARCH-001 §5.1)
     */
    private function readPdfTextBounded(Document $document, ?array $pages = null): string
    {
        $pages ??= $document->getPages();
        $parts = [];
        $length = 0;

        foreach ($pages as $page) {
            $remaining = self::EXTRACTED_TEXT_MAX_CHARS - $length;
            if ($remaining <= 0) {
                break;
            }

            if ($page === null) {
                continue;
            }

            $piece = trim((string) $page->getText());
            if ($piece === '') {
                continue;
            }

            if (mb_strlen($piece) > $remaining) {
                $piece = mb_substr($piece, 0, $remaining);
            }

            $parts[] = $piece;
            $length += mb_strlen($piece) + 2;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Read the plain text of a DOCX by streaming the `word/document.xml`
     * entry with XMLReader and collecting `<w:t>` runs in the
     * WordprocessingML namespace (ARCH-002 FR-026). Runs are joined with a space;
     * callers collapse whitespace and cap the length. A missing/invalid zip
     * entry or malformed XML propagates the Throwable to the upload path,
     * which stores NULL instead of failing the upload.
     *
     * F-05 bounded processing: document.xml is decompressed incrementally
     * into a byte-capped temp file (BoundedZip) — never held as a string —
     * and text nodes are accumulated only up to EXTRACTED_TEXT_MAX_CHARS
     * with a running length counter (never mb_strlen on the growing
     * accumulator); each run is truncated to the remaining allowance, and
     * parsing stops early once the bound is reached. Breaching the
     * decompression cap throws like any other unreadable entry, so the
     * upload still succeeds with NULL text. LIBXML_NONET keeps external
     * entities unfetched during the parse.
     *
     * @param int $compressedBytes Compressed size of the whole file (ratio guard).
     *
     * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 FR-021, ARCH-002 FR-028 (ARCH-001 §5.1)
     */
    private function readDocxText(string $realPath, int $compressedBytes): string
    {
        $tmp = BoundedZip::copyEntryToTempFile($realPath, 'word/document.xml', $compressedBytes);

        $reader = new \XMLReader();
        $previousErrors = libxml_use_internal_errors(true);

        try {
            if (! $reader->open($tmp, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException('Unable to parse DOCX document.xml.');
            }

            $parts = [];
            $length = 0;

            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 't') {
                    continue;
                }

                if ($reader->namespaceURI !== 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') {
                    continue;
                }

                $remaining = self::EXTRACTED_TEXT_MAX_CHARS - $length;
                if ($remaining <= 0) {
                    break;
                }

                $value = $reader->readString();
                if ($value === '') {
                    continue;
                }

                if (mb_strlen($value) > $remaining) {
                    $value = mb_substr($value, 0, $remaining);
                }

                $parts[] = $value;
                $length += mb_strlen($value) + 1;
            }
        } finally {
            $reader->close();
            libxml_use_internal_errors($previousErrors);
            libxml_clear_errors();
            @unlink($tmp);
        }

        return implode(' ', $parts);
    }
}

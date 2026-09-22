<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\ClassroomEnrollmentMove;
use App\Models\ClassroomJoinKeyHistory;
use App\Models\ClassroomLeaveHistory;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClassroomService
{
    private const JOIN_KEY_LENGTH = 6;

    private const JOIN_KEY_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const JOIN_KEY_MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * Normalize a raw join key: upper-case, strip dashes and spaces and any non-alphanumeric.
     */
    public static function normalizeKey(string $key): string
    {
        // Strip dashes, spaces, then keep only A-Z0-9, upper.
        $stripped = str_replace(['-', ' ', "\t", "\n", "\r"], '', $key);
        $upper = strtoupper($stripped);
        // Remove any remaining non-alphanumeric for robustness.
        $filtered = preg_replace('/[^A-Z0-9]/', '', $upper);

        return $filtered ?? $upper;
    }

    /**
     * Display form XX-XXXX (2-4 slice).
     */
    public static function formatDisplayKey(string $normalizedKey): string
    {
        if (strlen($normalizedKey) !== self::JOIN_KEY_LENGTH) {
            return $normalizedKey;
        }

        return substr($normalizedKey, 0, 2) . '-' . substr($normalizedKey, 2);
    }

    /**
     * Generate a 6-char A-Z0-9 uppercase key, collision-retried up to 10 attempts.
     * Checks both classrooms.join_key and classroom_join_key_history.old_join_key.
     *
     * @throws BusinessRuleConflictException if uniqueness cannot be achieved
     */
    public function generateUniqueJoinKey(): string
    {
        for ($attempt = 0; $attempt < self::JOIN_KEY_MAX_ATTEMPTS; $attempt++) {
            $key = $this->randomKey();

            $exists = Classroom::where('join_key', $key)->exists()
                || ClassroomJoinKeyHistory::where('old_join_key', $key)->exists();

            if (! $exists) {
                return $key;
            }
        }

        throw new BusinessRuleConflictException(
            'Could not generate a unique join key after multiple attempts.',
            'JOIN_KEY_GENERATION_FAILED',
            500
        );
    }

    private function randomKey(): string
    {
        $chars = self::JOIN_KEY_CHARS;
        $max = strlen($chars) - 1;
        $key = '';

        for ($i = 0; $i < self::JOIN_KEY_LENGTH; $i++) {
            $key .= $chars[random_int(0, $max)];
        }

        return $key;
    }

    /**
     * Create a classroom for a teacher.
     *
     * Teacher = Semester + Grade Level + Subject package → Classroom.
     * Teacher scope is derived from classrooms themselves: any teacher may
     * create a classroom when the subject + section package is valid
     * (both exist and share the same grade_level_id). No separate
     * assignment table is consulted. Cross-Semester creation is intentional
     * bootstrap — a teacher with no classroom in a Semester may self-provision
     * their first room there (package check still enforced); Semester
     * isolation is enforced at read time via classroom ownership
     * (teacher_id) and enrollment checks, never by blocking creation.
     * Use ?semester_id on the teacher index to partition rooms per Semester.
     *
     * Enforces:
     *  - subject + section share one grade_level_id else 422
     *  - UNIQUE(teacher_id, subject_id, section_id, school_year) else 409 DUPLICATE_CLASSROOM
     *  - suffix trimmed, max 50
     *  - auto name "{Section.name} {Subject.name}" + " - {suffix}" if suffix
     *
     * @Traced-To U02
     */
    public function createClassroom(int $teacherId, int $subjectId, int $sectionId, ?string $schoolYear, ?string $suffix): Classroom
    {
        $subject = Subject::with('gradeLevel.semester')->findOrFail($subjectId);
        $section = Section::with('gradeLevel.semester')->findOrFail($sectionId);

        if ((int) $subject->grade_level_id !== (int) $section->grade_level_id) {
            throw ValidationException::withMessages([
                'section_id' => ['The section must belong to the same grade level as the subject.'],
            ]);
        }

        $schoolYear = $schoolYear !== null ? trim($schoolYear) : '';
        if ($schoolYear === '') {
            $schoolYear = AcademicYear::current();
        }

        $suffix = $suffix !== null ? trim($suffix) : null;
        if ($suffix === '') {
            $suffix = null;
        }
        if ($suffix !== null && mb_strlen($suffix) > 50) {
            throw ValidationException::withMessages([
                'suffix' => ['The suffix may not be greater than 50 characters.'],
            ]);
        }

        // Duplicate guard per-teacher per-year on the natural key.
        $duplicate = Classroom::query()
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->where('section_id', $sectionId)
            ->where('school_year', $schoolYear)
            ->exists();

        if ($duplicate) {
            throw new BusinessRuleConflictException(
                'You already have a classroom for this subject and section in this school year.',
                'DUPLICATE_CLASSROOM',
                409
            );
        }

        // Generate unique join key with retry on DB unique violation (race safety)
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::JOIN_KEY_MAX_ATTEMPTS) {
            $joinKey = $this->generateUniqueJoinKey();

            // Build name
            $base = $section->name . ' ' . $subject->name;
            $name = $suffix ? $base . ' - ' . $suffix : $base;

            try {
                $classroom = DB::transaction(function () use ($teacherId, $subjectId, $sectionId, $schoolYear, $suffix, $name, $joinKey): Classroom {
                    return Classroom::create([
                        'teacher_id' => $teacherId,
                        'subject_id' => $subjectId,
                        'section_id' => $sectionId,
                        'school_year' => $schoolYear,
                        'suffix' => $suffix,
                        'name' => $name,
                        'join_key' => $joinKey,
                        'is_join_enabled' => true,
                    ]);
                });

                $this->auditLogService->log(
                    'create',
                    'Classroom created: ' . $classroom->name,
                    Auth::id() ?? $teacherId,
                    Classroom::class,
                    $classroom->id,
                    ['join_key' => $joinKey, 'subject_id' => $subjectId, 'section_id' => $sectionId, 'school_year' => $schoolYear]
                );

                return $classroom->fresh(['subject.gradeLevel.semester', 'section.gradeLevel.semester']);
            } catch (QueryException $e) {
                $message = $e->getMessage();
                // U02 race fix: check specific constraint name for duplicate classroom BEFORE
                // generic join_key handling. Narrow join_key check to specific constraint
                // names (classrooms_join_key_unique, classroom_join_key_history_old_join_key_unique)
                // not broad str_contains 'join_key' or '23505' alone.
                if (str_contains($message, 'classrooms_teacher_subject_section_year_unique')
                    || str_contains($message, 'classrooms_teacher_subject_year_unique')) {
                    throw new BusinessRuleConflictException(
                        'You already have a classroom for this subject and section in this school year.',
                        'DUPLICATE_CLASSROOM',
                        409
                    );
                }
                if (str_contains($message, 'classrooms_join_key_unique') || str_contains($message, 'classroom_join_key_history_old_join_key_unique')) {
                    $attempts++;
                    $lastException = $e;
                    continue;
                }
                throw $e;
            }
        }

        throw new BusinessRuleConflictException(
            'Could not create classroom due to join key collision.',
            'JOIN_KEY_GENERATION_FAILED',
            500
        );
    }

    /**
     * Competency-picker context for a classroom.
     *
     * Derives the (subject_id, grade_level, semester) triple the frontend
     * needs to call GET /api/admin/competency-tags?subject_id&grade_level&semester.
     * Ownership is classroom ownership (teacher_id); non-owners get 403.
     *
     * @return array{subject_id: int, section_id: int, grade_level_id: int, grade_level: string|null, semester_id: int|null, semester: string|null}
     */
    public function getCompetencyContext(int $teacherId, int $classroomId): array
    {
        $classroom = $this->getTeacherClassroom($teacherId, $classroomId);
        $classroom->loadMissing(['subject.gradeLevel.semester', 'section.gradeLevel.semester']);

        $gradeLevel = $classroom->section?->gradeLevel ?? $classroom->subject?->gradeLevel;
        $semester = $gradeLevel?->semester;

        return [
            'classroom_id' => $classroom->id,
            'subject_id' => (int) $classroom->subject_id,
            'section_id' => (int) $classroom->section_id,
            'grade_level_id' => $gradeLevel ? (int) $gradeLevel->id : 0,
            'grade_level' => $gradeLevel ? (string) $gradeLevel->grade_level : null,
            'semester_id' => $gradeLevel?->semester_id !== null ? (int) $gradeLevel->semester_id : null,
            'semester' => $semester ? (string) $semester->semester : null,
        ];
    }

    /**
     * List classrooms owned by teacher.
     *
     * Optional Semester partitioning via ?semester_id (canonical Semester
     * vocabulary; ?term_id accepted as a transition alias). Filters to
     * classrooms whose grade level belongs to the given Semester.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Classroom>
     */
    public function listTeacherClassrooms(int $teacherId, ?string $schoolYear = null, ?int $semesterId = null)
    {
        $query = Classroom::query()
            ->where('teacher_id', $teacherId)
            ->orderByDesc('created_at');

        if ($schoolYear !== null && trim($schoolYear) !== '') {
            $query->where('school_year', trim($schoolYear));
        }

        if ($semesterId !== null) {
            $query->whereHas('section.gradeLevel', function ($q) use ($semesterId): void {
                $q->where('semester_id', $semesterId);
            });
        }

        return $query->get();
    }

    /**
     * Get classroom detail for teacher (ownership checked).
     */
    public function getTeacherClassroom(int $teacherId, int $classroomId): Classroom
    {
        $classroom = Classroom::findOrFail($classroomId);

        if ((int) $classroom->teacher_id !== $teacherId) {
            // Allow admin? Caller should check admin separately; here strictly owner.
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        return $classroom;
    }

    /**
     * Get classroom detail allowing admin bypass.
     */
    public function getClassroomForActor(int $actorId, int $classroomId, string $actorRole): Classroom
    {
        $classroom = Classroom::findOrFail($classroomId);

        if (strtolower($actorRole) === 'admin') {
            return $classroom;
        }

        if ((int) $classroom->teacher_id !== $actorId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        return $classroom;
    }

    /**
     * Reset join key: rotates, stores old in history.
     */
    public function resetKey(int $teacherId, int $classroomId): Classroom
    {
        $classroom = $this->getTeacherClassroom($teacherId, $classroomId);

        $oldKey = $classroom->join_key;
        $newKey = $this->generateUniqueJoinKey();

        // Ensure new differs (extremely unlikely to be same, but loop)
        $attempts = 0;
        while ($newKey === $oldKey && $attempts < self::JOIN_KEY_MAX_ATTEMPTS) {
            $newKey = $this->generateUniqueJoinKey();
            $attempts++;
        }

        DB::transaction(function () use ($classroom, $oldKey, $newKey): void {
            ClassroomJoinKeyHistory::create([
                'classroom_id' => $classroom->id,
                'old_join_key' => $oldKey,
                'revoked_at' => now(),
            ]);

            $classroom->join_key = $newKey;
            $classroom->save();
        });

        $this->auditLogService->log(
            'update',
            'Classroom join key reset: ' . $classroom->name,
            Auth::id() ?? $teacherId,
            Classroom::class,
            $classroom->id,
            ['old_join_key' => $oldKey, 'new_join_key' => $newKey]
        );

        return $classroom->fresh();
    }

    /**
     * Toggle is_join_enabled.
     */
    public function toggleJoin(int $teacherId, int $classroomId, bool $enabled): Classroom
    {
        $classroom = $this->getTeacherClassroom($teacherId, $classroomId);

        $classroom->is_join_enabled = $enabled;
        $classroom->save();

        $this->auditLogService->log(
            'update',
            'Classroom join toggle: ' . $classroom->name . ' enabled=' . ($enabled ? 'true' : 'false'),
            Auth::id() ?? $teacherId,
            Classroom::class,
            $classroom->id,
            ['is_join_enabled' => $enabled]
        );

        return $classroom->fresh();
    }

    /**
     * Archive (idempotent): sets archived_at = now() if null.
     */
    public function archive(int $teacherId, int $classroomId): Classroom
    {
        $classroom = $this->getTeacherClassroom($teacherId, $classroomId);

        if ($classroom->archived_at === null) {
            $classroom->archived_at = now();
            $classroom->save();

            $this->auditLogService->log(
                'update',
                'Classroom archived: ' . $classroom->name,
                Auth::id() ?? $teacherId,
                Classroom::class,
                $classroom->id,
                []
            );
        }

        return $classroom->fresh();
    }

    /**
     * Unarchive (idempotent): restores archived_at to null.
     */
    public function unarchive(int $teacherId, int $classroomId): Classroom
    {
        $classroom = $this->getTeacherClassroom($teacherId, $classroomId);

        if ($classroom->archived_at !== null) {
            $classroom->archived_at = null;
            $classroom->save();

            $this->auditLogService->log(
                'update',
                'Classroom unarchived: ' . $classroom->name,
                Auth::id() ?? $teacherId,
                Classroom::class,
                $classroom->id,
                []
            );
        }

        return $classroom->fresh();
    }

    /**
     * Admin archive/unarchive bypassing ownership.
     */
    public function adminArchive(int $classroomId): Classroom
    {
        $classroom = Classroom::findOrFail($classroomId);
        if ($classroom->archived_at === null) {
            $classroom->archived_at = now();
            $classroom->save();
            $this->auditLogService->log('update', 'Classroom archived (admin): ' . $classroom->name, Auth::id(), Classroom::class, $classroom->id, []);
        }

        return $classroom->fresh();
    }

    public function adminUnarchive(int $classroomId): Classroom
    {
        $classroom = Classroom::findOrFail($classroomId);
        if ($classroom->archived_at !== null) {
            $classroom->archived_at = null;
            $classroom->save();
            $this->auditLogService->log('update', 'Classroom unarchived (admin): ' . $classroom->name, Auth::id(), Classroom::class, $classroom->id, []);
        }

        return $classroom->fresh();
    }

    public function adminResetKey(int $classroomId): Classroom
    {
        $classroom = Classroom::findOrFail($classroomId);
        $oldKey = $classroom->join_key;
        $newKey = $this->generateUniqueJoinKey();
        $attempts = 0;
        while ($newKey === $oldKey && $attempts < self::JOIN_KEY_MAX_ATTEMPTS) {
            $newKey = $this->generateUniqueJoinKey();
            $attempts++;
        }
        DB::transaction(function () use ($classroom, $oldKey, $newKey): void {
            ClassroomJoinKeyHistory::create([
                'classroom_id' => $classroom->id,
                'old_join_key' => $oldKey,
                'revoked_at' => now(),
            ]);
            $classroom->join_key = $newKey;
            $classroom->save();
        });
        $this->auditLogService->log('update', 'Classroom join key reset (admin): ' . $classroom->name, Auth::id(), Classroom::class, $classroom->id, ['old_join_key' => $oldKey, 'new_join_key' => $newKey]);

        return $classroom->fresh();
    }

    /**
     * Get people (enrolled students) for a classroom owned by teacher.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ClassroomEnrollment>
     */
    public function getPeople(int $teacherId, int $classroomId)
    {
        $classroom = $this->getTeacherClassroom($teacherId, $classroomId);

        return ClassroomEnrollment::query()
            ->where('classroom_id', $classroom->id)
            ->with('student:id,name,school_id')
            ->orderBy('joined_at')
            ->get();
    }

    /**
     * Join a classroom via key.
     *
     * Normalizes key, checks history for 410 vs 404, checks archived/disabled, idempotent.
     * Ordering: archived/disabled/revoked 410 checks remain BEFORE already_joined so that
     * a revoked/archived/disabled classroom returns 410 even for already-enrolled members,
     * per product spec (revoked keys are GONE even if the student previously joined).
     * If the product instead requires idempotent 200 for already-enrolled members
     * regardless of archived/disabled/revoked state, move the already_joined check
     * before those 410 throws.
     *
     * @return array{classroom: Classroom, already_joined: bool, status: int}
     */
    public function joinClassroom(int $studentId, string $rawKey): array
    {
        $normalized = self::normalizeKey($rawKey);

        if ($normalized === '' || strlen($normalized) !== self::JOIN_KEY_LENGTH) {
            // Still check history for exact normalized? If not found, 404.
            $inHistory = ClassroomJoinKeyHistory::where('old_join_key', $normalized)->exists();
            if ($inHistory) {
                throw new BusinessRuleConflictException('This join key has been revoked.', 'GONE', 410);
            }
            throw new BusinessRuleConflictException('Invalid join key.', 'KEY_INVALID', 404);
        }

        $classroom = Classroom::where('join_key', $normalized)->first();

        if ($classroom) {
            if ($classroom->archived_at !== null) {
                throw new BusinessRuleConflictException('This classroom has been archived.', 'CLASSROOM_ARCHIVED', 410);
            }

            if (! $classroom->is_join_enabled) {
                throw new BusinessRuleConflictException('Joining is disabled for this classroom.', 'CLASSROOM_JOIN_DISABLED', 410);
            }

            $already = ClassroomEnrollment::query()
                ->where('classroom_id', $classroom->id)
                ->where('student_id', $studentId)
                ->exists();

            if ($already) {
                return ['classroom' => $classroom, 'already_joined' => true, 'status' => 200];
            }

            try {
                ClassroomEnrollment::create([
                    'classroom_id' => $classroom->id,
                    'student_id' => $studentId,
                    'joined_at' => now(),
                ]);
            } catch (QueryException $e) {
                $msg = $e->getMessage();
                $isEnrollmentDuplicate = str_contains($msg, 'classroom_enrollments_classroom_student_unique');
                // Also handle sqlite UNIQUE constraint failed fallback
                $isSqliteDuplicate = str_contains($msg, 'UNIQUE constraint failed') && str_contains($msg, 'classroom_enrollments');
                $sqlState = $e->errorInfo[0] ?? null;
                $is23505 = ($sqlState === '23505') || $e->getCode() === '23505' || str_contains($msg, '23505');
                // In this narrow scope (only enrollment INSERT) a 23505 is safely treated as already_joined
                if ($isEnrollmentDuplicate || $isSqliteDuplicate || $is23505) {
                    return ['classroom' => $classroom, 'already_joined' => true, 'status' => 200];
                }
                throw $e;
            }

            $this->auditLogService->log(
                'create',
                'Student joined classroom: ' . $classroom->name,
                Auth::id() ?? $studentId,
                Classroom::class,
                $classroom->id,
                ['student_id' => $studentId]
            );

            return ['classroom' => $classroom, 'already_joined' => false, 'status' => 201];
        }

        // Not found in active keys — check history for 410 vs 404
        $history = ClassroomJoinKeyHistory::where('old_join_key', $normalized)->first();
        if ($history) {
            throw new BusinessRuleConflictException('This join key has been revoked.', 'GONE', 410);
        }

        throw new BusinessRuleConflictException('Invalid join key.', 'KEY_INVALID', 404);
    }

    /**
     * Leave a classroom.
     *
     * First leave removes the enrollment and returns already_left=false.
     * Repeat leave converges with already_left=true and performs no
     * additional write. Callers with no current or prior enrollment are
     * refused 403 NOT_ENROLLED.
     *
     * @return bool already_left
     */
    public function leaveClassroom(int $studentId, int $classroomId): bool
    {
        $classroom = Classroom::findOrFail($classroomId);

        $enrolled = ClassroomEnrollment::query()
            ->where('classroom_id', $classroom->id)
            ->where('student_id', $studentId)
            ->exists();

        if ($enrolled) {
            DB::transaction(function () use ($classroom, $studentId): void {
                ClassroomEnrollment::query()
                    ->where('classroom_id', $classroom->id)
                    ->where('student_id', $studentId)
                    ->delete();

                ClassroomLeaveHistory::firstOrCreate(
                    ['classroom_id' => $classroom->id, 'student_id' => $studentId],
                    ['left_at' => now()]
                );
            });

            $this->auditLogService->log(
                'delete',
                'Student left classroom: ' . $classroom->name,
                Auth::id() ?? $studentId,
                Classroom::class,
                $classroom->id,
                ['student_id' => $studentId]
            );

            return false;
        }

        $hadPrior = ClassroomLeaveHistory::query()
            ->where('classroom_id', $classroom->id)
            ->where('student_id', $studentId)
            ->exists();

        if ($hadPrior) {
            return true;
        }

        throw new BusinessRuleConflictException(
            'You are not enrolled in this classroom.',
            'NOT_ENROLLED',
            403
        );
    }

    /**
     * Remove a student from classroom by teacher (or admin).
     */
    public function removeStudent(int $actorId, int $classroomId, int $studentId, bool $isAdmin = false): void
    {
        $classroom = Classroom::findOrFail($classroomId);

        if (! $isAdmin && (int) $classroom->teacher_id !== $actorId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        $deleted = ClassroomEnrollment::query()
            ->where('classroom_id', $classroom->id)
            ->where('student_id', $studentId)
            ->delete();

        // Idempotent? Spec says Remove by owner/admin — not necessarily idempotent, but we treat as idempotent 200.
        // Audit only if deleted.

        if ($deleted) {
            // Record prior enrollment so a later leave by the removed
            // student converges with already_left=true (ARCH-005 block 4.20).
            ClassroomLeaveHistory::firstOrCreate(
                ['classroom_id' => $classroom->id, 'student_id' => $studentId],
                ['left_at' => now()]
            );

            $this->auditLogService->log(
                'delete',
                'Student removed from classroom: ' . $classroom->name,
                Auth::id() ?? $actorId,
                Classroom::class,
                $classroom->id,
                ['student_id' => $studentId, 'removed_by' => $actorId]
            );
        }
    }

    /**
     * Hand-place a single learner (manager-only path, ARCH-005 block 4.17).
     *
     * Phase B: full_name + classroom only. Always creates a new Student
     * account with a server-generated STU- CompAss ID, then enrolls it and
     * appends an immutable history row plus an audit row. No manual IDs, no
     * matching by name (names are not unique).
     *
     * @return array{id: int, classroom_id: int, school_id: string, display_name: string, placement_kind: string, placed_at: string|null, moved_at: null}
     */
    public function placeLearner(
        int $actorId,
        string $fullName,
        int $classroomId
    ): array {
        $fullName = trim($fullName);

        if ($fullName === '') {
            throw ValidationException::withMessages([
                'full_name' => ['Enter the learner full name.'],
            ]);
        }

        if (mb_strlen($fullName) > 255) {
            throw ValidationException::withMessages([
                'full_name' => ['The full name must not exceed 255 characters.'],
            ]);
        }

        return DB::transaction(function () use ($actorId, $fullName, $classroomId): array {
            $classroom = Classroom::findOrFail($classroomId);

            if ($classroom->archived_at !== null) {
                throw new BusinessRuleConflictException(
                    'The destination classroom has been archived. Choose an active classroom instead. If you need help, contact your school administrator.',
                    'CLASSROOM_ARCHIVED',
                    410
                );
            }

            // Server-generated CompAss ID with collision retry (the unique
            // check in generateUniqueCompassId races under concurrency).
            $user = null;
            $schoolId = '';
            $attempts = 0;
            while ($user === null && $attempts < 3) {
                $attempts++;
                $candidate = User::generateUniqueCompassId('Student');
                try {
                    $candidate_user = new User();
                    $candidate_user->name = $fullName;
                    $candidate_user->school_id = $candidate;
                    $candidate_user->password_hash = Hash::make(Str::password(16));
                    $candidate_user->role = 'Student';
                    $candidate_user->must_change_password = true;
                    $candidate_user->is_active = true;
                    $candidate_user->save();
                    $user = $candidate_user;
                    $schoolId = $candidate;
                } catch (QueryException $e) {
                    if ($this->isSchoolIdConflict($e) && $attempts < 3) {
                        continue;
                    }
                    throw $e;
                }
            }

            if ($user === null) {
                throw new BusinessRuleConflictException(
                    'Could not save this learner. Please try again or contact your administrator.',
                    'PLACE_FAILED',
                    500
                );
            }

            try {
                $enrollment = ClassroomEnrollment::create([
                    'classroom_id' => $classroom->id,
                    'student_id' => $user->id,
                    'joined_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ($this->isEnrollmentConflict($e)) {
                    throw new BusinessRuleConflictException(
                        'This learner is already placed in a classroom. Move the learner instead of placing again. If you need help, contact your school administrator.',
                        'ALREADY_PLACED',
                        409
                    );
                }
                throw $e;
            }

            ClassroomEnrollmentMove::create([
                'classroom_id' => $classroom->id,
                'source_classroom_id' => null,
                'student_id' => $user->id,
                'actor_id' => $actorId,
                'action' => ClassroomEnrollmentMove::ACTION_PLACED,
                'created_at' => now(),
            ]);

            $this->auditLogService->log(
                'create',
                'Learner placed by hand: ' . $user->name . ' (' . $schoolId . ') into classroom ' . $classroom->name,
                $actorId,
                ClassroomEnrollment::class,
                $enrollment->id,
                [
                    'school_id' => $schoolId,
                    'source_classroom_id' => null,
                    'destination_classroom_id' => $classroom->id,
                ]
            );

            return $this->formatEnrollmentForResponse($enrollment->fresh(), $schoolId, $user->name, null);
        });
    }

    /**
     * Hand-move a placed learner to another classroom (ARCH-005 block 4.17).
     *
     * Moves the enrollment in place, appends an immutable history row plus an
     * audit row. A move to the current classroom is refused with
     * ALREADY_PLACED; a move to an archived classroom with
     * CLASSROOM_ARCHIVED.
     *
     * @return array{id: int, classroom_id: int, school_id: string, display_name: string, placement_kind: string, placed_at: string|null, moved_at: string|null}
     */
    public function moveEnrollment(int $actorId, int $enrollmentId, int $destinationClassroomId): array
    {
        return DB::transaction(function () use ($actorId, $enrollmentId, $destinationClassroomId): array {
            // F2: serialize concurrent double-moves on the same enrollment.
            // Row-level exclusion on the existing enrollment row spans the
            // state check, enrollment write, history append, and audit write,
            // so the loser observes the winner's committed state.
            $enrollment = ClassroomEnrollment::query()
                ->where('id', $enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $enrollment->load(['student', 'classroom']);

            if ((int) $destinationClassroomId === (int) $enrollment->classroom_id) {
                throw new BusinessRuleConflictException(
                    'The learner is already in that classroom. Choose a different destination to move. If you need help, contact your school administrator.',
                    'ALREADY_PLACED',
                    409
                );
            }

            $destination = Classroom::findOrFail($destinationClassroomId);

            if ($destination->archived_at !== null) {
                throw new BusinessRuleConflictException(
                    'The destination classroom has been archived. Choose an active classroom instead. If you need help, contact your school administrator.',
                    'CLASSROOM_ARCHIVED',
                    410
                );
            }

            $sourceId = (int) $enrollment->classroom_id;
            $sourceName = $enrollment->classroom?->name ?? 'classroom ' . $sourceId;

            $enrollment->classroom_id = $destination->id;
            $enrollment->save();

            $history = ClassroomEnrollmentMove::create([
                'classroom_id' => $destination->id,
                'source_classroom_id' => $sourceId,
                'student_id' => $enrollment->student_id,
                'actor_id' => $actorId,
                'action' => ClassroomEnrollmentMove::ACTION_MOVED,
                'created_at' => now(),
            ]);

            $fresh = $enrollment->fresh(['student']);

            $this->auditLogService->log(
                'update',
                'Learner moved by hand: ' . $fresh->student->name . ' (' . $fresh->student->school_id . ') from ' . $sourceName . ' to ' . $destination->name,
                $actorId,
                ClassroomEnrollment::class,
                $fresh->id,
                [
                    'school_id' => $fresh->student->school_id,
                    'source_classroom_id' => $sourceId,
                    'destination_classroom_id' => $destination->id,
                ]
            );

            return $this->formatEnrollmentForResponse(
                $fresh,
                (string) $fresh->student->school_id,
                (string) $fresh->student->name,
                $history->created_at?->toIso8601String()
            );
        });
    }

    /**
     * Shape a hand-placed enrollment for API responses (ARCH-005 block 4.17).
     *
     * @return array{id: int, classroom_id: int, school_id: string, display_name: string, placement_kind: string, placed_at: string|null, moved_at: string|null}
     */
    public function formatEnrollmentForResponse(
        ClassroomEnrollment $enrollment,
        string $schoolId,
        string $displayName,
        ?string $movedAt
    ): array {
        return [
            'id' => $enrollment->id,
            'classroom_id' => $enrollment->classroom_id,
            'school_id' => $schoolId,
            'display_name' => $displayName,
            'placement_kind' => 'manual',
            'placed_at' => $enrollment->joined_at?->toIso8601String(),
            'moved_at' => $movedAt,
        ];
    }

    /**
     * Whether a query failure is a generated-ID race (concurrent CompAss ID
     * generation colliding on users.school_id).
     */
    private function isSchoolIdConflict(QueryException $e): bool
    {
        $message = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? null;

        return str_contains($message, 'users_school_id_unique')
            || (($sqlState === '23505' || str_contains($message, '23505')) && str_contains($message, 'school_id'))
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'users.school_id'));
    }

    /**
     * Whether a query failure is an enrollment race (concurrent hand-place
     * colliding on the enrollment unique key).
     */
    private function isEnrollmentConflict(QueryException $e): bool
    {
        $message = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? null;

        return str_contains($message, 'classroom_enrollments_classroom_student_unique')
            || (($sqlState === '23505' || str_contains($message, '23505')) && str_contains($message, 'classroom_enrollments'))
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'classroom_enrollments'));
    }

    /**
     * List classrooms for a student (joined, excluding archived by default).
     *
     * Learner rooms listing (ARCH-005 block 4.19): enrolled, unarchived
     * rooms ordered by room name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Classroom>
     */
    public function listStudentClassrooms(int $studentId, bool $includeArchived = false)
    {
        $query = Classroom::query()
            ->whereIn('id', function ($q) use ($studentId): void {
                $q->select('classroom_id')
                    ->from('classroom_enrollments')
                    ->where('student_id', $studentId);
            })
            ->with(['teacher:id,name', 'subject', 'section'])
            ->orderBy('name')
            ->orderBy('id');

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        return $query->get();
    }

    /**
     * Get student classroom detail (must be enrolled else 403).
     */
    public function getStudentClassroom(int $studentId, int $classroomId): Classroom
    {
        $classroom = Classroom::findOrFail($classroomId);

        $enrolled = ClassroomEnrollment::query()
            ->where('classroom_id', $classroomId)
            ->where('student_id', $studentId)
            ->exists();

        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        return $classroom;
    }

    /**
     * Learner people read (ARCH-005 block 4.18 / ADR-012).
     *
     * Enrolled students only, alphabetical by display name, paged.
     * Unknown classroom 404s; archived reads are refused 410
     * CLASSROOM_ARCHIVED; unenrolled callers are refused 403 NOT_ENROLLED.
     *
     * @return LengthAwarePaginator<int, ClassroomEnrollment>
     */
    public function listStudentPeople(int $studentId, int $classroomId, int $page, int $perPage): LengthAwarePaginator
    {
        $classroom = Classroom::findOrFail($classroomId);

        if ($classroom->archived_at !== null) {
            throw new BusinessRuleConflictException(
                'This classroom has been archived.',
                'CLASSROOM_ARCHIVED',
                410
            );
        }

        $enrolled = ClassroomEnrollment::query()
            ->where('classroom_id', $classroom->id)
            ->where('student_id', $studentId)
            ->exists();

        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        return ClassroomEnrollment::query()
            ->where('classroom_enrollments.classroom_id', $classroom->id)
            ->join('users', 'users.id', '=', 'classroom_enrollments.student_id')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->select('classroom_enrollments.*')
            ->with(['student' => fn ($q) => $q->select('id', 'name', 'photo_opt_in', 'photo_url')])
            ->paginate($perPage, ['classroom_enrollments.*'], 'page', $page);
    }

    /**
     * Format one enrollment for the learner people read: display name
     * only, with the photo URL present solely on a recorded opt-in.
     * No email, no learner code, no internal numbers.
     *
     * @return array<string, string>
     */
    public function formatStudentPerson(ClassroomEnrollment $enrollment): array
    {
        $student = $enrollment->student;

        $row = ['display_name' => (string) ($student?->name ?? '')];

        $photoUrl = $student?->photo_url;

        if ($student && (bool) $student->photo_opt_in && is_string($photoUrl) && trim($photoUrl) !== '') {
            $row['photo_url'] = $photoUrl;
        }

        return $row;
    }

    /**
     * Format one classroom for the learner rooms index (ARCH-005 block
     * 4.19): route key plus human names only, no internal numbers.
     *
     * Emits both `section_name` and its `group_name` alias (same value) so
     * learner list and detail labeling never drift; callers should prefer
     * `section_name`.
     *
     * @return array<string, mixed>
     */
    public function formatStudentRoom(Classroom $classroom): array
    {
        $classroom->loadMissing(['subject', 'section']);

        return [
            'id' => $classroom->id,
            'name' => $classroom->name,
            'subject_name' => $classroom->subject?->name,
            'section_name' => $classroom->section?->name,
            'group_name' => $classroom->section?->name,
            'school_year' => $classroom->school_year,
            'archived_at' => $classroom->archived_at?->toIso8601String(),
        ];
    }

    /**
     * Format one classroom for the learner room detail (ARCH-005 block
     * 4.19): room header with subject, group, and year. Archived rooms
     * report the archived state instead of refusing.
     *
     * Emits both `section_name` (canonical) and its `group_name` alias (same
     * value); callers should prefer `section_name`.
     *
     * @return array<string, mixed>
     */
    public function formatStudentRoomDetail(Classroom $classroom): array
    {
        $classroom->loadMissing(['subject', 'section']);

        return [
            'id' => $classroom->id,
            'name' => $classroom->name,
            'subject_name' => $classroom->subject?->name,
            'section_name' => $classroom->section?->name,
            'group_name' => $classroom->section?->name,
            'school_year' => $classroom->school_year,
            'archived_at' => $classroom->archived_at?->toIso8601String(),
        ];
    }

    public function formatClassroomForResponse(Classroom $classroom): array
    {
        $classroom->loadMissing(['subject.gradeLevel.semester', 'section.gradeLevel.semester', 'teacher:id,name,school_id']);
        $gradeLevel = $classroom->section?->gradeLevel ?? $classroom->subject?->gradeLevel;
        $semester = $gradeLevel?->semester;

        return [
            'id' => $classroom->id,
            'teacher_id' => $classroom->teacher_id,
            // Single contract: teacher identity (name + CompAss school_id)
            // ships on every classroom shape — list and detail alike — so
            // admin list, admin detail, and teacher reads never drift.
            'teacher_name' => $classroom->teacher?->name,
            'teacher_school_id' => $classroom->teacher?->school_id,
            'subject_id' => $classroom->subject_id,
            'section_id' => $classroom->section_id,
            'subject_name' => $classroom->subject?->name,
            'section_name' => $classroom->section?->name,
            'grade_level_id' => $gradeLevel ? (int) $gradeLevel->id : null,
            'grade_level' => $gradeLevel ? (string) $gradeLevel->grade_level : null,
            'semester_id' => $gradeLevel?->semester_id !== null ? (int) $gradeLevel->semester_id : null,
            // term_id is a read-only alias of semester_id for transition.
            'term_id' => $gradeLevel?->semester_id !== null ? (int) $gradeLevel->semester_id : null,
            'semester' => $semester ? (string) $semester->semester : null,
            'school_year' => $classroom->school_year,
            'name' => $classroom->name,
            'suffix' => $classroom->suffix,
            'join_key' => $classroom->join_key,
            'join_key_display' => self::formatDisplayKey($classroom->join_key),
            'is_join_enabled' => (bool) $classroom->is_join_enabled,
            'archived_at' => $classroom->archived_at?->toIso8601String(),
            'created_at' => $classroom->created_at?->toIso8601String(),
            'updated_at' => $classroom->updated_at?->toIso8601String(),
        ];
    }
}

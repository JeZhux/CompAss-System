<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemAttachment;
use App\Models\AssessmentResponse;
use App\Models\AssessmentSubmission;
use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentFeedback;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\ClassroomJoinKeyHistory;
use App\Models\CompetencyReference;
use App\Models\GradeEntry;
use App\Models\GradeLevel;
use App\Models\LearningMaterial;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Realistic dev-only seed: one Grade 7 Science class (7-Rizal) with a full
 * Semester journey — users, classroom, competencies, announcements, assignments
 * with submissions + feedback, a recorded quiz + an unrecorded practice
 * drill with attempts, responses, grades, and mastery records.
 *
 * Replaces the old mechanical demo fixture (Demo Teacher 01 / STU-DEMO-*
 * / "which fraction equals one half (dev/test only)") with real-world-like
 * data: Filipino names, MATATAG-style Grade 7 Science competencies, and
 * believable student work from a strong, an average, and a struggling learner.
 *
 * Runs ONLY in dev/testing (DatabaseSeeder gates on non-production,
 * ARCH-006 §5.1). Shares the evaluation school year + Semester + grade spine
 * (SY 2026-2027 → Semester '1' ('1st Quarter') → Grade 7) but owns its section (7-Rizal),
 * subject (SCI7 Science), competencies (SCI7-01…03), users
 * (ADM-7100-00001 + TEA-7100-00001 + STU-7100-00001…03 CompAss IDs). Never touches
 * BootstrapAdmin/Evaluation keys (eval SY 2026-2027 with the TEA-8100 and
 * STU-8200 ranges; harness SY 2026 + MATH7/STU-3000-00001).
 *
 * Classroom-creation path: direct Classroom::create + history-aware
 * generateUniqueJoinKey (ClassroomSeeder pattern), NOT
 * ClassroomService::createClassroom — the service writes audit_logs rows
 * as a side effect and this seeder owns zero audit rows.
 *
 * Metadata-only files: every attachment/file/material row uses a natural
 * filename, a pdf|docx MIME, and a small sentinel file_size (well under the
 * 15 MB ARCH-002 QA-009 cap). Zero bytes reach disk.
 *
 * Determinism: fixed mt_srand seed + fixed iteration order. Idempotency:
 * firstOrCreate on natural unique keys; SELECT-then-INSERT for items
 * (assessment_id + sort_order) and for attachments/files/materials
 * (parent + filename). Re-running inserts nothing.
 *
 * Credentials: literal 'password123' plaintext via the User model's
 * 'hashed' cast on password_hash — never pre-hash here (pre-hashing
 * double-hashes and breaks login). All accounts carry
 * must_change_password=false + is_active=true. Prints counts only, never
 * secrets. CompAss-ID: every role identifies by school_id
 * (<ROLE>-<4 digits>-<5 digits>) with a role-matching prefix.
 *
 * Note: like the other seeders, User rows rely on the db:seed command's
 * unguarded context for mass assignment (the User model exposes only
 * name/school_id as fillable).
 */
class DemoSeeder extends Seeder
{
    private const RNG_SEED = 20260912;

    private const SCHOOL_YEAR_NAME = 'SY 2026-2027';

    private const CLASSROOM_SCHOOL_YEAR = '2026-2027';

    private const SEMESTER_NAME = '1st Quarter';

    private const SEMESTER_START = '2026-09-01';

    private const SEMESTER_END = '2026-12-31';

    private const GRADE = '7';

    private const SECTION_NAME = '7-Rizal';

    private const SUBJECT_CODE = 'SCI7';

    private const SUBJECT_NAME = 'Science';

    private const ADMIN_SCHOOL_ID = 'ADM-7100-00001';

    private const ADMIN_NAME = 'Maria Santos';

    private const TEACHER_SCHOOL_ID = 'TEA-7100-00001';

    private const TEACHER_NAME = 'Jose Ramos';

    private const PLAIN_PASSWORD = 'password123';

    private const COMPETENCIES = [
        'SCI7-01' => 'Identify the parts of the compound microscope and state their functions',
        'SCI7-02' => 'Describe the levels of biological organization from cell to organism',
        'SCI7-03' => 'Distinguish solute from solvent and describe factors affecting solubility',
    ];

    /** CompAss ID => [name, photo_opt_in]. Fixed order. */
    private const STUDENTS = [
        'STU-7100-00001' => ['Ana Reyes', false],
        'STU-7100-00002' => ['Mark Dela Cruz', false],
        'STU-7100-00003' => ['Liza Mendoza', true],
    ];

    public function run(): void
    {
        mt_srand(self::RNG_SEED);

        // ------------------------------------------------------------------
        // Org spine (FK order): school year → Semester → grade → section →
        // subject (scoped to the Grade Level). The year/Semester/grade rows are
        // shared with the evaluation spine (same names + dates); the section,
        // subject, and classroom below are this seeder's own.
        // ------------------------------------------------------------------
        $schoolYear = SchoolYear::firstOrCreate(['name' => self::SCHOOL_YEAR_NAME]);

        $semester = Semester::firstOrCreate(
            ['school_year_id' => $schoolYear->id, 'semester' => '1'],
            ['name' => self::SEMESTER_NAME, 'start_date' => self::SEMESTER_START, 'end_date' => self::SEMESTER_END]
        );

        $gradeLevel = GradeLevel::firstOrCreate(
            ['semester_id' => $semester->id, 'grade_level' => self::GRADE]
        );

        $section = Section::firstOrCreate(
            ['grade_level_id' => $gradeLevel->id, 'name' => self::SECTION_NAME]
        );

        $subject = Subject::firstOrCreate(
            ['grade_level_id' => $gradeLevel->id, 'code' => self::SUBJECT_CODE],
            ['name' => self::SUBJECT_NAME, 'description' => 'Grade 7 Science']
        );

        // ------------------------------------------------------------------
        // Users: 1 admin + 1 teacher + 3 students, all CompAss-ID identified
        // (school_id-only). Liza opted into class photos.
        // ------------------------------------------------------------------
        $admin = User::firstOrCreate(
            ['school_id' => self::ADMIN_SCHOOL_ID],
            [
                'name' => self::ADMIN_NAME,
                'password_hash' => self::PLAIN_PASSWORD,
                'role' => 'Admin',
                'must_change_password' => false,
                'is_active' => true,
            ]
        );

        $teacher = User::firstOrCreate(
            ['school_id' => self::TEACHER_SCHOOL_ID],
            [
                'name' => self::TEACHER_NAME,
                'password_hash' => self::PLAIN_PASSWORD,
                'role' => 'Teacher',
                'must_change_password' => false,
                'is_active' => true,
            ]
        );

        $studentIds = [];
        foreach (self::STUDENTS as $code => [$name, $photoOptIn]) {
            $studentIds[$code] = User::firstOrCreate(
                ['school_id' => $code],
                [
                    'name' => $name,
                    'password_hash' => self::PLAIN_PASSWORD,
                    'role' => 'Student',
                    'must_change_password' => false,
                    'is_active' => true,
                    'photo_opt_in' => $photoOptIn,
                ]
            )->id;
        }

        // ------------------------------------------------------------------
        // Classroom (find-first on the natural (teacher, subject, section,
        // year) key, then create with the ClassroomSeeder-pattern helper —
        // no service audit side effects). Teacher scope derives from the
        // classroom itself since the Semester restructure.
        // ------------------------------------------------------------------
        $classroom = Classroom::where('teacher_id', $teacher->id)
            ->where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('school_year', self::CLASSROOM_SCHOOL_YEAR)
            ->first();
        if (! $classroom) {
            $classroom = Classroom::create([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'section_id' => $section->id,
                'school_year' => self::CLASSROOM_SCHOOL_YEAR,
                'name' => '7-Rizal Science',
                'suffix' => null,
                'join_key' => $this->generateUniqueJoinKey(),
                'is_join_enabled' => true,
            ]);
        }

        // ------------------------------------------------------------------
        // Enrollments: all three learners joined in the first week of class.
        // ------------------------------------------------------------------
        $joinedOn = ['STU-7100-00001' => '2026-09-03', 'STU-7100-00002' => '2026-09-04', 'STU-7100-00003' => '2026-09-05'];
        foreach ($studentIds as $code => $studentId) {
            ClassroomEnrollment::firstOrCreate(
                ['classroom_id' => $classroom->id, 'student_id' => $studentId],
                ['joined_at' => $this->manila($joinedOn[$code], '08:00:00')]
            );
        }

        // ------------------------------------------------------------------
        // Competencies: 3 real Grade 7 Science competencies, grade-7 scoped.
        // ------------------------------------------------------------------
        $competencyIds = [];
        foreach (self::COMPETENCIES as $code => $descriptor) {
            $competencyIds[$code] = CompetencyReference::firstOrCreate(
                ['code' => $code],
                [
                    'descriptor' => $descriptor,
                    'subject_id' => $subject->id,
                    'grade_level' => self::GRADE,
                    'semester' => '1',
                ]
            )->id;
        }

        // ------------------------------------------------------------------
        // Announcement + metadata attachment.
        // ------------------------------------------------------------------
        $announcement = Announcement::firstOrCreate(
            ['classroom_id' => $classroom->id, 'title' => 'Welcome to 7-Rizal Science'],
            [
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'body' => 'Magandang araw, 7-Rizal! This is our official classroom for Grade 7 Science. '
                    .'Our first quiz (Quiz 1: The Microscope and the Cell) opens on October 1 — '
                    .'please read the microscope reference material before then. — Sir Ramos',
            ]
        );
        if (! AnnouncementAttachment::where('announcement_id', $announcement->id)->where('filename', 'welcome-to-7rizal-science.pdf')->exists()) {
            AnnouncementAttachment::create([
                'announcement_id' => $announcement->id,
                'filename' => 'welcome-to-7rizal-science.pdf',
                'original_filename' => 'Welcome to 7-Rizal Science.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 2048,
            ]);
        }

        // ------------------------------------------------------------------
        // Assignment + metadata attachment + 3 submissions + teacher
        // feedback on Ana's (the first submission).
        // ------------------------------------------------------------------
        $assignment = Assignment::firstOrCreate(
            ['classroom_id' => $classroom->id, 'title' => 'Lab Report 1: Parts of the Microscope'],
            [
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'semester_id' => $semester->id,
                'description' => 'Draw and label the parts of the compound microscope from the laboratory '
                    .'demonstration, then write one sentence describing the function of each part.',
                'due_date' => $this->manila('2026-10-15', '23:59:00'),
            ]
        );
        if (! AssignmentAttachment::where('assignment_id', $assignment->id)->where('filename', 'lab-report-worksheet.docx')->exists()) {
            AssignmentAttachment::create([
                'assignment_id' => $assignment->id,
                'filename' => 'lab-report-worksheet.docx',
                'original_filename' => 'Lab Report Worksheet.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'file_size' => 4096,
            ]);
        }

        // [CompAss ID, submitted on, status, file size]. Liza turns hers
        // in a day late after the laboratory cleanup ran long.
        $submissions = [
            ['STU-7100-00001', '2026-10-10', 'on_time', 1024],
            ['STU-7100-00002', '2026-10-14', 'on_time', 1536],
            ['STU-7100-00003', '2026-10-16', 'late', 2048],
        ];
        $submissionFiles = ['STU-7100-00001' => 'submission-ana-reyes.pdf', 'STU-7100-00002' => 'submission-mark-dela-cruz.pdf', 'STU-7100-00003' => 'submission-liza-mendoza.pdf'];
        $submissionNames = ['STU-7100-00001' => 'Ana Reyes', 'STU-7100-00002' => 'Mark Dela Cruz', 'STU-7100-00003' => 'Liza Mendoza'];
        $firstSubmissionId = null;
        foreach ($submissions as [$code, $submittedOn, $status, $fileSize]) {
            $submission = AssignmentSubmission::firstOrCreate(
                ['assignment_id' => $assignment->id, 'student_id' => $studentIds[$code]],
                [
                    'submitted_at' => $this->manila($submittedOn, '16:00:00'),
                    'status' => $status,
                ]
            );
            if ($firstSubmissionId === null) {
                $firstSubmissionId = $submission->id;
            }
            if (! SubmissionFile::where('submission_id', $submission->id)->where('filename', $submissionFiles[$code])->exists()) {
                SubmissionFile::create([
                    'submission_id' => $submission->id,
                    'filename' => $submissionFiles[$code],
                    'original_filename' => $submissionNames[$code].' - Lab Report 1.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => $fileSize,
                ]);
            }
        }
        AssignmentFeedback::firstOrCreate(
            ['submission_id' => $firstSubmissionId],
            [
                'teacher_id' => $teacher->id,
                'feedback_text' => 'Excellent work, Ana! Your labels for the nosepiece and the objectives are '
                    .'accurate. For full marks next time, add the function of the diaphragm.',
            ]
        );

        // ------------------------------------------------------------------
        // Assessments: 1 Recorded quiz (4 items) + 1 Unrecorded practice
        // drill (3 items). Item spec: [item_type, competency code,
        // max_points, correct_answer, prompt]. Essay items carry a null
        // correct_answer (service invariant).
        // ------------------------------------------------------------------
        $recordedItems = [
            ['multiple_choice', 'SCI7-01', '2.00', 'C', 'Which part of the compound microscope holds the objective lenses and allows them to be rotated into place? A) Stage  B) Diaphragm  C) Revolving nosepiece  D) Fine adjustment knob'],
            ['true_false', 'SCI7-01', '1.00', 'false', 'The fine adjustment knob is used for the initial, coarse focusing of the specimen.'],
            ['essay', 'SCI7-02', '5.00', null, 'Arrange the following levels of biological organization from simplest to most complex — cell, organ, organism, organ system, tissue — and explain in 2–3 sentences why the cell is considered the basic unit of life.'],
            ['multiple_choice', 'SCI7-03', '2.00', 'B', 'Aling Nena dissolves 2 spoonfuls of sugar in hot calamansi juice. In this solution, which substance is the solvent? A) Sugar  B) Calamansi juice  C) The spoon  D) Heat'],
        ];
        $practiceItems = [
            ['true_false', 'SCI7-03', '1.00', 'false', 'In a saltwater solution, salt is the solvent because there is more water than salt.'],
            ['essay', 'SCI7-02', '5.00', null, 'Our class observed onion skin cells under the microscope. Describe two differences you would expect between those plant cells and human cheek cells.'],
            ['multiple_choice', 'SCI7-01', '2.00', 'C', 'To see the fine details of onion skin cells, you switch from the low-power to the high-power objective. Which part should you adjust to bring the image into sharp focus? A) Coarse adjustment knob  B) Mirror  C) Fine adjustment knob  D) Stage clips'],
        ];

        $quiz = Assessment::firstOrCreate(
            ['classroom_id' => $classroom->id, 'title' => 'Quiz 1: The Microscope and the Cell'],
            [
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'semester_id' => $semester->id,
                'description' => 'First recorded quiz: microscope parts, levels of organization, and solutions.',
                'type' => 'Recorded',
                'status' => 'released',
                'time_limit' => 30,
                'availability_starts_at' => $this->manila('2026-10-01', '08:00:00'),
                'availability_ends_at' => $this->manila('2026-10-08', '17:00:00'),
            ]
        );
        $quizItems = $this->seedItems($quiz, $recordedItems, $competencyIds);
        $this->seedItemAttachment($quizItems[0]->id, 'quiz-figure-microscope.pdf', 'Microscope diagram for Quiz 1.pdf');

        $drill = Assessment::firstOrCreate(
            ['classroom_id' => $classroom->id, 'title' => 'Practice Drill 1: Solutions'],
            [
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'semester_id' => $semester->id,
                'description' => 'Unrecorded practice on solutions and cells. Scores do not count toward grades.',
                'type' => 'Unrecorded',
                'status' => 'released',
                'time_limit' => 30,
                'availability_starts_at' => $this->manila('2026-10-10', '08:00:00'),
                'availability_ends_at' => $this->manila('2026-10-20', '17:00:00'),
            ]
        );
        $this->seedItems($drill, $practiceItems, $competencyIds);

        // ------------------------------------------------------------------
        // Learning material, scoped by the classroom's subject
        // (learning_materials has no classroom_id column).
        // ------------------------------------------------------------------
        if (! LearningMaterial::where('subject_id', $subject->id)->where('filename', 'microscope-parts-reference.pdf')->exists()) {
            LearningMaterial::create([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'competency_id' => $competencyIds['SCI7-01'],
                'title' => 'Microscope parts reference',
                'filename' => 'microscope-parts-reference.pdf',
                'original_filename' => 'Microscope Parts Reference.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 8192,
                'extracted_text' => 'The compound microscope has three groups of parts: the magnifying parts '
                    .'(eyepiece and objectives), the mechanical parts (base, arm, stage, revolving nosepiece, '
                    .'coarse and fine adjustment knobs), and the illuminating parts (mirror or lamp, diaphragm, '
                    .'condenser). Total magnification equals eyepiece power times objective power.',
            ]);
        }

        // ------------------------------------------------------------------
        // Taking → grading → mastery. FK order: attempts → submissions →
        // responses → grades → mastery (Recorded-only, classroom scope,
        // UNIQUE 4-tuple). Liza has no practice attempt yet — she has not
        // opened the drill, so her dashboard shows it as not-started.
        // ------------------------------------------------------------------
        $attemptQuizAna = $this->seedAttempt($quiz->id, $studentIds['STU-7100-00001'], $teacher->id, '2026-10-05', '2026-10-06');
        $attemptQuizMark = $this->seedAttempt($quiz->id, $studentIds['STU-7100-00002'], $teacher->id, '2026-10-06', '2026-10-07');
        $attemptQuizLiza = $this->seedAttempt($quiz->id, $studentIds['STU-7100-00003'], $teacher->id, '2026-10-06', '2026-10-07');
        $attemptDrillAna = $this->seedAttempt($drill->id, $studentIds['STU-7100-00001'], $teacher->id, '2026-10-12', '2026-10-13');
        $attemptDrillMark = $this->seedAttempt($drill->id, $studentIds['STU-7100-00002'], $teacher->id, '2026-10-12', '2026-10-13');

        $subQuizAna = $this->seedSubmission($attemptQuizAna, $quiz, $studentIds['STU-7100-00001'], $section->id, $semester->id, '2026-10-05', '2026-10-06');
        $subQuizMark = $this->seedSubmission($attemptQuizMark, $quiz, $studentIds['STU-7100-00002'], $section->id, $semester->id, '2026-10-06', '2026-10-07');
        $subQuizLiza = $this->seedSubmission($attemptQuizLiza, $quiz, $studentIds['STU-7100-00003'], $section->id, $semester->id, '2026-10-06', '2026-10-07');
        $subDrillAna = $this->seedSubmission($attemptDrillAna, $drill, $studentIds['STU-7100-00001'], $section->id, $semester->id, '2026-10-12', '2026-10-13');
        $subDrillMark = $this->seedSubmission($attemptDrillMark, $drill, $studentIds['STU-7100-00002'], $section->id, $semester->id, '2026-10-12', '2026-10-13');

        // Scores keyed by item sort order: [response text, earned points,
        // teacher feedback (essays only)]. Objective rows are auto-scored;
        // essay rows carry the teacher scorer. All grades are non-draft and
        // bounded (score within 0 and the item max).
        $gradingPlan = [
            [
                'submission' => $subQuizAna, 'items' => $quizItems, 'teacher' => $teacher->id,
                'graded_at' => $this->manila('2026-10-06', '10:00:00'),
                'scores' => [
                    1 => ['C', 2.00, null],
                    2 => ['true', 1.00, null],
                    3 => ['Cell, tissue, organ, organ system, organism. The cell is the basic unit of life because all living things are made of cells and all life processes happen inside them.', 4.00, 'Well ordered, Ana. One point off: mention that new cells come from existing cells.'],
                    4 => ['C', 2.00, null],
                ],
            ],
            [
                'submission' => $subQuizMark, 'items' => $quizItems, 'teacher' => $teacher->id,
                'graded_at' => $this->manila('2026-10-07', '10:00:00'),
                'scores' => [
                    1 => ['C', 2.00, null],
                    2 => ['true', 0.00, null],
                    3 => ['Cell, tissue, organ, organism, organ system. Cells are small so they are the basic unit.', 2.50, 'Correct order, Mark, but the explanation needs more detail — why is the cell the basic unit?'],
                    4 => ['B', 2.00, null],
                ],
            ],
            [
                'submission' => $subQuizLiza, 'items' => $quizItems, 'teacher' => $teacher->id,
                'graded_at' => $this->manila('2026-10-07', '11:00:00'),
                'scores' => [
                    1 => ['A', 0.00, null],
                    2 => ['true', 1.00, null],
                    3 => ['Organism, organ, tissue, cell. The cell is small.', 2.00, 'Good try, Liza. The order is reversed — simplest (cell) comes first. See me after class and we will review this together.'],
                    4 => ['A', 0.00, null],
                ],
            ],
            [
                'submission' => $subDrillAna, 'items' => $this->drillItems($drill->id), 'teacher' => $teacher->id,
                'graded_at' => $this->manila('2026-10-13', '10:00:00'),
                'scores' => [
                    1 => ['false', 1.00, null],
                    2 => ['Onion skin cells are rectangular with a visible cell wall, while cheek cells are round with no wall. Both have a nucleus, but only plant cells have a cell wall — and onion skin has very few chloroplasts since it grows underground.', 4.00, 'Sharp observation about the onion growing underground, Ana.'],
                    3 => ['C', 2.00, null],
                ],
            ],
            [
                'submission' => $subDrillMark, 'items' => $this->drillItems($drill->id), 'teacher' => $teacher->id,
                'graded_at' => $this->manila('2026-10-13', '11:00:00'),
                'scores' => [
                    1 => ['true', 0.00, null],
                    2 => ['Plant cells have a wall and animal cells do not. Both have a nucleus.', 2.00, 'Correct basics, Mark — next time name two differences as asked.'],
                    3 => ['C', 2.00, null],
                ],
            ],
        ];
        foreach ($gradingPlan as $plan) {
            $itemsBySort = [];
            foreach ($plan['items'] as $planItem) {
                $itemsBySort[(int) $planItem->sort_order] = $planItem;
            }
            foreach ($plan['scores'] as $sort => [$text, $earned, $feedback]) {
                $planItem = $itemsBySort[$sort];
                $isObjective = $planItem->item_type !== 'essay';
                AssessmentResponse::firstOrCreate(
                    ['submission_id' => $plan['submission']->id, 'item_id' => $planItem->id],
                    [
                        'response_text' => $text,
                        'earned_points' => $earned,
                        'is_auto_scored' => $isObjective,
                        'scored_by_teacher_id' => $isObjective ? null : $plan['teacher'],
                    ]
                );
                GradeEntry::firstOrCreate(
                    ['assessment_submission_id' => $plan['submission']->id, 'assessment_item_id' => $planItem->id],
                    [
                        'score' => $earned,
                        'max_score' => $planItem->max_points,
                        'feedback' => $isObjective ? null : $feedback,
                        'graded_by' => $plan['teacher'],
                        'graded_at' => $plan['graded_at'],
                        'is_draft' => false,
                    ]
                );
            }
        }

        // Mastery: Recorded submissions only (3). Per-competency percent
        // mirrors the service math (earned over total, rounded to 2), with
        // Mastered exactly at 80 and above. Practice submissions persist
        // zero mastery rows (recorded-only guard).
        foreach ([
            ['submission' => $subQuizAna, 'assessment' => $quiz],
            ['submission' => $subQuizMark, 'assessment' => $quiz],
            ['submission' => $subQuizLiza, 'assessment' => $quiz],
        ] as $entry) {
            $responses = AssessmentResponse::where('submission_id', $entry['submission']->id)
                ->get()->keyBy('item_id');
            $groups = [];
            foreach ($quizItems as $quizItem) {
                $competencyId = (int) $quizItem->competency_tag_id;
                if (! isset($groups[$competencyId])) {
                    $groups[$competencyId] = ['earned' => 0.0, 'total' => 0.0];
                }
                $response = $responses[$quizItem->id] ?? null;
                $groups[$competencyId]['earned'] += $response !== null && $response->earned_points !== null
                    ? (float) $response->earned_points
                    : 0.0;
                $groups[$competencyId]['total'] += (float) $quizItem->max_points;
            }
            foreach ($groups as $competencyId => $totals) {
                if ($totals['total'] <= 0) {
                    continue;
                }
                $percent = round(($totals['earned'] / $totals['total']) * 100, 2);
                MasteryRecord::firstOrCreate(
                    [
                        'student_id' => $entry['submission']->student_id,
                        'competency_id' => $competencyId,
                        'assessment_id' => $entry['assessment']->id,
                        'assessment_submission_id' => $entry['submission']->id,
                    ],
                    [
                        'subject_id' => $subject->id,
                        'classroom_id' => $classroom->id,
                        'mastery_percent' => $percent,
                        'mastery_status' => $percent >= 80 ? 'Mastered' : 'Not_Mastered',
                    ]
                );
            }
        }

        $this->command?->info(
            'DemoSeeder (7-Rizal Science): 1 section, 1 subject, 5 users, 1 classroom, '
            .'3 enrollments, 3 competencies, 1 announcement, 1 assignment, 3 assignment submissions, '
            .'1 feedback, 2 assessments, 7 items, 1 learning material, 5 attempts, 5 assessment '
            .'submissions, 19 responses, 19 grades, 9 mastery records.'
        );
    }

    /**
     * Seed an assessment's items in fixed sort order (SELECT on
     * assessment_id + sort_order, then INSERT). Returns items ordered by
     * sort_order.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string|null, 4: string}>  $specs
     * @return array<int, \App\Models\AssessmentItem>
     */
    private function seedItems(Assessment $assessment, array $specs, array $competencyIds): array
    {
        $items = [];
        $sort = 0;
        foreach ($specs as [$itemType, $competencyCode, $maxPoints, $answer, $prompt]) {
            $sort++;
            $item = AssessmentItem::where('assessment_id', $assessment->id)
                ->where('sort_order', $sort)
                ->first();
            if (! $item) {
                $item = AssessmentItem::create([
                    'assessment_id' => $assessment->id,
                    'item_type' => $itemType,
                    'prompt' => $prompt,
                    'max_points' => $maxPoints,
                    'correct_answer' => $answer,
                    'competency_tag_id' => $competencyIds[$competencyCode],
                    'sort_order' => $sort,
                ]);
            }
            $items[] = $item;
        }

        return $items;
    }

    /** Practice-drill items ordered by sort_order (seeded above). */
    private function drillItems(int $assessmentId): array
    {
        return AssessmentItem::where('assessment_id', $assessmentId)
            ->orderBy('sort_order')->get()->all();
    }

    private function seedItemAttachment(int $itemId, string $filename, string $original): void
    {
        if (! AssessmentItemAttachment::where('assessment_item_id', $itemId)->where('filename', $filename)->exists()) {
            AssessmentItemAttachment::create([
                'assessment_item_id' => $itemId,
                'filename' => $filename,
                'original_filename' => $original,
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
            ]);
        }
    }

    private function seedAttempt(int $assessmentId, int $studentId, int $teacherId, string $startedOn, string $gradedOn): AssessmentAttempt
    {
        return AssessmentAttempt::firstOrCreate(
            ['assessment_id' => $assessmentId, 'student_id' => $studentId, 'attempt_number' => 1],
            [
                'status' => 'scored',
                'grader_id' => $teacherId,
                'graded_at' => $this->manila($gradedOn, '10:00:00'),
                'response_history' => [],
                'started_at' => $this->manila($startedOn, '09:00:00'),
                'is_resubmission' => false,
            ]
        );
    }

    private function seedSubmission(AssessmentAttempt $attempt, Assessment $assessment, int $studentId, int $sectionId, int $semesterId, string $submittedOn, string $releasedOn): AssessmentSubmission
    {
        return AssessmentSubmission::firstOrCreate(
            ['attempt_id' => $attempt->id],
            [
                'assessment_id' => $assessment->id,
                'student_id' => $studentId,
                'section_id' => $sectionId,
                'semester_id' => $semesterId,
                'submitted_at' => $this->manila($submittedOn, '10:00:00'),
                'status' => 'scored',
                'is_results_released' => true,
                'results_released_at' => $this->manila($releasedOn, '14:00:00'),
            ]
        );
    }

    /**
     * 6-char A-Z0-9 join key from the seeded MT stream, checked against both
     * classrooms.join_key and classroom_join_key_history.old_join_key
     * (ClassroomSeeder pattern, deterministic variant).
     */
    private function generateUniqueJoinKey(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $key = '';
            for ($i = 0; $i < 6; $i++) {
                $key .= $chars[mt_rand(0, $max)];
            }

            $exists = Classroom::where('join_key', $key)->exists()
                || ClassroomJoinKeyHistory::where('old_join_key', $key)->exists();

            if (! $exists) {
                return $key;
            }
        }

        // Unreachable in practice (36^6 keyspace); draw once more and let the
        // UNIQUE index fail closed rather than looping forever.
        $fallback = '';
        for ($i = 0; $i < 6; $i++) {
            $fallback .= $chars[mt_rand(0, $max)];
        }

        return $fallback;
    }

    private function manila(string $date, string $time = '00:00:00'): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$time}", 'Asia/Manila');
    }
}

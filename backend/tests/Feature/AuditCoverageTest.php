<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Audit coverage policy (S-6, docs-only; ARCH-002 QA-006, ARCH-001 §5.1).
 *
 * Enumerates the `grep -R "auditLogService->log" backend/app/Services`
 * instrumentation contract: classroom creation, assessment creation/release,
 * material create/update/delete, AI flag/disable/note, results publish, and
 * purge each have an explicit audit row; grading deliberately has none.
 *
 * Policy: the GradeEntry ledger IS the grading audit trail — each finalized
 * attempt's per-item score/max/feedback plus grader id and graded-at timestamp
 * is persisted to `grade_entries` (UNIQUE per submission+item, non-draft rows
 * feed mastery), so a separate `grade_finalized` audit row would duplicate
 * that ledger and risk divergence; GradingService therefore MUST NOT inject
 * AuditLogService.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1)
 */
class AuditCoverageTest extends TestCase
{
    private function serviceSource(string $serviceFile): string
    {
        $path = base_path('app/Services/' . $serviceFile);
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertNotFalse($source);

        return $source;
    }

    public function test_classroom_creation_is_audited(): void
    {
        $source = $this->serviceSource('ClassroomService.php');

        $this->assertStringContainsString('auditLogService->log', $source);
        $this->assertStringContainsString('Classroom created', $source);
    }

    public function test_assessment_creation_and_release_are_audited(): void
    {
        $source = $this->serviceSource('AssessmentService.php');

        $this->assertStringContainsString('auditLogService->log', $source);
        $this->assertStringContainsString('Assessment created', $source);
        $this->assertStringContainsString('releaseAssessment', $source);
        $this->assertStringContainsString('release_results', $source);
    }

    public function test_material_create_update_delete_are_audited(): void
    {
        $source = $this->serviceSource('LearningMaterialService.php');

        $this->assertStringContainsString('auditLogService->log', $source);
        $this->assertStringContainsString('Learning material created', $source);
        $this->assertStringContainsString('Learning material updated', $source);
        $this->assertStringContainsString('Learning material deleted', $source);
    }

    public function test_ai_flag_disable_note_are_covered(): void
    {
        $source = $this->serviceSource('AIService.php');

        $this->assertStringContainsString('flag_explanation', $source);
        $this->assertStringContainsString('disable_explain_further', $source);
        $this->assertStringContainsString('teacher_note', $source);
        $this->assertStringContainsString('appendTeacherNote', $source);
    }

    public function test_results_publish_is_audited(): void
    {
        $source = $this->serviceSource('AssessmentService.php');

        $this->assertStringContainsString('release_results', $source);
        $this->assertStringContainsString('Assessment results released', $source);
    }

    public function test_purge_is_audited(): void
    {
        $orgSource = $this->serviceSource('OrgStructureService.php');
        $auditSource = $this->serviceSource('AuditLogService.php');

        $this->assertStringContainsString('purge_semesters', $orgSource);
        $this->assertStringContainsString('purgeOldLogs', $auditSource);
    }

    public function test_grading_relies_on_grade_entry_ledger_not_audit_row(): void
    {
        $source = $this->serviceSource('GradingService.php');

        $this->assertStringContainsString('GradeEntry', $source);
        $this->assertStringContainsString('grade_entries', $source);
        $this->assertStringNotContainsString('AuditLogService', $source);
        $this->assertStringNotContainsString('auditLogService->log', $source);
        $this->assertStringNotContainsString('grade_finalized', $source);
    }
}

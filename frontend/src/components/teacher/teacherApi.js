import { api, ApiError } from '../../api/client.js';
import { friendlyError } from '../shared/errors.js';

/* Teacher transport — every function hits the real backend via src/api/client.js.
   No mocks, no alert() stubs. Paginated reads resolve to { data, meta }. */

export { ApiError };

export function fieldErrors(err) {
  if (err instanceof ApiError && err.fields && typeof err.fields === 'object') return err.fields;
  return null;
}

export function firstFieldError(err) {
  const fields = fieldErrors(err);
  if (!fields) return null;
  const key = Object.keys(fields)[0];
  if (!key) return null;
  const v = fields[key];
  return Array.isArray(v) ? String(v[0]) : String(v);
}

export function errorMessage(err, fallback = 'Something went wrong. Please try again. If this keeps happening, contact your school administrator.') {
  return friendlyError(err, { fallback });
}

export function formatDateTime(value) {
  if (!value) return '—';
  try {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  } catch {
    return String(value);
  }
}

export function formatDate(value) {
  if (!value) return '—';
  try {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  } catch {
    return String(value);
  }
}

export function formatFileSize(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return '—';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

export function pagesFromMeta(meta, fallbackCount = 0, perPage = 15) {
  if (meta && typeof meta.total === 'number' && meta.total > 0) {
    const pp = Number(meta.per_page) > 0 ? Number(meta.per_page) : perPage;
    return Math.max(1, Math.ceil(meta.total / pp));
  }
  if (fallbackCount > perPage) return Math.max(1, Math.ceil(fallbackCount / perPage));
  return 1;
}

export function toneForPct(pct) {
  const n = Number(pct);
  if (!Number.isFinite(n)) return 'neutral';
  if (n >= 80) return 'green';
  if (n >= 60) return 'amber';
  return 'red';
}

export function colorForPct(pct) {
  const n = Number(pct);
  if (!Number.isFinite(n)) return 'var(--line)';
  if (n >= 80) return 'var(--green)';
  if (n >= 60) return 'var(--amber)';
  return 'var(--red)';
}

/* ---------------- session / assignments ---------------- */

export function getAiStatus() {
  return api.get('/api/ai/status', { skipAuthRedirect: true });
}

/* ---------------- classrooms ---------------- */

export function listTeacherClassrooms({ school_year } = {}) {
  const query = {};
  if (school_year) query.school_year = school_year;
  // Backend returns { data: [...] } without meta for this list.
  return api.get('/api/teacher/classrooms', { query });
}

export function createTeacherClassroom({ subject_id, section_id, school_year, suffix }) {
  const body = { subject_id: Number(subject_id), section_id: Number(section_id) };
  if (school_year && String(school_year).trim() !== '') body.school_year = String(school_year).trim();
  if (suffix && String(suffix).trim() !== '') body.suffix = String(suffix).trim();
  return api.post('/api/teacher/classrooms', body);
}

export function getTeacherClassroom(id) {
  return api.get(`/api/teacher/classrooms/${encodeURIComponent(id)}`);
}

export function resetClassroomKey(id) {
  return api.post(`/api/teacher/classrooms/${encodeURIComponent(id)}/reset-key`, {});
}

export function toggleClassroomJoin(id, isJoinEnabled) {
  if (isJoinEnabled === undefined || isJoinEnabled === null) {
    return api.post(`/api/teacher/classrooms/${encodeURIComponent(id)}/toggle-join`, {});
  }
  return api.post(`/api/teacher/classrooms/${encodeURIComponent(id)}/toggle-join`, { is_join_enabled: Boolean(isJoinEnabled) });
}

export function archiveTeacherClassroom(id) {
  return api.post(`/api/teacher/classrooms/${encodeURIComponent(id)}/archive`, {});
}

export function unarchiveTeacherClassroom(id) {
  return api.post(`/api/teacher/classrooms/${encodeURIComponent(id)}/unarchive`, {});
}

export function getClassroomCompetencyContext(classroomId) {
  // GET /api/teacher/classrooms/{id}/competency-context — returns the
  // { subject_id, section_id, grade_level_id, grade_level, semester_id,
  // semester } triple the competency picker needs to call the filtered
  // competency-tags list (?subject_id&grade_level&semester).
  return api.get(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/competency-context`);
}

export async function listClassroomCompetencyTags(classroomId, { page = 1, per_page = 100 } = {}) {
  // Competency picker source: competency-context triple → filtered catalog.
  // The catalog lives on the admin read today, so a teacher 403 falls back
  // to the classroom competency summary mapped to { id, code, descriptor }.
  const raw = await getClassroomCompetencyContext(classroomId);
  const ctx = raw?.data ?? raw ?? {};
  const query = { page, per_page };
  if (ctx.subject_id) query.subject_id = ctx.subject_id;
  if (ctx.grade_level) query.grade_level = ctx.grade_level;
  if (ctx.semester) query.semester = ctx.semester;
  try {
    return await api.list('/api/admin/competency-tags', query);
  } catch (err) {
    if (err instanceof ApiError && err.status === 403) {
      const summary = await getClassroomCompetencySummary(classroomId).catch(() => null);
      const list = summary?.competencies || summary?.data || summary || [];
      const rows = (Array.isArray(list) ? list : []).map((c) => ({
        id: c.competency_id ?? c.id,
        code: c.code ?? c.competency_code,
        descriptor: c.descriptor,
        subject_id: ctx.subject_id,
        grade_level: ctx.grade_level,
        semester: ctx.semester,
      })).filter((r) => r.id !== undefined && r.id !== null);
      return { data: rows, meta: null, _context: ctx, _fallback: true };
    }
    throw err;
  }
}

export function isCompetencyMismatch(err) {
  if (err instanceof ApiError && err.status === 422) {
    if (String(err.code || '').toUpperCase() === 'COMPETENCY_MISMATCH') return true;
    const msg = String(err.message || '').toLowerCase();
    if (msg.includes('competency') && (msg.includes('subject') || msg.includes('semester') || msg.includes('grade'))) return true;
  }
  return false;
}

export function competencyMismatchMessage() {
  return 'That competency does not belong to this classroom\u2019s subject, grade level, and semester. Pick one from the filtered list for this classroom. If this keeps happening, contact your school administrator.';
}

export function getClassroomPeople(id, { page = 1, per_page = 50, search } = {}) {
  // Backend returns the FULL roster as { data: [...] } with no meta and no
  // server-side search/paging (TeacherClassroomController@people) — page,
  // per_page and search are accepted here for call-site convenience but are
  // ignored server-side. Callers must page/filter client-side over the full
  // set and never treat one response as a sample for counts.
  const query = { page, per_page };
  if (search && String(search).trim() !== '') query.search = String(search).trim();
  return api.list(`/api/teacher/classrooms/${encodeURIComponent(id)}/people`, query);
}

export function removeClassroomStudent(classroomId, studentId) {
  return api.del(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/people/${encodeURIComponent(studentId)}`);
}

/* ---------------- announcements (stream) ---------------- */

export function listClassroomAnnouncements(classroomId, { page = 1, per_page = 15 } = {}) {
  return api.list(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/announcements`, { page, per_page });
}

export function createClassroomAnnouncement(classroomId, { title, body, files = [] }) {
  const fd = new FormData();
  fd.append('title', String(title || ''));
  fd.append('body', String(body || ''));
  for (const f of files || []) fd.append('attachments[]', f);
  return api.upload(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/announcements`, fd, { method: 'POST' });
}

export function updateAnnouncement(id, { title, body, files = null }) {
  const fd = new FormData();
  // Laravel PUT via multipart needs method spoofing.
  fd.append('_method', 'PUT');
  if (title !== undefined) fd.append('title', String(title));
  if (body !== undefined) fd.append('body', String(body));
  if (Array.isArray(files)) {
    for (const f of files) fd.append('attachments[]', f);
  }
  return api.upload(`/api/teacher/announcements/${encodeURIComponent(id)}`, fd, { method: 'POST' });
}

export function deleteAnnouncement(id) {
  return api.del(`/api/teacher/announcements/${encodeURIComponent(id)}`);
}

/* ---------------- global announcements / assignments / assessments (#34–#35, #40–#41, #53, #55) ----------------
   Cross-classroom variants of the classroom-scoped calls above.
   Globals are deprecated server-side; classroom variants take the classroom from the path. */

export function listTeacherAnnouncements({ page = 1, per_page = 15 } = {}) {
  return api.list('/api/teacher/announcements', { page, per_page });
}

/* ---------------- assignments ---------------- */

export function listClassroomAssignments(classroomId, { page = 1, per_page = 15 } = {}) {
  return api.list(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/assignments`, { page, per_page });
}

export function createClassroomAssignment(classroomId, { title, description, due_date, files = [] }) {
  const fd = new FormData();
  fd.append('title', String(title || ''));
  if (description) fd.append('description', String(description));
  if (due_date) fd.append('due_date', String(due_date));
  for (const f of files || []) fd.append('attachments[]', f);
  return api.upload(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/assignments`, fd, { method: 'POST' });
}

/* ---------------- assignments ----------------
   Classroom-scoped variants take the classroom from the path. The global
   (cross-classroom) variants are deprecated server-side: subject_id is
   prohibited there — create inside a classroom instead. */

export function listTeacherAssignments({ subject_id, section_id, page = 1, per_page = 15 } = {}) {
  const query = { page, per_page };
  if (subject_id !== undefined && subject_id !== null && String(subject_id).trim() !== '') {
    query.subject_id = subject_id;
  }
  // section_id is accepted for call-site convenience; the backend treats it
  // as a legacy alias in the same slot.
  if (section_id !== undefined && section_id !== null && String(section_id).trim() !== '') {
    query.section_id = section_id;
  }
  return api.list('/api/teacher/assignments', query);
}

/** @deprecated Global creates are deprecated server-side — use createClassroomAssignment. */
export function createTeacherAssignment({ subject_id, title, description, due_date, files = [] }) {
  const fd = new FormData();
  if (subject_id !== undefined && subject_id !== null && String(subject_id).trim() !== '') {
    fd.append('subject_id', String(subject_id));
  }
  fd.append('title', String(title || ''));
  if (description) fd.append('description', String(description));
  if (due_date) fd.append('due_date', String(due_date));
  for (const f of files || []) fd.append('attachments[]', f);
  return api.upload('/api/teacher/assignments', fd, { method: 'POST' });
}

export function getTeacherAssignment(id) {
  return api.get(`/api/teacher/assignments/${encodeURIComponent(id)}`);
}

export function updateTeacherAssignment(id, { title, description, due_date }) {
  const body = {};
  if (title !== undefined) body.title = title;
  if (description !== undefined) body.description = description;
  if (due_date !== undefined) body.due_date = due_date;
  return api.put(`/api/teacher/assignments/${encodeURIComponent(id)}`, body);
}

export function deleteTeacherAssignment(id) {
  return api.del(`/api/teacher/assignments/${encodeURIComponent(id)}`);
}

export function confirmDeleteTeacherAssignment(id) {
  return api.post(`/api/teacher/assignments/${encodeURIComponent(id)}/confirm-delete`, {});
}

export function listAssignmentSubmissions(assignmentId, { page = 1, per_page = 50 } = {}) {
  return api.list(`/api/teacher/assignments/${encodeURIComponent(assignmentId)}/submissions`, { page, per_page });
}

export function getTeacherSubmission(submissionId) {
  return api.get(`/api/teacher/submissions/${encodeURIComponent(submissionId)}`);
}

export function storeAssignmentFeedback(submissionId, feedback) {
  return api.post(`/api/teacher/submissions/${encodeURIComponent(submissionId)}/feedback`, { feedback });
}

export function downloadAssignmentAttachment(assignmentId, attachmentId) {
  return api.download(`/api/teacher/assignments/${encodeURIComponent(assignmentId)}/attachments/${encodeURIComponent(attachmentId)}/download`);
}

export function downloadSubmissionFile(submissionId, fileId) {
  return api.download(`/api/teacher/submissions/${encodeURIComponent(submissionId)}/files/${encodeURIComponent(fileId)}/download`);
}

/* ---------------- assessments ---------------- */

export function listClassroomAssessments(classroomId, { status, page = 1, per_page = 15 } = {}) {
  const query = { page, per_page };
  if (status && status !== 'all') query.status = status;
  return api.list(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/assessments`, query);
}

export function listTeacherAssessments({ status, page = 1, per_page = 15 } = {}) {
  const query = { page, per_page };
  if (status && status !== 'all') query.status = status;
  return api.list('/api/teacher/assessments', query);
}

export function createClassroomAssessment(classroomId, { title, description, type, time_limit, availability_starts_at, availability_ends_at }) {
  const body = { title: String(title || '') };
  if (description) body.description = String(description);
  body.type = type === 'Unrecorded' ? 'Unrecorded' : 'Recorded';
  if (time_limit !== undefined && time_limit !== null && String(time_limit).trim() !== '') body.time_limit = Number(time_limit);
  if (availability_starts_at) body.availability_starts_at = availability_starts_at;
  if (availability_ends_at) body.availability_ends_at = availability_ends_at;
  return api.post(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/assessments`, body);
}

/** @deprecated Global creates are deprecated server-side — use createClassroomAssessment. */
export function createTeacherAssessment({ subject_id, title, description, type, time_limit, availability_starts_at, availability_ends_at }) {
  const body = { title: String(title || '') };
  if (subject_id !== undefined && subject_id !== null && String(subject_id).trim() !== '') {
    body.subject_id = Number(subject_id);
  }
  if (description) body.description = String(description);
  body.type = type === 'Unrecorded' ? 'Unrecorded' : 'Recorded';
  if (time_limit !== undefined && time_limit !== null && String(time_limit).trim() !== '') body.time_limit = Number(time_limit);
  if (availability_starts_at) body.availability_starts_at = availability_starts_at;
  if (availability_ends_at) body.availability_ends_at = availability_ends_at;
  return api.post('/api/teacher/assessments', body);
}

export function getTeacherAssessment(id) {
  return api.get(`/api/teacher/assessments/${encodeURIComponent(id)}`);
}

export function updateTeacherAssessment(id, { title, description }) {
  const body = {};
  if (title !== undefined) body.title = title;
  if (description !== undefined) body.description = description;
  return api.put(`/api/teacher/assessments/${encodeURIComponent(id)}`, body);
}

export function deleteTeacherAssessment(id) {
  return api.del(`/api/teacher/assessments/${encodeURIComponent(id)}`);
}

export function createAssessmentItem(assessmentId, { item_type, prompt, max_points, correct_answer, competency_tag_id, sort_order = 0, files = [] }) {
  const fd = new FormData();
  fd.append('item_type', String(item_type));
  fd.append('prompt', String(prompt || ''));
  fd.append('max_points', String(max_points));
  if (correct_answer !== undefined && correct_answer !== null && String(correct_answer) !== '') {
    fd.append('correct_answer', String(correct_answer));
  }
  fd.append('competency_tag_id', String(competency_tag_id));
  fd.append('sort_order', String(sort_order));
  for (const f of files || []) fd.append('attachments[]', f);
  return api.upload(`/api/teacher/assessments/${encodeURIComponent(assessmentId)}/items`, fd, { method: 'POST' });
}

export function updateAssessmentItem(itemId, { item_type, prompt, max_points, correct_answer, competency_tag_id }) {
  const body = {};
  if (item_type !== undefined) body.item_type = item_type;
  if (prompt !== undefined) body.prompt = prompt;
  if (max_points !== undefined) body.max_points = max_points;
  if (correct_answer !== undefined) body.correct_answer = correct_answer;
  if (competency_tag_id !== undefined) body.competency_tag_id = competency_tag_id;
  return api.put(`/api/teacher/items/${encodeURIComponent(itemId)}`, body);
}

export function deleteAssessmentItem(itemId) {
  return api.del(`/api/teacher/items/${encodeURIComponent(itemId)}`);
}

export function releaseAssessment(id) {
  return api.post(`/api/teacher/assessments/${encodeURIComponent(id)}/release`, {});
}

export function listPendingGrading({ assessment_id, page = 1, per_page = 15 } = {}) {
  // Paginated { data, meta }. assessment_id is optional: omitting it lists
  // pending grading GLOBALLY across the teacher's assessments — prefer one
  // global call + meta.total over per-assessment fan-out. Never per_page > 100.
  const query = { page, per_page };
  if (assessment_id) query.assessment_id = assessment_id;
  return api.list('/api/teacher/pending-grading', query);
}

export function getPendingItems(submissionId) {
  return api.get(`/api/teacher/submissions/${encodeURIComponent(submissionId)}/pending-items`);
}

export function releaseAssessmentResults(id) {
  return api.post(`/api/teacher/assessments/${encodeURIComponent(id)}/release-results`, {});
}

export function downloadItemAttachment(itemId, attachmentId) {
  return api.download(`/api/teacher/items/${encodeURIComponent(itemId)}/attachments/${encodeURIComponent(attachmentId)}/download`);
}

/* ---------------- grading & mastery (#100–#104) ---------------- */

export function storeManualGrade({ attempt_id, grade_entries, is_draft = false }) {
  return api.post('/api/grades/manual', { attempt_id: Number(attempt_id), grade_entries, is_draft: Boolean(is_draft) });
}

export function bulkGrade({ assessment_id, grades }) {
  return api.post('/api/grades/bulk', { assessment_id: Number(assessment_id), grades });
}

export function requestResubmission(assessmentId, attemptId, reason) {
  return api.post(`/api/assessments/${encodeURIComponent(assessmentId)}/attempts/${encodeURIComponent(attemptId)}/resubmit`, { reason });
}

export function getMasteryRecords({ student_id, competency_id, subject_id } = {}) {
  const query = {};
  if (student_id) query.student_id = student_id;
  if (competency_id) query.competency_id = competency_id;
  if (subject_id) query.subject_id = subject_id;
  return api.get('/api/mastery/records', { query });
}

export function getMasterySummary(studentId) {
  return api.get(`/api/mastery/records/${encodeURIComponent(studentId)}/summary`);
}

/* ---------------- competency mapping (#73–#75) ---------------- */

export function getNotCompetentFlags({ classroom_id, subject_id } = {}) {
  const query = {};
  if (classroom_id) query.classroom_id = classroom_id;
  if (subject_id) query.subject_id = subject_id;
  return api.get('/api/teacher/not-competent-flags', { query });
}

export function getTeacherCompetencySummary({ classroom_id, subject_id } = {}) {
  const query = {};
  if (classroom_id) query.classroom_id = classroom_id;
  if (subject_id) query.subject_id = subject_id;
  return api.get('/api/teacher/competency-summary', { query });
}

export function getClassLevelReport(sectionId) {
  return api.get(`/api/teacher/sections/${encodeURIComponent(sectionId)}/class-level-report`);
}

/* ---------------- analytics (#77–#80 + classroom heatmap) ---------------- */

export function getClassroomHeatmap(classroomId, { assessment_id } = {}) {
  const query = {};
  if (assessment_id) query.assessment_id = assessment_id;
  return api.get(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/heatmap`, { query });
}

export function getClassroomCompetencySummary(classroomId, { assessment_id } = {}) {
  const query = {};
  if (assessment_id) query.assessment_id = assessment_id;
  return api.get(`/api/teacher/classrooms/${encodeURIComponent(classroomId)}/competency-summary`, { query });
}

export function getGapReport({ subject_id, group_by = 'section' } = {}) {
  return api.get('/api/teacher/dashboard/gap-report', { query: { subject_id, group_by } });
}

export function getTrends({ subject_id, competency_code } = {}) {
  const query = { subject_id };
  if (competency_code) query.competency_code = competency_code;
  return api.get('/api/teacher/dashboard/trends', { query });
}

export function getStudentDrillDown({ student_id, subject_id }) {
  return api.get('/api/teacher/dashboard/student-drill-down', { query: { student_id, subject_id } });
}

/* ---------------- learning materials (#83–#86) ---------------- */

export function listLearningMaterials({ subject_id, competency_id, page = 1, per_page = 15 }) {
  const query = { subject_id, page, per_page };
  if (competency_id) query.competency_id = competency_id;
  return api.list('/api/teacher/learning-materials', query);
}

export function storeLearningMaterial({ subject_id, competency_id, title, file }) {
  const fd = new FormData();
  fd.append('subject_id', String(subject_id));
  fd.append('competency_id', String(competency_id));
  fd.append('title', String(title || ''));
  fd.append('file', file);
  return api.upload('/api/teacher/learning-materials', fd, { method: 'POST' });
}

export function updateLearningMaterial(id, { title, file }) {
  const fd = new FormData();
  fd.append('_method', 'PUT');
  if (title !== undefined) fd.append('title', String(title));
  if (file) fd.append('file', file);
  return api.upload(`/api/teacher/learning-materials/${encodeURIComponent(id)}`, fd, { method: 'POST' });
}

export function deleteLearningMaterial(id) {
  return api.del(`/api/teacher/learning-materials/${encodeURIComponent(id)}`);
}

/* ---------------- moderation log (#90–#94) ---------------- */

export function listModerationLog({ page = 1, per_page = 15, assessment_id, section_id } = {}) {
  const query = { page, per_page };
  if (assessment_id) query.assessment_id = assessment_id;
  if (section_id) query.section_id = section_id;
  return api.list('/api/teacher/moderation-log', query);
}

export function getModerationDetail(id) {
  return api.get(`/api/teacher/moderation-log/${encodeURIComponent(id)}`);
}

export function flagModerationExplanation(id, note) {
  const body = {};
  if (note !== undefined && note !== null && String(note).trim() !== '') body.note = String(note);
  return api.post(`/api/teacher/moderation-log/${encodeURIComponent(id)}/flag`, body);
}

export function appendModerationNote(id, note) {
  return api.post(`/api/teacher/moderation-log/${encodeURIComponent(id)}/note`, { note: String(note) });
}

export function disableExplainFurther(id) {
  return api.post(`/api/teacher/moderation-log/${encodeURIComponent(id)}/disable-explain-further`, {});
}

import { api, ApiError } from '../../api/client.js';
import { friendlyError } from '../shared/errors.js';

/* Student transport — every function hits the real backend via src/api/client.js.
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

export function normalizeJoinKey(raw) {
  return String(raw || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
}

export function isValidJoinKey(raw) {
  return /^[A-Z0-9]{6}$/.test(normalizeJoinKey(raw));
}

/* ---------------- session ---------------- */

export function getMe() {
  return api.get('/api/me');
}

export function getAiStatus() {
  return api.get('/api/ai/status', { skipAuthRedirect: true });
}

export function changePassword(currentPassword, newPassword) {
  return api.post('/api/auth/change-password', {
    current_password: currentPassword,
    new_password: newPassword,
  });
}

/* ---------------- classrooms ----------------
   The rooms index defines no paging and no search parameters; any
   page, per_page, or ?search= value is rejected 422. Never send query
   params here. */

export function listStudentClassrooms() {
  return api.get('/api/student/classrooms');
}

export function getStudentClassroom(id) {
  // Never send ?search= here — the backend rejects it 422.
  return api.get(`/api/student/classrooms/${encodeURIComponent(id)}`);
}

export function joinClassroom(key) {
  const normalized = normalizeJoinKey(key);
  return api.post('/api/classrooms/join', { key: normalized });
}

export function joinErrorMessage(err) {
  if (err instanceof ApiError) {
    if (err.status === 429) return err.message;
    if (err.status === 404) return "We couldn't find a classroom with that key. Double-check it with your teacher.";
    if (err.status === 410) {
      const code = String(err.code || '');
      if (code === 'GONE') return 'This key was rotated out and no longer works. Ask your teacher for the current key.';
      if (code === 'CLASSROOM_ARCHIVED') return "This classroom is archived and isn't accepting new members.";
      if (code === 'CLASSROOM_JOIN_DISABLED') return 'Your teacher has turned off joining for this classroom right now.';
      return 'This classroom is no longer accepting joins. Ask your teacher for help.';
    }
    if (err.status === 422) {
      const first = firstFieldError(err);
      if (first) return first;
      return 'That key does not look right. Keys are 6 characters, letters and numbers only.';
    }
    return err.message;
  }
  return 'Something went wrong. Please try again.';
}

export function leaveClassroom(id) {
  return api.post(`/api/student/classrooms/${encodeURIComponent(id)}/leave`, {});
}

/* ---------------- stream + classwork + people ---------------- */

export function getClassroomStream(classroomId, { page = 1, per_page = 15 } = {}) {
  // Backend paginates (meta.total) but defines no ?search= here — it would be
  // silently ignored, so it is never sent.
  return api.list(`/api/student/classrooms/${encodeURIComponent(classroomId)}/stream`, { page, per_page });
}

export function getClassroomClasswork(classroomId, { page = 1, per_page = 50 } = {}) {
  // Backend paginates (meta.total) but defines no ?search= here — it would be
  // silently ignored, so it is never sent.
  return api.list(`/api/student/classrooms/${encodeURIComponent(classroomId)}/classwork`, { page, per_page });
}

export function getClassroomPeople(classroomId, { page = 1, per_page = 8 } = {}) {
  // Never send ?search= here — the backend rejects it 422.
  return api.list(`/api/student/classrooms/${encodeURIComponent(classroomId)}/people`, { page, per_page });
}

export function downloadAnnouncementFile(announcementId, attachmentId) {
  return api.download(`/api/student/announcements/${encodeURIComponent(announcementId)}/download/${encodeURIComponent(attachmentId)}`);
}

/* ---------------- global announcements / assignments / assessments (#38, #49, #66) ----------------
   Cross-classroom reads. The backend resolves these to a plain data array
   (no pager), so they stay on api.get instead of api.list. */

export function listStudentAnnouncements() {
  return api.get('/api/student/announcements');
}

export function listStudentAssignments() {
  return api.get('/api/student/assignments');
}

export function listStudentAssessments() {
  return api.get('/api/student/assessments');
}

export function downloadItemAttachment(itemId, attachmentId) {
  return api.download(`/api/student/items/${encodeURIComponent(itemId)}/attachments/${encodeURIComponent(attachmentId)}/download`);
}

/* ---------------- assignments ---------------- */

export function getStudentAssignment(id) {
  return api.get(`/api/student/assignments/${encodeURIComponent(id)}`);
}

export function getAssignmentFeedback(id) {
  return api.get(`/api/student/assignments/${encodeURIComponent(id)}/feedback`);
}

export function submitAssignment(id, files) {
  const fd = new FormData();
  for (const f of files || []) fd.append('files[]', f);
  return api.upload(`/api/student/assignments/${encodeURIComponent(id)}/submit`, fd, { method: 'POST' });
}

export function downloadAssignmentAttachment(assignmentId, attachmentId) {
  return api.download(`/api/student/assignments/${encodeURIComponent(assignmentId)}/attachments/${encodeURIComponent(attachmentId)}/download`);
}

export function downloadSubmissionFile(submissionId, fileId) {
  return api.download(`/api/student/submissions/${encodeURIComponent(submissionId)}/files/${encodeURIComponent(fileId)}/download`);
}

/* ---------------- assessments ---------------- */

export function getStudentAssessment(id) {
  return api.get(`/api/student/assessments/${encodeURIComponent(id)}`);
}

export function startAssessment(id) {
  return api.post(`/api/student/assessments/${encodeURIComponent(id)}/start`, {});
}

export function autoSaveAssessment(id, responses) {
  const clean = {};
  for (const [k, v] of Object.entries(responses || {})) {
    if (v !== undefined && v !== null && String(v) !== '') clean[String(k)] = String(v);
  }
  return api.request(`/api/student/assessments/${encodeURIComponent(id)}/auto-save`, {
    method: 'PUT',
    body: { responses: clean },
  });
}

export function submitAssessment(id, responses) {
  const clean = {};
  for (const [k, v] of Object.entries(responses || {})) {
    if (v !== undefined && v !== null && String(v).trim() !== '') clean[String(k)] = String(v);
  }
  return api.post(`/api/student/assessments/${encodeURIComponent(id)}/submit`, { responses: clean });
}

export function getAssessmentResults(id) {
  return api.get(`/api/student/assessments/${encodeURIComponent(id)}/results`);
}

/* ---------------- AI explanations (#87–#89) ----------------
   #87 list is a pure read — viewing never triggers generation.
   Generate is POST .../explanations/generate (throttle ai-generate 10/min).
   Follow-up is POST /api/student/explanations/{id}/explain-further
   with { item_id } (throttle ai-chat 30/min). */

export function listExplanations(assessmentId) {
  return api.get(`/api/student/assessments/${encodeURIComponent(assessmentId)}/explanations`);
}

export function generateExplanations(assessmentId) {
  return api.post(`/api/student/assessments/${encodeURIComponent(assessmentId)}/explanations/generate`, {});
}

export function getExplanation(explanationId) {
  return api.get(`/api/student/explanations/${encodeURIComponent(explanationId)}`);
}

export function explainFurther(explanationId, itemId) {
  return api.post(`/api/student/explanations/${encodeURIComponent(explanationId)}/explain-further`, {
    item_id: Number(itemId),
  });
}

/* ---------------- mastery ----------------
   #82 mastery-history is self-scoped and read-only — looking never
   generates AI explanations. #101/#102 are the shared mastery reads. */

export function getMasteryHistory({ competency_id } = {}) {
  const query = {};
  if (competency_id) query.competency_id = competency_id;
  return api.get('/api/student/dashboard/mastery-history', { query });
}

export function getMasteryRecords({ competency_id, subject_id } = {}) {
  const query = {};
  if (competency_id) query.competency_id = competency_id;
  if (subject_id) query.subject_id = subject_id;
  return api.get('/api/mastery/records', { query });
}

export function getMasterySummary(studentId) {
  return api.get(`/api/mastery/records/${encodeURIComponent(studentId)}/summary`);
}

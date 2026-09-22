import { api, ApiError } from '../../api/client.js';
import { friendlyError } from '../shared/errors.js';

/* Central admin transport. Every function hits the real backend via
   src/api/client.js (same-origin relative by default; VITE_API_BASE only
   for direct-origin mode). No mocks, no alert() stubs. */

export { ApiError };

export function isDigitsOnly(value) {
  const s = String(value ?? '').trim();
  return s !== '' && /^\d+$/.test(s);
}

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

export function loginOf(user) {
  if (!user) return '—';
  return user.school_id || '—';
}

export function isActiveUser(user) {
  if (!user) return false;
  if (typeof user.status === 'string') {
    const s = user.status.toLowerCase();
    return s === 'active';
  }
  if (typeof user.is_active === 'boolean') return user.is_active;
  return true;
}

export function roleLabel(role) {
  const r = String(role || '').toLowerCase();
  if (r === 'admin') return 'Admin';
  if (r === 'teacher') return 'Teacher';
  return 'Student';
}

function roleParam(role) {
  if (!role || role === 'all') return undefined;
  const r = String(role).toLowerCase();
  if (r === 'admin') return 'Admin';
  if (r === 'teacher') return 'Teacher';
  if (r === 'student') return 'Student';
  return role;
}

/* ---------------- users ---------------- */

export function listUsers({ role = 'all', page = 1, per_page = 50, search } = {}) {
  // Paginated { data, meta } via api.list — meta.total is exact. Supported
  // filters are role + search only (no status filter server-side); exact
  // status breakdowns need a full client-side scan. Cheap total:
  // listUsers({ per_page: 1 }) and read meta.total. Never exceed per_page 100.
  const query = { role: roleParam(role), page, per_page };
  if (search && String(search).trim().length >= 2 && !isDigitsOnly(search)) query.search = String(search).trim();
  return api.list('/api/admin/users', query);
}

export function getUser(id) {
  return api.get(`/api/admin/users/${encodeURIComponent(id)}`);
}

export function createUser({ name, role }) {
  return api.post('/api/admin/users', { name, role });
}

export function deactivateUser(id) {
  return api.post(`/api/admin/users/${encodeURIComponent(id)}/deactivate`, {});
}

export function updateUser(id, { name } = {}) {
  const body = {};
  if (name !== undefined) body.name = name;
  return api.put(`/api/admin/users/${encodeURIComponent(id)}`, body);
}

export function reactivateUser(id) {
  return api.post(`/api/admin/users/${encodeURIComponent(id)}/reactivate`, {});
}

export function resetUserPassword(id) {
  return api.post(`/api/admin/users/${encodeURIComponent(id)}/reset-password`, {});
}

export function getUserAudit(id, { page = 1, per_page = 4 } = {}) {
  return api.list(`/api/admin/audit-logs/user/${encodeURIComponent(id)}`, { page, per_page });
}

/* ---------------- classrooms ---------------- */

export function listClassrooms({ archived, school_year, search, page = 1, per_page = 15 } = {}) {
  // Paginated { data, meta }. archived=true/false is a real server filter, so
  // exact active/archived counts come from cheap per_page:1 count queries.
  const query = { page, per_page };
  if (archived === true) query.archived = 'true';
  else if (archived === false) query.archived = 'false';
  if (school_year) query.school_year = school_year;
  if (search && String(search).trim().length >= 2 && !isDigitsOnly(search)) query.search = String(search).trim();
  return api.list('/api/admin/classrooms', query);
}

export function getClassroom(id) {
  return api.get(`/api/admin/classrooms/${encodeURIComponent(id)}`);
}

export function resetClassroomKey(id) {
  return api.post(`/api/admin/classrooms/${encodeURIComponent(id)}/reset-key`, {});
}

export function archiveClassroom(id) {
  return api.post(`/api/admin/classrooms/${encodeURIComponent(id)}/archive`, {});
}

export function unarchiveClassroom(id) {
  return api.post(`/api/admin/classrooms/${encodeURIComponent(id)}/unarchive`, {});
}

export function updateClassroomSchoolYear(id, school_year) {
  return api.patch(`/api/admin/classrooms/${encodeURIComponent(id)}`, { school_year });
}

export async function getClassroomPeople(id) {
  // No admin-scoped roster endpoint exists; the teacher-scoped people read is
  // attempted so a future role grant renders rows, otherwise callers fall back
  // to the enrollment count. 403 is expected for pure admins.
  return api.get(`/api/teacher/classrooms/${encodeURIComponent(id)}/people`);
}

/* ---------------- org structure ---------------- */

export function listSchoolYears({ page = 1, per_page = 50 } = {}) {
  return api.list('/api/admin/school-years', { page, per_page });
}

export function createSchoolYear(name) {
  return api.post('/api/admin/school-years', { name });
}

export function listSemesters(schoolYearId, { page = 1, per_page = 50 } = {}) {
  return api.list(`/api/admin/school-years/${encodeURIComponent(schoolYearId)}/semesters`, { page, per_page });
}

export function createSemester(schoolYearId, { semester, name, start_date, end_date }) {
  return api.post(`/api/admin/school-years/${encodeURIComponent(schoolYearId)}/semesters`, { semester, name, start_date, end_date });
}

export function listGradeLevels(semesterId, { page = 1, per_page = 50 } = {}) {
  return api.list(`/api/admin/semesters/${encodeURIComponent(semesterId)}/grade-levels`, { page, per_page });
}

export function createGradeLevel(semesterId, grade_level) {
  return api.post(`/api/admin/semesters/${encodeURIComponent(semesterId)}/grade-levels`, { grade_level });
}

export function listSections(gradeLevelId, { page = 1, per_page = 50 } = {}) {
  return api.list(`/api/admin/grade-levels/${encodeURIComponent(gradeLevelId)}/sections`, { page, per_page });
}

export function createSection(gradeLevelId, name) {
  return api.post(`/api/admin/grade-levels/${encodeURIComponent(gradeLevelId)}/sections`, { name });
}

export function purgeSemester(semesterId, dryRun = true) {
  const suffix = dryRun ? '?dry_run=1' : '';
  return api.post(`/api/admin/semesters/${encodeURIComponent(semesterId)}/purge${suffix}`, {});
}

/* ---------------- subjects ---------------- */

export function listSubjects({ page = 1, per_page = 20, grade_level_id } = {}) {
  const query = { page, per_page };
  if (grade_level_id) query.grade_level_id = grade_level_id;
  return api.list('/api/admin/subjects', query);
}

export function createSubject({ name, code, description, grade_level_id }) {
  return api.post('/api/admin/subjects', { name, code, description, grade_level_id: Number(grade_level_id) });
}

export function updateSubject(id, { name, code, description, grade_level_id }) {
  const body = { name, code, description };
  if (grade_level_id !== undefined && grade_level_id !== null && String(grade_level_id) !== '') {
    body.grade_level_id = Number(grade_level_id);
  }
  return api.put(`/api/admin/subjects/${encodeURIComponent(id)}`, body);
}

export function deleteSubject(id) {
  return api.del(`/api/admin/subjects/${encodeURIComponent(id)}`);
}

/* ---------------- assignments (classroom-derived) ----------------
   Teacher scope is derived from Classrooms (teacher_id + subject_id +
   section_id + semester_id + school_year). Classroom writes live on the
   classroom endpoints — manage scope there. Filtered reads below remain
   available. */

export async function listSectionAssignments(sectionId) {
  // GET /api/admin/sections/{id}/assignments (#24) — unpaginated: { data: [...] }, no meta.
  return api.get(`/api/admin/sections/${encodeURIComponent(sectionId)}/assignments`);
}

export function listTeacherAssignments({ teacher_id, subject_id, section_id, school_year, search, page = 1, per_page = 15 } = {}) {
  // Classroom-derived rows carry subject_id/section_id/semester_id.
  const query = { page, per_page };
  if (teacher_id) query.teacher_id = teacher_id;
  if (subject_id) query.subject_id = subject_id;
  if (section_id) query.section_id = section_id;
  if (school_year) query.school_year = school_year;
  if (search && String(search).trim().length >= 2 && !isDigitsOnly(search)) query.search = String(search).trim();
  return api.list('/api/admin/teacher-assignments', query);
}

export function listCompetencyTags({ search, subject_id, grade_level, semester, page = 1, per_page = 15 } = {}) {
  // GET /api/admin/competency-tags — paginated { data, meta } over the
  // competency_reference catalog populated by the #32 import. Digits-only
  // and min-2 searches never reach the server (backend 422s them).
  // Filters: subject_id, grade_level (7–12), semester (1–3).
  const query = { page, per_page };
  if (subject_id) query.subject_id = subject_id;
  if (grade_level && grade_level !== 'all') query.grade_level = grade_level;
  if (semester && semester !== 'all') query.semester = semester;
  if (search && String(search).trim().length >= 2 && !isDigitsOnly(search)) query.search = String(search).trim();
  return api.list('/api/admin/competency-tags', query);
}

/* ---------------- onboarding: sheet import ---------------- */

export function previewEnrollments(file) {
  const fd = new FormData();
  fd.append('file', file);
  return api.upload('/api/admin/import/student-enrollments/preview', fd, { method: 'POST' });
}

export function importCompetencyTags(file) {
  // POST /api/admin/import/competency-tags (#32) — multipart { file }, .xlsx/.xls ≤ 15MB.
  const fd = new FormData();
  fd.append('file', file);
  return api.upload('/api/admin/import/competency-tags', fd, { method: 'POST' });
}

export function confirmEnrollments(preview_token) {
  return api.post('/api/admin/import/student-enrollments/confirm', { preview_token });
}

export function previewTeacherApplications(file) {
  const fd = new FormData();
  fd.append('file', file);
  return api.upload('/api/admin/import/teacher-applications/preview', fd, { method: 'POST' });
}

export function confirmTeacherApplications(preview_token) {
  return api.post('/api/admin/import/teacher-applications/confirm', { preview_token });
}

export function downloadTemplate(type = 'student-enrollments') {
  return api.download(`/api/admin/import/templates/${encodeURIComponent(type)}`);
}

export function downloadErrorReportUrl(error_report_url) {
  // error_report_url arrives as /api/admin/import/error-reports/<token>.
  return api.download(error_report_url);
}

/* ---------------- onboarding: hand place / move ---------------- */

export function placeLearner({ full_name, classroom_id }) {
  return api.post('/api/admin/enrollments/place', { full_name, classroom_id });
}

export function moveEnrollment(enrollmentId, classroom_id) {
  return api.post(`/api/admin/enrollments/${encodeURIComponent(enrollmentId)}/move`, { classroom_id });
}

/* ---------------- analytics / competency / mastery / audit ---------------- */

export function getSchoolWideOverview() {
  return api.get('/api/admin/dashboard/school-wide-overview');
}

export function getAdminCompetencySummary({ subject_id, grade_level_id, semester_id, classroom_id } = {}) {
  // GET /api/admin/competency-summary (#76) — UNPAGED aggregate array, no
  // meta. Show the full length as the total; never invent a pager for it.
  const query = {};
  if (subject_id) query.subject_id = subject_id;
  if (grade_level_id) query.grade_level_id = grade_level_id;
  if (semester_id) query.semester_id = semester_id;
  if (classroom_id) query.classroom_id = classroom_id;
  const qs = new URLSearchParams(query).toString();
  return api.get(`/api/admin/competency-summary${qs ? `?${qs}` : ''}`);
}

export function getMasteryRecords({ student_id, competency_id, subject_id } = {}) {
  // GET /api/mastery/records (#101) — UNPAGED aggregate { data: [...]|{...} },
  // no meta, no page/per_page. Callers must page client-side and never render
  // a slice without an honest total. Scope by subject_id.
  const query = {};
  if (student_id) query.student_id = student_id;
  if (competency_id) query.competency_id = competency_id;
  if (subject_id) query.subject_id = subject_id;
  const qs = new URLSearchParams(query).toString();
  return api.get(`/api/mastery/records${qs ? `?${qs}` : ''}`);
}

export function getMasterySummary(studentId) {
  return api.get(`/api/mastery/records/${encodeURIComponent(studentId)}/summary`);
}

export function listAuditLogs({ event_type, user_id, auditable_type, auditable_id, from, to, page = 1, per_page = 15 } = {}) {
  const query = { page, per_page };
  if (event_type && event_type !== 'all') query.event_type = event_type;
  if (user_id) query.user_id = user_id;
  if (auditable_type) query.auditable_type = auditable_type;
  if (auditable_id) query.auditable_id = auditable_id;
  if (from) query.from = from;
  if (to) query.to = to;
  return api.list('/api/admin/audit-logs', query);
}

export function getEntityAudit({ auditable_type, auditable_id, page = 1, per_page = 15, event_type, from, to } = {}) {
  // GET /api/admin/audit-logs/entity (#97) — auditable_type + auditable_id are required.
  const type = String(auditable_type ?? '').trim();
  const rawId = auditable_id;
  if (!type) throw new Error('Entity type is required for entity-trail lookup.');
  if (rawId === undefined || rawId === null || String(rawId).trim() === '') {
    throw new Error('Entity ID is required for entity-trail lookup.');
  }
  const idText = String(rawId).trim();
  if (!/^\d+$/.test(idText)) throw new Error('Entity ID must be a positive integer.');
  const query = { auditable_type: type, auditable_id: Number(idText), page, per_page };
  if (event_type && event_type !== 'all') query.event_type = event_type;
  if (from) query.from = from;
  if (to) query.to = to;
  return api.list('/api/admin/audit-logs/entity', query);
}

export const AUDIT_EVENT_TYPES = [
  'login', 'logout', 'create', 'update', 'delete', 'error_report_download',
  'release_results', 'flag_explanation', 'disable_explain_further', 'purge_semesters', 'other',
];

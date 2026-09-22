import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import Pager from '../../components/shared/Pager.jsx';
import Modal from '../../components/shared/Modal.jsx';
import { PageHead, InlineAlert, EmptyState, SkeletonTable, useConfirm } from '../../components/admin/ui.jsx';
import FormField from '../../components/shared/FormField.jsx';
import {
  listSchoolYears, createSchoolYear, listSemesters, createSemester, listGradeLevels, createGradeLevel,
  listSections, createSection, listSubjects, createSubject, updateSubject, deleteSubject,
  listTeacherAssignments, listSectionAssignments,
  purgeSemester, isDigitsOnly, firstFieldError, ApiError,
} from '../../components/admin/adminApi.js';

const SEMESTER_VALUES = ['1', '2', '3'];

function semesterLabel(sem, yearName) {
  if (!sem) return 'Semester';
  const bits = [`Semester ${sem.semester ?? sem.id}`];
  if (sem.name) bits.push(sem.name);
  if (yearName) bits.push(`· ${yearName}`);
  return bits.join(' ');
}

export default function Org() {
  const [years, setYears] = useState([]);
  const [loadingYears, setLoadingYears] = useState(true);
  const [yearsErr, setYearsErr] = useState(null);
  const [openYear, setOpenYear] = useState({});
  const [semestersByYear, setSemestersByYear] = useState({});
  const [openSemester, setOpenSemester] = useState({});
  const [gradesBySemester, setGradesBySemester] = useState({});
  const [openGrade, setOpenGrade] = useState({});
  const [sectionsByGrade, setSectionsByGrade] = useState({});
  const [openSection, setOpenSection] = useState({});
  // Lookup caches for Semester-vocabulary labels across the page:
  // semesterMeta[semesterId] = { semester, name, yearName },
  // gradeOptions[gradeLevelId] = { id, grade_level, semester_id, label }.
  const [semesterMeta, setSemesterMeta] = useState({});
  const [gradeOptions, setGradeOptions] = useState({});
  const [treeErr, setTreeErr] = useState(null);

  // Classroom-derived teacher scope per section (read-only — writes are 410
  // GONE server-side; classrooms are managed under Admin Classrooms).
  const [sectionAssignBySection, setSectionAssignBySection] = useState({});
  const [sectionAssignErrBySection, setSectionAssignErrBySection] = useState({});

  const [subjects, setSubjects] = useState([]);
  const [subjectsMeta, setSubjectsMeta] = useState(null);
  const [subjectsPage, setSubjectsPage] = useState(1);
  const [subjectGradeFilter, setSubjectGradeFilter] = useState('all');
  const [subjectsErr, setSubjectsErr] = useState(null);
  // Bulk subject list for the assignment filter dropdown (single page; the
  // subject catalogue is small).
  const [allSubjects, setAllSubjects] = useState([]);
  const [subjectsCapped, setSubjectsCapped] = useState(false);

  const [assignments, setAssignments] = useState([]);
  const [assignMeta, setAssignMeta] = useState(null);
  const [assignPage, setAssignPage] = useState(1);
  const [assignYear, setAssignYear] = useState('');
  const [assignSubject, setAssignSubject] = useState('all');
  const [assignSearch, setAssignSearch] = useState('');
  const [assignErr, setAssignErr] = useState(null);

  const [purgeYearId, setPurgeYearId] = useState('');
  const [purgeSemesterId, setPurgeSemesterId] = useState('');
  const [purgePreview, setPurgePreview] = useState(null);
  const [purgeErr, setPurgeErr] = useState(null);
  const [purgeMsg, setPurgeMsg] = useState(null);
  const [purging, setPurging] = useState(false);

  const [modal, setModal] = useState(null);
  const [modalErr, setModalErr] = useState(null);
  const [modalForm, setModalForm] = useState({});
  const [modalBusy, setModalBusy] = useState(false);
  // Double-submit guard for the org modal: the ref blocks a second click
  // before `modalBusy` disables the button.
  const modalBusyRef = useRef(false);
  const { ask, node } = useConfirm();
  const mountedRef = useRef(true);
  useEffect(() => () => { mountedRef.current = false; }, []);

  function rememberSemesters(yearId, yearName, list) {
    setSemesterMeta((prev) => {
      const next = { ...prev };
      for (const s of list) {
        next[s.id] = { semester: s.semester, name: s.name, yearName };
      }
      return next;
    });
  }

  function rememberGrades(semesterId, list) {
    const meta = semesterMeta[semesterId];
    const semBits = meta ? `Semester ${meta.semester ?? ''}${meta.name ? ` — ${meta.name}` : ''}${meta.yearName ? ` (${meta.yearName})` : ''}` : `Semester row ${semesterId}`;
    setGradeOptions((prev) => {
      const next = { ...prev };
      for (const g of list) {
        next[g.id] = { id: g.id, grade_level: g.grade_level, semester_id: semesterId, label: `Grade ${g.grade_level ?? g.id} · ${semBits}` };
      }
      return next;
    });
  }

  function gradeContextFor(subject) {
    const nested = subject?.grade_level && typeof subject.grade_level === 'object' ? subject.grade_level : null;
    const gradeId = subject?.grade_level_id ?? nested?.id;
    const cached = gradeId ? gradeOptions[gradeId] : null;
    if (cached) return cached.label;
    const gradeValue = nested?.grade_level ?? null;
    const semId = nested?.semester_id ?? null;
    const sem = semId ? semesterMeta[semId] : null;
    const gradeBits = gradeValue ? `Grade ${gradeValue}` : (gradeId ? `Grade row ${gradeId}` : 'No grade');
    const semBits = sem ? `Semester ${sem.semester ?? ''}${sem.name ? ` — ${sem.name}` : ''}${sem.yearName ? ` (${sem.yearName})` : ''}` : (semId ? `Semester row ${semId}` : 'no semester');
    return `${gradeBits} · ${semBits}`;
  }

  async function loadYears() {
    setLoadingYears(true);
    setYearsErr(null);
    try {
      const res = await listSchoolYears({ per_page: 50 });
      const list = Array.isArray(res?.data) ? res.data : [];
      setYears(list);
      if (list.length > 0) setPurgeYearId((prev) => prev || String(list[0].id));
    } catch (e) {
      setYearsErr(e);
    } finally {
      setLoadingYears(false);
    }
  }

  async function loadSubjects() {
    setSubjectsErr(null);
    try {
      const res = await listSubjects({
        page: subjectsPage,
        per_page: 10,
        grade_level_id: subjectGradeFilter !== 'all' ? subjectGradeFilter : undefined,
      });
      setSubjects(Array.isArray(res?.data) ? res.data : []);
      setSubjectsMeta(res?.meta || null);
    } catch (e) {
      setSubjectsErr(e);
    }
  }

  async function loadAllSubjects() {
    try {
      const res = await listSubjects({ page: 1, per_page: 100 });
      const list = Array.isArray(res?.data) ? res.data : [];
      setAllSubjects(list);
      setSubjectsCapped(list.length >= 100);
    } catch {
      setAllSubjects([]);
      setSubjectsCapped(false);
    }
  }

  async function loadAssignments() {
    setAssignErr(null);
    try {
      const search = assignSearch.trim().length >= 2 && !isDigitsOnly(assignSearch.trim()) ? assignSearch.trim() : undefined;
      const res = await listTeacherAssignments({
        page: assignPage,
        per_page: 10,
        school_year: assignYear.trim() || undefined,
        subject_id: assignSubject !== 'all' ? assignSubject : undefined,
        search,
      });
      setAssignments(Array.isArray(res?.data) ? res.data : []);
      setAssignMeta(res?.meta || null);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setAssignErr(e.message || 'Search by name or code only.');
      else setAssignErr(e);
    }
  }

  useEffect(() => { loadYears(); loadAllSubjects(); }, []);
  useEffect(() => { loadSubjects(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [subjectsPage, subjectGradeFilter]);
  useEffect(() => { loadAssignments(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [assignPage]);

  async function ensureYearSemesters(id) {
    if (!id || semestersByYear[id]) return;
    try {
      const res = await listSemesters(id, { per_page: 50 });
      const list = Array.isArray(res?.data) ? res.data : [];
      const yearName = years.find((y) => String(y.id) === String(id))?.name;
      rememberSemesters(id, yearName, list);
      setSemestersByYear((p) => ({ ...p, [id]: list }));
    } catch (e) {
      setTreeErr(e);
    }
  }

  async function toggleYear(id) {
    const next = !openYear[id];
    setOpenYear((p) => ({ ...p, [id]: next }));
    if (next && !semestersByYear[id]) {
      try {
        const res = await listSemesters(id, { per_page: 50 });
        const list = Array.isArray(res?.data) ? res.data : [];
        if (!mountedRef.current) return;
        const yearName = years.find((y) => String(y.id) === String(id))?.name;
        rememberSemesters(id, yearName, list);
        setSemestersByYear((p) => ({ ...p, [id]: list }));
      } catch (e) {
        if (mountedRef.current) setTreeErr(e);
      }
    }
  }

  async function toggleSemester(id) {
    const next = !openSemester[id];
    setOpenSemester((p) => ({ ...p, [id]: next }));
    if (next && !gradesBySemester[id]) {
      try {
        const res = await listGradeLevels(id, { per_page: 50 });
        const list = Array.isArray(res?.data) ? res.data : [];
        if (!mountedRef.current) return;
        rememberGrades(id, list);
        setGradesBySemester((p) => ({ ...p, [id]: list }));
      } catch (e) {
        if (mountedRef.current) setTreeErr(e);
      }
    }
  }

  async function toggleGrade(id) {
    const next = !openGrade[id];
    setOpenGrade((p) => ({ ...p, [id]: next }));
    if (next && !sectionsByGrade[id]) {
      try {
        const res = await listSections(id, { per_page: 50 });
        setSectionsByGrade((p) => ({ ...p, [id]: Array.isArray(res?.data) ? res.data : [] }));
      } catch (e) {
        setTreeErr(e);
      }
    }
  }

  async function toggleSection(id) {
    const next = !openSection[id];
    setOpenSection((p) => ({ ...p, [id]: next }));
    if (next && sectionAssignBySection[id] === undefined) {
      await reloadSectionAssignments(id);
    }
  }

  async function reloadSectionAssignments(sectionId) {
    setSectionAssignErrBySection((p) => ({ ...p, [sectionId]: null }));
    try {
      const raw = await listSectionAssignments(sectionId);
      if (!mountedRef.current) return;
      const saList = Array.isArray(raw) ? raw : (Array.isArray(raw?.data) ? raw.data : []);
      setSectionAssignBySection((p) => ({ ...p, [sectionId]: saList }));
      setSectionAssignErrBySection((p) => ({ ...p, [sectionId]: null }));
    } catch (e) {
      if (!mountedRef.current) return;
      setSectionAssignErrBySection((p) => ({ ...p, [sectionId]: e }));
    }
  }

  function openModal(kind, payload = {}) {
    setModal(kind);
    setModalErr(null);
    setModalBusy(false);
    modalBusyRef.current = false;
    setModalForm(payload);
  }

  async function submitModal(e) {
    e?.preventDefault();
    if (modalBusyRef.current || modalBusy) return;
    modalBusyRef.current = true;
    setModalBusy(true);
    setModalErr(null);
    try {
      if (modal === 'year') {
        await createSchoolYear(modalForm.name?.trim());
        await loadYears();
      } else if (modal === 'semester') {
        await createSemester(modalForm.schoolYearId, {
          semester: modalForm.semester,
          name: modalForm.name?.trim(),
          start_date: modalForm.start_date,
          end_date: modalForm.end_date,
        });
        setSemestersByYear((p) => ({ ...p, [modalForm.schoolYearId]: undefined }));
        setOpenYear((p) => ({ ...p, [modalForm.schoolYearId]: true }));
        const res = await listSemesters(modalForm.schoolYearId, { per_page: 50 });
        const list = Array.isArray(res?.data) ? res.data : [];
        const yearName = years.find((y) => String(y.id) === String(modalForm.schoolYearId))?.name;
        rememberSemesters(modalForm.schoolYearId, yearName, list);
        setSemestersByYear((p) => ({ ...p, [modalForm.schoolYearId]: list }));
      } else if (modal === 'grade') {
        await createGradeLevel(modalForm.semesterId, Number(modalForm.grade_level));
        const res = await listGradeLevels(modalForm.semesterId, { per_page: 50 });
        const list = Array.isArray(res?.data) ? res.data : [];
        rememberGrades(modalForm.semesterId, list);
        setGradesBySemester((p) => ({ ...p, [modalForm.semesterId]: list }));
      } else if (modal === 'section') {
        await createSection(modalForm.gradeLevelId, modalForm.name?.trim());
        const res = await listSections(modalForm.gradeLevelId, { per_page: 50 });
        setSectionsByGrade((p) => ({ ...p, [modalForm.gradeLevelId]: Array.isArray(res?.data) ? res.data : [] }));
      } else if (modal === 'subject') {
        if (!modalForm.grade_level_id) throw new Error('Pick the grade level this subject belongs to.');
        await createSubject({
          name: modalForm.name?.trim(),
          code: modalForm.code?.trim(),
          description: modalForm.description?.trim() || null,
          grade_level_id: Number(modalForm.grade_level_id),
        });
        await loadSubjects();
        await loadAllSubjects();
      } else if (modal === 'subject-edit') {
        await updateSubject(modalForm.id, {
          name: modalForm.name?.trim(),
          code: modalForm.code?.trim(),
          description: modalForm.description?.trim() || null,
          grade_level_id: modalForm.grade_level_id ? Number(modalForm.grade_level_id) : undefined,
        });
        await loadSubjects();
        await loadAllSubjects();
      }
      setModal(null);
    } catch (err) {
      const f = firstFieldError(err);
      setModalErr(f ? `${friendlyError(err)} (${f})` : friendlyError(err));
    } finally {
      modalBusyRef.current = false;
      setModalBusy(false);
    }
  }

  async function doDeleteSubject(id) {
    try {
      await deleteSubject(id);
      await loadSubjects();
      await loadAllSubjects();
    } catch (err) {
      setSubjectsErr(err);
    }
  }

  async function runPurgePreview() {
    setPurgeErr(null); setPurgeMsg(null); setPurgePreview(null);
    if (!purgeSemesterId) { setPurgeErr('Pick a semester first.'); return; }
    setPurging(true);
    try {
      const data = await purgeSemester(Number(purgeSemesterId), true);
      setPurgePreview(data?.data ?? data);
    } catch (e) {
      setPurgeErr(e);
    } finally {
      setPurging(false);
    }
  }

  async function runPurgeConfirm() {
    setPurgeErr(null); setPurgeMsg(null);
    try {
      const data = await purgeSemester(Number(purgeSemesterId), false);
      const d = data?.data ?? data;
      setPurgeMsg(`Semester purged. ${d?.files_purged ?? 0} files, ${d?.assignments_purged ?? 0} assignments, ${d?.submissions_purged ?? 0} submissions removed. Mastery, explanations, and materials were kept.`);
      setPurgePreview(null);
    } catch (e) {
      setPurgeErr(e);
    }
  }

  const purgeSemesters = purgeYearId && semestersByYear[purgeYearId] ? semestersByYear[purgeYearId] : [];
  const gradeOptionList = Object.values(gradeOptions).sort((a, b) => String(a.label).localeCompare(String(b.label)));

  function subjectModalGradePicker() {
    if (gradeOptionList.length > 0) {
      const current = modalForm.grade_level_id ? String(modalForm.grade_level_id) : '';
      const known = current && gradeOptionList.some((g) => String(g.id) === current);
      return (
        <FormField label="Grade level" hint="Subjects belong to one grade level (code is unique per grade). Expand the tree above to load more grades.">
          <select value={current} onChange={(e) => setModalForm({ ...modalForm, grade_level_id: e.target.value })} required>
            <option value="">Pick a grade level</option>
            {!known && current && <option value={current}>Grade row {current} (current)</option>}
            {gradeOptionList.map((g) => <option key={g.id} value={g.id}>{g.label}</option>)}
          </select>
        </FormField>
      );
    }
    return (
      <FormField label="Grade level ID" hint="No grade levels loaded yet — expand Year → Semester → Grade in the tree above, then pick from the dropdown. As a fallback, enter the grade-level reference number directly.">
        <input value={modalForm.grade_level_id || ''} onChange={(e) => setModalForm({ ...modalForm, grade_level_id: e.target.value })} inputMode="numeric" placeholder="Grade-level reference number" required />
      </FormField>
    );
  }

  return (
    <div className="admin-page">
      <PageHead
        title="Organization"
        sub="Years → Semesters → Grades → Sections → Classrooms → Teacher scope."
        actions={<button type="button" className="btn btn-primary" onClick={() => openModal('year', { name: '' })}>New school year</button>}
      />
      {treeErr ? <ErrorNotice error={treeErr} onRetry={() => window.location.reload()} /> : null}

      {loadingYears ? <SkeletonTable rows={4} /> : years.length === 0 ? (
        <EmptyState title="No school years yet" hint="Create the first school year to start building the tree." />
      ) : (
        <div className="tree">
          {years.map((y) => (
            <details key={y.id} open={!!openYear[y.id]} onToggle={(e) => { if (e.target.open !== !!openYear[y.id]) toggleYear(y.id); }}>
              <summary><span>{y.name}</span><span className="arrow">{openYear[y.id] ? '–' : '+'}</span></summary>
              <div className="tree-body">
                <div className="admin-row">
                  <button type="button" className="btn btn-sm" onClick={() => openModal('semester', { schoolYearId: y.id, semester: '1', name: '', start_date: '', end_date: '' })}>New semester</button>
                </div>
                {(semestersByYear[y.id] || []).map((t) => (
                  <details key={t.id} open={!!openSemester[t.id]} onToggle={(e) => { if (e.target.open !== !!openSemester[t.id]) toggleSemester(t.id); }}>
                    <summary><span>Semester {t.semester ?? ''}{t.name ? ` — ${t.name}` : ''} <span className="meta-faint">· {t.start_date || t.start || ''} — {t.end_date || t.end || ''}</span></span><span className="arrow">{openSemester[t.id] ? '–' : '+'}</span></summary>
                    <div className="tree-body">
                      <div className="admin-row">
                        <button type="button" className="btn btn-sm" onClick={() => openModal('grade', { semesterId: t.id, grade_level: '7' })}>New grade</button>
                      </div>
                      {(gradesBySemester[t.id] || []).map((g) => (
                        <details key={g.id} open={!!openGrade[g.id]} onToggle={(e) => { if (e.target.open !== !!openGrade[g.id]) toggleGrade(g.id); }}>
                          <summary><span>Grade {g.grade_level ?? g.name ?? g.id}</span><span className="arrow">{openGrade[g.id] ? '–' : '+'}</span></summary>
                          <div className="tree-body">
                            <div className="admin-row">
                              <button type="button" className="btn btn-sm" onClick={() => openModal('section', { gradeLevelId: g.id, name: '' })}>New section</button>
                            </div>
                            {(sectionsByGrade[g.id] || []).map((s) => (
                              <details key={s.id} open={!!openSection[s.id]} onToggle={(e) => { if (e.target.open !== !!openSection[s.id]) toggleSection(s.id); }}>
                                <summary><span>Section {s.name}</span><span className="arrow">{openSection[s.id] ? '–' : '+'}</span></summary>
                                <div className="tree-body">
                                  <div className="section-head section-head--tight"><h3>Classrooms & teachers</h3></div>
                                  {sectionAssignErrBySection[s.id]
                                    ? <ErrorNotice error={sectionAssignErrBySection[s.id]} retryLabel="Retry" onRetry={() => reloadSectionAssignments(s.id)} />
                                    : (sectionAssignBySection[s.id] || []).length === 0
                                    ? <div className="note-line">No classrooms in this section yet. Classrooms are created by teachers (or under <Link to="/admin/classrooms">Admin Classrooms</Link>).</div>
                                    : (sectionAssignBySection[s.id] || []).map((sa) => (
                                      <div key={sa.id} className="leaf-row">
                                        <div><Link to={`/admin/classrooms/${sa.id}`}>{sa.subject || sa.subject_name || 'Subject'}</Link> <span className="leaf-meta">{sa.subject_code || ''} · {sa.teacher || sa.teacher_name || 'Unassigned teacher'}{sa.teacher_school_id ? ` (${sa.teacher_school_id})` : ''} · {sa.school_year || ''}</span></div>
                                      </div>
                                    ))}
                                  <div className="admin-row mt-8">
                                    <button type="button" className="btn btn-sm" onClick={() => reloadSectionAssignments(s.id)}>Refresh</button>
                                  </div>
                                </div>
                              </details>
                            ))}
                            {(sectionsByGrade[g.id] || []).length === 0 && <div className="note-line">No sections yet.</div>}
                          </div>
                        </details>
                      ))}
                      {(gradesBySemester[t.id] || []).length === 0 && <div className="note-line">No grades yet (grades 7–12).</div>}
                      <div className="admin-row mt-10">
                        <button type="button" className="btn btn-sm" onClick={() => { setPurgeYearId(String(y.id)); ensureYearSemesters(y.id); setOpenYear((p) => ({ ...p, [y.id]: true })); setPurgeSemesterId(String(t.id)); }}>Preview semester purge</button>
                      </div>
                    </div>
                  </details>
                ))}
                {(semestersByYear[y.id] || []).length === 0 && <div className="note-line">Open to load semesters.</div>}
              </div>
            </details>
          ))}
        </div>
      )}

      <div className="section-head"><h2>Subjects</h2><span className="count">{subjectsMeta?.total ?? subjects.length} defined</span></div>
      {subjectsErr ? <ErrorNotice error={subjectsErr} onRetry={() => window.location.reload()} /> : null}
      <div className="filter-bar filter-bar--labeled">
        <div className="filter-field">
          <label htmlFor="org-grade">Grade level</label>
          <select id="org-grade" value={subjectGradeFilter} onChange={(e) => { setSubjectGradeFilter(e.target.value); setSubjectsPage(1); }}>
            <option value="all">All grades</option>
            {gradeOptionList.map((g) => <option key={g.id} value={g.id}>{g.label}</option>)}
          </select>
        </div>
        <button type="button" className="btn btn-sm" onClick={() => openModal('subject', { name: '', code: '', description: '', grade_level_id: subjectGradeFilter !== 'all' ? subjectGradeFilter : '' })}>New subject</button>
      </div>
      {subjectGradeFilter === 'all' && gradeOptionList.length === 0 && <div className="note-line mb-12">Expand the tree above to load grade levels for filtering and subject creation.</div>}
      <Card>
        <table className="dtable">
          <thead><tr><th>Name</th><th>Code</th><th>Grade & semester</th><th>Description</th><th></th></tr></thead>
          <tbody>
            {subjects.map((s) => (
              <tr key={s.id}>
                <td className="cell-main">{s.name}</td>
                <td>{s.code}</td>
                <td className="meta-soft">{gradeContextFor(s)}</td>
                <td className="meta-soft">{s.description || '—'}</td>
                <td className="text-right nowrap">
                  <button type="button" className="btn btn-sm" onClick={() => openModal('subject-edit', { id: s.id, name: s.name, code: s.code, description: s.description || '', grade_level_id: s.grade_level_id ? String(s.grade_level_id) : '' })}>Edit</button>{' '}
                  <button type="button" className="btn btn-sm btn-danger" onClick={() => ask('Delete this subject?', 'Classrooms or competencies using it will block the delete.', 'Delete', () => doDeleteSubject(s.id), true)}>Delete</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!subjectsErr && subjects.length === 0 && <EmptyState title="No subjects yet" hint="Subjects belong to a grade level — create one with its grade picked." />}
        <Pager page={subjectsMeta?.current_page || subjectsPage} pages={subjectsMeta?.last_page || 1} onChange={setSubjectsPage} />
      </Card>

      <div className="section-head"><h2>Teacher scope</h2><span className="count">{assignMeta?.total ?? assignments.length}</span></div>
      <div className="note-line">Teacher scope is derived from classrooms (subject + section + semester) — read-only here. Create, archive, or move classrooms under <Link to="/admin/classrooms">Admin Classrooms</Link>.</div>
      {assignErr ? <ErrorNotice error={assignErr} onRetry={() => window.location.reload()} /> : null}
      <div className="filter-bar filter-bar--labeled">
        <div className="filter-field">
          <label htmlFor="org-scope-search">Search</label>
          <input id="org-scope-search" type="text" placeholder="Teacher, subject, section (min 2 chars)" value={assignSearch} onChange={(e) => { setAssignSearch(e.target.value); }} />
        </div>
        <div className="filter-field">
          <label htmlFor="org-scope-year">School year</label>
          <input id="org-scope-year" type="text" placeholder="e.g. 2025-2026" value={assignYear} onChange={(e) => setAssignYear(e.target.value)} className="ff-control" />
        </div>
        <div className="filter-field">
          <label htmlFor="org-scope-subject">Subject</label>
          <select id="org-scope-subject" value={assignSubject} onChange={(e) => setAssignSubject(e.target.value)}>
            <option value="all">All subjects</option>
            {allSubjects.map((s) => <option key={s.id} value={s.id}>{s.code ? `${s.name} (${s.code})` : s.name}</option>)}
          </select>
        </div>
        <button type="button" className="btn btn-sm" onClick={() => { setAssignPage(1); loadAssignments(); }}>Apply</button>
      </div>
      {subjectsCapped && <div className="note-line mb-12">Subject filter shows the first 100 subjects.</div>}
      {isDigitsOnly(assignSearch) && <div className="err-line">Search can&apos;t be digits only — try a name instead.</div>}
      <Card>
        <table className="dtable">
          <thead><tr><th>Teacher</th><th>Subject</th><th>Section</th><th>Grade</th><th>Semester</th><th>School year</th><th></th></tr></thead>
          <tbody>
            {assignments.map((a) => (
              <tr key={a.id}>
                <td><div className="cell-main">{a.teacher_name || 'Unnamed teacher'}</div><div className="cell-sub mono">{a.teacher_school_id || ''}</div></td>
                <td>{a.subject_name || '—'}<div className="cell-sub">{a.subject_code || ''}</div></td>
                <td>{a.section_name || '—'}</td>
                <td>{a.grade_level ? `Grade ${a.grade_level}` : '—'}</td>
                <td>{a.semester ? `Semester ${a.semester}` : '—'}<div className="cell-sub">{a.semester_name || ''}</div></td>
                <td><span className="mono">{a.school_year}</span></td>
                <td className="text-right nowrap">
                  <Link to={`/admin/classrooms/${a.id}`} className="btn btn-sm">Open classroom</Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!assignErr && assignments.length === 0 && <EmptyState title="No classrooms match" hint="Classrooms appear here once teachers create them for a subject + section." />}
        <Pager page={assignMeta?.current_page || assignPage} pages={assignMeta?.last_page || 1} onChange={setAssignPage} />
      </Card>

      <Card title="Semester purge — dry run" sub="Preview shows what a purge would remove before anything happens." className="">
        <p className="lede lede--sm">Only semester submission files are removed — mastery, explanations, and materials are kept.</p>
        <div className="form-grid-2">
          <FormField label="School year">
            <select value={purgeYearId} onChange={(e) => { setPurgeYearId(e.target.value); setPurgeSemesterId(''); ensureYearSemesters(Number(e.target.value)); }}>
              <option value="">Pick a year</option>
              {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
            </select>
          </FormField>
          <FormField label="Semester">
            <select value={purgeSemesterId} onChange={(e) => setPurgeSemesterId(e.target.value)}>
              <option value="">Pick a semester</option>
              {purgeSemesters.map((t) => <option key={t.id} value={t.id}>Semester {t.semester ?? ''}{t.name ? ` — ${t.name}` : ''}</option>)}
            </select>
          </FormField>
        </div>
        {purgeErr ? <ErrorNotice error={purgeErr} onRetry={() => window.location.reload()} /> : null}
        {purgeMsg && <InlineAlert kind="ok">{purgeMsg}</InlineAlert>}
        {purgePreview && (
          <div className="warn-line">
            Preview: {purgePreview.files_purged ?? 0} submission files, {purgePreview.assignments_purged ?? 0} assignments, {purgePreview.submissions_purged ?? 0} submissions would be removed. No mastery or explanation records are affected. <Link to="/admin/help">How semester purges work</Link>.
          </div>
        )}
        <div className="admin-row">
          <button type="button" className="btn btn-sm" disabled={purging || !purgeSemesterId} onClick={runPurgePreview}>{purging ? 'Running…' : 'Re-run preview'}</button>
          <button type="button" className="btn btn-sm btn-danger" disabled={!purgePreview} onClick={() => ask('Purge this semester?', 'This permanently removes the submission files listed in the preview. Mastery, explanations, and materials are kept. This can\u2019t be undone.', 'Purge semester', runPurgeConfirm, true)}>Confirm purge</button>
        </div>
      </Card>

      {modal && (
        <Modal
          title={
            modal === 'year' ? 'New school year' : modal === 'semester' ? 'New semester' : modal === 'grade' ? 'New grade level' :
            modal === 'section' ? 'New section' : modal === 'subject' ? 'New subject' : 'Edit subject'
          }
          onClose={() => setModal(null)}
          message={modalErr}
          tone={modalErr ? 'err' : 'ok'}
          actions={
            <>
              <button type="button" className="btn btn-quiet" onClick={() => setModal(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={modalBusy} onClick={submitModal}>{modalBusy ? 'Saving…' : 'Save'}</button>
            </>
          }
        >
          {(modal === 'year' || modal === 'section' || modal === 'subject' || modal === 'subject-edit') && (
            <FormField label={modal.startsWith('subject') ? 'Name' : 'Name'}>
              <input value={modalForm.name || ''} onChange={(e) => setModalForm({ ...modalForm, name: e.target.value })} required />
            </FormField>
          )}
          {(modal === 'subject' || modal === 'subject-edit') && (
            <>
              <FormField label="Code" hint="Unique per grade level, not globally."><input value={modalForm.code || ''} onChange={(e) => setModalForm({ ...modalForm, code: e.target.value })} required /></FormField>
              {subjectModalGradePicker()}
              <FormField label="Description"><input value={modalForm.description || ''} onChange={(e) => setModalForm({ ...modalForm, description: e.target.value })} /></FormField>
            </>
          )}
          {modal === 'semester' && (
            <>
              <FormField label="Semester" hint="One of each per school year — duplicates are rejected.">
                <select value={modalForm.semester || '1'} onChange={(e) => setModalForm({ ...modalForm, semester: e.target.value })} required>
                  {SEMESTER_VALUES.map((v) => <option key={v} value={v}>Semester {v}</option>)}
                </select>
              </FormField>
              <FormField label="Name"><input value={modalForm.name || ''} onChange={(e) => setModalForm({ ...modalForm, name: e.target.value })} required /></FormField>
              <FormField label="Start date"><input type="date" value={modalForm.start_date || ''} onChange={(e) => setModalForm({ ...modalForm, start_date: e.target.value })} required /></FormField>
              <FormField label="End date"><input type="date" value={modalForm.end_date || ''} onChange={(e) => setModalForm({ ...modalForm, end_date: e.target.value })} required /></FormField>
            </>
          )}
          {modal === 'grade' && (
            <FormField label="Grade level (grades 7–12)">
              <select value={modalForm.grade_level || '7'} onChange={(e) => setModalForm({ ...modalForm, grade_level: e.target.value })}>
                {['7', '8', '9', '10', '11', '12'].map((g) => <option key={g} value={g}>Grade {g}</option>)}
              </select>
            </FormField>
          )}
        </Modal>
      )}
      {node}
    </div>
  );
}

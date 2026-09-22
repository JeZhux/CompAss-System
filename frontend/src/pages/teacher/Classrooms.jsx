import { useEffect, useMemo, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import { PageHead, InlineAlert, EmptyState, SkeletonTable, Modal } from '../../components/teacher/ui.jsx';
import FormField from '../../components/shared/FormField.jsx';
import { useAuth } from '../../auth/AuthContext.jsx';
import {
  listTeacherClassrooms,
  createTeacherClassroom,
  firstFieldError,
} from '../../components/teacher/teacherApi.js';

export default function Classrooms() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rooms, setRooms] = useState([]);
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ subject_id: '', section_id: '', school_year: '', suffix: '' });
  const [formError, setFormError] = useState(null);
  const [fieldError, setFieldError] = useState(null);
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);

  const assignments = Array.isArray(user?.assignments) ? user.assignments : [];

  // Classroom-derived scope rows: { subject_id, section_id, subject, section,
  // grade_level, semester, semester_id, school_year }. Unique pick lists for
  // the create form.
  const subjectOptions = useMemo(() => {
    const seen = new Map();
    for (const a of assignments) {
      if (a?.subject_id && !seen.has(a.subject_id)) {
        seen.set(a.subject_id, {
          id: a.subject_id,
          label: `${a.subject || 'Subject'}${a.grade_level ? ` · Grade ${a.grade_level}` : ''}${a.semester ? ` · Semester ${a.semester}` : ''}${a.school_year ? ` (${a.school_year})` : ''}`,
          grade_level: a.grade_level,
          semester: a.semester,
        });
      }
    }
    return [...seen.values()];
  }, [assignments]);

  const sectionOptions = useMemo(() => {
    const seen = new Map();
    for (const a of assignments) {
      if (a?.section_id && !seen.has(a.section_id)) {
        seen.set(a.section_id, {
          id: a.section_id,
          label: `${a.section || `Section ${a.section_id}`}${a.grade_level ? ` · Grade ${a.grade_level}` : ''}${a.semester ? ` · Semester ${a.semester}` : ''}`,
          grade_level: a.grade_level,
          semester: a.semester,
        });
      }
    }
    return [...seen.values()];
  }, [assignments]);

  // Best-effort narrowing: when a subject is picked, prefer sections from the
  // same grade + semester (the server still owns the same-grade-level rule).
  const chosenSubject = subjectOptions.find((s) => String(s.id) === String(form.subject_id));
  const visibleSections = chosenSubject && (chosenSubject.grade_level || chosenSubject.semester)
    ? sectionOptions.filter((s) => s.grade_level === chosenSubject.grade_level && s.semester === chosenSubject.semester)
    : sectionOptions;
  const narrowedSections = visibleSections.length > 0 ? visibleSections : sectionOptions;

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await listTeacherClassrooms();
      setRooms(Array.isArray(data) ? data : Array.isArray(data?.data) ? data.data : []);
    } catch (err) {
      setError(err);
      setRooms([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  async function handleCreate(e) {
    e.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    setFieldError(null);
    if (!form.subject_id || !form.section_id) {
      setFormError('Choose a subject and a section. They must belong to the same grade level.');
      return;
    }
    if (form.school_year && !/^\d{4}-\d{4}$/.test(form.school_year.trim())) {
      setFieldError('School year must look like 2025-2026.');
      return;
    }
    setSaving(true);
    savingRef.current = true;
    try {
      await createTeacherClassroom({
        subject_id: Number(form.subject_id),
        section_id: Number(form.section_id),
        school_year: form.school_year.trim() || undefined,
        suffix: form.suffix.trim() || undefined,
      });
      setCreating(false);
      setForm({ subject_id: '', section_id: '', school_year: '', suffix: '' });
      await load();
    } catch (err) {
      const fe = firstFieldError(err);
      if (fe) setFieldError(fe);
      else setFormError(friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  return (
    <div className="teacher-page">
      <PageHead
        title="My Classrooms"
        sub={rooms.length ? `${rooms.length} classroom${rooms.length === 1 ? '' : 's'} this year` : 'Classrooms you own appear here.'}
        actions={<button type="button" className="btn btn-primary" onClick={() => { setCreating(true); setFormError(null); setFieldError(null); }}>Create classroom</button>}
      />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {loading ? (
        <SkeletonTable rows={4} />
      ) : error ? null : rooms.length === 0 ? (
        <EmptyState title="No classrooms yet" hint="Create your first classroom from a subject + section in your scope. The name is derived automatically and a join key is generated." />
      ) : (
        <div className="classroom-grid">
          {rooms.map((c) => (
            <Link key={c.id} to={`/teacher/classrooms/${c.id}`} className="classroom-card">
              <div className="subj">
                <span>{c.suffix || 'Classroom'}</span>
                {c.archived_at ? <span className="pill pill-neutral">Archived</span> : null}
              </div>
              <h3>{c.name || `Classroom ${c.id}`}</h3>
              <div className="meta">
                SY {c.school_year || '—'} · Join: {c.is_join_enabled ? 'open' : 'closed'} · Key {c.join_key_display || c.join_key || '—'}
              </div>
            </Link>
          ))}
        </div>
      )}
      <div className="note-line">One classroom per subject + section per school year. Archived rooms keep history but don&apos;t accept new students or new work. <Link to="/teacher/help">How classrooms work</Link>.</div>

      {creating && (
        <Modal
          title="Create a classroom"
          sub="Pick a subject and a section from your scope for this school year. Each subject + section can have only one classroom per year."
          onClose={() => (!saving ? setCreating(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setCreating(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleCreate}>{saving ? 'Creating…' : 'Create classroom'}</button>
            </>
          }
        >
          {(formError || fieldError) && <div className="modal-msg err">{formError || fieldError}</div>}
          <form className="stacked" onSubmit={handleCreate}>
            <FormField label="Subject">
              <select value={form.subject_id} onChange={(e) => setForm((f) => ({ ...f, subject_id: e.target.value, section_id: '' }))}>
                <option value="">Select a subject…</option>
                {subjectOptions.map((s) => (
                  <option key={s.id} value={s.id}>{s.label}</option>
                ))}
              </select>
            </FormField>
            <FormField label="Section" hint="Sections are narrowed to the subject's grade + semester where known — the server still validates the pairing.">
              <select value={form.section_id} onChange={(e) => setForm((f) => ({ ...f, section_id: e.target.value }))}>
                <option value="">Select a section…</option>
                {narrowedSections.map((s) => (
                  <option key={s.id} value={s.id}>{s.label}</option>
                ))}
              </select>
            </FormField>
            {assignments.length === 0 && (
              <div className="note-line">No subjects in your scope yet. Ask an admin to set up your classroom scope before creating a classroom.</div>
            )}
            <div className="form-grid-2">
              <FormField label="School year (optional)">
                <input value={form.school_year} onChange={(e) => setForm((f) => ({ ...f, school_year: e.target.value }))} placeholder="2025-2026" />
              </FormField>
              <FormField label="Suffix (optional)">
                <input value={form.suffix} onChange={(e) => setForm((f) => ({ ...f, suffix: e.target.value }))} placeholder="e.g. Newton" maxLength={50} />
              </FormField>
            </div>
            <div className="note-line">Name is derived automatically from the section and subject. A unique join key is generated on creation.</div>
          </form>
        </Modal>
      )}
    </div>
  );
}

import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import { InlineAlert, EmptyState, SkeletonTable, useConfirm } from '../../components/admin/ui.jsx';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { getClassroom, resetClassroomKey, archiveClassroom, unarchiveClassroom, updateClassroomSchoolYear, getClassroomPeople, formatDateTime, ApiError } from '../../components/admin/adminApi.js';

export default function ClassroomDetail() {
  const { id } = useParams();
  const [room, setRoom] = useState(null);
  const [people, setPeople] = useState(null);
  const [peopleNote, setPeopleNote] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [msg, setMsg] = useState(null);
  const [err, setErr] = useState(null);
  const [editingYear, setEditingYear] = useState(false);
  const [yearValue, setYearValue] = useState('');
  const { ask, node } = useConfirm();

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await getClassroom(id);
      setRoom(data);
      setYearValue(data?.school_year || '');
      try {
        const p = await getClassroomPeople(id);
        // The people read returns { data: [...] } (or a bare array); no
        // `students` envelope exists — anything else means no rows to render.
        const list = Array.isArray(p) ? p : Array.isArray(p?.data) ? p.data : null;
        setPeople(list);
        setPeopleNote(null);
      } catch (peopleErr) {
        setPeople(null);
        if (peopleErr instanceof ApiError && peopleErr.status === 403) {
          setPeopleNote('Roster rows are teacher-managed; this admin view shows the enrollment count. Teachers see full rosters in their workspace.');
        } else {
          setPeopleNote('Roster could not be loaded. The enrollment count above is authoritative.');
        }
      }
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) setError('Classroom not found. It may have been removed or the link is wrong. If this keeps happening, contact your school administrator.');
      else if (e instanceof ApiError && e.status === 403) setError(friendlyError(e));
      else setError(e);
      setRoom(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [id]);

  async function doRotate() {
    setMsg(null); setErr(null);
    try {
      const data = await resetClassroomKey(id);
      setRoom(data);
      setMsg('Join key rotated. The old key is retired forever — it is never reissued.');
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) setErr(friendlyError(e, { action: 'key' }));
      else if (e instanceof ApiError && e.status === 410) setErr(friendlyError(e, { action: 'key' }));
      else setErr(e);
    }
  }

  async function doArchive() {
    setMsg(null); setErr(null);
    try {
      const data = await archiveClassroom(id);
      setRoom(data);
      setMsg('Classroom archived. New students can\u2019t join and new work can\u2019t be added; history stays visible.');
    } catch (e) {
      setErr(e);
    }
  }

  async function doRestore() {
    setMsg(null); setErr(null);
    try {
      const data = await unarchiveClassroom(id);
      setRoom(data);
      setMsg('Classroom restored. Students can join again and work can continue.');
    } catch (e) {
      setErr(e);
    }
  }

  async function doYearSave() {
    setMsg(null); setErr(null);
    const v = yearValue.trim();
    if (!/^\d{4}-\d{4}$/.test(v)) {
      setErr('School year must look like 2025-2026, where the second year is the first plus one.');
      return;
    }
    try {
      const data = await updateClassroomSchoolYear(id, v);
      setRoom((prev) => ({ ...prev, school_year: data?.school_year || v }));
      setEditingYear(false);
      setMsg(`School year moved to ${data?.school_year || v}.`);
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) setErr(friendlyError(e));
      else if (e instanceof ApiError && e.status === 422) setErr(friendlyError(e));
      else setErr(e);
    }
  }

  // Joining is a teacher-owned control: this admin view shows the current
  // state read-only instead of offering a toggle that cannot save.

  if (loading) return <div className="admin-page"><SkeletonTable rows={6} /></div>;
  if (error) return <div className="admin-page"><div className="crumb"><Link to="/admin/classrooms">Classrooms</Link> / missing</div><ErrorNotice error={error} onRetry={load} /></div>;
  if (!room) return <div className="admin-page"><EmptyState title="Classroom not found" /></div>;

  const archived = Boolean(room.archived_at);
  const mastery = room.mastery || null;
  const mastered = mastery?.mastered_count ?? null;
  const totalM = mastery?.total_records ?? mastery?.total ?? null;

  return (
    <div className="admin-page">
      <div className="crumb"><Link to="/admin/classrooms">Classrooms</Link> / {room.name}</div>
      <div className="detail-head">
        <div className="toolbar-split">
          <div>
            <div className="kicker">{room.subject_name || room.suffix || 'Classroom'} · SY {room.school_year || '—'}{archived ? ' · Archived' : ''}</div>
            <h1>{room.name}</h1>
            <div className="detail-meta">
              Taught by {room.teacher_name || 'Assigned teacher'}{room.teacher_school_id ? ` (${room.teacher_school_id})` : ''} · Created {formatDateTime(room.created_at)}
            </div>
          </div>
          <div className="btn-row">
            {archived
              ? <button type="button" className="btn btn-primary" onClick={() => ask('Restore this classroom?', 'Students will be able to join again and work can continue once restored.', 'Restore', doRestore)}>Restore</button>
              : <button type="button" className="btn btn-danger" onClick={() => ask('Archive this classroom?', 'Archived classrooms stop accepting new students and new work. Existing history stays visible.', 'Archive', doArchive, true)}>Archive</button>}
          </div>
        </div>
      </div>

      {msg && <InlineAlert kind="ok">{msg}</InlineAlert>}
      {err ? <ErrorNotice error={err} action="key" onRetry={load} /> : null}

      <div className="grid-2 maxw-800">
        <Card title="Join key">
          <div className="key-display">{room.join_key_display || room.join_key || '—'}</div>
          <div className="btn-row mt-12">
            <button type="button" className="btn btn-sm" disabled={archived} onClick={() => ask('Rotate join key?', 'The current key stops working immediately and is never reissued.', 'Rotate key', doRotate, true)}>Rotate key</button>
            <span className={`pill ${room.is_join_enabled ? 'pill-green' : 'pill-amber'}`}>Joining {room.is_join_enabled ? 'open' : 'closed'}</span>
          </div>
          <div className="note-line">Joining is managed by the classroom teacher — ask them to open or close it. Rotating retires the old key forever{archived ? '; archived rooms stay closed to new students' : ''}. <Link to="/admin/help">How join keys work</Link>.</div>
        </Card>
        <Card title="Mastery summary">
          {totalM ? (
            <div className="kv">
              <div><div className="k">Mastered</div><div className="v">{mastered ?? 0} of {totalM} records</div></div>
              <div><div className="k">Students assessed</div><div className="v">{mastery?.total_students_assessed ?? '—'}</div></div>
              <div><div className="k">Rate</div><div className="v">{typeof mastery?.mastery_rate_percent === 'number' ? `${mastery.mastery_rate_percent}%` : '—'}</div></div>
            </div>
          ) : <div className="note-line">No recorded assessment results yet.</div>}
          <div className="section-head section-head--tight"><h3>School year</h3></div>
          {editingYear ? (
            <div className="btn-row btn-row--labeled">
              <div className="filter-field">
                <label htmlFor="cd-year">School year</label>
                <input id="cd-year" value={yearValue} onChange={(e) => setYearValue(e.target.value)} placeholder="2025-2026" className="ff-control" />
              </div>
              <button type="button" className="btn btn-sm btn-primary" onClick={doYearSave}>Save</button>
              <button type="button" className="btn btn-quiet" onClick={() => { setEditingYear(false); setYearValue(room.school_year || ''); }}>Cancel</button>
            </div>
          ) : (
            <div className="btn-row">
              <span className="mono">{room.school_year || '—'}</span>
              <button type="button" className="btn btn-sm" onClick={() => setEditingYear(true)}>Move year</button>
            </div>
          )}
        </Card>
      </div>

      <div className="section-head"><h2>Roster</h2><span className="count">{room.enrollment_count ?? 0} enrolled</span></div>
      {people && people.length > 0 ? (
        <Card>
          <table className="dtable">
            <thead><tr><th>Student</th><th>CompAss ID</th><th>Joined</th></tr></thead>
            <tbody>
              {people.map((s, i) => (
                <tr key={s.id ?? i}><td>{s.student_name || s.name || s.display_name || 'Enrolled student'}</td><td className="mono">{s.school_id || '—'}</td><td className="meta-faint">{formatDateTime(s.joined_at || s.created_at)}</td></tr>
              ))}
            </tbody>
          </table>
        </Card>
      ) : (
        <EmptyState
          title={(room.enrollment_count ?? 0) === 0 ? 'No students have joined this classroom yet' : `${room.enrollment_count ?? 0} enrolled`}
          hint={peopleNote || 'Individual roster rows are managed by the classroom teacher.'}
        />
      )}
      <div className="note-line">Move the year with the control above — teacher scope follows the classroom automatically. <Link to="/admin/help">Learn more</Link>.</div>
      {node}
    </div>
  );
}
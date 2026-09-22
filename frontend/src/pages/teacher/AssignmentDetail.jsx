import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useNavigate, useParams } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import { PageHead, InlineAlert, EmptyState, SkeletonTable, Modal } from '../../components/teacher/ui.jsx';
import FormField from '../../components/shared/FormField.jsx';
import {
  getTeacherAssignment,
  listAssignmentSubmissions,
  updateTeacherAssignment,
  deleteTeacherAssignment,
  confirmDeleteTeacherAssignment,
  downloadAssignmentAttachment,
  firstFieldError,
  formatDateTime,
  ApiError,
} from '../../components/teacher/teacherApi.js';

export default function AssignmentDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [assignment, setAssignment] = useState(null);
  const [submissions, setSubmissions] = useState([]);
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleteInfo, setDeleteInfo] = useState(null);
  const [confirmChecked, setConfirmChecked] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [eTitle, setETitle] = useState('');
  const [eDesc, setEDesc] = useState('');
  const [eDue, setEDue] = useState('');
  const [submissionsErr, setSubmissionsErr] = useState(null);
  const savingRef = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    setSubmissionsErr(null);
    try {
      const data = await getTeacherAssignment(id);
      setAssignment(data);
      try {
        const sRes = await listAssignmentSubmissions(id, { per_page: 100 });
        const fromTable = Array.isArray(sRes?.data) ? sRes.data : [];
        if (fromTable.length > 0 || !Array.isArray(data?.submissions)) {
          setSubmissions(fromTable);
        } else {
          setSubmissions(data.submissions);
        }
      } catch (e) {
        setSubmissionsErr(e);
        setSubmissions(Array.isArray(data?.submissions) ? data.submissions : []);
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setError('Assignment not found. If this keeps happening, contact your school administrator.');
      else setError(err);
      setAssignment(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [id]);

  function openEdit() {
    setETitle(assignment.title || '');
    setEDesc(assignment.description || '');
    setEDue(assignment.due_date ? String(assignment.due_date).slice(0, 10) : '');
    setFormError(null);
    setEditOpen(true);
  }

  async function handleEditSave(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    savingRef.current = true;
    setSaving(true);
    try {
      await updateTeacherAssignment(id, {
        title: eTitle.trim() || undefined,
        description: eDesc,
        due_date: eDue ? (eDue.length === 10 ? `${eDue}T23:59:00` : eDue) : undefined,
      });
      setEditOpen(false);
      setNotice('Assignment updated.');
      await load();
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleDeleteAsk() {
    if (savingRef.current || saving) return;
    setFormError(null);
    setConfirmChecked(false);
    savingRef.current = true;
    setSaving(true);
    try {
      const res = await deleteTeacherAssignment(id);
      if (res?.confirmation_required) {
        setDeleteInfo(res);
        setDeleteOpen(true);
      } else {
        navigate(assignment?.classroom_id ? `/teacher/classrooms/${assignment.classroom_id}?tab=classwork` : '/teacher/classrooms');
      }
    } catch (err) {
      setFormError(null);
      setError(err);
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleConfirmDelete() {
    if (savingRef.current || saving) return;
    savingRef.current = true;
    setSaving(true);
    try {
      await confirmDeleteTeacherAssignment(id);
      navigate(assignment?.classroom_id ? `/teacher/classrooms/${assignment.classroom_id}?tab=classwork` : '/teacher/classrooms');
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  if (loading) return <div className="teacher-page"><SkeletonTable rows={6} /></div>;
  if (error && !assignment) return <div className="teacher-page"><ErrorNotice error={error} onRetry={() => window.location.reload()} /></div>;
  if (!assignment) return <div className="teacher-page"><EmptyState title="Assignment not found" /></div>;

  const submittedCount = submissions.filter((s) => s.submitted_at || s.student_name).length || submissions.length;
  const attachments = Array.isArray(assignment.attachments) ? assignment.attachments : [];
  const backTo = assignment.classroom_id ? `/teacher/classrooms/${assignment.classroom_id}?tab=classwork` : '/teacher/classrooms';

  return (
    <div className="teacher-page">
      <div className="crumb"><Link to={backTo}>{assignment.classroom_id ? 'Classroom' : 'Classrooms'}</Link> / Classwork / {assignment.title}</div>
      <PageHead
        title={assignment.title}
        sub={`Assignment · Due ${formatDateTime(assignment.due_date)}`}
        actions={
          <>
            <button type="button" className="btn btn-sm" onClick={openEdit}>Edit</button>
            <button type="button" className="btn btn-sm btn-danger" disabled={saving} onClick={handleDeleteAsk}>Delete</button>
          </>
        }
      />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {notice && <InlineAlert kind="ok">{notice}</InlineAlert>}
      <p className="lede">{assignment.description || 'No description.'}</p>
      <div className="ff-gap">
        {attachments.map((f) => (
          <button key={f.id} type="button" className="file-chip" onClick={() => downloadAssignmentAttachment(id, f.id).catch(() => setError('Could not download that file.'))}>
            ↓ {f.original_filename}
          </button>
        ))}
      </div>

      <div className="section-head"><h2>Submissions</h2><span className="count">{submittedCount} of {assignment.enrolled_students ?? submissions.length} submitted</span></div>
      {submissionsErr ? <ErrorNotice error={submissionsErr} onRetry={load} /> : null}
      {submissionsErr && submissions.length === 0 ? null : submissions.length === 0 ? (
        <EmptyState title="No submissions yet" hint="Students have not submitted this assignment." />
      ) : (
        <table className="data-table">
          <thead><tr><th>Student</th><th>Status</th><th>Submitted</th><th>Feedback</th></tr></thead>
          <tbody>
            {submissions.map((s) => (
              <tr key={s.id}>
                <td><Link to={`/teacher/submissions/${s.id}`}>{s.student_name || `Student ${s.student_id ?? ''}`}</Link></td>
                <td>
                  {s.submitted_at
                    ? (s.is_late ? <span className="pill pill-amber">Late</span> : <span className="pill pill-green">On time</span>)
                    : <span className="pill pill-neutral">Not submitted</span>}
                </td>
                <td className="meta-faint nowrap">{s.submitted_at ? formatDateTime(s.submitted_at) : '—'}</td>
                <td>{s.has_feedback ? <span className="pill pill-blue">Given</span> : <span className="pill pill-neutral">None</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <div className="note-line">Feedback only — assignments carry no numeric score.</div>

      {editOpen && (
        <Modal
          title="Edit assignment"
          onClose={() => (!saving ? setEditOpen(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setEditOpen(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleEditSave}>{saving ? 'Saving…' : 'Save changes'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleEditSave}>
            <FormField label="Title">
              <input value={eTitle} onChange={(e) => setETitle(e.target.value)} maxLength={255} />
            </FormField>
            <FormField label="Description">
              <textarea value={eDesc} onChange={(e) => setEDesc(e.target.value)} />
            </FormField>
            <FormField label="Due date">
              <input type="date" value={eDue} onChange={(e) => setEDue(e.target.value)} />
            </FormField>
          </form>
        </Modal>
      )}

      {deleteOpen && (
        <Modal
          title={`Delete "${assignment.title}"?`}
          onClose={() => (!saving ? setDeleteOpen(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setDeleteOpen(false)}>Cancel</button>
              <button type="button" className="btn btn-danger" disabled={saving || !confirmChecked} onClick={handleConfirmDelete}>{saving ? 'Deleting…' : 'Delete assignment'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <div className="block-line">This assignment has {deleteInfo?.submission_count ?? submissions.length} submissions. Deleting it permanently removes those submissions and any feedback.</div>
          <label className="checkline">
            <input type="checkbox" checked={confirmChecked} onChange={(e) => setConfirmChecked(e.target.checked)} />
            I understand this deletes {deleteInfo?.submission_count ?? submissions.length} submissions and cannot be undone.
          </label>
        </Modal>
      )}
    </div>
  );
}
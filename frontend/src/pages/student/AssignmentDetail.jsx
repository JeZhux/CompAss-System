import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useParams } from 'react-router-dom';
import '../../components/student/student.css';
import { InlineAlert, EmptyState, SkeletonTable, StatusPill } from '../../components/student/ui.jsx';
import {
  getStudentAssignment,
  getAssignmentFeedback,
  submitAssignment,
  downloadAssignmentAttachment,
  downloadSubmissionFile,
  formatDateTime,
  ApiError,
} from '../../components/student/studentApi.js';

const ACCEPT = '.pdf,.docx,.pptx,.xlsx,.jpg,.jpeg,.png,.zip';
const MAX_FILE_BYTES = 15 * 1024 * 1024;

export default function AssignmentDetail() {
  const { id } = useParams();
  const fileRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [assignment, setAssignment] = useState(null);
  const [feedback, setFeedback] = useState(null);
  const [files, setFiles] = useState([]);
  const [drag, setDrag] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submittedOnce, setSubmittedOnce] = useState(false);
  const [formError, setFormError] = useState(null);
  const [downloading, setDownloading] = useState(null);
  const submitRef = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await getStudentAssignment(id);
      setAssignment(data);
      try {
        const fb = await getAssignmentFeedback(id);
        setFeedback(fb && fb.has_feedback ? fb : (fb?.has_feedback === false ? null : fb));
      } catch (err) {
        // 404 here means "not submitted yet" — not an error state.
        if (!(err instanceof ApiError && err.status === 404)) {
          setFeedback(null);
        } else {
          setFeedback(null);
        }
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setError('Assignment not found. If this keeps happening, contact your school administrator.');
      else if (err instanceof ApiError && err.status === 403) setError('You are not enrolled in the classroom for this assignment.');
      else setError(err);
      setAssignment(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [id]);

  function pickFiles(list) {
    const arr = Array.from(list || []);
    if (arr.length + files.length > 5) {
      setFormError('A maximum of 5 files can be submitted.');
      return;
    }
    setFormError(null);
    setFiles((prev) => [...prev, ...arr].slice(0, 5));
  }

  async function handleSubmit(e) {
    e?.preventDefault();
    if (submitRef.current || submitting || submittedOnce) return;
    if (files.length === 0) {
      setFormError('Add at least one file before submitting.');
      return;
    }
    const big = (files || []).find((f) => Number(f?.size) > MAX_FILE_BYTES);
    if (big) {
      setFormError(`"${big.name || 'File'}" exceeds 15 MB. Remove it or choose a smaller file.`);
      return;
    }
    setFormError(null);
    submitRef.current = true;
    setSubmitting(true);
    try {
      await submitAssignment(id, files);
      setSubmittedOnce(true);
      setFiles([]);
      await load();
    } catch (err) {
      if (err instanceof ApiError && err.code === 'ALREADY_SUBMITTED') {
        setSubmittedOnce(true);
        setFormError('You have already submitted this assignment.');
        await load();
      } else {
        setFormError(friendlyError(err));
      }
    } finally {
      submitRef.current = false;
      setSubmitting(false);
    }
  }

  async function handleDownloadAttachment(a) {
    setDownloading(`a-${a.id}`);
    try {
      await downloadAssignmentAttachment(id, a.id);
    } catch (err) {
      setFormError(friendlyError(err, { fallback: 'Could not download the file. Please try again.' }));
    } finally {
      setDownloading(null);
    }
  }

  async function handleDownloadSubmissionFile(submissionId, f) {
    setDownloading(`s-${f.id}`);
    try {
      await downloadSubmissionFile(submissionId, f.id);
    } catch (err) {
      setFormError(friendlyError(err, { fallback: 'Could not download the file. Please try again.' }));
    } finally {
      setDownloading(null);
    }
  }

  if (loading) return <div className="student-page"><SkeletonTable rows={5} /></div>;
  if (error) {
    return (
      <div className="student-page">
        <div className="crumb"><Link to="/student/classrooms">My Classrooms</Link> / missing</div>
        <ErrorNotice error={error} onRetry={() => window.location.reload()} />
      </div>
    );
  }
  if (!assignment) return <div className="student-page"><InlineAlert kind="error">Assignment not found. If this keeps happening, contact your school administrator.</InlineAlert></div>;

  const existing = assignment.existing_submission || null;
  const submitted = Boolean(existing) || submittedOnce;
  const classroomId = assignment.classroom_id;

  return (
    <div className="student-page">
      <div className="crumb">
        {classroomId ? <><Link to={`/student/classrooms/${classroomId}?tab=classwork`}>Classroom</Link> / Classwork / </> : null}
        {assignment.title}
      </div>
      <div className="detail-head">
        <div className="kicker">Assignment · {assignment.due_date ? `Due ${formatDateTime(assignment.due_date)}` : 'No due date'}</div>
        <h1>{assignment.title}</h1>
      </div>
      <div className="two-col">
        <div>
          <p className="lede prewrap">{assignment.description || 'No description provided.'}</p>
          {(assignment.attachments || []).length > 0 && (
            <>
              <div className="section-head"><h2>Attached by your teacher</h2></div>
              <div>
                {(assignment.attachments || []).map((f) => (
                  <button
                    key={f.id}
                    type="button"
                    className="file-chip"
                    disabled={downloading === `a-${f.id}`}
                    onClick={() => handleDownloadAttachment(f)}
                  >
                    {f.original_filename || `Attachment ${f.id}`}
                  </button>
                ))}
              </div>
            </>
          )}
        </div>
        <div className="card">
          {!submitted ? (
            <>
              <h3 className="card-title-xs">Submit your work</h3>
              {formError && <InlineAlert kind="error">{formError}</InlineAlert>}
              <div
                className={drag ? 'upload-box drag' : 'upload-box'}
                role="button"
                tabIndex={0}
                aria-label="Drop files here or click to browse"
                onClick={() => fileRef.current?.click()}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileRef.current?.click(); } }}
                onDragOver={(e) => { e.preventDefault(); setDrag(true); }}
                onDragLeave={() => setDrag(false)}
                onDrop={(e) => { e.preventDefault(); setDrag(false); pickFiles(e.dataTransfer?.files); }}
              >
                <b>Drop files here</b> or click to browse
                {files.length > 0 && <div className="mt-8">{files.length} file{files.length === 1 ? '' : 's'} selected</div>}
              </div>
              <input
                ref={fileRef}
                type="file"
                multiple
                accept={ACCEPT}
                className="is-hidden"
                onChange={(e) => { pickFiles(e.target.files); e.target.value = ''; }}
              />
              {files.length > 0 && (
                <div className="mb-12">
                  {files.map((f, i) => (
                    <div key={`${f.name}-${i}`} className="file-row">
                      <span>{f.name}</span>
                      <button type="button" className="btn btn-sm btn-quiet" onClick={() => setFiles((prev) => prev.filter((_, j) => j !== i))}>Remove</button>
                    </div>
                  ))}
                </div>
              )}
              <button type="button" className="btn btn-primary btn-block" onClick={handleSubmit} disabled={submitting || submittedOnce}>
                {submitting ? 'Submitting…' : 'Submit assignment'}
              </button>
              <div className="note-line">One submission per assignment. PDF, DOCX, PPTX, XLSX, JPG, PNG, ZIP up to 15 MB each, max 5 files.</div>
            </>
          ) : (
            <>
              <h3 className="card-title-xs">Your submission</h3>
              <div className="submit-meta">
                Submitted {existing?.submitted_at ? formatDateTime(existing.submitted_at) : 'just now'}{' '}
                {existing?.is_late ? <StatusPill tone="amber">Late</StatusPill> : <StatusPill tone="green">On time</StatusPill>}
              </div>
              <div>
                {(existing?.files || []).map((f) => (
                  <button
                    key={f.id}
                    type="button"
                    className="file-chip"
                    disabled={downloading === `s-${f.id}`}
                    onClick={() => handleDownloadSubmissionFile(existing.id, f)}
                  >
                    {f.original_filename || `File ${f.id}`}
                  </button>
                ))}
              </div>
              {feedback?.has_feedback || feedback?.feedback ? (
                <div className="feedback-box">
                  <div className="who">Teacher feedback</div>
                  <p>{feedback.feedback || feedback.feedback_text || 'Feedback received.'}</p>
                </div>
              ) : (
                <div className="note-line">Your teacher hasn&apos;t left feedback yet.</div>
              )}
              {formError && <InlineAlert kind="error">{formError}</InlineAlert>}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
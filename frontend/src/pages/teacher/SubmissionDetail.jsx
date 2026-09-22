import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useParams } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import Card from '../../components/shared/Card.jsx';
import { InlineAlert, EmptyState, SkeletonTable } from '../../components/teacher/ui.jsx';
import {
  getTeacherSubmission,
  storeAssignmentFeedback,
  downloadSubmissionFile,
  firstFieldError,
  formatDateTime,
  ApiError,
} from '../../components/teacher/teacherApi.js';

export default function SubmissionDetail() {
  const { submissionId } = useParams();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [submission, setSubmission] = useState(null);
  const [feedback, setFeedback] = useState('');
  const [existing, setExisting] = useState(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const [notice, setNotice] = useState(null);
  const savingRef = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await getTeacherSubmission(submissionId);
      setSubmission(data);
      // Feedback text is not part of the submission payload; keep the box empty
      // until the teacher writes. A prior feedback existence is unknown here.
      setExisting(null);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setError('Submission not found. If this keeps happening, contact your school administrator.');
      else setError(err);
      setSubmission(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [submissionId]);

  async function handleSave() {
    if (savingRef.current || saving) return;
    setFormError(null);
    setNotice(null);
    if (!feedback.trim()) {
      setFormError('Feedback is required before sending.');
      return;
    }
    savingRef.current = true;
    setSaving(true);
    try {
      await storeAssignmentFeedback(submissionId, feedback.trim());
      setExisting({ text: feedback.trim() });
      setNotice('Feedback recorded.');
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  if (loading) return <div className="teacher-page"><SkeletonTable rows={5} /></div>;
  if (error && !submission) return <div className="teacher-page"><ErrorNotice error={error} onRetry={() => window.location.reload()} /></div>;
  if (!submission) return <div className="teacher-page"><EmptyState title="Submission not found" /></div>;

  const files = Array.isArray(submission.files) ? submission.files : [];
  const submitted = Boolean(submission.submitted_at);

  return (
    <div className="teacher-page">
      <div className="crumb"><Link to={`/teacher/assignments/${submission.assignment_id}`}>Assignment</Link> / {submission.student_name}</div>
      <div className="detail-head"><div className="kicker">Submission</div><h1>{submission.student_name}</h1></div>
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {notice && <InlineAlert kind="ok">{notice}</InlineAlert>}
      {!submitted ? (
        <EmptyState title="No submission from this student yet" hint="The feedback box unlocks once files arrive." />
      ) : (
        <div className="two-col">
          <div>
            <Card title="Files">
              <div className="submit-meta">
                Submitted {formatDateTime(submission.submitted_at)}{' '}
                {submission.is_late ? <span className="pill pill-amber ml-6">Late</span> : <span className="pill pill-green ml-6">On time</span>}
              </div>
              {files.length === 0 ? (
                <div className="note-line">No files attached to this submission.</div>
              ) : (
                <div>
                  {files.map((f) => (
                    <button key={f.id} type="button" className="file-chip" onClick={() => downloadSubmissionFile(submissionId, f.id).catch(() => setError('Could not download that file.'))}>
                      ↓ {f.original_filename}
                    </button>
                  ))}
                </div>
              )}
            </Card>
          </div>
          <div>
            <Card title="Feedback">
              {formError && <InlineAlert kind="error">{formError}</InlineAlert>}
              <textarea
                value={feedback}
                onChange={(e) => setFeedback(e.target.value)}
                placeholder="Write specific, actionable feedback…"
                aria-label="Feedback"
                className="ff-control feedback-input"
              />
              <button type="button" className="btn btn-primary btn-sm btn-block mt-10" disabled={saving} onClick={handleSave}>
                {saving ? 'Saving…' : existing ? 'Save changes' : 'Send feedback'}
              </button>
              <div className="note-line">Feedback only — assignments carry no numeric score.</div>
            </Card>
          </div>
        </div>
      )}
    </div>
  );
}
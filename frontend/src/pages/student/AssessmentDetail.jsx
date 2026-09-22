import { useEffect, useRef, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useNavigate, useParams } from 'react-router-dom';
import '../../components/student/student.css';
import { InlineAlert, SkeletonTable, StatusPill } from '../../components/student/ui.jsx';
import {
  getStudentAssessment,
  getAssessmentResults,
  startAssessment,
  formatDateTime,
  ApiError,
} from '../../components/student/studentApi.js';

export function attemptStorageKey(assessmentId) {
  return `compass:student:attempt:${assessmentId}`;
}

export function readStoredAttempt(assessmentId) {
  try {
    const raw = localStorage.getItem(attemptStorageKey(assessmentId));
    if (!raw) return null;
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function writeStoredAttempt(assessmentId, payload) {
  try {
    localStorage.setItem(attemptStorageKey(assessmentId), JSON.stringify(payload));
  } catch {
    /* storage is best-effort */
  }
}

export function clearStoredAttempt(assessmentId) {
  try {
    localStorage.removeItem(attemptStorageKey(assessmentId));
  } catch {
    /* noop */
  }
}

export default function AssessmentDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [assessment, setAssessment] = useState(null);
  const [resultsReleased, setResultsReleased] = useState(false);
  const [starting, setStarting] = useState(false);
  const startingRef = useRef(false);
  const [actionError, setActionError] = useState(null);
  const [storedAttempt, setStoredAttempt] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const data = await getStudentAssessment(id);
        if (cancelled) return;
        setAssessment(data);
        setStoredAttempt(readStoredAttempt(id));
        try {
          await getAssessmentResults(id);
          if (!cancelled) setResultsReleased(true);
        } catch (err) {
          // RESULTS_NOT_RELEASED / 404 means not yet released — pre-start state.
          if (!cancelled && !(err instanceof ApiError)) setResultsReleased(false);
        }
      } catch (err) {
        if (!cancelled) {
          if (err instanceof ApiError && err.status === 404) setError('Assessment not found. If this keeps happening, contact your school administrator.');
          else if (err instanceof ApiError && err.status === 403) setError('You are not enrolled in the classroom for this assessment.');
          else if (err instanceof ApiError && err.status === 410) setError('This classroom has been archived.');
          else setError(err);
          setAssessment(null);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [id]);

  async function handleStart() {
    if (startingRef.current || starting) return;
    setActionError(null);
    startingRef.current = true;
    setStarting(true);
    try {
      const data = await startAssessment(id);
      writeStoredAttempt(id, { ...data, startedAt: new Date().toISOString() });
      navigate(`/student/assessments/${id}/take`);
    } catch (err) {
      if (err instanceof ApiError && err.code === 'STUDENT_HAS_ACTIVE_ATTEMPT') {
        const cached = readStoredAttempt(id);
        if (cached?.attempt_id) {
          setActionError('An attempt is already in progress. Resume it below.');
          setStoredAttempt(cached);
        } else {
          setActionError('An attempt is already in progress on another device or browser — continue it there, or ask your teacher for a resubmission');
          setStoredAttempt(null);
        }
      } else if (err instanceof ApiError && err.code === 'ALREADY_SUBMITTED') {
        setActionError('Submitted — waiting on your teacher to release results.');
      } else {
        setActionError(err);
      }
    } finally {
      startingRef.current = false;
      setStarting(false);
    }
  }

  if (loading) return <div className="student-page"><SkeletonTable rows={4} /></div>;
  if (error) {
    return (
      <div className="student-page">
        <div className="crumb"><Link to="/student/classrooms">My Classrooms</Link> / missing</div>
        <ErrorNotice error={error} onRetry={() => window.location.reload()} />
      </div>
    );
  }
  if (!assessment) return <div className="student-page"><InlineAlert kind="error">Assessment not found. If this keeps happening, contact your school administrator.</InlineAlert></div>;

  const recorded = (assessment.type || 'Recorded') === 'Recorded';
  const windowText = assessment.availability_starts_at || assessment.availability_ends_at
    ? `${assessment.availability_starts_at ? formatDateTime(assessment.availability_starts_at) : '—'} – ${assessment.availability_ends_at ? formatDateTime(assessment.availability_ends_at) : '—'}`
    : null;

  let action = null;
  if (resultsReleased) {
    action = <button type="button" className="btn btn-primary" onClick={() => navigate(`/student/assessments/${id}/results`)}>View results</button>;
  } else if (storedAttempt?.attempt_id) {
    action = (
      <div className="btn-row">
        <button type="button" className="btn btn-primary" onClick={() => navigate(`/student/assessments/${id}/take`)}>Resume</button>
        <button type="button" className="btn" onClick={handleStart} disabled={starting}>{starting ? 'Starting…' : 'Restart attempt'}</button>
      </div>
    );
  } else {
    action = <button type="button" className="btn btn-primary" onClick={handleStart} disabled={starting}>{starting ? 'Starting…' : 'Start assessment'}</button>;
  }

  return (
    <div className="student-page">
      <div className="crumb"><Link to="/student/classrooms">My Classrooms</Link> / Classwork / {assessment.title}</div>
      <div className="detail-head">
        <div className="kicker">
          Assessment · <StatusPill tone={recorded ? 'green' : 'neutral'}>{recorded ? 'Recorded' : 'Unrecorded'}</StatusPill>
        </div>
        <h1>{assessment.title}</h1>
        {assessment.description && <p className="page-sub page-sub--spaced">{assessment.description}</p>}
      </div>
      {!recorded && <div className="note-line mb-16">Practice only — this won&apos;t affect your mastery.</div>}
      {actionError ? <ErrorNotice error={actionError} onRetry={() => window.location.reload()} /> : null}
      {!resultsReleased && !storedAttempt?.attempt_id && (
        <InlineAlert kind="note">Starting the timer begins your attempt. Answer each item, then submit once — unanswered items are scored blank.</InlineAlert>
      )}
      <div className="card maxw-460">
        <div className="kv-stack mb-16">
          <div>Items: {assessment.item_count ?? '—'}</div>
          {assessment.time_limit ? <div>Time limit: {assessment.time_limit} minutes once started</div> : <div>No time limit</div>}
          {windowText ? <div>Available: {windowText}</div> : null}
        </div>
        {action}
        {!resultsReleased && !storedAttempt?.attempt_id && (
          <div className="note-line">Submitted — waiting on your teacher to release results will appear here after you submit.</div>
        )}
      </div>
    </div>
  );
}
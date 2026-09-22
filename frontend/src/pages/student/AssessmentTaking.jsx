import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useNavigate, useParams } from 'react-router-dom';
import '../../components/student/student.css';
import { InlineAlert, SkeletonTable, Modal } from '../../components/student/ui.jsx';
import {
  startAssessment,
  autoSaveAssessment,
  submitAssessment,
  ApiError,
} from '../../components/student/studentApi.js';
import { readStoredAttempt, writeStoredAttempt, clearStoredAttempt } from './AssessmentDetail.jsx';

function formatClock(totalSeconds) {
  const s = Math.max(0, Math.floor(totalSeconds));
  const m = Math.floor(s / 60);
  const r = s % 60;
  return `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')} remaining`;
}

// Stored attempts are a convenience cache only: render one as a session
// only when its shape is intact (attempt id present, items array intact,
// and — when the payload carries one — the assessment id matches this
// page). Anything else is discarded and a fresh attempt starts. The server
// stays the source of truth on submit either way — never the client clock
// or the cached payload.
function isUsableStoredAttempt(stored, assessmentId) {
  if (!stored || typeof stored !== 'object') return false;
  if (!stored.attempt_id) return false;
  if (!Array.isArray(stored.items)) return false;
  const tagged = stored.assessment_id ?? stored.assessmentId;
  if (tagged !== undefined && tagged !== null && String(tagged) !== String(assessmentId)) return false;
  return true;
}

export default function AssessmentTaking() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [session, setSession] = useState(null);
  const [responses, setResponses] = useState({});
  const [saveState, setSaveState] = useState('saved');
  const [saveError, setSaveError] = useState(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [timeLeft, setTimeLeft] = useState(null);
  const [expired, setExpired] = useState(false);
  const saveTimer = useRef(null);
  const deadlineRef = useRef(null);
  const submitRef = useRef(false);

  const loadSession = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const stored = readStoredAttempt(id);
      if (isUsableStoredAttempt(stored, id)) {
        setSession(stored);
        const prefill = stored.responses && typeof stored.responses === 'object' ? stored.responses : {};
        const clean = {};
        for (const [k, v] of Object.entries(prefill)) clean[String(k)] = String(v);
        setResponses(clean);
        if (stored.time_limit && stored.startedAt) {
          const deadline = new Date(stored.startedAt).getTime() + Number(stored.time_limit) * 60 * 1000;
          deadlineRef.current = deadline;
          setTimeLeft(Math.max(0, Math.floor((deadline - Date.now()) / 1000)));
        }
        return;
      }
      // Corrupt or foreign cached payloads are discarded, never rendered.
      if (stored) clearStoredAttempt(id);
      const data = await startAssessment(id);
      const withStart = { ...data, startedAt: new Date().toISOString() };
      writeStoredAttempt(id, withStart);
      setSession(withStart);
      const prefill = data.responses && typeof data.responses === 'object' ? data.responses : {};
      const clean = {};
      for (const [k, v] of Object.entries(prefill)) clean[String(k)] = String(v);
      setResponses(clean);
      if (data.time_limit) {
        const deadline = Date.now() + Number(data.time_limit) * 60 * 1000;
        deadlineRef.current = deadline;
        setTimeLeft(Number(data.time_limit) * 60);
      }
    } catch (err) {
      if (err instanceof ApiError && err.code === 'STUDENT_HAS_ACTIVE_ATTEMPT') {
        const stored = readStoredAttempt(id);
        if (isUsableStoredAttempt(stored, id)) {
          setSession(stored);
          setError(null);
        } else {
          if (stored) clearStoredAttempt(id);
          setError('An attempt is already in progress for this assessment. Resume it from the assessment page.');
        }
      } else if (err instanceof ApiError && err.code === 'ALREADY_SUBMITTED') {
        setError('This assessment has already been submitted.');
      } else {
        setError(err);
      }
      setSession(null);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { loadSession(); }, [loadSession]);

  // Timer countdown: one deadline-driven subscription per attempt. Deps stay on
  // the attempt id only — timeLeft ticks must not tear down and rebuild the
  // interval (the old `timeLeft === null` dep did exactly that on transitions).
  useEffect(() => {
    if (timeLeft === null || timeLeft <= 0) return;
    const t = setInterval(() => {
      if (!deadlineRef.current) return;
      const left = Math.floor((deadlineRef.current - Date.now()) / 1000);
      setTimeLeft(Math.max(0, left));
      if (left <= 0) {
        clearInterval(t);
        setExpired(true);
      }
    }, 1000);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session?.attempt_id]);

  // Debounced auto-save on every response change.
  useEffect(() => {
    if (!session?.attempt_id) return;
    if (Object.keys(responses).length === 0) return;
    setSaveState('saving');
    if (saveTimer.current) clearTimeout(saveTimer.current);
    saveTimer.current = setTimeout(async () => {
      try {
        await autoSaveAssessment(id, responses);
        setSaveState('saved');
        setSaveError(null);
      } catch (err) {
        if (err instanceof ApiError && err.code === 'TIME_LIMIT_EXPIRED') {
          setExpired(true);
          setSaveState('error');
          setSaveError("Time's up — the system marks this attempt as expired and your answers so far are kept. Unanswered items are left blank for your teacher to review.");
        } else {
          setSaveState('error');
          setSaveError(friendlyError(err, { fallback: 'Could not save. Keep working — your answers are still on this page.' }));
        }
      }
    }, 1200);
    return () => { if (saveTimer.current) clearTimeout(saveTimer.current); };
  }, [responses, id, session?.attempt_id]);

  function setAnswer(itemId, value) {
    setResponses((prev) => ({ ...prev, [String(itemId)]: String(value) }));
  }

  const items = useMemo(() => (Array.isArray(session?.items) ? session.items : []), [session]);
  const shellRef = useRef(null);

  // Single scroll chain (RED-12): the page scrolls, the footer stays sticky
  // with overscroll-behavior: contain. When keyboard focus lands on an input,
  // scroll it into view above the sticky footer so it is never obscured.
  useEffect(() => {
    const shell = shellRef.current;
    if (!shell) return undefined;
    function onFocusIn(e) {
      const t = e.target;
      if (!(t instanceof HTMLElement)) return;
      if (!/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
      try {
        t.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      } catch {
        try {
          t.scrollIntoView();
        } catch {
          /* best-effort */
        }
      }
    }
    shell.addEventListener('focusin', onFocusIn);
    return () => shell.removeEventListener('focusin', onFocusIn);
  }, [session?.attempt_id]);

  async function handleSubmit() {
    if (submitRef.current || submitting) return;
    submitRef.current = true;
    setSubmitting(true);
    try {
      await submitAssessment(id, responses);
      clearStoredAttempt(id);
      navigate(`/student/assessments/${id}`);
    } catch (err) {
      if (err instanceof ApiError && err.code === 'ALREADY_SUBMITTED') {
        clearStoredAttempt(id);
        navigate(`/student/assessments/${id}`);
      } else {
        setSaveError(friendlyError(err));
        setConfirmOpen(false);
      }
    } finally {
      submitRef.current = false;
      setSubmitting(false);
    }
  }

  if (loading) return <div className="student-page"><SkeletonTable rows={5} /></div>;
  if (error || !session) {
    return (
      <div className="student-page">
        <div className="crumb"><Link to={`/student/assessments/${id}`}>Assessment</Link> / taking</div>
        <ErrorNotice error={error} fallback="Could not load this attempt. If this keeps happening, contact your school administrator." onRetry={loadSession} />
      </div>
    );
  }

  const urgent = timeLeft !== null && timeLeft <= 300;

  return (
    <div className="student-page">
      <div className="taking-shell" ref={shellRef}>
        <div className="kicker">{session.title || 'Assessment'}</div>
        <h1 className="taking-title">Answer each item below</h1>
        {expired && <div className="warn-line">Time&apos;s up — the system marks this attempt as expired and your answers so far are kept. Unanswered items are left blank for your teacher to review.</div>}
        {saveError && <InlineAlert kind="error">{saveError}</InlineAlert>}
        {items.map((it, i) => (
          <div key={it.id} className="item-block">
            <div className="qmeta">Item {i + 1} of {items.length} · {it.max_points} pt{Number(it.max_points) === 1 ? '' : 's'}</div>
            <div className="prompt">{it.prompt}</div>
            {it.item_type === 'multiple_choice' ? (
              <div role="radiogroup" aria-label={`Item ${i + 1} choices`}>
                {(it.options || it.choices || []).length > 0 ? (
                  (it.options || it.choices).map((opt) => {
                    const value = typeof opt === 'string' ? opt : (opt.value ?? opt.label ?? String(opt));
                    return (
                      <label key={value} className="option-row">
                        <input
                          type="radio"
                          name={`q-${it.id}`}
                          checked={responses[String(it.id)] === String(value)}
                          onChange={() => setAnswer(it.id, value)}
                        />
                        {value}
                      </label>
                    );
                  })
                ) : (
                  <input
                    type="text"
                    className="essay essay--short"
                    placeholder="Type your answer…"
                    value={responses[String(it.id)] || ''}
                    onChange={(e) => setAnswer(it.id, e.target.value)}
                    aria-label={`Answer for item ${i + 1}`}
                  />
                )}
              </div>
            ) : it.item_type === 'true_false' ? (
              <div role="radiogroup" aria-label={`Item ${i + 1} true or false`}>
                {['True', 'False'].map((opt) => (
                  <label key={opt} className="option-row">
                    <input
                      type="radio"
                      name={`q-${it.id}`}
                      checked={String(responses[String(it.id)] || '').toLowerCase() === opt.toLowerCase()}
                      onChange={() => setAnswer(it.id, opt)}
                    />
                    {opt}
                  </label>
                ))}
              </div>
            ) : (
              <textarea
                className="essay"
                placeholder="Type your answer…"
                value={responses[String(it.id)] || ''}
                onChange={(e) => setAnswer(it.id, e.target.value)}
                aria-label={`Answer for item ${i + 1}`}
              />
            )}
          </div>
        ))}
      </div>
      <div className="taking-footer">
        <div className={saveState === 'saved' ? 'save-status' : saveState === 'saving' ? 'save-status saving' : 'save-status error'}>
          <span className="dot" aria-hidden="true" />
          {saveState === 'saved' ? 'Saved just now' : saveState === 'saving' ? 'Saving…' : 'Save failed — retrying'}
        </div>
        <div className={urgent ? 'timer urgent' : 'timer'} role="status" aria-live="polite">{timeLeft !== null ? formatClock(timeLeft) : ''}</div>
        <button type="button" className="btn btn-primary" onClick={() => setConfirmOpen(true)}>Submit assessment</button>
      </div>

      {confirmOpen && (
        <Modal
          title="Submit assessment?"
          sub="Once submitted you can't change your answers. Unanswered items will be scored blank."
          onClose={() => { if (!submitting) setConfirmOpen(false); }}
          actions={(
            <>
              <button type="button" className="btn btn-quiet" onClick={() => setConfirmOpen(false)} disabled={submitting}>Keep working</button>
              <button type="button" className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>{submitting ? 'Submitting…' : 'Submit'}</button>
            </>
          )}
        />
      )}
    </div>
  );
}
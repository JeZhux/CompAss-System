import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useNavigate, useParams } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import { InlineAlert, SkeletonTable } from '../../components/teacher/ui.jsx';
import FormField from '../../components/shared/FormField.jsx';
import {
  getTeacherAssessment,
  getPendingItems,
  storeManualGrade,
  firstFieldError,
  ApiError,
} from '../../components/teacher/teacherApi.js';

export default function ScoreSubmission() {
  const { id, submissionId } = useParams();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [assessment, setAssessment] = useState(null);
  const [pending, setPending] = useState(null);
  const [scores, setScores] = useState({});
  const [feedbacks, setFeedbacks] = useState({});
  const [isDraft, setIsDraft] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const [notice, setNotice] = useState(null);
  // Pending-list failures are tracked separately: only a genuine empty list
  // shows the empty state, never a failed fetch.
  const [pendingErr, setPendingErr] = useState(null);
  const cancelledRef = useRef(false);
  const savingRef = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    setPendingErr(null);
    try {
      const a = await getTeacherAssessment(id);
      if (cancelledRef.current) return;
      setAssessment(a);
      try {
        const p = await getPendingItems(submissionId);
        if (cancelledRef.current) return;
        setPending(p);
        const init = {};
        for (const it of p?.pending_items || []) init[it.item_id] = '';
        setScores(init);
      } catch (e) {
        if (cancelledRef.current) return;
        setPendingErr(e);
        setPending(null);
        setScores({});
      }
    } catch (err) {
      if (cancelledRef.current) return;
      if (err instanceof ApiError && err.status === 404) setError('Submission not found. If this keeps happening, contact your school administrator.');
      else setError(err);
    } finally {
      if (!cancelledRef.current) setLoading(false);
    }
  }

  useEffect(() => {
    cancelledRef.current = false;
    load();
    return () => { cancelledRef.current = true; };
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, [id, submissionId]);

  async function handleSave() {
    if (savingRef.current || saving) return;
    setFormError(null);
    setNotice(null);
    const items = pending?.pending_items || [];
    if (items.length === 0) {
      setFormError('No pending subjective items on this submission.');
      return;
    }
    const gradeEntries = [];
    for (let idx = 0; idx < items.length; idx += 1) {
      const it = items[idx];
      const raw = scores[it.item_id];
      const label = `Question ${idx + 1}`;
      if (raw === '' || raw === undefined || raw === null) {
        setFormError(`Enter a score for ${label} (0–${it.max_points}).`);
        return;
      }
      const n = Number(raw);
      if (!Number.isFinite(n) || n < 0) {
        setFormError(`Score for ${label} must be 0 or more.`);
        return;
      }
      if (n > Number(it.max_points)) {
        setFormError(`Score for ${label} exceeds the maximum of ${it.max_points}.`);
        return;
      }
      gradeEntries.push({
        assessment_item_id: Number(it.item_id),
        score: n,
        max_score: Number(it.max_points),
        feedback: feedbacks[it.item_id]?.trim() ? feedbacks[it.item_id].trim() : undefined,
      });
    }
    setSaving(true);
    savingRef.current = true;
    try {
      // Guard the nullable pending payload: without an attempt id there is
      // nothing to score — never send Number(undefined) to the backend.
      const attemptId = pending?.attempt_id ?? pending?.attemptId;
      if (attemptId === undefined || attemptId === null || attemptId === '') {
        setFormError('This submission is no longer awaiting scoring. Reload the assessment to see the latest state.');
        return;
      }
      await storeManualGrade({ attempt_id: Number(attemptId), grade_entries: gradeEntries, is_draft: isDraft });
      if (isDraft) {
        setNotice('Draft saved. Draft scores never affect mastery until finalized.');
      } else {
        navigate(`/teacher/assessments/${id}`);
      }
    } catch (err) {
      const f = firstFieldError(err);
      setFormError(f ? `${friendlyError(err)} (${f})` : friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  if (loading) return <div className="teacher-page"><SkeletonTable rows={6} /></div>;
  if (error && !assessment) return <div className="teacher-page"><ErrorNotice error={error} onRetry={() => window.location.reload()} /></div>;

  const items = pending?.pending_items || [];

  return (
    <div className="teacher-page">
      <div className="crumb"><Link to={`/teacher/assessments/${id}`}>{assessment?.title || 'Assessment'}</Link> / Scoring</div>
      <div className="detail-head"><div className="kicker">Scoring · submission {submissionId}</div><h1>{assessment?.title || 'Score submission'}</h1></div>
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {pendingErr ? <ErrorNotice error={pendingErr} onRetry={load} /> : null}
      {notice && <InlineAlert kind="ok">{notice}</InlineAlert>}
      {formError && <InlineAlert kind="error">{formError}</InlineAlert>}
      {pendingErr ? null : items.length === 0 ? (
        <div className="empty">
          <div className="empty-title">Nothing pending here</div>
          Every subjective item on this submission has already been scored.
        </div>
      ) : (
        <>
          {items.map((it) => (
            <div key={it.item_id} className="item-card">
              <div className="top">
                <div>
                  <div className="prompt">{it.question_text}</div>
                  <div className="meta">{it.item_type} · max {it.max_points} pts</div>
                </div>
              </div>
              <div className="score-line">
                Student response: <i>“{it.student_response || '—'}”</i>
              </div>
              <div className="form-grid-2">
                <FormField label={`Score (0–${it.max_points})`}>
                  <input
                    type="number"
                    min={0}
                    max={it.max_points}
                    step="0.01"
                    value={scores[it.item_id] ?? ''}
                    onChange={(e) => setScores((s) => ({ ...s, [it.item_id]: e.target.value }))}
                    className="score-input"
                  />
                </FormField>
                <FormField label="Feedback (optional)">
                  <input
                    value={feedbacks[it.item_id] || ''}
                    onChange={(e) => setFeedbacks((s) => ({ ...s, [it.item_id]: e.target.value }))}
                    placeholder="Short note"
                    className="score-input"
                  />
                </FormField>
              </div>
            </div>
          ))}
          <div className="settings-row settings-row--flat">
            <label className="checkline">
              <input type="checkbox" checked={isDraft} onChange={(e) => setIsDraft(e.target.checked)} />
              Save as draft (won&apos;t affect mastery until finalized)
            </label>
            <button type="button" className="btn btn-primary" disabled={saving} onClick={handleSave}>
              {saving ? 'Saving…' : 'Save scores'}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
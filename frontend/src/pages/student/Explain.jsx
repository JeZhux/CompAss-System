import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import '../../components/student/student.css';
import { EmptyState, SkeletonTable } from '../../components/student/ui.jsx';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import {
  listExplanations,
  generateExplanations,
  getExplanation,
  explainFurther,
  getAiStatus,
  ApiError,
} from '../../components/student/studentApi.js';

const MAX_TURNS = 2;

export default function Explain() {
  const { id: assessmentId } = useParams();
  const [search] = useSearchParams();
  const focusItemId = search.get('itemId') ? Number(search.get('itemId')) : null;

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [aiDown, setAiDown] = useState(false);
  const [rows, setRows] = useState([]);
  const [generating, setGenerating] = useState(false);
  const [genError, setGenError] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [followSending, setFollowSending] = useState(false);
  const [followError, setFollowError] = useState(null);
  const [followUps, setFollowUps] = useState([]);
  const generatingRef = useRef(false);
  const followRef = useRef(false);

  const loadList = useCallback(async () => {
    setLoading(true);
    setError(null);
    setGenError(null);
    try {
      try {
        await getAiStatus();
        setAiDown(false);
      } catch (err) {
        if (err instanceof ApiError && (err.status === 503 || err.code === 'AI_SERVICE_UNAVAILABLE')) {
          setAiDown(true);
        }
      }
      // Pure read — viewing never triggers generation.
      const list = await listExplanations(assessmentId);
      setRows(Array.isArray(list) ? list : []);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setError('Explanations are available once results are released.');
      } else {
        setError(err);
      }
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [assessmentId]);

  useEffect(() => { loadList(); }, [loadList]);

  const thread = useMemo(() => {
    if (!focusItemId) return rows;
    return rows.filter((r) => Number(r.item_id) === Number(focusItemId));
  }, [rows, focusItemId]);

  const primary = useMemo(() => {
    const primaries = thread.filter((r) => !r.is_follow_up);
    if (primaries.length > 0) return primaries[0];
    return thread.length > 0 ? thread[0] : null;
  }, [thread]);

  const threadFollowUps = useMemo(() => {
    if (!primary) return [];
    const fromRows = rows.filter((r) => r.is_follow_up && Number(r.item_id) === Number(primary.item_id));
    const merged = [...fromRows, ...followUps.filter((f) => Number(f.item_id) === Number(primary.item_id))];
    const seen = new Set();
    return merged.filter((f) => {
      const key = `${f.id}-${f.turn_number}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).sort((a, b) => Number(a.turn_number || 0) - Number(b.turn_number || 0));
  }, [rows, followUps, primary]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (!primary?.id) {
        setDetail(null);
        return;
      }
      setDetailLoading(true);
      try {
        const d = await getExplanation(primary.id);
        if (!cancelled) setDetail(d);
      } catch {
        if (!cancelled) setDetail(null);
      } finally {
        if (!cancelled) setDetailLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [primary?.id]);

  async function handleGenerate() {
    if (generatingRef.current || generating) return;
    generatingRef.current = true;
    setGenerating(true);
    setGenError(null);
    try {
      // Explicit student-initiated generation — the ONLY generation trigger.
      const list = await generateExplanations(assessmentId);
      setRows(Array.isArray(list) ? list : []);
      setAiDown(false);
    } catch (err) {
      if (err instanceof ApiError && (err.status === 503 || err.code === 'AI_SERVICE_UNAVAILABLE')) {
        setAiDown(true);
        setGenError('AI explanations are temporarily unavailable. Everything else in CompAss keeps working — try again in a bit.');
      } else if (err instanceof ApiError && err.status === 429) {
        setGenError(err);
      } else {
        setGenError(friendlyError(err, { action: 'ai' }));
      }
    } finally {
      generatingRef.current = false;
      setGenerating(false);
    }
  }

  async function handleFollowUp(e) {
    e?.preventDefault();
    if (!primary || !detail) return;
    if (followRef.current || followSending) return;
    setFollowError(null);
    followRef.current = true;
    setFollowSending(true);
    try {
      // The backend follow-up endpoint takes only the explanation + item —
      // there is no free-text field, so no student note is sent.
      const result = await explainFurther(primary.id, primary.item_id);
      setFollowUps((prev) => [...prev, {
        id: result.id ?? `local-${Date.now()}`,
        item_id: primary.item_id,
        is_follow_up: true,
        turn_number: result.turn_number ?? (threadFollowUps.length + 1),
        explanation_text: result.explanation_text || 'Follow-up received.',
      }]);
      // Refresh the detail for the new turns-remaining count.
      try {
        const d = await getExplanation(primary.id);
        setDetail(d);
      } catch {
        /* best-effort */
      }
    } catch (err) {
      if (err instanceof ApiError && (err.status === 503 || err.code === 'AI_SERVICE_UNAVAILABLE')) {
        setAiDown(true);
        setFollowError('AI explanations are temporarily unavailable. Try again in a bit.');
      } else if (err instanceof ApiError && err.status === 429) {
        setFollowError(err);
      } else {
        setFollowError(friendlyError(err, { action: 'chat' }));
      }
    } finally {
      followRef.current = false;
      setFollowSending(false);
    }
  }

  const itemType = detail?.item_type || primary?.item_type || null;
  const subjective = itemType === 'essay';
  const disabledByTeacher = Boolean(detail?.explain_further_disabled);
  const turnsRemaining = typeof detail?.explain_further_turns_remaining === 'number'
    ? detail.explain_further_turns_remaining
    : Math.max(0, MAX_TURNS - threadFollowUps.length);
  const explainAvailable = detail ? Boolean(detail.explain_further_available) && !disabledByTeacher && turnsRemaining > 0 && !subjective : false;

  if (loading) return <div className="student-page"><SkeletonTable rows={4} /></div>;

  return (
    <div className="student-page">
      <div className="crumb"><Link to={`/student/assessments/${assessmentId}/results`}>Results</Link> / Explain this item</div>
      <div className="detail-head">
        <div className="kicker">{detail?.competency_code || primary?.competency_code || 'AI explanation'}</div>
        <h1 className="detail-title">{detail?.item_text || primary?.item_text || 'Explanation'}</h1>
      </div>
      <div className="disclaimer">This explanation is a supplementary aid generated to help you review — it isn&apos;t a substitute for asking your teacher.</div>

      {error ? <ErrorNotice error={error} onRetry={loadList} /> : null}

      {aiDown && rows.length === 0 ? (
        <EmptyState title="AI explanations are temporarily unavailable" hint="Everything else in CompAss keeps working — try again in a bit." />
      ) : !primary ? (
        <div className="card maxw-460">
          <p className="lede mb-14">
            {focusItemId
              ? 'You answered this incorrectly. Generate a step-by-step explanation grounded in your teacher\u2019s materials.'
              : 'Generate step-by-step explanations for the items you missed, grounded in your teacher\u2019s materials.'}
          </p>
          {genError ? <ErrorNotice error={genError} action="ai" onRetry={handleGenerate} /> : null}
          <button type="button" className="btn btn-primary" onClick={handleGenerate} disabled={generating}>
            {generating ? 'Generating…' : 'Generate explanation'}
          </button>
          <div className="note-line">You can generate explanations a few times per minute. <Link to="/student/help">Usage limits</Link>.</div>
        </div>
      ) : (
        <>
          {genError ? <ErrorNotice error={genError} action="ai" onRetry={handleGenerate} /> : null}
          {(detail?.is_ungrounded ?? primary?.is_ungrounded) ? (
            <div className="ungrounded-flag">This explanation may not be fully grounded in your teacher&apos;s materials — read it critically.</div>
          ) : null}
          {detailLoading && !detail ? (
            <SkeletonTable rows={3} />
          ) : (
            <div className="explanation-text">{detail?.explanation_text || primary?.explanation_text}</div>
          )}
          {detail?.teacher_note ? (
            <div className="feedback-box">
              <div className="who">Teacher note</div>
              {detail.teacher_note}
            </div>
          ) : null}

          {threadFollowUps.map((f) => (
            <div key={`${f.id}-${f.turn_number}`} className="followup-turn">
              <div className="who">Follow-up {f.turn_number}</div>
              <div className="explanation-text">{f.explanation_text}</div>
            </div>
          ))}

          <div className="turns-left">
            {subjective
              ? 'Follow-ups are not available for written-response items.'
              : disabledByTeacher
                ? 'Your teacher has turned off follow-ups for this item.'
                : turnsRemaining === 0
                  ? "You've used all your follow-up turns for this item."
                  : `${turnsRemaining} follow-up turn${turnsRemaining === 1 ? '' : 's'} remaining`}
          </div>

          {subjective ? (
            <div className="note-line">Follow-ups aren&apos;t available for written-response items.</div>
          ) : disabledByTeacher ? (
            <div className="note-line">Your teacher has turned off follow-ups for this item.</div>
          ) : (
            <div className="followup-box">
              <div className="followup-title">Ask a follow-up</div>
              {followError ? <ErrorNotice error={followError} action="chat" onRetry={handleFollowUp} /> : null}
              <button type="button" className="btn btn-sm mt-8" onClick={handleFollowUp} disabled={followSending || turnsRemaining === 0 || !explainAvailable}>
                {followSending ? 'Asking…' : turnsRemaining === 0 ? 'No turns remaining' : 'Ask for a follow-up explanation'}
              </button>
              <div className="note-line">At most 2 follow-up turns per item. <Link to="/student/help">How explanations work</Link>.</div>
            </div>
          )}

          {!focusItemId && rows.length > 1 && (
            <div className="note-line">Showing {rows.filter((r) => !r.is_follow_up).length} explanations for this assessment.</div>
          )}
        </>
      )}
    </div>
  );
}
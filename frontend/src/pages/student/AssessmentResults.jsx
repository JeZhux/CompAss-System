import { useEffect, useMemo, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useNavigate, useParams } from 'react-router-dom';
import '../../components/student/student.css';
import { InlineAlert, EmptyState, SkeletonTable } from '../../components/student/ui.jsx';
import {
  getAssessmentResults,
  listExplanations,
  ApiError,
} from '../../components/student/studentApi.js';

function isObjective(item) {
  return item.item_type === 'multiple_choice' || item.item_type === 'true_false';
}

export default function AssessmentResults() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [results, setResults] = useState(null);
  const [explanations, setExplanations] = useState([]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const data = await getAssessmentResults(id);
        if (cancelled) return;
        setResults(data);
        try {
          // Pure read — viewing never triggers generation.
          const list = await listExplanations(id);
          if (!cancelled) setExplanations(Array.isArray(list) ? list : []);
        } catch {
          if (!cancelled) setExplanations([]);
        }
      } catch (err) {
        if (!cancelled) {
          if (err instanceof ApiError && err.code === 'RESULTS_NOT_RELEASED') {
            setError('Submitted — waiting on your teacher to release results.');
          } else if (err instanceof ApiError && err.status === 404) {
            setError('Results are not available for this assessment yet.');
          } else if (err instanceof ApiError && err.status === 403) {
            setError('You are not enrolled in the classroom for this assessment.');
          } else {
            setError(err);
          }
          setResults(null);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [id]);

  const explainByItem = useMemo(() => {
    const map = new Map();
    for (const e of explanations) {
      if (e?.item_id != null && !map.has(Number(e.item_id))) map.set(Number(e.item_id), e);
    }
    return map;
  }, [explanations]);

  if (loading) return <div className="student-page"><SkeletonTable rows={5} /></div>;
  if (error) {
    return (
      <div className="student-page">
        <div className="crumb"><Link to={`/student/assessments/${id}`}>Assessment</Link> / results</div>
        <ErrorNotice error={error} onRetry={() => window.location.reload()} />
      </div>
    );
  }
  if (!results) return <div className="student-page"><EmptyState title="Results aren't available for this item yet" /></div>;

  const unrecorded = (results.type || '') === 'Unrecorded';
  const items = Array.isArray(results.items) ? results.items : [];

  return (
    <div className="student-page">
      <div className="crumb"><Link to={`/student/assessments/${id}`}>{results.title || 'Assessment'}</Link> / results</div>
      <div className="detail-head"><h1>{results.title || 'Results'}</h1></div>
      {unrecorded && <div className="note-line mb-14">Practice (Unrecorded) — this score doesn&apos;t affect your mastery.</div>}
      <div className="result-summary">
        <div>
          <div className="big">{results.overall_score ?? 0}/{results.max_score ?? 0}</div>
          <div className="lbl">Total score{typeof results.percentage === 'number' ? ` · ${results.percentage}%` : ''}</div>
        </div>
      </div>
      <div className="row-list mt-20">
        {items.map((it) => {
          const earned = it.earned_points;
          const max = Number(it.max_points ?? 0);
          const wrong = earned !== null && earned !== undefined && Number(earned) < max;
          const objective = isObjective(it);
          const expl = explainByItem.get(Number(it.item_id));
          // Explain is offered for wrong objective items.
          const eligible = wrong && objective;
          return (
            <div key={it.item_id} className="result-item">
              <div className="top">
                <div>
                  <div className="prompt">{it.prompt}</div>
                  <div className="answer-line">Your answer: <b>{it.response_text ?? '—'}</b></div>
                  {expl?.correct_answer && wrong ? <div className="answer-line">Correct answer: <b>{expl.correct_answer}</b></div> : null}
                  {expl?.teacher_note ? (
                    <div className="feedback-box mt-8">
                      <div className="who">Teacher note</div>
                      {expl.teacher_note}
                    </div>
                  ) : null}
                </div>
                <div className="result-score">
                  <div className="result-pts">{earned ?? '—'}/{max}</div>
                  {eligible ? (
                    <button
                      type="button"
                      className="btn btn-sm mt-8"
                      onClick={() => navigate(`/student/assessments/${id}/explain?itemId=${it.item_id}`)}
                    >
                      Explain this
                    </button>
                  ) : null}
                </div>
              </div>
            </div>
          );
        })}
      </div>
      {items.length === 0 && <EmptyState title="No items in these results" />}
    </div>
  );
}
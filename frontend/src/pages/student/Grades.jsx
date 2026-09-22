import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import '../../components/student/student.css';
import { PageHead, InlineAlert, EmptyState, SkeletonTable, StatusPill, MasteryBar } from '../../components/student/ui.jsx';
import { getMasteryHistory, formatDate } from '../../components/student/studentApi.js';

export default function Grades() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [competencies, setCompetencies] = useState([]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        // Read-only — looking never generates AI explanations.
        const data = await getMasteryHistory();
        const list = Array.isArray(data?.competencies) ? data.competencies : [];
        list.sort((a, b) => String(b.last_assessed_at || '').localeCompare(String(a.last_assessed_at || '')));
        if (!cancelled) setCompetencies(list);
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  return (
    <div className="student-page">
      <PageHead
        title="Grades & Mastery"
        sub="Your Recorded results across every classroom, most recent first."
      />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {loading ? (
        <SkeletonTable rows={5} />
      ) : error ? null : competencies.length === 0 ? (
        <EmptyState title="No Recorded results yet" hint="Mastery appears here once you complete a Recorded assessment." />
      ) : (
        <>
          <table className="mastery-table">
            <thead><tr><th>Competency</th><th>Progress</th><th>Percent</th><th>Status</th><th>Last assessed</th></tr></thead>
            <tbody>
              {competencies.map((m) => {
                const pct = Number(m.current_mastery_percent ?? 0);
                const mastered = pct >= 80 || String(m.current_mastery_status || '').toLowerCase() === 'mastered';
                return (
                  <tr key={m.competency_id}>
                    <td>
                      <div className="cell-main">{m.descriptor || m.code || `Competency ${m.competency_id}`}</div>
                      {m.code && m.descriptor ? <div className="cell-sub">{m.code}</div> : null}
                    </td>
                    <td><MasteryBar pct={pct} /></td>
                    <td>{Math.round(pct)}%</td>
                    <td>{mastered ? <StatusPill tone="green">Mastered</StatusPill> : <StatusPill tone="amber">Not Mastered</StatusPill>}</td>
                    <td className="meta-faint">{m.last_assessed_at ? formatDate(m.last_assessed_at) : '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <div className="note-line">Viewing this page never generates AI explanations — those are always requested one at a time from a specific result. <Link to="/student/help">How explanations work</Link>.</div>
        </>
      )}
    </div>
  );
}
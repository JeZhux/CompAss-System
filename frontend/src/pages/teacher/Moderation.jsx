import { useEffect, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import { PageHead, EmptyState, SkeletonTable } from '../../components/teacher/ui.jsx';
import { listModerationLog, formatDateTime } from '../../components/teacher/teacherApi.js';

export default function Moderation() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rows, setRows] = useState([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [total, setTotal] = useState(0);

  async function load(p = 1) {
    setLoading(true);
    setError(null);
    try {
      const res = await listModerationLog({ page: p, per_page: 15 });
      setRows(Array.isArray(res?.data) ? res.data : []);
      const t = Number(res?.meta?.total || 0);
      const per = Number(res?.meta?.per_page || 15);
      setTotal(t);
      setPages(t > 0 ? Math.max(1, Math.ceil(t / per)) : 1);
      setPage(Number(res?.meta?.current_page || p));
    } catch (err) {
      setError(err);
      setRows([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1); }, []);

  return (
    <div className="teacher-page">
      <PageHead
        title="Moderation"
        sub="AI explanations generated for your students, across all your classrooms."
      />
      {error ? <ErrorNotice error={error} action="mod" onRetry={() => load(page)} /> : null}
      {loading ? (
        <SkeletonTable rows={5} />
      ) : error ? null : rows.length === 0 ? (
        <EmptyState title="No AI explanations to review right now" hint="Student-requested explanations will queue here for flagging and notes." />
      ) : (
        <>
          <div className="page-sub page-sub--flush">{total > 0 ? `${total} item${total === 1 ? '' : 's'} in queue` : `${rows.length} shown`}</div>
          {rows.map((m) => (
            <Link key={m.id} to={`/teacher/moderation/${m.id}`} className="mod-queue-row">
              <div className="queue-main">
                <div className="queue-title">
                  {m.student_name || m.student?.name || `Explanation ${m.id}`}{' '}
                  <span className="queue-sub">· {m.item?.assessment?.title || m.assessment_title || formatDateTime(m.created_at)}</span>
                </div>
                <div className="excerpt">{m.item?.prompt || m.excerpt || m.explanation_text?.slice(0, 140) || 'AI explanation'}</div>
              </div>
              <div className="queue-side">
                {(m.is_ungrounded || m.ungrounded) ? <span className="pill pill-amber">Ungrounded</span> : null}
                {m.flagged ? <span className="pill pill-red">Flagged</span> : null}
              </div>
            </Link>
          ))}
          {pages > 1 && (
            <div className="pagination">
              <button type="button" disabled={page <= 1} onClick={() => load(page - 1)}>← Prev</button>
              <span>Page {page} of {pages}</span>
              <button type="button" disabled={page >= pages} onClick={() => load(page + 1)}>Next →</button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
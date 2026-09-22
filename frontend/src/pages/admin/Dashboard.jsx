import { useEffect, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link } from 'react-router-dom';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import { PageHead, StatCard, SkeletonTable, ActivePill, EmptyState } from '../../components/admin/ui.jsx';
import { listUsers, listClassrooms, listAuditLogs, getSchoolWideOverview, isActiveUser, formatDateTime } from '../../components/admin/adminApi.js';

function countBelowThreshold(overview) {
  if (!overview?.grade_levels) return 0;
  let n = 0;
  for (const g of overview.grade_levels) {
    for (const s of g.subjects || []) {
      if (typeof s.mastery_rate_percent === 'number' && s.total_students_assessed > 0 && s.mastery_rate_percent < 80) n += 1;
    }
  }
  return n;
}

export default function Dashboard() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [userCounts, setUserCounts] = useState(null);
  const [roomCounts, setRoomCounts] = useState(null);
  const [classrooms, setClassrooms] = useState([]);
  const [audit, setAudit] = useState([]);
  const [overview, setOverview] = useState(null);

  useEffect(() => {
    let cancelled = false;
    const isCancelled = () => cancelled;
    // Exact user-status counts need a full scan: the users list has no
    // server-side status filter, so page through at per_page:100 (never more)
    // and count client-side. Sequential + cancelled between pages.
    async function scanUserCounts() {
      const perPage = 100;
      let page = 1;
      let total = 0;
      let active = 0;
      let forced = 0;
      for (;;) {
        const res = await listUsers({ page, per_page: perPage });
        if (isCancelled()) return null;
        const rows = Array.isArray(res?.data) ? res.data : [];
        if (page === 1) total = Number(res?.meta?.total ?? rows.length);
        for (const u of rows) {
          if (isActiveUser(u)) active += 1;
          if (u.must_change_password) forced += 1;
        }
        if (rows.length < perPage || page * perPage >= total || rows.length === 0) break;
        page += 1;
      }
      return { total, active, deactivated: Math.max(0, total - active), forced };
    }
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const [countsRes, activeRoomsRes, archivedRoomsRes, sliceRes, a, o] = await Promise.all([
          // Exact counts survive past 100 rows; a failure here degrades the
          // user stat cards to '—' instead of failing the whole dashboard.
          scanUserCounts().catch(() => null),
          // Cheap count queries: archived is a real server filter.
          listClassrooms({ archived: false, per_page: 1 }).catch(() => null),
          listClassrooms({ archived: true, per_page: 1 }).catch(() => null),
          listClassrooms({ per_page: 5 }).catch(() => ({ data: [], meta: null })),
          listAuditLogs({ per_page: 5 }).catch(() => ({ data: [] })),
          getSchoolWideOverview().catch(() => null),
        ]);
        if (cancelled) return;
        setUserCounts(countsRes);
        const activeRooms = Number(activeRoomsRes?.meta?.total);
        const archivedRooms = Number(archivedRoomsRes?.meta?.total);
        setRoomCounts(
          Number.isFinite(activeRooms) && Number.isFinite(archivedRooms)
            ? { active: activeRooms, archived: archivedRooms, total: activeRooms + archivedRooms }
            : null,
        );
        setClassrooms(Array.isArray(sliceRes?.data) ? sliceRes.data : []);
        setAudit(Array.isArray(a?.data) ? a.data : []);
        setOverview(o || null);
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  const activeUsers = userCounts?.active;
  const deactivated = userCounts?.deactivated;
  const forcedChange = userCounts?.forced;
  const activeRooms = roomCounts?.active;
  const archivedRooms = roomCounts?.archived;
  const roomsTotal = roomCounts?.total;
  const flags = countBelowThreshold(overview);

  return (
    <div className="admin-page">
      <PageHead
        title="Good morning"
        sub="Today's snapshot for your school."
      />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {loading ? (
        <SkeletonTable rows={6} />
      ) : error ? null : (
        <>
          <div className="stat-grid">
            <StatCard value={activeUsers ?? '—'} label="Active users" sub={deactivated !== undefined ? `${deactivated} deactivated` : 'Count unavailable'} />
            <StatCard value={activeRooms ?? '—'} label="Active classrooms" sub={archivedRooms !== undefined ? `${archivedRooms} archived` : 'Count unavailable'} />
            <StatCard value={forcedChange ?? '—'} label="Pending forced password change" sub="Locked until changed" />
            <StatCard value={flags} label="Below-threshold flags" sub="Subjects under 80% mastery" />
          </div>

          <div className="section-head"><h2>Needs your attention</h2></div>
          <div className="row-list">
            <Link to="/admin/users" className="row row--link">
              <div className="main">
                <div className="title">{forcedChange ?? '—'} account{forcedChange === 1 ? '' : 's'} still on a temporary password</div>
                <div className="meta">Users — filter by pending change</div>
              </div>
              <div className="side"><span className="pill pill-amber">Review</span></div>
            </Link>
            <Link to="/admin/users?tab=enroll" className="row row--link">
              <div className="main">
                <div className="title">New learners from a sheet still need a classroom — enroll them under Users, then place them by hand or share a join key</div>
                <div className="meta">Users — Enroll Students, then place · <span>How it works</span></div>
              </div>
              <div className="side"><span className="pill pill-blue">Users</span></div>
            </Link>
            <Link to="/admin/classrooms" className="row row--link">
              <div className="main">
                <div className="title">{archivedRooms ?? '—'} archived classroom{archivedRooms === 1 ? '' : 's'} — no new students or new work while archived</div>
                <div className="meta">Classrooms — archived filter</div>
              </div>
              <div className="side"><span className="pill pill-neutral">Archived</span></div>
            </Link>
          </div>

          <div className="section-head">
            <h2>Recent audit activity</h2>
            <span className="count">Last 5 events</span>
          </div>
          {audit.length === 0 ? (
            <EmptyState title="No audit events yet" hint="Actions across the school will appear here." />
          ) : (
            <Card>
              <div className="row-list row-list--open">
                {audit.slice(0, 5).map((a) => (
                  <div key={a.id} className="activity-row">
                    <div className="tag">{formatDateTime(a.created_at)}</div>
                    <div><b>{a.user_name || `User ${a.user_id ?? '—'}`}</b> — {a.description || a.event_type}</div>
                  </div>
                ))}
              </div>
            </Card>
          )}
          <div className="note-line">Full history in <Link to="/admin/audit">Audit</Link> — filter by what happened, who, which record, or date. <Link to="/admin/help">How long records are kept</Link>.</div>

          {classrooms.length > 0 && (
            <>
              <div className="section-head"><h2>Classroom snapshot</h2><span className="count">{roomsTotal ?? classrooms.length} total</span></div>
              <Card>
                <div className="scroll-x">
                <table className="dtable">
                  <thead><tr><th>Classroom</th><th>Year</th><th>Status</th></tr></thead>
                  <tbody>
                    {classrooms.slice(0, 5).map((c) => (
                      <tr key={c.id}>
                        <td><div className="cell-main">{c.name || `Classroom ${c.id}`}</div><div className="cell-sub">{c.teacher_name || ''}</div></td>
                        <td>{c.school_year || c.school_year_name || '—'}</td>
                        <td>{c.archived_at ? <span className="pill pill-neutral">Archived</span> : <ActivePill active={c.is_join_enabled !== false} />}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                </div>
              </Card>
            </>
          )}
        </>
      )}
    </div>
  );
}
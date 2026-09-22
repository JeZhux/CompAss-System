import { useCallback, useEffect, useMemo, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import '../../components/student/student.css';
import { InlineAlert, EmptyState, SkeletonTable, Modal, StatusPill, MasteryBar, Pager } from '../../components/student/ui.jsx';
import {
  getStudentClassroom,
  getClassroomStream,
  getClassroomClasswork,
  getClassroomPeople,
  getMasteryHistory,
  leaveClassroom,
  downloadAnnouncementFile,
  formatDate,
  formatDateTime,
  pagesFromMeta,
  ApiError,
} from '../../components/student/studentApi.js';

const TABS = [
  ['stream', 'Stream'],
  ['classwork', 'Classwork'],
  ['people', 'People'],
  ['mastery', 'Mastery'],
];

function initialsOf(name) {
  return String(name || '?').trim().split(/\s+/).map((x) => x[0]).join('').slice(0, 2).toUpperCase();
}

export default function ClassroomDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [search, setSearch] = useSearchParams();
  const activeTab = search.get('tab') || 'stream';
  const [room, setRoom] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [leaveOpen, setLeaveOpen] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const [leaveMsg, setLeaveMsg] = useState(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await getStudentClassroom(id);
      const classroom = data?.classroom || data;
      setRoom(classroom);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setError('Classroom not found. It may have been removed or the link is wrong. If this keeps happening, contact your school administrator.');
      else if (err instanceof ApiError && err.status === 403) setError('You are not enrolled in this classroom.');
      else if (err instanceof ApiError && err.status === 410) setError('This classroom has been archived.');
      else setError(err);
      setRoom(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [id]);

  function setTab(tab) {
    setSearch(tab === 'stream' ? {} : { tab }, { replace: false });
  }

  // Same a11y treatment as admin Users tabs: roving tabindex + arrow/Home/End
  // with automatic activation. No dirty guard here — switching tabs discards
  // only trivial local filter/page state; no one-time secrets or in-progress
  // imports live in these panels, so confirming is unnecessary. Panels unmount
  // on switch, so only the mounted panel is referenced via aria-controls.
  function onTabKeyDown(e) {
    const order = TABS.map(([k]) => k);
    const current = TABS.some(([k]) => k === activeTab) ? activeTab : 'stream';
    const i = order.indexOf(current);
    let next = null;
    if (e.key === 'ArrowRight') next = order[(i + 1 + order.length) % order.length];
    else if (e.key === 'ArrowLeft') next = order[(i - 1 + order.length) % order.length];
    else if (e.key === 'Home') next = order[0];
    else if (e.key === 'End') next = order[order.length - 1];
    if (next && next !== current) {
      e.preventDefault();
      setTab(next);
      requestAnimationFrame(() => {
        document.getElementById(`student-classroom-tab-${next}`)?.focus();
      });
    } else if (next) {
      e.preventDefault();
    }
  }

  async function handleLeave() {
    setLeaving(true);
    setLeaveMsg(null);
    try {
      const data = await leaveClassroom(id);
      if (data?.already_left) {
        setLeaveMsg({ tone: 'warn', text: 'You had already left this classroom.' });
        setTimeout(() => navigate('/student/classrooms'), 700);
      } else {
        setLeaveOpen(false);
        navigate('/student/classrooms');
      }
    } catch (err) {
      setLeaveMsg({ tone: 'err', text: friendlyError(err) });
    } finally {
      setLeaving(false);
    }
  }

  if (loading) return <div className="student-page"><SkeletonTable rows={6} /></div>;
  if (error) {
    return (
      <div className="student-page">
        <div className="crumb"><Link to="/student/classrooms">My Classrooms</Link> / missing</div>
        <ErrorNotice error={error} onRetry={() => window.location.reload()} />
      </div>
    );
  }
  if (!room) return <div className="student-page"><InlineAlert kind="error">Classroom not found. If this keeps happening, contact your school administrator.</InlineAlert></div>;

  const tab = TABS.some(([k]) => k === activeTab) ? activeTab : 'stream';
  const archived = Boolean(room.archived_at);

  return (
    <div className="student-page">
      <div className="crumb"><Link to="/student/classrooms">My Classrooms</Link> / {room.name || `Classroom ${room.id}`}</div>
      <div className="classroom-header">
        <div>
          <h1>{room.name || `Classroom ${room.id}`} {archived ? <span className="pill pill-neutral ml-8">Archived</span> : null}</h1>
          <div className="meta-line meta-line--spaced">
            {/* section_name is canonical; group_name is its legacy alias (same value). */}
            {room.section_name || room.group_name ? <span>{room.section_name || room.group_name}</span> : null}
            {room.school_year ? <span>SY {room.school_year}</span> : null}
            {room.subject_name ? <span>{room.subject_name}</span> : null}
          </div>
        </div>
        <button type="button" className="btn btn-danger btn-sm" onClick={() => { setLeaveMsg(null); setLeaveOpen(true); }}>Leave classroom</button>
      </div>
      <div className="tabbar" role="tablist" aria-label="Classroom sections" onKeyDown={onTabKeyDown}>
        {TABS.map(([k, label]) => (
          <button key={k} type="button" role="tab" id={`student-classroom-tab-${k}`} aria-controls={tab === k ? `student-classroom-panel-${k}` : undefined} aria-selected={tab === k} tabIndex={tab === k ? 0 : -1} className={tab === k ? 'active' : undefined} onClick={() => setTab(k)}>{label}</button>
        ))}
      </div>
      {archived && <div className="warn-line">This classroom is archived. You can still view past work, but nothing new can be posted or submitted here.</div>}
      <div role="tabpanel" id={`student-classroom-panel-${tab}`} aria-labelledby={`student-classroom-tab-${tab}`} tabIndex={0}>
        {tab === 'stream' && <StreamTab classroomId={id} />}
        {tab === 'classwork' && <ClassworkTab classroomId={id} />}
        {tab === 'people' && <PeopleTab classroomId={id} />}
        {tab === 'mastery' && <MasteryTab classroomId={id} />}
      </div>

      {leaveOpen && (
        <Modal
          title="Leave this classroom?"
          sub="You'll lose access to its stream, classwork, and materials. Your submitted work and mastery history stay on record."
          onClose={() => { if (!leaving) setLeaveOpen(false); }}
          actions={(
            <>
              <button type="button" className="btn btn-quiet" onClick={() => setLeaveOpen(false)} disabled={leaving}>Cancel</button>
              <button type="button" className="btn btn-danger" onClick={handleLeave} disabled={leaving}>{leaving ? 'Leaving…' : 'Leave classroom'}</button>
            </>
          )}
        >
          {leaveMsg && <div className={`modal-msg ${leaveMsg.tone}`}>{leaveMsg.text}</div>}
        </Modal>
      )}
    </div>
  );
}

/* ---------------- stream: read-only + file chips download ---------------- */

function StreamTab({ classroomId }) {
  const perPage = 15;
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState(null);
  const [downloading, setDownloading] = useState(null);

  const load = useCallback(async (p) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getClassroomStream(classroomId, { page: p, per_page: perPage });
      setItems(Array.isArray(res?.data) ? res.data : []);
      setMeta(res?.meta || null);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setError('You are not enrolled in this classroom.');
      else if (err instanceof ApiError && err.status === 410) setError('This classroom has been archived.');
      else setError(err);
      setItems([]);
      setMeta(null);
    } finally {
      setLoading(false);
    }
  }, [classroomId]);

  useEffect(() => { setPage(1); }, [classroomId]);
  useEffect(() => { load(page); }, [load, page]);

  const pages = pagesFromMeta(meta, items.length, perPage);

  async function handleDownload(announcementId, attachment) {
    setDownloading(`${announcementId}-${attachment.id}`);
    try {
      await downloadAnnouncementFile(announcementId, attachment.id);
    } catch (err) {
      setError(err);
    } finally {
      setDownloading(null);
    }
  }

  if (loading) return <SkeletonTable rows={4} />;
  if (error) return <ErrorNotice error={error} onRetry={() => window.location.reload()} />;
  if (!items.length) {
    return <EmptyState title="No announcements yet" hint="Your teacher hasn't posted anything here yet." />;
  }
  return (
    <div>
      {items.map((s) => (
        <div key={s.id} className="stream-item">
          <div className="meta"><span>{s.teacher_name || 'Teacher'}</span><span>{s.created_at ? formatDate(s.created_at) : ''}</span></div>
          <h4>{s.title}</h4>
          <p>{s.body}</p>
          <div>
            {(s.attachments || []).map((f) => (
              <button
                key={f.id}
                type="button"
                className="file-chip"
                disabled={downloading === `${s.id}-${f.id}`}
                onClick={() => handleDownload(s.id, f)}
              >
                {f.original_filename || `Attachment ${f.id}`}
              </button>
            ))}
          </div>
        </div>
      ))}
      <Pager page={page} pages={pages} onChange={setPage} />
    </div>
  );
}

/* ---------------- classwork: filter chips + status pills ---------------- */

function ClassworkTab({ classroomId }) {
  const perPage = 15;
  const [filter, setFilter] = useState('all');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState(null);
  const navigate = useNavigate();

  const load = useCallback(async (p) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getClassroomClasswork(classroomId, { page: p, per_page: perPage });
      setItems(Array.isArray(res?.data) ? res.data : []);
      setMeta(res?.meta || null);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setError('You are not enrolled in this classroom.');
      else if (err instanceof ApiError && err.status === 410) setError('This classroom has been archived.');
      else setError(err);
      setItems([]);
      setMeta(null);
    } finally {
      setLoading(false);
    }
  }, [classroomId]);

  useEffect(() => { setPage(1); }, [classroomId]);
  useEffect(() => { load(page); }, [load, page]);

  const pages = pagesFromMeta(meta, items.length, perPage);

  const visible = useMemo(() => {
    if (filter === 'assignments') return items.filter((k) => (k.kind || k.type) === 'assignment');
    if (filter === 'assessments') return items.filter((k) => (k.kind || k.type) !== 'assignment');
    return items;
  }, [items, filter]);

  function openItem(k) {
    const kind = k.kind || (k.type === 'assignment' ? 'assignment' : 'assessment');
    if (kind === 'assignment') navigate(`/student/assignments/${k.id}`);
    else navigate(`/student/assessments/${k.id}`);
  }

  if (loading) return <SkeletonTable rows={4} />;
  if (error) return <ErrorNotice error={error} onRetry={() => window.location.reload()} />;

  return (
    <div>
      <div className="filter-chips mb-14" role="group" aria-label="Filter classwork">
        <button type="button" className={filter === 'all' ? 'active' : undefined} onClick={() => setFilter('all')}>All</button>
        <button type="button" className={filter === 'assignments' ? 'active' : undefined} onClick={() => setFilter('assignments')}>Assignments</button>
        <button type="button" className={filter === 'assessments' ? 'active' : undefined} onClick={() => setFilter('assessments')}>Assessments</button>
      </div>
      {visible.length === 0 ? (
        <EmptyState title={`No ${filter === 'all' ? 'classwork' : filter} posted yet`} hint="Check back after your teacher posts new work." />
      ) : (
        <div className="row-list">
          {visible.map((k) => {
            const kind = k.kind || (k.type === 'assignment' ? 'assignment' : 'assessment');
            if (kind === 'assignment') {
              return (
                <div key={`as-${k.id}`} className="row" role="button" tabIndex={0} onClick={() => openItem(k)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openItem(k); } }}>
                  <div className="main">
                    <div className="title">{k.title}</div>
                    <div className="meta">Assignment · {k.due_date ? `Due ${formatDate(k.due_date)}` : 'No due date'}{k.has_feedback ? ' · Feedback received' : ''}</div>
                  </div>
                  <div className="side">
                    {k.is_submitted ? <StatusPill tone="green">Submitted</StatusPill> : <StatusPill tone="neutral">Not submitted</StatusPill>}
                    {k.has_feedback ? <StatusPill tone="blue">Feedback</StatusPill> : null}
                  </div>
                </div>
              );
            }
            const status = k.status || 'available';
            const pill = status === 'results_released'
              ? <StatusPill tone="green">Results available</StatusPill>
              : status === 'results_pending'
                ? <StatusPill tone="blue">Scored · awaiting release</StatusPill>
                : status === 'taken'
                  ? <StatusPill tone="amber">Submitted · pending</StatusPill>
                  : <StatusPill tone="neutral">Not started</StatusPill>;
            return (
              <div key={`am-${k.id}`} className="row" role="button" tabIndex={0} onClick={() => openItem(k)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openItem(k); } }}>
                <div className="main">
                  <div className="title">{k.title}</div>
                  <div className="meta">Assessment · {k.type || 'Recorded'}{k.time_limit ? ` · ${k.time_limit} min` : ''} · {k.item_count ?? 0} items</div>
                </div>
                <div className="side">{pill}</div>
              </div>
            );
          })}
        </div>
      )}
      <Pager page={page} pages={pages} onChange={setPage} />
    </div>
  );
}

/* ---------------- people: paginated 15/page, names-only ---------------- */

function PeopleTab({ classroomId }) {
  const perPage = 15;
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);

  const load = useCallback(async (p) => {
    setLoading(true);
    setError(null);
    try {
      // Never send ?search= — the backend rejects it 422.
      const res = await getClassroomPeople(classroomId, { page: p, per_page: perPage });
      setRows(Array.isArray(res?.data) ? res.data : []);
      setMeta(res?.meta || null);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setError('You need to be enrolled in this classroom to see classmates.');
      else if (err instanceof ApiError && err.status === 410) setError('This classroom has been archived.');
      else setError(err);
      setRows([]);
      setMeta(null);
    } finally {
      setLoading(false);
    }
  }, [classroomId]);

  useEffect(() => { load(page); }, [load, page]);

  const pages = pagesFromMeta(meta, rows.length, perPage);

  if (loading) return <SkeletonTable rows={4} />;
  if (error) return <ErrorNotice error={error} onRetry={() => window.location.reload()} />;

  return (
    <div>
      <div className="people-list row-list--flush">
        {rows.map((r, i) => {
          const name = r.display_name || 'Classmate';
          return (
            <div key={`${name}-${i}`} className="person-row">
              {r.photo_url
                ? <img className="photo" src={r.photo_url} alt="" />
                : <div className="initial" aria-hidden="true">{initialsOf(name)}</div>}
              <span>{name}</span>
            </div>
          );
        })}
      </div>
      {rows.length === 0 && <EmptyState title="No classmates to show" hint="Classmates appear here once they join." />}
      {pages > 1 && (
        <div className="pager" role="navigation" aria-label="Classmates pages">
          {Array.from({ length: pages }).map((_, i) => (
            <button key={i} type="button" className={page === i + 1 ? 'active' : undefined} onClick={() => setPage(i + 1)}>{i + 1}</button>
          ))}
        </div>
      )}
      <div className="note-line">Names only — no emails, codes, or IDs shown here. Photos appear only if a classmate has opted in.</div>
    </div>
  );
}

/* ---------------- mastery: Competency/Progress/%/Status, Mastered ≥ 80 ---------------- */

function MasteryTab({ classroomId }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rows, setRows] = useState([]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const [history, cw] = await Promise.all([
          getMasteryHistory().catch(() => ({ competencies: [] })),
          getClassroomClasswork(classroomId, { page: 1, per_page: 50 }).catch(() => ({ data: [] })),
        ]);
        const competencies = Array.isArray(history?.competencies) ? history.competencies : [];
        const classwork = Array.isArray(cw?.data) ? cw.data : [];
        const assessmentIds = new Set(
          classwork
            .filter((k) => (k.kind || k.type) !== 'assignment')
            .map((k) => Number(k.id)),
        );
        // Scope to this classroom: keep competencies assessed by this
        // classroom's assessments. Falls back to empty (Recorded-only note).
        const scoped = [];
        for (const c of competencies) {
          const hist = Array.isArray(c.history) ? c.history : [];
          const inRoom = hist.filter((h) => assessmentIds.has(Number(h.assessment_id)));
          if (inRoom.length === 0) continue;
          const latest = inRoom[0];
          scoped.push({
            competency: c.descriptor || c.code || `Competency ${c.competency_id}`,
            pct: Number(latest.mastery_percent ?? c.current_mastery_percent ?? 0),
            date: latest.created_at || c.last_assessed_at,
          });
        }
        // If the classroom has no Recorded assessments yet, or none of the
        // student's history maps here, show the empty Recorded-only state
        // rather than leaking other classrooms' mastery.
        if (!cancelled) setRows(scoped);
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [classroomId]);

  if (loading) return <SkeletonTable rows={3} />;
  if (error) return <ErrorNotice error={error} onRetry={() => window.location.reload()} />;
  if (!rows.length) {
    return <EmptyState title="No Recorded results yet in this class" hint="Mastery updates once you complete a Recorded assessment. Practice quizzes don't count toward mastery." />;
  }
  return (
    <div>
      <table className="mastery-table">
        <thead><tr><th>Competency</th><th>Progress</th><th>Percent</th><th>Status</th><th>Last updated</th></tr></thead>
        <tbody>
          {rows.map((m, i) => {
            const mastered = Number(m.pct) >= 80;
            return (
              <tr key={i}>
                <td>{m.competency}</td>
                <td><MasteryBar pct={m.pct} /></td>
                <td>{Math.round(Number(m.pct))}%</td>
                <td>{mastered ? <StatusPill tone="green">Mastered</StatusPill> : <StatusPill tone="amber">Not Mastered</StatusPill>}</td>
                <td className="meta-faint">{m.date ? formatDate(m.date) : '—'}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <div className="note-line">Recorded assessments only — practice (Unrecorded) work never affects mastery.</div>
    </div>
  );
}
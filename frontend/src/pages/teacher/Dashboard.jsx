import { useEffect, useMemo, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import Card from '../../components/shared/Card.jsx';
import { PageHead, StatCard, InlineAlert, EmptyState, SkeletonTable } from '../../components/teacher/ui.jsx';
import { useAuth } from '../../auth/AuthContext.jsx';
import {
  listTeacherClassrooms,
  listPendingGrading,
  listModerationLog,
  listTeacherAnnouncements,
  listTeacherAssignments,
  listTeacherAssessments,
  getNotCompetentFlags,
  formatDateTime,
} from '../../components/teacher/teacherApi.js';

function firstName(full) {
  return String(full || '').split(' ')[0] || 'Teacher';
}

export default function Dashboard() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [classrooms, setClassrooms] = useState([]);
  const [pending, setPending] = useState([]);
  const [pendingMeta, setPendingMeta] = useState(null);
  const [moderation, setModeration] = useState([]);
  const [moderationMeta, setModerationMeta] = useState(null);
  const [flags, setFlags] = useState([]);
  const [needsGrading, setNeedsGrading] = useState([]);
  const [recentSubs, setRecentSubs] = useState([]);
  const [allAnnouncements, setAllAnnouncements] = useState([]);
  const [annMeta, setAnnMeta] = useState(null);
  const [allAssignments, setAllAssignments] = useState([]);
  const [assignMeta, setAssignMeta] = useState(null);
  const [allAssessments, setAllAssessments] = useState([]);
  const [assessMeta, setAssessMeta] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const rooms = await listTeacherClassrooms().catch(() => []);
        const roomList = Array.isArray(rooms) ? rooms : Array.isArray(rooms?.data) ? rooms.data : [];
        if (cancelled) return;
        // The rooms list is unpaged ({ data }) — roomList.length is the exact
        // total, not a sample. Detail sections below never fan out per room.
        setClassrooms(roomList);

        // Flat global reads only: no per-room, per-assessment, or
        // per-assignment loops (the old N+1 fan-out reached ~160 requests).
        const [pendRes, modRes, flagRes, annRes, assignRes, assessRes] = await Promise.all([
          listPendingGrading({ per_page: 8 }).catch(() => ({ data: [], meta: null })),
          listModerationLog({ per_page: 5 }).catch(() => ({ data: [], meta: null })),
          getNotCompetentFlags().catch(() => []),
          listTeacherAnnouncements({ per_page: 5 }).catch(() => ({ data: [], meta: null })),
          listTeacherAssignments({ per_page: 6 }).catch(() => ({ data: [], meta: null })),
          // One wider assessment read doubles as the title map for the
          // needs-grading groups and as the All-assessments section source.
          listTeacherAssessments({ per_page: 100 }).catch(() => ({ data: [], meta: null })),
        ]);
        if (cancelled) return;
        const pendList = Array.isArray(pendRes?.data) ? pendRes.data : [];
        setPending(pendList);
        setPendingMeta(pendRes?.meta || null);
        setModeration(Array.isArray(modRes?.data) ? modRes.data : []);
        setModerationMeta(modRes?.meta || null);
        const flagList = Array.isArray(flagRes) ? flagRes : Array.isArray(flagRes?.data) ? flagRes.data : [];
        setFlags(flagList);

        const annList = Array.isArray(annRes?.data) ? annRes.data : [];
        setAllAnnouncements(annList);
        setAnnMeta(annRes?.meta || null);
        const assignList = Array.isArray(assignRes?.data) ? assignRes.data : [];
        setAllAssignments(assignList);
        setAssignMeta(assignRes?.meta || null);
        const assessList = Array.isArray(assessRes?.data) ? assessRes.data : [];
        setAllAssessments(assessList);
        setAssessMeta(assessRes?.meta || null);

        // Needs grading: group the global pending sample by assessment. The
        // per-group count covers only the visible sample — the exact total
        // comes from pendingMeta.total on the stat card and section note.
        const titleById = new Map(assessList.map((a) => [String(a.id), a.title || `Assessment ${a.id}`]));
        const groups = new Map();
        for (const p of pendList) {
          const key = String(p.assessment_id ?? p.id);
          if (!groups.has(key)) {
            groups.set(key, {
              assessmentId: p.assessment_id ?? p.id,
              title: titleById.get(key) || `Assessment ${p.assessment_id ?? p.id}`,
              count: 0,
            });
          }
          groups.get(key).count += 1;
        }
        const grading = [...groups.values()].sort((a, b) => b.count - a.count);
        setNeedsGrading(grading.slice(0, 6));

        // Recent classwork: newest-first global assignments (backend already
        // orders by created_at desc). The global read carries no classroom
        // name, so cards show submissions + date instead of a room label.
        const recents = assignList.slice(0, 6).map((a) => ({
          assignmentId: a.id,
          title: a.title || `Assignment ${a.id}`,
          count: a.submission_count ?? 0,
          created: a.created_at,
        }));
        setRecentSubs(recents);
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  const pendingTotal = useMemo(() => {
    if (pendingMeta && typeof pendingMeta.total === 'number') return pendingMeta.total;
    return pending.length;
  }, [pendingMeta, pending]);

  const modTotal = useMemo(() => {
    if (moderationMeta && typeof moderationMeta.total === 'number') return moderationMeta.total;
    return moderation.length;
  }, [moderationMeta, moderation]);

  const annTotal = annMeta && typeof annMeta.total === 'number' ? annMeta.total : allAnnouncements.length;
  const assignTotal = assignMeta && typeof assignMeta.total === 'number' ? assignMeta.total : allAssignments.length;
  const assessTotal = assessMeta && typeof assessMeta.total === 'number' ? assessMeta.total : allAssessments.length;

  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

  return (
    <div className="teacher-page">
      <PageHead
        title={`${greeting}, ${firstName(user?.name)}`}
        sub={classrooms.length ? `Across your ${classrooms.length} classroom${classrooms.length === 1 ? '' : 's'} this semester.` : 'Your teaching workspace. Classrooms appear once your assignments are set.'}
      />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {loading ? (
        <SkeletonTable rows={6} />
      ) : error ? null : (
        <>
          <div className="stat-grid">
            <StatCard value={pendingTotal} label="Responses awaiting your score" sub="Subjective items in pending grading" />
            <StatCard value={modTotal} label="Items in moderation queue" sub="AI explanations awaiting review" />
            <StatCard value={flags.length} label="Flags below mastery threshold" sub="Not-competent flags under 80%" />
          </div>

          <div className="section-head"><h2>Needs grading</h2><span className="count">{pendingTotal ? `${pendingTotal} awaiting` : 'Clear'}</span></div>
          {needsGrading.length === 0 ? (
            <EmptyState title="Nothing waiting on you right now" hint="Pending subjective responses will appear here as soon as students submit." />
          ) : (
            <div>
              {needsGrading.map((t) => (
                <Link key={t.assessmentId} to={`/teacher/assessments/${t.assessmentId}`} className="due-card">
                  <div><div className="t">{t.title}</div></div>
                  <div className="when">{t.count} shown →</div>
                </Link>
              ))}
              {pendingTotal > pending.length && (
                <div className="note-line">First {pending.length} of {pendingTotal} awaiting scoring — open an assessment to reach them all.</div>
              )}
            </div>
          )}

          <div className="section-head"><h2>Recent classwork</h2><span className="count">Showing {recentSubs.length} of {assignTotal}</span></div>
          {recentSubs.length === 0 ? (
            <EmptyState title="No classwork yet" hint="Create an assignment or assessment inside a classroom to get started." />
          ) : (
            <div className="row-list">
              {recentSubs.map((r) => (
                <Link key={r.assignmentId} to={`/teacher/assignments/${r.assignmentId}`} className="row">
                  <div className="main">
                    <div className="title">{r.title}</div>
                    <div className="meta">{typeof r.count === 'number' ? `${r.count} submissions` : ''} · {formatDateTime(r.created)}</div>
                  </div>
                  <div className="side"><span className="pill pill-blue">Assignment</span></div>
                </Link>
              ))}
            </div>
          )}

          <div className="section-head"><h2>All announcements</h2><span className="count">{allAnnouncements.length ? `Showing ${allAnnouncements.length} of ${annTotal}` : 'None'}</span></div>
          {allAnnouncements.length === 0 ? (
            <EmptyState title="No announcements across your classrooms" hint="Post one inside any classroom's Stream tab." />
          ) : (
            <div className="row-list">
              {allAnnouncements.map((a) => (
                <div key={a.id} className="row static">
                  <div className="main">
                    <div className="title">{a.title || `Announcement ${a.id}`}</div>
                    <div className="meta">Subject {a.subject_id ?? a.classroom_id ?? '—'} · {formatDateTime(a.created_at)}{a.has_attachments ? ' · Has attachments' : ''}</div>
                  </div>
                </div>
              ))}
            </div>
          )}

          <div className="section-head"><h2>All assignments</h2><span className="count">{allAssignments.length ? `Showing ${allAssignments.length} of ${assignTotal}` : 'None'}</span></div>
          {allAssignments.length === 0 ? (
            <EmptyState title="No assignments across your classrooms" hint="Create one inside any classroom's Classwork tab." />
          ) : (
            <div className="row-list">
              {allAssignments.map((a) => (
                <Link key={a.id} to={`/teacher/assignments/${a.id}`} className="row">
                  <div className="main">
                    <div className="title">{a.title || `Assignment ${a.id}`}</div>
                    <div className="meta">Due {formatDateTime(a.due_date)} · {a.submission_count ?? 0} submissions</div>
                  </div>
                  <div className="side"><span className="pill pill-blue">Assignment</span></div>
                </Link>
              ))}
            </div>
          )}

          <div className="section-head"><h2>All assessments</h2><span className="count">{allAssessments.length ? `Showing ${allAssessments.slice(0, 5).length} of ${assessTotal}` : 'None'}</span></div>
          {allAssessments.length === 0 ? (
            <EmptyState title="No assessments across your classrooms" hint="Create one inside any classroom's Classwork tab." />
          ) : (
            <div className="row-list">
              {allAssessments.slice(0, 5).map((a) => (
                <Link key={a.id} to={`/teacher/assessments/${a.id}`} className="row">
                  <div className="main">
                    <div className="title">{a.title || `Assessment ${a.id}`}</div>
                    <div className="meta">{a.type || 'Assessment'} · {a.item_count ?? 0} items · {a.status === 'released' ? 'Released' : 'Draft'}</div>
                  </div>
                  <div className="side">{a.status === 'draft' ? <span className="pill pill-neutral">Draft</span> : <span className="pill pill-blue">Released</span>}</div>
                </Link>
              ))}
            </div>
          )}

          <div className="section-head"><h2>Moderation preview</h2><Link to="/teacher/moderation" className="count">Open queue →</Link></div>
          {moderation.length === 0 ? (
            <EmptyState title="Moderation queue is clear" hint="AI explanations generated for your students will appear here." />
          ) : (
            <Card>
              <div className="row-list row-list--open">
                {moderation.slice(0, 4).map((m) => (
                  <Link key={m.id} to={`/teacher/moderation/${m.id}`} className="row">
                    <div className="main">
                      <div className="title">{m.student_name || m.student?.name || `Explanation ${m.id}`}</div>
                      <div className="meta">{m.item?.assessment?.title || m.assessment_title || 'AI explanation'} · {formatDateTime(m.created_at)}</div>
                    </div>
                    <div className="side">
                      {m.is_ungrounded || m.ungrounded ? <span className="pill pill-amber">Ungrounded</span> : null}
                      {m.flagged ? <span className="pill pill-red">Flagged</span> : <span className="pill pill-neutral">Review</span>}
                    </div>
                  </Link>
                ))}
              </div>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
import { useEffect, useMemo, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link } from 'react-router-dom';
import '../../components/student/student.css';
import { PageHead, InlineAlert, EmptyState, SkeletonTable } from '../../components/student/ui.jsx';
import { useAuth } from '../../auth/AuthContext.jsx';
import {
  listStudentClassrooms,
  getClassroomClasswork,
  getClassroomStream,
  listStudentAnnouncements,
  listStudentAssignments,
  listStudentAssessments,
  formatDate,
  formatDateTime,
} from '../../components/student/studentApi.js';

function firstName(full) {
  return String(full || '').split(' ')[0] || 'Student';
}

function greetingFor(hour) {
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

export default function Dashboard() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rooms, setRooms] = useState([]);
  const [dueSoon, setDueSoon] = useState([]);
  const [dueTotal, setDueTotal] = useState(0);
  const [needsAttention, setNeedsAttention] = useState([]);
  const [attentionTotal, setAttentionTotal] = useState(0);
  const [activity, setActivity] = useState([]);
  const [activityTotal, setActivityTotal] = useState(0);
  const [scan, setScan] = useState({ scanned: 0, total: 0, truncated: false });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const list = await listStudentClassrooms();
        const roomList = Array.isArray(list) ? list : [];
        roomList.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
        if (cancelled) return;
        setRooms(roomList);

        // Bounded fan-out: per-room classwork + stream reads are capped so a
        // long room list never multiplies requests. Cross-classroom global
        // reads below fill gaps for rooms outside the cap.
        const ROOM_SCAN_CAP = 8;
        const scannedRooms = roomList.slice(0, ROOM_SCAN_CAP);
        const due = [];
        const attention = [];
        const feed = [];
        let truncated = false;
        for (const room of scannedRooms) {
          try {
            // per_page:100 is the backend ceiling; meta.total tells whether
            // the room holds more than the fetched page.
            const cw = await getClassroomClasswork(room.id, { page: 1, per_page: 100 }).catch(() => ({ data: [], meta: null }));
            const items = Array.isArray(cw?.data) ? cw.data : [];
            if (Number(cw?.meta?.total ?? items.length) > items.length) truncated = true;
            for (const k of items) {
              const kind = k.kind || (k.type === 'assignment' ? 'assignment' : 'assessment');
              if (kind === 'assignment') {
                if (!k.is_submitted) {
                  due.push({
                    key: `as-${k.id}`,
                    classroomId: room.id,
                    classroomName: room.name || `Classroom ${room.id}`,
                    title: k.title || `Assignment ${k.id}`,
                    when: k.due_date ? `Due ${formatDate(k.due_date)}` : 'No due date',
                    sortKey: k.due_date || k.created_at || '',
                    to: `/student/assignments/${k.id}`,
                  });
                }
                if (k.has_feedback) {
                  attention.push({
                    key: `asf-${k.id}`,
                    classroomId: room.id,
                    classroomName: room.name || `Classroom ${room.id}`,
                    title: `Feedback on ${k.title || `Assignment ${k.id}`}`,
                    to: `/student/assignments/${k.id}`,
                  });
                }
              } else {
                const status = k.status || 'available';
                if (status === 'available') {
                  due.push({
                    key: `am-${k.id}`,
                    classroomId: room.id,
                    classroomName: room.name || `Classroom ${room.id}`,
                    title: k.title || `Assessment ${k.id}`,
                    when: k.type === 'Unrecorded' ? 'Practice — open now' : 'Open now',
                    sortKey: k.created_at || '',
                    to: `/student/assessments/${k.id}`,
                  });
                }
                if (status === 'results_released') {
                  attention.push({
                    key: `amr-${k.id}`,
                    classroomId: room.id,
                    classroomName: room.name || `Classroom ${room.id}`,
                    title: `Results released: ${k.title || `Assessment ${k.id}`}`,
                    to: `/student/assessments/${k.id}/results`,
                  });
                }
              }
            }
          } catch {
            /* per-classroom classwork is best-effort */
          }
          try {
            const st = await getClassroomStream(room.id, { page: 1, per_page: 5 }).catch(() => ({ data: [] }));
            const posts = Array.isArray(st?.data) ? st.data : [];
            for (const p of posts) {
              feed.push({
                key: `st-${room.id}-${p.id}`,
                classroomName: room.name || `Classroom ${room.id}`,
                title: p.title || 'Announcement',
                date: p.created_at ? formatDate(p.created_at) : '',
                sortKey: p.created_at || '',
              });
            }
          } catch {
            /* best-effort */
          }
        }
        if (cancelled) return;
        due.sort((a, b) => String(a.sortKey || '').localeCompare(String(b.sortKey || '')));
        feed.sort((a, b) => String(b.sortKey || '').localeCompare(String(a.sortKey || '')));
        // Global cross-classroom reads (#38 announcements, #49 assignments,
        // #66 assessments). They resolve to a plain data array and only fill
        // gaps left by the per-classroom loop above — never replace it.
        try {
          const [gAnn, gAssign, gAssess] = await Promise.all([
            listStudentAnnouncements().catch(() => []),
            listStudentAssignments().catch(() => []),
            listStudentAssessments().catch(() => []),
          ]);
          if (!cancelled) {
            const annList = Array.isArray(gAnn) ? gAnn : Array.isArray(gAnn?.data) ? gAnn.data : [];
            const assignList = Array.isArray(gAssign) ? gAssign : Array.isArray(gAssign?.data) ? gAssign.data : [];
            const assessList = Array.isArray(gAssess) ? gAssess : Array.isArray(gAssess?.data) ? gAssess.data : [];
            const seenDue = new Set(due.map((d) => d.key));
            const seenFeed = new Set(feed.map((f) => f.key));
            for (const a of assignList) {
              if (a?.is_submitted) continue;
              const key = `gas-${a?.id}`;
              if (a?.id === undefined || seenDue.has(key)) continue;
              seenDue.add(key);
              due.push({
                key,
                classroomId: a?.classroom_id,
                classroomName: a?.classroom_name || 'All classrooms',
                title: a?.title || `Assignment ${a?.id}`,
                when: a?.due_date ? `Due ${formatDate(a.due_date)}` : 'No due date',
                sortKey: a?.due_date || a?.created_at || '',
                to: `/student/assignments/${a?.id}`,
              });
              if (a?.has_feedback) {
                const fkey = `gasf-${a?.id}`;
                if (!due.some((d) => d.key === fkey)) {
                  attention.push({
                    key: fkey,
                    classroomId: a?.classroom_id,
                    classroomName: a?.classroom_name || 'All classrooms',
                    title: `Feedback on ${a?.title || `Assignment ${a?.id}`}`,
                    to: `/student/assignments/${a?.id}`,
                  });
                }
              }
            }
            for (const m of assessList) {
              const status = m?.status || 'available';
              if (status !== 'available') continue;
              const key = `gam-${m?.id}`;
              if (m?.id === undefined || seenDue.has(key)) continue;
              seenDue.add(key);
              due.push({
                key,
                classroomId: m?.classroom_id,
                classroomName: m?.classroom_name || 'All classrooms',
                title: m?.title || `Assessment ${m?.id}`,
                when: m?.type === 'Unrecorded' ? 'Practice — open now' : 'Open now',
                sortKey: m?.created_at || '',
                to: `/student/assessments/${m?.id}`,
              });
            }
            for (const p of annList) {
              const key = `gst-${p?.id}`;
              if (p?.id === undefined || seenFeed.has(key)) continue;
              seenFeed.add(key);
              feed.push({
                key,
                classroomName: p?.classroom_name || 'All classrooms',
                title: p?.title || 'Announcement',
                date: p?.created_at ? formatDate(p.created_at) : '',
                sortKey: p?.created_at || '',
              });
            }
            due.sort((a, b) => String(a.sortKey || '').localeCompare(String(b.sortKey || '')));
            feed.sort((a, b) => String(b.sortKey || '').localeCompare(String(a.sortKey || '')));
          }
        } catch {
          /* globals are best-effort */
        }
        if (cancelled) return;
        setDueSoon(due.slice(0, 5));
        setDueTotal(due.length);
        setNeedsAttention(attention.slice(0, 6));
        setAttentionTotal(attention.length);
        setActivity(feed.slice(0, 5));
        setActivityTotal(feed.length);
        setScan({ scanned: scannedRooms.length, total: roomList.length, truncated });
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  const greet = useMemo(() => greetingFor(new Date().getHours()), []);

  return (
    <div className="student-page">
      <PageHead
        title={`${greet}, ${firstName(user?.name)}`}
        sub={rooms.length ? `Here's what's happening across your ${rooms.length} classroom${rooms.length === 1 ? '' : 's'}.` : 'Your learning workspace. Join a classroom to get started.'}
      />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {loading ? (
        <SkeletonTable rows={6} />
      ) : error ? null : (
        <>
          {scan.total > scan.scanned && (
            <div className="note-line">Classroom detail scanned for {scan.scanned} of {scan.total} — the lists below also include all-classroom reads.</div>
          )}
          {scan.truncated && (
            <div className="note-line">A classroom holds more than one page of classwork — counts cover everything found.</div>
          )}
          <div className="section-head">
            <h2>Due soon</h2>
            <span className="count">{dueTotal ? `Showing ${dueSoon.length} of ${dueTotal}` : 'Clear'}</span>
          </div>
          {dueSoon.length === 0 ? (
            <EmptyState title="Nothing due right now" hint="You're caught up. Check back after your teachers post new work." />
          ) : (
            <div>
              {dueSoon.map((d) => (
                <Link key={d.key} to={d.to} className="due-card">
                  <div>
                    <div className="cls">{d.classroomName}</div>
                    <div className="t">{d.title}</div>
                  </div>
                  <div className="when">{d.when}</div>
                </Link>
              ))}
            </div>
          )}

          <div className="section-head">
            <h2>Needs your attention</h2>
            <span className="count">{attentionTotal ? `Showing ${needsAttention.length} of ${attentionTotal}` : 'Clear'}</span>
          </div>
          {needsAttention.length === 0 ? (
            <EmptyState title="No new feedback or released results yet" hint="Teacher feedback and released assessment results will appear here." />
          ) : (
            <div>
              {needsAttention.map((d) => (
                <Link key={d.key} to={d.to} className="due-card">
                  <div>
                    <div className="cls">{d.classroomName}</div>
                    <div className="t">{d.title}</div>
                  </div>
                  <div className="when">View →</div>
                </Link>
              ))}
            </div>
          )}

          <div className="section-head"><h2>Recent activity</h2>{activityTotal ? <span className="count">Showing {activity.length} of {activityTotal}</span> : null}</div>
          {activity.length === 0 ? (
            <EmptyState title="No recent announcements" hint="Announcements from your classrooms will appear here." />
          ) : (
            <div className="row-list">
              {activity.map((a) => (
                <div key={a.key} className="activity-row">
                  <div className="tag">{a.classroomName}</div>
                  <div>{a.title} <span className="meta-faint">— {a.date || formatDateTime(a.sortKey)}</span></div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}
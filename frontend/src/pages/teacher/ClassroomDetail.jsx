import { useEffect, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import { InlineAlert, SkeletonTable } from '../../components/teacher/ui.jsx';
import { getTeacherClassroom, ApiError } from '../../components/teacher/teacherApi.js';
import StreamTab from './tabs/StreamTab.jsx';
import ClassworkTab from './tabs/ClassworkTab.jsx';
import PeopleTab from './tabs/PeopleTab.jsx';
import MaterialsTab from './tabs/MaterialsTab.jsx';
import AnalyticsTab from './tabs/AnalyticsTab.jsx';
import SettingsTab from './tabs/SettingsTab.jsx';

const TABS = [
  ['stream', 'Stream'],
  ['classwork', 'Classwork'],
  ['people', 'People'],
  ['materials', 'Materials'],
  ['analytics', 'Analytics'],
  ['settings', 'Settings'],
];

export default function ClassroomDetail() {
  const { id } = useParams();
  const [search, setSearch] = useSearchParams();
  const activeTab = search.get('tab') || 'stream';
  const [room, setRoom] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await getTeacherClassroom(id);
      setRoom(data);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setError('Classroom not found. It may have been removed or the link is wrong. If this keeps happening, contact your school administrator.');
      else if (err instanceof ApiError && err.status === 403) setError('You do not have permission to view this classroom. If this keeps happening, contact your school administrator.');
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
  // only trivial local filter state; no one-time secrets or in-progress
  // imports live in these panels, so confirming is unnecessary. Panels unmount
  // on switch, so only the mounted panel is referenced via aria-controls.
  function onTabKeyDown(e) {
    const order = TABS.map(([k]) => k);
    const i = order.indexOf(tab);
    let next = null;
    if (e.key === 'ArrowRight') next = order[(i + 1 + order.length) % order.length];
    else if (e.key === 'ArrowLeft') next = order[(i - 1 + order.length) % order.length];
    else if (e.key === 'Home') next = order[0];
    else if (e.key === 'End') next = order[order.length - 1];
    if (next && next !== tab) {
      e.preventDefault();
      setTab(next);
      requestAnimationFrame(() => {
        document.getElementById(`teacher-classroom-tab-${next}`)?.focus();
      });
    } else if (next) {
      e.preventDefault();
    }
  }

  if (loading) return <div className="teacher-page"><SkeletonTable rows={6} /></div>;
  if (error) return <div className="teacher-page"><div className="crumb"><Link to="/teacher/classrooms">My Classrooms</Link> / missing</div><ErrorNotice error={error} onRetry={() => window.location.reload()} /></div>;
  if (!room) return <div className="teacher-page"><InlineAlert kind="error">Classroom not found. If this keeps happening, contact your school administrator.</InlineAlert></div>;

  const tab = TABS.some(([k]) => k === activeTab) ? activeTab : 'stream';
  const archived = Boolean(room.archived_at);

  return (
    <div className="teacher-page">
      <div className="crumb"><Link to="/teacher/classrooms">My Classrooms</Link> / {room.name || `Classroom ${room.id}`}</div>
      <div className="classroom-header">
        <div>
          <h1>{room.name || `Classroom ${room.id}`} {archived ? <span className="pill pill-neutral ml-8">Archived</span> : null}</h1>
          <div className="meta-line meta-line--spaced">
            {room.suffix ? <span>{room.suffix}</span> : null}
            <span>SY {room.school_year || '—'}</span>
            <span>Key: {room.join_key_display || room.join_key || '—'}</span>
          </div>
        </div>
      </div>
      <div className="tabbar" role="tablist" aria-label="Classroom sections" onKeyDown={onTabKeyDown}>
        {TABS.map(([k, label]) => (
          <button key={k} type="button" role="tab" id={`teacher-classroom-tab-${k}`} aria-controls={tab === k ? `teacher-classroom-panel-${k}` : undefined} aria-selected={tab === k} tabIndex={tab === k ? 0 : -1} className={tab === k ? 'active' : undefined} onClick={() => setTab(k)}>{label}</button>
        ))}
      </div>
      {archived && <div className="warn-line">This classroom is archived. Data is preserved but nothing new can be posted or graded here.</div>}
      <div role="tabpanel" id={`teacher-classroom-panel-${tab}`} aria-labelledby={`teacher-classroom-tab-${tab}`} tabIndex={0}>
        {tab === 'stream' && <StreamTab classroom={room} />}
        {tab === 'classwork' && <ClassworkTab classroom={room} />}
        {tab === 'people' && <PeopleTab classroom={room} />}
        {tab === 'materials' && <MaterialsTab classroom={room} />}
        {tab === 'analytics' && <AnalyticsTab classroom={room} />}
        {tab === 'settings' && <SettingsTab classroom={room} onChanged={setRoom} />}
      </div>
    </div>
  );
}
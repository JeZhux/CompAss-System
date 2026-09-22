import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import '../../components/student/student.css';
import { PageHead, EmptyState, SkeletonTable, Modal } from '../../components/student/ui.jsx';
import { friendlyError, isThrottleError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import ThrottleNotice, { effectiveWaitSeconds, useThrottleCooldown } from '../../components/shared/ThrottleNotice.jsx';
import {
  listStudentClassrooms,
  joinClassroom,
  joinErrorMessage,
  normalizeJoinKey,
  isValidJoinKey,
  ApiError,
} from '../../components/student/studentApi.js';

export default function Classrooms() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rooms, setRooms] = useState([]);
  const [joinOpen, setJoinOpen] = useState(false);
  const [joinKey, setJoinKey] = useState('');
  const [joinMsg, setJoinMsg] = useState(null);
  const [joinThrottle, setJoinThrottle] = useState(null);
  const [joining, setJoining] = useState(false);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const list = await listStudentClassrooms();
      const arr = Array.isArray(list) ? list : [];
      arr.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
      setRooms(arr);
    } catch (err) {
      setError(err);
      setRooms([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  function openJoin() {
    setJoinKey('');
    setJoinMsg(null);
    setJoinThrottle(null);
    setJoinOpen(true);
  }

  const joinCooldownTotal = joinThrottle && isThrottleError(joinThrottle)
    ? effectiveWaitSeconds(joinThrottle, 'join')
    : 0;
  const { remaining: joinRemaining, cooling: joinCooling } = useThrottleCooldown(joinCooldownTotal);

  async function handleJoin(e) {
    e?.preventDefault();
    if (joining || joinCooling) return;
    const normalized = normalizeJoinKey(joinKey);
    if (!isValidJoinKey(normalized)) {
      setJoinMsg({ tone: 'err', text: 'Enter the 6-character key from your teacher. Letters and numbers only.' });
      return;
    }
    setJoining(true);
    setJoinMsg(null);
    setJoinThrottle(null);
    try {
      const data = await joinClassroom(normalized);
      const classroom = data?.classroom || null;
      const already = Boolean(data?.already_joined);
      const targetId = classroom?.id;
      setJoinMsg({
        tone: 'ok',
        text: already
          ? "You're already a member of this classroom — taking you there now."
          : "You've joined the classroom — taking you there now.",
      });
      setTimeout(() => {
        setJoinOpen(false);
        if (targetId) navigate(`/student/classrooms/${targetId}?tab=stream`);
        else load();
      }, 700);
    } catch (err) {
      if (err instanceof ApiError && err.status === 429) {
        // Keep the raw error so ThrottleNotice can count down (join: 20/min).
        setJoinThrottle(err);
      } else if (err instanceof ApiError && err.status === 422) {
        setJoinMsg({ tone: 'err', text: joinErrorMessage(err) });
      } else {
        // joinErrorMessage covers 404/410; fall back to friendlyError for 403/5xx admin guidance.
        const text = joinErrorMessage(err) || friendlyError(err, { action: 'join' });
        setJoinMsg({ tone: 'err', text });
      }
    } finally {
      setJoining(false);
    }
  }

  return (
    <div className="student-page">
      <PageHead
        title="My Classrooms"
        sub={rooms.length ? `${rooms.length} joined · sorted alphabetically` : 'Join a classroom to get started.'}
        actions={<button type="button" className="btn btn-primary" onClick={openJoin}>Join a classroom</button>}
      />
      {error ? <ErrorNotice error={error} onRetry={load} /> : null}
      {loading ? (
        <SkeletonTable rows={4} />
      ) : error ? null : rooms.length === 0 ? (
        <EmptyState title="You haven't joined any classrooms" hint="Ask a teacher for a join key to get started." />
      ) : (
        <div className="classroom-grid">
          {rooms.map((c) => (
            <Link key={c.id} to={`/student/classrooms/${c.id}?tab=stream`} className="classroom-card">
              <div className="subj">{c.subject_name || c.school_year || 'Classroom'}</div>
              <h3>{c.name || `Classroom ${c.id}`}</h3>
              <div className="meta">
                {/* section_name canonical; group_name legacy alias (same value). */}
                {[(c.section_name || c.group_name), c.school_year ? `SY ${c.school_year}` : null].filter(Boolean).join(' · ') || 'Enrolled'}
              </div>
            </Link>
          ))}
        </div>
      )}

      {joinOpen && (
        <Modal
          title="Join a classroom"
          sub="Enter the join key your teacher gave you."
          onClose={() => { setJoinOpen(false); }}
          actions={(
            <>
              <button type="button" className="btn btn-quiet" onClick={() => setJoinOpen(false)} disabled={joining}>Cancel</button>
              <button type="button" className="btn btn-primary" onClick={handleJoin} disabled={joining || joinCooling || !isValidJoinKey(joinKey)}>
                {joining ? 'Joining…' : joinCooling ? `Wait ${joinRemaining}s` : 'Join'}
              </button>
            </>
          )}
        >
          {joinThrottle && isThrottleError(joinThrottle) ? (
            <ThrottleNotice error={joinThrottle} action="join" />
          ) : joinMsg ? (
            <div className={`modal-msg ${joinMsg.tone}`}>{joinMsg.text}</div>
          ) : null}
          <form onSubmit={handleJoin}>
            <input
              className="join-input"
              value={joinKey}
              onChange={(e) => setJoinKey(normalizeJoinKey(e.target.value))}
              placeholder="e.g. 7F3QXA"
              maxLength={6}
              autoComplete="off"
              aria-label="Classroom join key"
            />
          </form>
          <div className="note-line">Keys are 6 characters, uppercase letters and numbers. If a key doesn&apos;t work, you&apos;ll see what to do next. <Link to="/student/help">How join keys work</Link>.</div>
        </Modal>
      )}
    </div>
  );
}
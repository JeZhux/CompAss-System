import { useEffect, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import '../../components/student/student.css';
import { PageHead, InlineAlert, EmptyState, SkeletonTable } from '../../components/student/ui.jsx';
import { useAuth } from '../../auth/AuthContext.jsx';
import { getMe, changePassword, ApiError } from '../../components/student/studentApi.js';

export default function Profile() {
  const { user: sessionUser } = useAuth();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [me, setMe] = useState(null);
  const [photoOptIn, setPhotoOptIn] = useState(false);
  const [photoNote, setPhotoNote] = useState(null);
  const [currentPw, setCurrentPw] = useState('');
  const [newPw, setNewPw] = useState('');
  const [pwSaving, setPwSaving] = useState(false);
  const [pwMsg, setPwMsg] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const data = await getMe();
        if (!cancelled) {
          setMe(data);
          const stored = localStorage.getItem('compass:student:photo-opt-in');
          if (stored !== null) setPhotoOptIn(stored === '1');
          else setPhotoOptIn(Boolean(data?.photo_opt_in));
        }
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  function handlePhotoToggle(checked) {
    setPhotoOptIn(checked);
    try {
      localStorage.setItem('compass:student:photo-opt-in', checked ? '1' : '0');
    } catch {
      /* best-effort */
    }
    setPhotoNote(
      checked
        ? 'Photo preview is on for this device only. It does not share your photo with classmates.'
        : 'Photo preview is off on this device.',
    );
  }

  async function handlePasswordSave(e) {
    e?.preventDefault();
    setPwMsg(null);
    if (!currentPw) {
      setPwMsg({ tone: 'err', text: 'Enter your current password.' });
      return;
    }
    if (newPw.length < 8 || !/[A-Za-z]/.test(newPw) || !/[0-9]/.test(newPw) || newPw === newPw.toLowerCase() || newPw === newPw.toUpperCase()) {
      setPwMsg({ tone: 'err', text: 'New password must be at least 8 characters, with a letter, a number, and mixed case.' });
      return;
    }
    setPwSaving(true);
    try {
      await changePassword(currentPw, newPw);
      setPwMsg({ tone: 'ok', text: 'Password changed.' });
      setCurrentPw('');
      setNewPw('');
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setPwMsg({ tone: 'err', text: friendlyError(err, { action: 'defaults', fallback: 'That password does not meet the requirements.' }) });
      } else {
        setPwMsg({ tone: 'err', text: friendlyError(err) });
      }
    } finally {
      setPwSaving(false);
    }
  }

  const display = me || sessionUser || {};

  return (
    <div className="student-page">
      <PageHead title="Profile & Settings" />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {loading ? (
        <SkeletonTable rows={4} />
      ) : !me && !sessionUser ? (
        <EmptyState title="Could not load your profile" hint="Check your connection and try again." />
      ) : (
        <>
          <div className="grid-2 maxw-640">
            <div className="card">
              <h3 className="card-title-sm">Your details</h3>
              <div className="kv-stack">
                <div>Name<br /><b>{display.name || '—'}</b></div>
                <div>CompAss ID<br /><b>{display.school_id || '—'}</b></div>
              </div>
              <div className="note-line">Name and CompAss ID are managed by your school — contact your adviser to correct them.</div>
            </div>
            <div className="card">
              <h3 className="card-title-sm">Privacy</h3>
              <label className="checkline">
                <input type="checkbox" checked={photoOptIn} onChange={(e) => handlePhotoToggle(e.target.checked)} />
                Preview my photo on this device only
              </label>
              {photoNote && <div className="note-line">{photoNote}</div>}
              <div className="note-line">This is a preview on this device only — it doesn&apos;t share your photo with classmates. Off by default.</div>
            </div>
          </div>
          <div className="card maxw-640 mt-14">
            <h3 className="card-title-sm">Change password</h3>
            {pwMsg && <div className={`modal-msg ${pwMsg.tone} mb-12`}>{pwMsg.text}</div>}
            <form onSubmit={handlePasswordSave} className="stacked">
              <input
                placeholder="Current password"
                type="password"
                autoComplete="current-password"
                value={currentPw}
                onChange={(e) => setCurrentPw(e.target.value)}
                className="ff-control ff-wide"
              />
              <input
                placeholder="New password"
                type="password"
                autoComplete="new-password"
                value={newPw}
                onChange={(e) => setNewPw(e.target.value)}
                className="ff-control ff-wide"
              />
              <div className="note-line">At least 8 characters, with a letter, a number, and mixed case.</div>
              <div>
                <button type="submit" className="btn btn-primary" disabled={pwSaving}>{pwSaving ? 'Saving…' : 'Save new password'}</button>
              </div>
            </form>
          </div>
        </>
      )}
    </div>
  );
}
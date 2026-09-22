import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import Card from '../../components/shared/Card.jsx';
import { InlineAlert, SkeletonTable } from '../../components/teacher/ui.jsx';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import {
  getModerationDetail,
  flagModerationExplanation,
  appendModerationNote,
  disableExplainFurther,
  firstFieldError,
  formatDateTime,
  ApiError,
} from '../../components/teacher/teacherApi.js';

export default function ModerationDetail() {
  const { id } = useParams();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [item, setItem] = useState(null);
  const [flagged, setFlagged] = useState(false);
  const [note, setNote] = useState('');
  const [followupsDisabled, setFollowupsDisabled] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [noteRetry, setNoteRetry] = useState(false);
  const savingRef = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await getModerationDetail(id);
      setItem(data);
      setFlagged(Boolean(data?.flagged));
      setNote(data?.teacher_note || '');
      setFollowupsDisabled(Boolean(data?.explain_further_disabled || data?.followups_disabled));
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setError('Explanation not found. If this keeps happening, contact your school administrator.');
      else setError(err);
      setItem(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [id]);

  async function handleSave(noteOnly = false) {
    if (savingRef.current || saving) return;
    setFormError(null);
    setNotice(null);
    if (noteOnly && !note.trim()) {
      setFormError('Enter a note before retrying the note.');
      return;
    }
    if (flagged && !note.trim()) {
      setFormError('A note is required when the explanation is flagged.');
      return;
    }
    savingRef.current = true;
    setSaving(true);
    // Snapshot pre-save state so a partial success (flag persisted, note
    // failed) is detected honestly instead of silently diverging on retry.
    const originalFlagged = Boolean(item?.flagged);
    const originalNote = item?.teacher_note || '';
    const pendingNote = note;
    const needFlag = !noteOnly && flagged !== originalFlagged;
    const needNote = noteOnly
      ? Boolean(pendingNote.trim())
      : (pendingNote.trim() && pendingNote.trim() !== originalNote);
    let flagSaved = false;
    try {
      // Flag toggle is its own endpoint; note append is separate. Run both so
      // the toggle state and the note text persist together.
      if (needFlag) {
        await flagModerationExplanation(id, pendingNote.trim() || undefined);
        flagSaved = true;
      }
      if (needNote) {
        await appendModerationNote(id, pendingNote.trim());
      }
      setNoteRetry(false);
      setNotice('Moderation notes saved.');
      await load();
    } catch (err) {
      // Always reload so local state matches the server after any outcome.
      await load();
      // load() resets the note field from the server — restore the typed
      // text so a failed note is never silently discarded.
      setNote(pendingNote);
      if (flagSaved && needNote) {
        setNoteRetry(true);
        setFormError('Flag saved, but the note failed — retry the note.');
      } else {
        const f = firstFieldError(err);
        setFormError(f ? `${friendlyError(err, { action: 'mod' })} (${f})` : friendlyError(err, { action: 'mod' }));
      }
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleDisableFollowups() {
    if (savingRef.current || saving) return;
    setFormError(null);
    setNotice(null);
    savingRef.current = true;
    setSaving(true);
    try {
      await disableExplainFurther(id);
      setFollowupsDisabled(true);
      setNotice('Follow-up turns disabled. The original explanation stays visible to the student.');
    } catch (err) {
      const f = firstFieldError(err);
      setFormError(f ? `${friendlyError(err, { action: 'mod' })} (${f})` : friendlyError(err, { action: 'mod' }));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  if (loading) return <div className="teacher-page"><SkeletonTable rows={5} /></div>;
  if (error && !item) return <div className="teacher-page"><div className="crumb"><Link to="/teacher/moderation">Moderation</Link> / missing</div><ErrorNotice error={error} action="mod" onRetry={load} /></div>;
  if (!item) return <div className="teacher-page"><InlineAlert kind="error">Explanation not found. If this keeps happening, contact your school administrator.</InlineAlert></div>;

  return (
    <div className="teacher-page">
      <div className="crumb"><Link to="/teacher/moderation">Moderation</Link> / {item.student_name || item.student?.name || `Explanation ${item.id}`}</div>
      <div className="detail-head">
        <div className="kicker">{item.item?.assessment?.title || 'AI explanation'} · {formatDateTime(item.created_at)}</div>
        <h1 className="detail-title">{item.item?.prompt || item.title || 'Explanation detail'}</h1>
      </div>
      {error ? <ErrorNotice error={error} action="mod" onRetry={load} /> : null}
      {notice && <InlineAlert kind="ok">{notice}</InlineAlert>}
      {(item.is_ungrounded || item.ungrounded) && (
        <div className="warn-line">This explanation was marked ungrounded by the system — it may not be well supported by uploaded materials.</div>
      )}
      <Card>
        <div className="explain-body">{item.explanation_text || item.excerpt || 'No explanation text returned.'}</div>
        {item.student_response && (
          <div className="note-line">Student answered: “{item.student_response}”</div>
        )}
      </Card>

      <Card title="Moderation controls">
        {formError ? <ErrorNotice error={formError} action="mod" /> : null}
        <div className="settings-row">
          <div>
            <div className="label">Flag this explanation</div>
            <div className="desc">Flagged explanations stay visible to the student with a warning attached.</div>
          </div>
          <button
            type="button"
            role="switch"
            aria-checked={flagged}
            aria-label="Flag this explanation"
            className={`toggle-switch${flagged ? ' on' : ''}`}
            onClick={() => setFlagged((v) => !v)}
          >
            <span className="knob" />
          </button>
        </div>
        <label className="mod-note-label" htmlFor="mod-note">
          Note {flagged ? '(required when flagged)' : '(optional)'}
        </label>
        <textarea
          id="mod-note"
          value={note}
          onChange={(e) => setNote(e.target.value)}
          className="ff-control mod-note"
        />
        <div className="settings-row settings-row--flat">
          <div>
            <div className="label">Disable follow-ups</div>
            <div className="desc">Stops further follow-up turns on this explanation.</div>
          </div>
          <button
            type="button"
            role="switch"
            aria-checked={followupsDisabled}
            aria-label="Disable follow-ups"
            className={`toggle-switch${followupsDisabled ? ' on' : ''}`}
            disabled={followupsDisabled || saving}
            onClick={handleDisableFollowups}
          >
            <span className="knob" />
          </button>
        </div>
        <button type="button" className="btn btn-primary mt-14" disabled={saving} onClick={() => handleSave(false)}>
          {saving ? 'Saving…' : 'Save moderation notes'}
        </button>
        {noteRetry && (
          <button type="button" className="btn mt-14" disabled={saving || !note.trim()} onClick={() => handleSave(true)}>
            {saving ? 'Saving…' : 'Retry note only'}
          </button>
        )}
      </Card>
    </div>
  );
}
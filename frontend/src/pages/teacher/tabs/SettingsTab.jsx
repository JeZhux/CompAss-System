import { useRef, useState } from 'react';
import { InlineAlert, Modal, useConfirm } from '../../../components/teacher/ui.jsx';
import { friendlyError } from '../../../components/shared/errors.js';
import { ErrorNotice } from '../../../components/shared/Feedback.jsx';
import {
  resetClassroomKey,
  toggleClassroomJoin,
  archiveTeacherClassroom,
  unarchiveTeacherClassroom,
  ApiError,
} from '../../../components/teacher/teacherApi.js';

export default function SettingsTab({ classroom, onChanged }) {
  const [msg, setMsg] = useState(null);
  const [err, setErr] = useState(null);
  const [rotateOpen, setRotateOpen] = useState(false);
  const [working, setWorking] = useState(false);
  const workingRef = useRef(false);
  const { ask, node } = useConfirm();

  const archived = Boolean(classroom.archived_at);

  async function doRotate() {
    if (workingRef.current || working) return;
    setMsg(null);
    setErr(null);
    workingRef.current = true;
    setWorking(true);
    try {
      const data = await resetClassroomKey(classroom.id);
      onChanged(data);
      setRotateOpen(false);
      setMsg('Join key rotated. The old key is retired forever — it is never reissued.');
    } catch (e) {
      if (e instanceof ApiError && e.status === 410) setErr(friendlyError(e, { action: 'key' }));
      else setErr(e);
    } finally {
      workingRef.current = false;
      setWorking(false);
    }
  }

  async function doToggle() {
    if (workingRef.current || working) return;
    setMsg(null);
    setErr(null);
    workingRef.current = true;
    setWorking(true);
    try {
      const data = await toggleClassroomJoin(classroom.id, !classroom.is_join_enabled);
      onChanged(data);
      setMsg(data.is_join_enabled ? 'Joining is now open for this classroom.' : 'Joining is now closed. The current key stops admitting new students.');
    } catch (e) {
      setErr(e);
    } finally {
      workingRef.current = false;
      setWorking(false);
    }
  }

  async function doArchive() {
    if (workingRef.current || working) return;
    setMsg(null);
    setErr(null);
    workingRef.current = true;
    setWorking(true);
    try {
      const data = await archiveTeacherClassroom(classroom.id);
      onChanged(data);
      setMsg('Classroom archived. New students can\u2019t join and new work can\u2019t be added; history stays visible.');
    } catch (e) {
      setErr(e);
    } finally {
      workingRef.current = false;
      setWorking(false);
    }
  }

  async function doRestore() {
    if (workingRef.current || working) return;
    setMsg(null);
    setErr(null);
    workingRef.current = true;
    setWorking(true);
    try {
      const data = await unarchiveTeacherClassroom(classroom.id);
      onChanged(data);
      setMsg('Classroom restored. Students can join again and work can continue.');
    } catch (e) {
      setErr(e);
    } finally {
      workingRef.current = false;
      setWorking(false);
    }
  }

  return (
    <div>
      {msg && <InlineAlert kind="ok">{msg}</InlineAlert>}
      {err ? <ErrorNotice error={err} action="key" /> : null}
      <div className="card maxw-560">
        <div className="settings-row">
          <div><div className="label">Join key</div><div className="desc">Students use this to join. Rotating revokes the old key permanently.</div></div>
        </div>
        <div className="key-display">{classroom.join_key_display || classroom.join_key || '—'}</div>
        <div className="key-gap">
          <button type="button" className="btn btn-sm" disabled={archived} onClick={() => setRotateOpen(true)}>Rotate key</button>
        </div>

        <div className="settings-row">
          <div><div className="label">Allow joining</div><div className="desc">Turn off to stop new students from joining with the current key.</div></div>
          <button type="button" role="switch" aria-checked={Boolean(classroom.is_join_enabled)} aria-label="Allow joining" className={`toggle-switch${classroom.is_join_enabled ? ' on' : ''}`} disabled={archived || working} onClick={doToggle}>
            <span className="knob" />
          </button>
        </div>
        <div className="settings-row">
          <div><div className="label">Archive classroom</div><div className="desc">{archived ? 'This classroom is archived. Restoring lets students join and work continue.' : 'Archiving stops new students and new work but keeps everything saved.'}</div></div>
          {archived ? (
            <button type="button" className="btn btn-sm" disabled={working} onClick={() => ask('Restore this classroom?', 'Students will be able to join again and work can continue once restored.', 'Restore', doRestore)}>{working ? 'Working…' : 'Restore'}</button>
          ) : (
            <button type="button" className="btn btn-sm btn-danger" disabled={working} onClick={() => ask('Archive this classroom?', 'Students keep read access to past work, but no one can post, submit, or join while archived.', 'Archive', doArchive, true)}>{working ? 'Working…' : 'Archive'}</button>
          )}
        </div>
      </div>
      {node}

      {rotateOpen && (
        <Modal
          title="Rotate join key?"
          sub={`The current key ${classroom.join_key_display || classroom.join_key || ''} will stop working immediately and can never be reissued. Share the new key with your students.`}
          onClose={() => (!working ? setRotateOpen(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={working} onClick={() => setRotateOpen(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={working} onClick={doRotate}>{working ? 'Rotating…' : 'Rotate key'}</button>
            </>
          }
        />
      )}
    </div>
  );
}
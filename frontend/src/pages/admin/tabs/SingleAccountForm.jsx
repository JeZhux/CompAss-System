import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../../components/shared/errors.js';
import Card from '../../../components/shared/Card.jsx';
import { InlineAlert } from '../../../components/admin/ui.jsx';
import { createUser, firstFieldError } from '../../../components/admin/adminApi.js';

export default function SingleAccountForm({ role = 'Student', onCreated, onDirtyChange, onReset }) {
  const isStudent = String(role).toLowerCase() === 'student';
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [err, setErr] = useState(null);
  const [created, setCreated] = useState(null);
  // Dirty = one-time temporary_password on screen, unacknowledged.
  // Switching tabs unmounts this form and the password is never shown again.
  const dirty = !!created;
  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);

  async function submit(e) {
    e?.preventDefault();
    setErr(null);
    const trimmed = String(name ?? '').trim();
    if (!trimmed) { setErr('Enter the full name.'); return; }
    if (busyRef.current || busy) return;
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await createUser({ name: trimmed, role });
      setCreated(data);
      setName('');
      onCreated?.(data);
    } catch (e2) {
      const f = firstFieldError(e2);
      setErr(f ? `${friendlyError(e2)} (${f})` : friendlyError(e2));
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  function reset() {
    setCreated(null);
    setErr(null);
    setName('');
    // "Add another" discards the created confirmation screen, so any parent
    // post-action notice tied to it is stale — let the parent clear it.
    onReset?.();
  }

  return (
    <Card
      title={isStudent ? 'Enroll Students' : 'Apply Teachers'}
      sub="Single account — name only; the CompAss ID is auto-generated and a temporary password is shown once."
    >
      {err ? <div className="modal-msg err mb-12" role="alert">{typeof err === 'string' ? err : friendlyError(err)}</div> : null}
      {!created ? (
        <form onSubmit={submit}>
          <div className="form-field">
            <label htmlFor={isStudent ? 'enroll-single-name' : 'apply-single-name'}>Full name</label>
            <input
              id={isStudent ? 'enroll-single-name' : 'apply-single-name'}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Jonas dela Cruz"
              maxLength={255}
              required
            />
          </div>
          <div className="admin-row mt-8">
            <button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : (isStudent ? 'Enroll student' : 'Apply teacher')}</button>
          </div>
        </form>
      ) : (
        <div>
          <InlineAlert kind="ok">{isStudent ? 'Student enrolled.' : 'Teacher account created.'} Copy the temporary password now — it is never shown again.</InlineAlert>
          <div className="kv mt-10">
            <div><div className="k">Name</div><div className="v">{created.name}</div></div>
            <div><div className="k">CompAss ID</div><div className="v"><span className="mono">{created.school_id}</span></div></div>
            <div><div className="k">Temporary password</div><div className="v"><span className="mono">{created.temporary_password}</span></div></div>
          </div>
          <div className="note-line">The account must change it on next login.</div>
          <div className="admin-row mt-10">
            <button type="button" className="btn" onClick={reset}>Add another</button>
          </div>
        </div>
      )}
    </Card>
  );
}

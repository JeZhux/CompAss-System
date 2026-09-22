import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useParams } from 'react-router-dom';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import FormField from '../../components/shared/FormField.jsx';
import Modal from '../../components/shared/Modal.jsx';
import { InlineAlert, EmptyState, SkeletonTable, RolePill, ActivePill, useConfirm } from '../../components/admin/ui.jsx';
import { getUser, updateUser, deactivateUser, reactivateUser, resetUserPassword, getUserAudit, loginOf, isActiveUser, formatDateTime, fieldErrors, firstFieldError, ApiError } from '../../components/admin/adminApi.js';

export default function UserDetail() {
  const { id } = useParams();
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionMsg, setActionMsg] = useState(null);
  const [actionErr, setActionErr] = useState(null);
  const [tempPassword, setTempPassword] = useState(null);
  const [history, setHistory] = useState([]);
  const [editing, setEditing] = useState(false);
  const [editForm, setEditForm] = useState({ name: '' });
  const [editFieldErrs, setEditFieldErrs] = useState(null);
  const [editErr, setEditErr] = useState(null);
  const [saving, setSaving] = useState(false);
  const { ask, node } = useConfirm();
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `saving` disables the button.
  const savingRef = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await getUser(id);
      setUser(data);
      try {
        const h = await getUserAudit(id, { per_page: 4 });
        setHistory(Array.isArray(h?.data) ? h.data : []);
      } catch {
        setHistory([]);
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setError('User not found. It may have been removed or the link is wrong. If this keeps happening, contact your school administrator.');
      else if (err instanceof ApiError && err.status === 403) setError('You do not have permission to view this user. If this keeps happening, contact your school administrator.');
      else setError(err);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [id]);

  async function doDeactivate() {
    setActionMsg(null); setActionErr(null);
    try {
      await deactivateUser(id);
      setActionMsg('Account deactivated. Access is cut immediately; history is preserved.');
      await load();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) setActionErr(`${friendlyError(err, { action: 'mod' })} Score pending grading work before deactivating a teacher.`);
      else if (err instanceof ApiError && err.status === 403) setActionErr(friendlyError(err, { action: 'mod' }));
      else setActionErr(err);
    }
  }

  async function doReactivate() {
    setActionMsg(null); setActionErr(null);
    try {
      await reactivateUser(id);
      setActionMsg('Account reactivated. They can log in again immediately.');
      await load();
    } catch (err) {
      setActionErr(err);
    }
  }

  async function doReset() {
    setActionMsg(null); setActionErr(null); setTempPassword(null);
    try {
      const data = await resetUserPassword(id);
      setTempPassword(data?.temporary_password || null);
      setActionMsg('Password reset. All of the user\u2019s sessions were revoked; they must change it on next login.');
      await load();
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setActionErr(friendlyError(err, { action: 'mod' }));
      else setActionErr(err);
    }
  }

  function openEdit() {
    setEditForm({
      name: user?.name || '',
    });
    setEditFieldErrs(null);
    setEditErr(null);
    setEditing(true);
  }

  async function submitEdit(e) {
    e?.preventDefault();
    setEditErr(null);
    setEditFieldErrs(null);
    const name = String(editForm.name ?? '').trim();
    if (!name) { setEditErr('Name is required.'); return; }
    if (savingRef.current || saving) return;
    savingRef.current = true;
    setSaving(true);
    try {
      const payload = { name };
      const data = await updateUser(id, payload);
      const fallback = { ...user, name };
      setUser(data || fallback);
      setEditing(false);
      setActionMsg('Account details updated.');
      await load();
    } catch (err) {
      const fields = fieldErrors(err);
      if (err instanceof ApiError && err.status === 422) {
        setEditFieldErrs(fields);
        const f = firstFieldError(err);
        setEditErr(f ? `${friendlyError(err)} (${f})` : friendlyError(err));
      } else {
        setEditErr(friendlyError(err));
      }
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  function fieldMsg(key) {
    const v = editFieldErrs?.[key];
    if (!v) return null;
    return Array.isArray(v) ? String(v[0]) : String(v);
  }

  if (loading) return <div className="admin-page"><SkeletonTable rows={6} /></div>;
  if (error) return (
    <div className="admin-page">
      <div className="crumb"><Link to="/admin/users">Users</Link> / missing</div>
      <ErrorNotice error={error} onRetry={() => window.location.reload()} />
    </div>
  );
  if (!user) return <div className="admin-page"><EmptyState title="User not found" /></div>;

  const active = isActiveUser(user);

  return (
    <div className="admin-page">
      <div className="crumb"><Link to="/admin/users">Users</Link> / {user.name}</div>
      <div className="detail-head">
        <div className="toolbar-split">
          <div>
            <div className="kicker">{String(user.role || 'user')}</div>
            <h1>{user.name}</h1>
          </div>
          {active
            ? <button type="button" className="btn btn-danger" onClick={() => ask('Deactivate this user?', 'Access is cut immediately. Their history and past work are preserved and can be viewed later.', 'Deactivate', doDeactivate, true)}>Deactivate</button>
            : <button type="button" className="btn btn-primary" onClick={() => ask('Reactivate this user?', 'They will be able to log in again immediately.', 'Reactivate', doReactivate)}>Reactivate</button>}
        </div>
        <div className="mt-10">
          <button type="button" className="btn btn-sm" onClick={openEdit}>Edit details</button>
        </div>
      </div>

      {actionMsg && <InlineAlert kind="ok">{actionMsg}</InlineAlert>}
      {actionErr ? <ErrorNotice error={actionErr} onRetry={() => window.location.reload()} /> : null}

      <div className="grid-2 maxw-760">
        <Card title="Account details">
          <div className="kv">
            <div><div className="k">CompAss ID</div><div className="v">{loginOf(user)}</div></div>
            <div><div className="k">Role</div><div className="v"><RolePill role={user.role} /></div></div>
            <div><div className="k">Status</div><div className="v"><ActivePill active={active} /></div></div>
            <div><div className="k">Password state</div><div className="v">{user.must_change_password ? <span className="pill pill-amber">Forced change pending</span> : <span className="pill pill-green">Set by user</span>}</div></div>
            <div><div className="k">Created</div><div className="v">{formatDateTime(user.created_at)}</div></div>
          </div>
        </Card>
        <Card title="Password reset" sub="One-time temporary password, shown only to you.">
          <p className="lede lede--sm">
            Generates a strong temporary password, revokes all of the user&apos;s sessions, and forces a change on next login. There is no public self-service reset.
          </p>
          <button
            type="button"
            className="btn"
            onClick={() => ask('Reset this user\u2019s password?', 'A new temporary password will be generated and shown once to you. All of their active sessions will be revoked.', 'Reset password', doReset, true)}
          >
            Generate reset
          </button>
          {tempPassword && (
            <div className="ok-line">Temporary password: <span className="mono">{tempPassword}</span><br />Copy it now — it is never shown again.</div>
          )}
          <div className="note-line">Weak or number-only passwords don&apos;t work here — resetting always issues a strong temporary password.</div>
        </Card>
      </div>

      <div className="section-head"><h2>Recent history</h2></div>
      {history.length === 0 ? (
        <div className="empty">No audit history for this user yet.</div>
      ) : (
        <div className="timeline">
          {history.map((a) => (
            <div key={a.id} className="tl-row">
              <div className="tl-time">{formatDateTime(a.created_at)}</div>
              <div className="tl-body"><b>{a.user_name || 'System'}</b> — {a.description || a.event_type}</div>
            </div>
          ))}
        </div>
      )}
      <div className="note-line"><Link to={`/admin/audit?user=${encodeURIComponent(user.id)}`}>View full audit trail for this user</Link></div>
      {editing && (
        <Modal
          title="Edit user details"
          sub="Edit the display name only. The CompAss ID and role cannot be changed here."
          onClose={() => setEditing(false)}
          message={editErr}
          tone={editErr ? 'err' : 'ok'}
          actions={
            <>
              <button type="button" className="btn btn-quiet" onClick={() => setEditing(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={submitEdit}>{saving ? 'Saving…' : 'Save'}</button>
            </>
          }
        >
          <form onSubmit={submitEdit}>
            <div className="note-line">Questions about roles or password resets? See <Link to="/admin/help">Help</Link>.</div>
            <FormField label="Full name" htmlFor="eu-name" error={fieldMsg('name')} errorId="eu-name-err">
              <input id="eu-name" value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} required maxLength={255} aria-describedby={fieldMsg('name') ? 'eu-name-err' : undefined} />
            </FormField>
            <div className="note-line">CompAss ID <span className="mono">{loginOf(user)}</span> is auto-generated and immutable.</div>
          </form>
        </Modal>
      )}
      {node}
    </div>
  );
}
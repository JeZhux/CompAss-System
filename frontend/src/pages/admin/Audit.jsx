import { useEffect, useRef, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useSearchParams } from 'react-router-dom';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import Pager from '../../components/shared/Pager.jsx';
import { PageHead, EmptyState, SkeletonTable } from '../../components/admin/ui.jsx';
import { listAuditLogs, getEntityAudit, AUDIT_EVENT_TYPES, formatDateTime } from '../../components/admin/adminApi.js';

const PER_PAGE = 15;

export default function Audit() {
  const [params, setParams] = useSearchParams();
  const [eventType, setEventType] = useState('all');
  const [userId, setUserId] = useState(params.get('user') || '');
  const [entityType, setEntityType] = useState('');
  const [entityId, setEntityId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [text, setText] = useState('');
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [entityMode, setEntityMode] = useState(false);
  // Cancellation guard (same pattern as the other list pages): stale
  // responses never overwrite state after unmount or a newer load().
  const loadSeq = useRef(0);

  async function load(p = page) {
    const seq = ++loadSeq.current;
    const isStale = () => loadSeq.current !== seq;
    setLoading(true);
    setError(null);
    try {
      const hasType = entityType.trim() !== '';
      const hasId = entityId.trim() !== '';
      if ((hasType && !hasId) || (!hasType && hasId)) {
        if (isStale()) return;
        setEntityMode(false);
        setRows([]);
        setMeta(null);
        setError('Record type and record reference are both needed to follow one record. Fill both, or clear both to use the general log.');
        return;
      }
      if (hasType && hasId) {
        // Record-history lookup — both fields validated client-side
        // in getEntityAudit, field problems surface from the backend.
        const res = await getEntityAudit({
          auditable_type: entityType.trim(),
          auditable_id: entityId.trim(),
          event_type: eventType,
          from: from || undefined,
          to: to || undefined,
          page: p,
          per_page: PER_PAGE,
        });
        if (isStale()) return;
        setEntityMode(true);
        setRows(Array.isArray(res?.data) ? res.data : []);
        setMeta(res?.meta || null);
        return;
      }
      setEntityMode(false);
      const res = await listAuditLogs({
        event_type: eventType,
        user_id: userId.trim() || undefined,
        from: from || undefined,
        to: to || undefined,
        page: p,
        per_page: PER_PAGE,
      });
      if (isStale()) return;
      setRows(Array.isArray(res?.data) ? res.data : []);
      setMeta(res?.meta || null);
    } catch (e) {
      if (isStale()) return;
      setError(e);
      setRows([]);
      setMeta(null);
    } finally {
      if (!isStale()) setLoading(false);
    }
  }

  useEffect(() => { load(1); setPage(1); return () => { loadSeq.current += 1; }; /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);
  useEffect(() => {
    const u = params.get('user');
    if (u) setUserId(u);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function apply(e) {
    e?.preventDefault();
    setPage(1);
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      if (userId.trim()) next.set('user', userId.trim());
      else next.delete('user');
      return next;
    });
    load(1);
  }

  function changePage(n) {
    setPage(n);
    load(n);
  }

  const q = text.trim().toLowerCase();
  const visible = q.length >= 2
    ? rows.filter((a) =>
      String(a.user_name || '').toLowerCase().includes(q) ||
      String(a.description || '').toLowerCase().includes(q) ||
      String(a.auditable_id || '').toLowerCase().includes(q) ||
      String(a.event_type || '').toLowerCase().includes(q),
    )
    : rows;

  return (
    <div className="admin-page">
      <PageHead title="Audit" sub="Append-only log of everything auditable. Writes here never block the actions that caused them." />
      <form onSubmit={apply}>
        <div className="filter-bar filter-bar--labeled">
          <div className="filter-field">
            <label htmlFor="audit-search">Search</label>
            <input id="audit-search" type="text" placeholder="Actor, entity, or description" value={text} onChange={(e) => setText(e.target.value)} />
          </div>
          <div className="filter-field">
            <label htmlFor="audit-event">Event type</label>
            <select id="audit-event" value={eventType} onChange={(e) => setEventType(e.target.value)}>
              <option value="all">All event types</option>
              {AUDIT_EVENT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>
          <div className="filter-field">
            <label htmlFor="audit-user">Person</label>
            <input id="audit-user" type="text" placeholder="Person ID" value={userId} onChange={(e) => setUserId(e.target.value)} inputMode="numeric" className="ff-control w-person" />
          </div>
          <div className="filter-field">
            <label htmlFor="audit-etype">Record type</label>
            <input id="audit-etype" type="text" placeholder="Record type" value={entityType} onChange={(e) => setEntityType(e.target.value)} className="ff-control w-record" />
          </div>
          <div className="filter-field">
            <label htmlFor="audit-eid">Record reference</label>
            <input id="audit-eid" type="text" placeholder="Record ID" value={entityId} onChange={(e) => setEntityId(e.target.value)} inputMode="numeric" className="ff-control w-person" />
          </div>
          <div className="filter-field">
            <label htmlFor="audit-from">From</label>
            <input id="audit-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="ff-control" />
          </div>
          <div className="filter-field">
            <label htmlFor="audit-to">To</label>
            <input id="audit-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} className="ff-control" />
          </div>
          <button type="submit" className="btn btn-sm btn-primary">Apply filters</button>
        </div>
      </form>
      <div className="note-line">To follow one record&apos;s history, fill in both the record type and the record reference together. Clearing both returns to the general log. <Link to="/admin/help">How history works</Link>.</div>
      {entityMode && <div className="note-line">Showing history for this record (<button type="button" className="btn btn-sm" onClick={() => { setEntityType(''); setEntityId(''); setPage(1); setEntityMode(false); setRows([]); setMeta(null); }}>Clear record filter</button> — press Apply filters to reload the general log).</div>}
      {text.trim() !== '' && text.trim().length < 2 && <div className="note-line">Type at least 2 characters to filter the loaded page.</div>}
      {q.length >= 2 && <div className="note-line">Showing {visible.length} of {rows.length} loaded rows — the search filters this page only, not the server.</div>}
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}

      {loading ? <SkeletonTable rows={10} /> : error ? null : visible.length === 0 ? (
        <EmptyState title="No audit events match these filters" />
      ) : (
        <Card>
          <div className="scroll-x">
          <table className="dtable">
            <thead><tr><th>Date</th><th>Actor</th><th>Event</th><th>Entity</th><th>Description</th></tr></thead>
            <tbody>
              {visible.map((a) => (
                <tr key={a.id}>
                  <td className="meta-faint nowrap">{formatDateTime(a.created_at)}</td>
                  <td>{a.user_name || (a.user_id ? `User ${a.user_id}` : 'System')}</td>
                  <td><span className="pill pill-neutral">{a.event_type}</span></td>
                  <td>{a.auditable_type ? `${String(a.auditable_type).split('\\').pop()}${a.auditable_id ? `: ${a.auditable_id}` : ''}` : '—'}</td>
                  <td className="meta-soft">{a.description || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
          <Pager page={meta?.current_page || page} pages={meta?.last_page || 1} onChange={changePage} />
        </Card>
      )}
      <div className="note-line">History is kept for a limited time. <Link to="/admin/help">How long records are kept</Link>.</div>
    </div>
  );
}
import { useEffect, useRef, useState } from 'react';
import { ErrorNotice } from '../../../components/shared/Feedback.jsx';
import { InlineAlert, EmptyState, SkeletonTable, Modal, Pager } from '../../../components/teacher/ui.jsx';
import { getClassroomPeople, removeClassroomStudent, formatDateTime } from '../../../components/teacher/teacherApi.js';

function initials(name) {
  return String(name || '?').split(' ').map((x) => x[0]).join('').slice(0, 2).toUpperCase();
}

function isDigitsOnly(value) {
  const s = String(value || '').trim();
  return s.length > 0 && /^[0-9]+$/.test(s);
}

export default function PeopleTab({ classroom }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [allRows, setAllRows] = useState([]);
  const [confirm, setConfirm] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [removing, setRemoving] = useState(false);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const removingRef = useRef(false);
  const perPage = 15;

  const query = search.trim();
  const digitsOnly = isDigitsOnly(query);
  const tooShort = query.length > 0 && query.length < 2;
  // Digits-only and single-character searches never reach any server.
  const guardedSearch = query.length >= 2 && !digitsOnly ? query : undefined;

  // The people endpoint returns the FULL roster in one unpaged response
  // (no meta, no server-side search), so fetch once per classroom and do
  // all filtering + paging client-side. Page turns never refetch.
  useEffect(() => {
    setPage(1);
    setSearch('');
    setAllRows([]);
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const res = await getClassroomPeople(classroom.id);
        if (cancelled) return;
        const list = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
        setAllRows(list);
      } catch (err) {
        if (cancelled) return;
        setError(err);
        setAllRows([]);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [classroom.id]);

  const q = guardedSearch ? guardedSearch.toLowerCase() : '';
  // Roster shape is {id, student_id, student_name, school_id, joined_at}:
  // filter matches the display name or the CompAss ID (school_id).
  const filtered = q
    ? allRows.filter((s) => String(s.student_name || s.name || '').toLowerCase().includes(q)
      || String(s.school_id || '').toLowerCase().includes(q))
    : allRows;

  // Local pager over the full fetched set — always honest about visible rows.
  const pages = Math.max(1, Math.ceil(filtered.length / perPage));
  const safePage = Math.min(page, pages);
  const pageRows = filtered.slice((safePage - 1) * perPage, safePage * perPage);

  async function handleRemove() {
    if (!confirm) return;
    if (removingRef.current || removing) return;
    removingRef.current = true;
    setRemoving(true);
    setActionError(null);
    try {
      const studentId = confirm.student_id ?? confirm.studentId ?? confirm.id;
      await removeClassroomStudent(classroom.id, studentId);
      setConfirm(null);
      setAllRows((prev) => prev.filter((r) => (r.student_id ?? r.id) !== studentId));
    } catch (err) {
      setActionError(err);
    } finally {
      removingRef.current = false;
      setRemoving(false);
    }
  }

  return (
    <div>
      <div className="toolbar">
        <div className="filter-field">
          <label htmlFor="people-search">Search students</label>
          <input
            id="people-search"
            type="search"
            placeholder="Name or CompAss ID (min 2 chars)"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          />
        </div>
      </div>
      {digitsOnly && <div className="err-line">Search can&apos;t be digits only — try a name instead.</div>}
      {tooShort && !digitsOnly && <div className="note-line mb-12">Type at least 2 characters to search.</div>}
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {actionError ? <ErrorNotice error={actionError} onRetry={() => window.location.reload()} /> : null}
      {loading ? (
        <SkeletonTable rows={5} />
      ) : error ? null : filtered.length === 0 ? (
        <EmptyState title={q ? `No students match “${guardedSearch}”` : 'No students enrolled yet'} hint={q ? 'Try a shorter search, or clear the search.' : 'Share the classroom join key. Students join with the key while joining is open.'} />
      ) : (
        <>
          <div>
            {pageRows.map((s, i) => (
              <div key={s.id ?? s.student_id ?? i} className="person-row">
                <div className="initial">{initials(s.student_name || s.name)}</div>
                <div className="queue-main">
                  <div className="queue-title">{s.student_name || s.name || `Student ${s.student_id ?? ''}`}</div>
                  <div className="queue-sub person-sub">
                    {s.school_id || ''}{s.joined_at ? ` · Joined ${formatDateTime(s.joined_at)}` : ''}
                  </div>
                </div>
                <button type="button" className="btn btn-sm btn-danger" disabled={Boolean(classroom.archived_at)} onClick={() => setConfirm(s)}>Remove</button>
              </div>
            ))}
          </div>
          <Pager page={safePage} pages={pages} onChange={setPage} />
          <div className="note-line">Showing {filtered.length === 0 ? 0 : (safePage - 1) * perPage + 1}–{(safePage - 1) * perPage + pageRows.length} of {filtered.length} enrolled{q ? ` (matching “${guardedSearch}” of ${allRows.length} total)` : ''}.</div>
        </>
      )}

      {confirm && (
        <Modal
          title={`Remove ${confirm.student_name || confirm.name || 'this student'}?`}
          sub="This removes them from the roster and appends a leave-history record. Their submitted work and mastery history stay on record."
          onClose={() => (!removing ? setConfirm(null) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={removing} onClick={() => setConfirm(null)}>Cancel</button>
              <button type="button" className="btn btn-danger" disabled={removing} onClick={handleRemove}>{removing ? 'Removing…' : 'Remove student'}</button>
            </>
          }
        />
      )}
    </div>
  );
}
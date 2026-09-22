import { useEffect, useMemo, useRef, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useParams } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import { InlineAlert, EmptyState, SkeletonTable, Pager } from '../../components/teacher/ui.jsx';
import {
  getTeacherAssessment,
  listPendingGrading,
  bulkGrade,
  firstFieldError,
} from '../../components/teacher/teacherApi.js';

export default function BulkGrade() {
  const { id } = useParams();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [assessment, setAssessment] = useState(null);
  const [rows, setRows] = useState([]);
  const [cells, setCells] = useState({});
  const [status, setStatus] = useState({});
  const [savingAll, setSavingAll] = useState(false);
  const [formError, setFormError] = useState(null);
  // Pending-list failures are tracked separately from the assessment load:
  // only a genuine empty list shows the empty state, never a failed fetch.
  const [pendingErr, setPendingErr] = useState(null);
  const [notice, setNotice] = useState(null);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const perPage = 50;
  const cancelledRef = useRef(false);
  const assessmentRef = useRef({ id: null, value: null });
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `savingAll` disables the button.
  const savingRef = useRef(false);
  const savingRowsRef = useRef(new Set());

  // Paged pending-grading read (meta.total) so every submission is reachable —
  // no silent slice cap. Score inputs stay keyed by row id across pages.
  async function load(p = 1) {
    setLoading(true);
    setError(null);
    setPendingErr(null);
    try {
      const needAssessment = assessmentRef.current.id !== id;
      const assessmentP = needAssessment ? getTeacherAssessment(id) : Promise.resolve(assessmentRef.current.value);
      // The pending-grading list must not fail silently into an empty
      // success — a failure is recorded and shown with a retry.
      let pend = null;
      let pendErr = null;
      try {
        pend = await listPendingGrading({ assessment_id: id, page: p, per_page: perPage });
      } catch (e) {
        pendErr = e;
      }
      const a = await assessmentP;
      if (cancelledRef.current) return;
      assessmentRef.current = { id, value: a };
      setAssessment(a);
      if (pendErr) {
        setPendingErr(pendErr);
        setRows([]);
        setTotal(0);
        return;
      }
      const list = Array.isArray(pend?.data) ? pend.data : [];
      setRows(list);
      setTotal(Number(pend?.meta?.total ?? list.length));
      setPage(Number(pend?.meta?.current_page ?? p));
    } catch (err) {
      if (!cancelledRef.current) setError(err);
    } finally {
      if (!cancelledRef.current) setLoading(false);
    }
  }

  useEffect(() => {
    cancelledRef.current = false;
    assessmentRef.current = { id: null, value: null };
    setCells({}); setStatus({}); setNotice(null); setFormError(null);
    load(1);
    return () => { cancelledRef.current = true; };
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, [id]);

  function changePage(p) {
    setPage(p);
    load(p);
  }

  const items = useMemo(() => {
    const list = Array.isArray(assessment?.items) ? assessment.items : [];
    return list.filter((i) => i.item_type === 'essay');
  }, [assessment]);

  function setCell(rowId, itemId, value) {
    setCells((prev) => ({ ...prev, [`${rowId}:${itemId}`]: value }));
  }

  function itemLabel(it, idx) {
    const short = String(it.prompt || '').slice(0, 28);
    return `Question ${idx + 1}${short ? ` (“${short}${String(it.prompt || '').length > 28 ? '…' : ''}”)` : ''}`;
  }

  function validateRow(row) {
    for (let idx = 0; idx < items.length; idx += 1) {
      const it = items[idx];
      const raw = cells[`${row.id}:${it.id}`];
      if (raw === '' || raw === undefined || raw === null) return `Row for ${row.student_name || 'this student'}: enter a score for ${itemLabel(it, idx)}.`;
      const n = Number(raw);
      if (!Number.isFinite(n) || n < 0) return `Row for ${row.student_name || 'this student'}: score must be 0 or more.`;
      if (n > Number(it.max_points)) return `Row for ${row.student_name || 'this student'}: score exceeds max ${it.max_points} on ${itemLabel(it, idx)}.`;
    }
    return null;
  }

  async function persistRow(row) {
    const problem = validateRow(row);
    if (problem) {
      setStatus((s) => ({ ...s, [row.id]: { tone: 'red', text: problem } }));
      return { ok: false, error: problem };
    }
    try {
      await bulkGrade({
        assessment_id: Number(id),
        grades: [{
          student_id: Number(row.student_id),
          grade_entries: items.map((it) => ({
            assessment_item_id: Number(it.id),
            score: Number(cells[`${row.id}:${it.id}`]),
            max_score: Number(it.max_points),
          })),
        }],
      });
      setStatus((s) => ({ ...s, [row.id]: { tone: 'green', text: 'Saved' } }));
      return { ok: true };
    } catch (err) {
      // User-visible text goes through friendlyError; the field-level
      // detail is kept alongside it where human-readable.
      const field = firstFieldError(err);
      const base = friendlyError(err);
      const text = field ? `${base} (${field})` : base;
      setStatus((s) => ({ ...s, [row.id]: { tone: 'red', text } }));
      return { ok: false, error: text };
    }
  }

  async function saveRow(row) {
    if (savingRowsRef.current.has(row.id) || savingRef.current || savingAll) return { ok: false, error: null };
    savingRowsRef.current.add(row.id);
    try {
      return await persistRow(row);
    } finally {
      savingRowsRef.current.delete(row.id);
    }
  }

  async function saveAll() {
    if (savingRef.current || savingAll) return;
    setFormError(null);
    setNotice(null);
    savingRef.current = true;
    setSavingAll(true);
    try {
      let ok = 0;
      let failed = 0;
      for (const row of rows) {
        const res = await persistRow(row);
        if (res.ok) ok += 1;
        else failed += 1;
      }
      if (failed === 0) setNotice(`${ok} row${ok === 1 ? '' : 's'} saved.`);
      else setFormError(`${failed} row${failed === 1 ? '' : 's'} reported an error while every other row still saved. Fix the flagged rows and retry them individually.`);
    } finally {
      savingRef.current = false;
      setSavingAll(false);
    }
  }

  if (loading) return <div className="teacher-page"><SkeletonTable rows={6} /></div>;
  if (error && !assessment) return <div className="teacher-page"><ErrorNotice error={error} onRetry={() => window.location.reload()} /></div>;

  const pages = Math.max(1, Math.ceil(total / perPage));

  return (
    <div className="teacher-page">
      <div className="crumb"><Link to={`/teacher/assessments/${id}`}>{assessment?.title || 'Assessment'}</Link> / Bulk grade</div>
      <div className="detail-head"><h1>Bulk grade</h1><div className="page-sub">Enter scores page by page — {total} pending in total. Each row saves independently.</div></div>
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {pendingErr ? <ErrorNotice error={pendingErr} onRetry={() => load(page)} /> : null}
      {formError && <InlineAlert kind="error">{formError}</InlineAlert>}
      {notice && <InlineAlert kind="ok">{notice}</InlineAlert>}
      {pendingErr ? null : rows.length === 0 ? (
        <EmptyState title="No pending rows" hint="Bulk grading pages through every pending submission for this assessment." />
      ) : items.length === 0 ? (
        <EmptyState title="No essay items" hint="Bulk grading applies to subjective (essay) items. Add an essay item first." />
      ) : (
        <>
          <div className="scroll-x">
            <table className="score-grid-table">
              <thead>
                <tr>
                  <th>Student</th>
                  {items.map((it) => <th key={it.id}>{String(it.prompt || '').slice(0, 24)}{String(it.prompt || '').length > 24 ? '…' : ''} (/{it.max_points})</th>)}
                  <th></th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td>{row.student_name || 'A student'}</td>
                    {items.map((it, idx) => (
                      <td key={it.id}>
                        <input
                          type="number"
                          min={0}
                          max={it.max_points}
                          step="0.01"
                          value={cells[`${row.id}:${it.id}`] ?? ''}
                          onChange={(e) => setCell(row.id, it.id, e.target.value)}
                          placeholder={`0–${it.max_points}`}
                          aria-label={`${row.student_name || 'Student'} question ${idx + 1}`}
                        />
                      </td>
                    ))}
                    <td><button type="button" className="btn btn-sm" disabled={savingAll} onClick={() => saveRow(row)}>Save row</button></td>
                    <td className="row-status">
                      {status[row.id] ? <span className={`pill pill-${status[row.id].tone}`} title={status[row.id].text}>{status[row.id].text}</span> : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mt-16">
            <button type="button" className="btn btn-primary" disabled={savingAll} onClick={saveAll}>
              {savingAll ? 'Saving…' : 'Save this page'}
            </button>
          </div>
          <Pager page={page} pages={pages} onChange={changePage} />
          <div className="note-line">Showing {total === 0 ? 0 : (page - 1) * perPage + 1}–{(page - 1) * perPage + rows.length} of {total} pending. If a score exceeds an item&apos;s max, that row reports an error while every other row still saves. <Link to="/teacher/help">Learn more</Link>.</div>
        </>
      )}
    </div>
  );
}
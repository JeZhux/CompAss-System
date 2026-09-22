import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import FormField from '../../components/shared/FormField.jsx';
import Pager from '../../components/shared/Pager.jsx';
import { PageHead, EmptyState } from '../../components/admin/ui.jsx';
import { getMasteryRecords, getMasterySummary, listUsers, listSubjects, isDigitsOnly } from '../../components/admin/adminApi.js';

function pickNumber(obj, keys) {
  for (const k of keys) {
    const v = obj?.[k];
    if (typeof v === 'number' && Number.isFinite(v)) return v;
  }
  return null;
}

function summaryBreakdown(summary) {
  if (!summary || typeof summary !== 'object') return [];
  const candidates = ['records', 'competencies', 'breakdown', 'items', 'data', 'by_competency'];
  for (const k of candidates) {
    const v = summary[k];
    if (Array.isArray(v) && v.length > 0 && typeof v[0] === 'object') return v;
  }
  return [];
}

function MasterySummaryCard({ summary }) {
  const mastered = pickNumber(summary, ['mastered_count', 'mastered', 'mastered_records']);
  const total = pickNumber(summary, ['total', 'total_records', 'total_competencies']);
  const rate = pickNumber(summary, ['mastery_rate_percent', 'mastery_rate', 'rate_percent']);
  const rows = summaryBreakdown(summary);
  // The summary payload is an unpaged aggregate — page client-side over the
  // full row set with an honest total, never a silent slice.
  const [page, setPage] = useState(1);
  const perPage = 20;
  useEffect(() => { setPage(1); }, [summary]);
  const pages = Math.max(1, Math.ceil(rows.length / perPage));
  const safe = Math.min(page, pages);
  const pageRows = rows.slice((safe - 1) * perPage, safe * perPage);
  return (
    <Card>
      <div className={`grid-3 maxw-560${rows.length > 0 ? ' mb-18' : ''}`}>
        <div className="stat-card"><div className="num">{mastered ?? '—'}</div><div className="lbl">Mastered</div></div>
        <div className="stat-card"><div className="num">{total ?? '—'}</div><div className="lbl">Tracked</div></div>
        <div className="stat-card"><div className="num">{typeof rate === 'number' ? `${Math.round(rate)}%` : '—'}</div><div className="lbl">Mastery rate</div></div>
      </div>
      {rows.length > 0 ? (
        <table className="dtable">
          <thead><tr><th>Competency</th><th>Status</th><th>Rate</th></tr></thead>
          <tbody>
            {pageRows.map((r, i) => (
              <tr key={r.id ?? r.competency_id ?? i}>
                <td className="cell-main">{r.competency_code || r.code || 'Competency'}</td>
                <td>{String(r.mastery_status || r.status || '') === 'Mastered' ? <span className="pill pill-green">Mastered</span> : <span className="pill pill-amber">{String(r.mastery_status || r.status || 'Not mastered')}</span>}</td>
                <td className="tabular">{typeof r.mastery_percent === 'number' ? `${Math.round(r.mastery_percent)}%` : typeof r.mastery_rate_percent === 'number' ? `${Math.round(r.mastery_rate_percent)}%` : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <div className="note-line">Per-competency details appear here when available.</div>
      )}
      {rows.length > 0 && <div className="note-line">Showing {rows.length === 0 ? 0 : (safe - 1) * perPage + 1}–{(safe - 1) * perPage + pageRows.length} of {rows.length}.</div>}
      {rows.length > perPage && <Pager page={safe} pages={pages} onChange={setPage} />}
    </Card>
  );
}

export default function Mastery() {
  const [studentId, setStudentId] = useState('');
  const [competencyId, setCompetencyId] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const [records, setRecords] = useState(null);
  const [summary, setSummary] = useState(null);
  const [busy, setBusy] = useState(false);
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `busy` disables the buttons.
  const busyRef = useRef(false);
  const [error, setError] = useState(null);
  const [recordsPage, setRecordsPage] = useState(1);
  const recordsPerPage = 20;
  // Server-search pickers (debounced, stale-guarded). listUsers ?search=
  // is a real backend filter; subjects and competencies have no search
  // endpoint, so those inputs filter a bulk subject list client-side and
  // stay numeric for competencies.
  const [learnerQuery, setLearnerQuery] = useState('');
  const [learnerOptions, setLearnerOptions] = useState([]);
  const [subjectQuery, setSubjectQuery] = useState('');
  const [subjectOptions, setSubjectOptions] = useState([]);

  useEffect(() => {
    const q = learnerQuery.trim();
    if (q.length < 2 || isDigitsOnly(q)) { setLearnerOptions([]); return undefined; }
    let cancelled = false;
    const t = setTimeout(async () => {
      try {
        const res = await listUsers({ role: 'student', search: q, page: 1, per_page: 8 });
        if (!cancelled) setLearnerOptions(Array.isArray(res?.data) ? res.data : []);
      } catch {
        if (!cancelled) setLearnerOptions([]);
      }
    }, 300);
    return () => { cancelled = true; clearTimeout(t); };
  }, [learnerQuery]);

  useEffect(() => {
    const q = subjectQuery.trim().toLowerCase();
    if (q.length < 2 || isDigitsOnly(q)) { setSubjectOptions([]); return undefined; }
    let cancelled = false;
    const t = setTimeout(async () => {
      try {
        // No subject search endpoint exists — filter one bulk page client-side.
        const res = await listSubjects({ page: 1, per_page: 100 });
        const list = Array.isArray(res?.data) ? res.data : [];
        if (!cancelled) {
          setSubjectOptions(list.filter((s) =>
            `${s.name || ''} ${s.code || ''}`.toLowerCase().includes(q),
          ).slice(0, 8));
        }
      } catch {
        if (!cancelled) setSubjectOptions([]);
      }
    }, 300);
    return () => { cancelled = true; clearTimeout(t); };
  }, [subjectQuery]);

  async function loadRecords() {
    if (busyRef.current || busy) return;
    setError(null);
    setRecords(null);
    setRecordsPage(1);
    const sid = studentId.trim() ? Number(studentId.trim()) : undefined;
    if (studentId.trim() && (!sid || sid <= 0)) { setError('That account number does not look right — use the learner\u2019s account number, or pick from name search above.'); return; }
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await getMasteryRecords({
        student_id: sid,
        competency_id: competencyId.trim() ? Number(competencyId.trim()) : undefined,
        subject_id: subjectId.trim() ? Number(subjectId.trim()) : undefined,
      });
      setRecords(data);
    } catch (e) {
      setError(e);
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  async function loadSummary() {
    if (busyRef.current || busy) return;
    setError(null);
    setSummary(null);
    const sid = Number(studentId.trim());
    if (!sid || sid <= 0) { setError('Enter the learner\u2019s account number, or pick from name search above.'); return; }
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await getMasterySummary(sid);
      setSummary(data);
    } catch (e) {
      setError(e);
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  const recordRows = Array.isArray(records) ? records : Array.isArray(records?.data) ? records.data : records && typeof records === 'object' ? [records] : [];
  // Mastery records arrive as one unpaged payload — page client-side over the
  // full set with an honest total.
  const recordPages = Math.max(1, Math.ceil(recordRows.length / recordsPerPage));
  const safeRecordPage = Math.min(recordsPage, recordPages);
  const recordPageRows = recordRows.slice((safeRecordPage - 1) * recordsPerPage, safeRecordPage * recordsPerPage);

  return (
    <div className="admin-page">
      <PageHead title="Mastery records" sub="Admin view of learner mastery — records plus per-student summary." />
      <Card title="Look up mastery">
        <div className="stacked">
          <div>
            <FormField label="Find learner by name">
              <input className="ff-wide" value={learnerQuery} onChange={(e) => setLearnerQuery(e.target.value)} placeholder="Type at least 2 characters, then pick" />
            </FormField>
            {learnerOptions.length > 0 && (
              <div className="btn-row mt-8">
                {learnerOptions.map((u) => (
                  <button key={u.id} type="button" className="btn btn-sm" onClick={() => { setStudentId(String(u.id)); setLearnerQuery(''); setLearnerOptions([]); }}>{u.name || `Account ${u.id}`}</button>
                ))}
              </div>
            )}
          </div>
          <div className="form-grid-2">
            <FormField label="Learner account">
              <input className="ff-wide" value={studentId} onChange={(e) => setStudentId(e.target.value)} placeholder="Account number — or pick from name search above" inputMode="numeric" />
            </FormField>
            <FormField label="Subject (optional)">
              <input className="ff-wide" value={subjectId} onChange={(e) => setSubjectId(e.target.value)} placeholder="Subject reference — or pick from subject search below" inputMode="numeric" />
            </FormField>
          </div>
          <div>
            <FormField label="Find subject by name">
              <input className="ff-wide" value={subjectQuery} onChange={(e) => setSubjectQuery(e.target.value)} placeholder="Type at least 2 characters, then pick" />
            </FormField>
            {subjectOptions.length > 0 && (
              <div className="btn-row mt-8">
                {subjectOptions.map((s) => (
                  <button key={s.id} type="button" className="btn btn-sm" onClick={() => { setSubjectId(String(s.id)); setSubjectQuery(''); setSubjectOptions([]); }}>{s.code ? `${s.name} (${s.code})` : (s.name || `Subject ${s.id}`)}</button>
                ))}
              </div>
            )}
          </div>
          <FormField label="Competency (optional)" hint={<>Find the reference number in the <Link to="/admin/competencies">competencies catalogue</Link>.</>}>
            <input className="ff-wide" value={competencyId} onChange={(e) => setCompetencyId(e.target.value)} placeholder="Competency reference number" inputMode="numeric" />
          </FormField>
          <div className="btn-row">
            <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={loadRecords}>{busy ? 'Loading…' : 'Load records'}</button>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={loadSummary}>Load summary</button>
          </div>
        </div>
      </Card>
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}

      {summary && (
        <>
          <div className="section-head"><h2>Student summary</h2></div>
          <MasterySummaryCard summary={summary} />
        </>
      )}

      {records && (
        <>
          <div className="section-head"><h2>Records</h2><span className="count">{recordRows.length} rows</span></div>
          {recordRows.length === 0 ? (
            <EmptyState title="No mastery records for these filters" />
          ) : (
            <Card>
              <table className="dtable">
                <thead><tr><th>Student</th><th>Competency</th><th>Status</th><th>Percent</th></tr></thead>
                <tbody>
                  {recordPageRows.map((r, i) => (
                    <tr key={r.id ?? i}>
                      <td>{r.student_name || 'A student'}</td>
                      <td className="cell-main">{r.competency_code || r.code || 'Competency'}</td>
                      <td>{String(r.mastery_status || r.status || '—') === 'Mastered' ? <span className="pill pill-green">Mastered</span> : <span className="pill pill-amber">{String(r.mastery_status || r.status || 'Not mastered')}</span>}</td>
                      <td className="tabular">{typeof r.mastery_percent === 'number' ? `${Math.round(r.mastery_percent)}%` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {recordRows.length > 0 && <div className="note-line">Showing {recordRows.length === 0 ? 0 : (safeRecordPage - 1) * recordsPerPage + 1}–{(safeRecordPage - 1) * recordsPerPage + recordPageRows.length} of {recordRows.length}.</div>}
              <Pager page={safeRecordPage} pages={recordPages} onChange={setRecordsPage} />
            </Card>
          )}
        </>
      )}
    </div>
  );
}
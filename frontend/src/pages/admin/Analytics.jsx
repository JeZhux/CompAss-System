import { useEffect, useMemo, useRef, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import { PageHead, EmptyState, SkeletonTable } from '../../components/admin/ui.jsx';
import { getSchoolWideOverview, getAdminCompetencySummary, getMasteryRecords, getMasterySummary, listUsers, isDigitsOnly } from '../../components/admin/adminApi.js';

function cellToneClass(v) {
  if (v === null || v === undefined) return '';
  if (v >= 80) return 'tone-green';
  if (v >= 60) return 'tone-amber';
  return 'tone-red';
}

export default function Analytics() {
  const [overview, setOverview] = useState(null);
  const [summary, setSummary] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [studentId, setStudentId] = useState('');
  const [drill, setDrill] = useState(null);
  const [drillSummary, setDrillSummary] = useState(null);
  const [drillErr, setDrillErr] = useState(null);
  const [drillBusy, setDrillBusy] = useState(false);
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `drillBusy` disables the button.
  const drillBusyRef = useRef(false);
  // Learner name picker over the real listUsers ?search= filter (debounced,
  // stale-guarded). The account-number input below stays as the fallback.
  const [learnerQuery, setLearnerQuery] = useState('');
  const [learnerOptions, setLearnerOptions] = useState([]);

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
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const [o, s] = await Promise.all([
          getSchoolWideOverview(),
          getAdminCompetencySummary().catch(() => []),
        ]);
        if (cancelled) return;
        setOverview(o || null);
        setSummary(Array.isArray(s) ? s : Array.isArray(s?.data) ? s.data : []);
      } catch (e) {
        if (!cancelled) setError(e);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  const { grades, subjects } = useMemo(() => {
    const gl = overview?.grade_levels || [];
    const gradeNums = gl.map((g) => g.grade_level).sort((a, b) => a - b);
    const subMap = new Map();
    for (const g of gl) {
      for (const s of g.subjects || []) {
        if (!subMap.has(s.subject_id)) subMap.set(s.subject_id, { id: s.subject_id, code: s.subject_code, name: s.subject_name });
      }
    }
    const subs = [...subMap.values()].sort((a, b) => String(a.name).localeCompare(String(b.name)));
    const table = {};
    for (const g of gl) {
      table[g.grade_level] = {};
      for (const s of subs) {
        const found = (g.subjects || []).find((x) => x.subject_id === s.id);
        table[g.grade_level][s.id] = found && found.total_students_assessed > 0 ? Math.round(found.mastery_rate_percent) : null;
      }
    }
    return { grades: gradeNums, subjects: subs, table };
  }, [overview]);

  const rateTable = useMemo(() => {
    const gl = overview?.grade_levels || [];
    const table = {};
    for (const g of gl) {
      table[g.grade_level] = {};
      for (const s of (g.subjects || [])) {
        table[g.grade_level][s.subject_id] = s.total_students_assessed > 0 ? Math.round(s.mastery_rate_percent) : null;
      }
    }
    return table;
  }, [overview]);

  const gaps = useMemo(() => {
    const out = [];
    for (const g of overview?.grade_levels || []) {
      for (const s of g.subjects || []) {
        if (s.total_students_assessed > 0 && s.mastery_rate_percent < 80) {
          out.push({ grade: g.grade_level, subject: s.subject_name, code: s.subject_code, pct: Math.round(s.mastery_rate_percent), assessed: s.total_students_assessed });
        }
      }
    }
    return out.sort((a, b) => a.pct - b.pct);
  }, [overview]);

  const drillRows = drill === null || drill === undefined
    ? null
    : Array.isArray(drill) ? drill : Array.isArray(drill?.data) ? drill.data : (drill && typeof drill === 'object' ? [drill] : []);
  const drillLoaded = drill !== null && drill !== undefined;

  async function runDrill() {
    if (drillBusyRef.current || drillBusy) return;
    setDrillErr(null); setDrill(null); setDrillSummary(null);
    const sid = Number(studentId);
    if (!sid || sid <= 0) { setDrillErr('Enter the learner\u2019s account number, or pick from name search above.'); return; }
    drillBusyRef.current = true;
    setDrillBusy(true);
    try {
      const [records, summ] = await Promise.all([
        getMasteryRecords({ student_id: sid }).catch(() => []),
        getMasterySummary(sid).catch(() => null),
      ]);
      setDrill(Array.isArray(records) ? records : Array.isArray(records?.data) ? records.data : records);
      setDrillSummary(summ);
    } catch (e) {
      setDrillErr(e);
    } finally {
      drillBusyRef.current = false;
      setDrillBusy(false);
    }
  }

  return (
    <div className="admin-page">
      <PageHead title="Analytics" sub="School-wide mastery from recorded assessments." />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {loading ? <SkeletonTable rows={8} /> : error ? null : !overview ? (
        <EmptyState title="No recorded data for analytics" hint="Recorded assessment results will populate this view." />
      ) : (
        <>
          <div className="stat-grid">
            <div className="stat-card"><div className="num">{typeof overview.overall_mastery_rate_percent === 'number' ? `${Math.round(overview.overall_mastery_rate_percent)}%` : '—'}</div><div className="lbl">Overall mastery rate</div><div className="sub">{overview.total_students_assessed ?? 0} assessed</div></div>
            <div className="stat-card"><div className="num">{gaps.length}</div><div className="lbl">Below-threshold flags</div><div className="sub">Subjects under 80%</div></div>
            <div className="stat-card"><div className="num">{(overview.by_competency || []).length}</div><div className="lbl">Competencies tracked</div><div className="sub">School-wide</div></div>
          </div>

          <div className="section-head"><h2>Mastery rate by grade &amp; subject</h2></div>
          {grades.length === 0 || subjects.length === 0 ? (
            <EmptyState title="No recorded assessment results yet for this grade and subject" />
          ) : (
            <>
              <div className="heatmap heatmap--grades" style={{ '--heat-cols': subjects.length }}>
                <div className="hcell corner" />
                {subjects.map((s) => <div key={s.id} className="hcell head">{s.code || s.name}</div>)}
                {grades.map((g) => (
                  <span key={g} className="contents">
                    <div className="hcell head text-left">Grade {g}</div>
                    {subjects.map((s) => {
                      const v = rateTable[g]?.[s.id] ?? null;
                      if (v === null) return <div key={s.id} className="hcell text-faint">—</div>;
                      return <div key={s.id} className={`hcell ${cellToneClass(v)}`}>{v}%</div>;
                    })}
                  </span>
                ))}
              </div>
              <div className="note-line">Cells with — have no recorded assessment data yet for that grade/subject combination.</div>
            </>
          )}

          <div className="section-head"><h2>Gap report</h2><span className="count">{gaps.length} below threshold</span></div>
          {gaps.length === 0 ? (
            <EmptyState title="No below-threshold flags" hint="Every assessed grade/subject is at or above 80%." />
          ) : (
            <Card>
              <div className="scroll-x">
              <table className="dtable">
                <thead><tr><th>Grade</th><th>Subject</th><th>Mastered rate</th><th>Assessed</th></tr></thead>
                <tbody>
                  {gaps.map((g, i) => (
                    <tr key={i}>
                      <td>Grade {g.grade}</td>
                      <td className="cell-main">{g.subject} <span className="meta-faint nowrap">({g.code})</span></td>
                      <td><span className="pill pill-amber">{g.pct}%</span></td>
                      <td>{g.assessed}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            </Card>
          )}

          <div className="section-head"><h2>Competency summary</h2><span className="count">{summary.length} competencies</span></div>
          {summary.length === 0 ? (
            <EmptyState title="No competency aggregates yet" hint="Recorded assessment results will populate this view." />
          ) : (
            <Card>
              <div className="scroll-x">
              <table className="dtable">
                <thead><tr><th>Code</th><th>Descriptor</th><th>Mastered</th><th>Rate</th></tr></thead>
                <tbody>
                  {summary.slice(0, 20).map((c, i) => (
                    <tr key={c.competency_id ?? c.id ?? i}>
                      <td className="cell-main">{c.code}</td>
                      <td className="meta-soft">{c.descriptor || c.competency_descriptor || '—'}</td>
                      <td>{c.mastered_count ?? 0} / {c.total_students_assessed ?? 0}</td>
                      <td>{typeof c.mastery_rate_percent === 'number' ? <span className={`pill pill-${Math.round(c.mastery_rate_percent) >= 80 ? 'green' : Math.round(c.mastery_rate_percent) >= 60 ? 'amber' : 'red'}`}>{Math.round(c.mastery_rate_percent)}%</span> : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
              {summary.length > 20 && <div className="note-line">Showing 20 of {summary.length}.</div>}
            </Card>
          )}

          <div className="section-head"><h2>Drill-down</h2></div>
          <Card title="Student mastery" sub="Per-learner mastery across competencies.">
            <div className="btn-row btn-row--labeled">
              <div className="filter-field">
                <label htmlFor="analytics-learner">Find learner by name</label>
                <input id="analytics-learner" value={learnerQuery} onChange={(e) => setLearnerQuery(e.target.value)} placeholder="Find learner by name (min 2 chars)" className="ff-control" />
              </div>
              <div className="filter-field">
                <label htmlFor="analytics-account">Learner account number</label>
                <input id="analytics-account" value={studentId} onChange={(e) => setStudentId(e.target.value)} placeholder="Enter the learner's account number, or pick from name search above." inputMode="numeric" className="ff-control" />
              </div>
              <button type="button" className="btn btn-sm" disabled={drillBusy} onClick={runDrill}>{drillBusy ? 'Loading…' : 'View mastery history'}</button>
            </div>
            {learnerOptions.length > 0 && (
              <div className="btn-row">
                {learnerOptions.map((u) => (
                  <button key={u.id} type="button" className="btn btn-sm" onClick={() => { setStudentId(String(u.id)); setLearnerQuery(''); setLearnerOptions([]); }}>{u.name || `Account ${u.id}`}</button>
                ))}
              </div>
            )}
            {drillErr ? <ErrorNotice error={drillErr} onRetry={() => window.location.reload()} /> : null}
            {drillSummary && (() => {
              // Real GradingService shape: { student_id, competencies[{competency_id,
              // competency_code, mastery_percent, mastery_status, last_assessed_at}],
              // overall_level, last_updated }. Derive mastered count + rate from the
              // already-fetched summary — no invented fields, no new fetching.
              const comps = Array.isArray(drillSummary.competencies) ? drillSummary.competencies : [];
              const mastered = comps.filter((c) => c.mastery_status === 'Mastered').length;
              const rate = comps.length > 0 ? Math.round((mastered / comps.length) * 100) : null;
              return (
                <div className="ok-line">
                  Summary: {mastered} mastered of {comps.length}{rate !== null ? ` (${rate}%)` : ''}
                  {drillSummary.overall_level ? ` · ${drillSummary.overall_level}` : ''}.
                </div>
              );
            })()}
            {Array.isArray(drillRows) && drillLoaded && (
              drillRows.length === 0
                ? <EmptyState title="No mastery records for this learner yet" hint="Recorded assessment results will populate this view." />
                : (
                  <div className="scroll-x">
                  <table className="dtable">
                    <thead><tr><th>Competency</th><th>Status</th><th>Rate</th></tr></thead>
                    <tbody>
                      {drillRows.slice(0, 50).map((r, i) => (
                        <tr key={r.id ?? r.competency_id ?? i}>
                          <td className="cell-main">{r.competency_code || r.code || 'Competency'}</td>
                          <td>{String(r.mastery_status || r.status || '') === 'Mastered' ? <span className="pill pill-green">Mastered</span> : <span className="pill pill-amber">{String(r.mastery_status || r.status || 'Not mastered')}</span>}</td>
                          <td className="tabular">{typeof r.mastery_percent === 'number' ? `${Math.round(r.mastery_percent)}%` : '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  </div>
                )
            )}
            {Array.isArray(drillRows) && drillLoaded && drillRows.length > 50 && <div className="note-line">Showing 50 of {drillRows.length}.</div>}
          </Card>
        </>
      )}
    </div>
  );
}
import { useEffect, useMemo, useState } from 'react';
import { ErrorNotice } from '../../../components/shared/Feedback.jsx';
import { InlineAlert, EmptyState, SkeletonTable, Modal, MasteryBar, TrendSvg } from '../../../components/teacher/ui.jsx';
import {
  getClassroomHeatmap,
  getNotCompetentFlags,
  getGapReport,
  getTrends,
  getStudentDrillDown,
  getMasteryRecords,
  getClassroomPeople,
  formatDateTime,
  toneForPct,
} from '../../../components/teacher/teacherApi.js';

const CHART_DOT_CLASSES = ['dot-green', 'dot-blue', 'dot-amber', 'dot-purple'];

const SUBTABS = [
  ['summary', 'Summary'],
  ['distribution', 'Distribution'],
  ['flags', 'Flags'],
  ['gap', 'Gap Report'],
  ['heatmap', 'Heatmap'],
  ['drilldown', 'Drill-down'],
];

function compLabel(c) {
  if (!c) return '—';
  const code = c.code || c.competency_code;
  const desc = c.descriptor || c.competence_descriptor || c.competency_descriptor;
  if (code && desc) return `${code} — ${desc}`;
  return desc || code || `Competency ${c.competency_id ?? ''}`;
}

function shortLabel(c, max = 26) {
  const l = compLabel(c);
  return l.length > max ? `${l.slice(0, max)}…` : l;
}

export default function AnalyticsTab({ classroom }) {
  const [tab, setTab] = useState('summary');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [competencies, setCompetencies] = useState([]);
  const [flags, setFlags] = useState([]);
  const [gapStudent, setGapStudent] = useState([]);
  const [gapSection, setGapSection] = useState([]);
  const [people, setPeople] = useState([]);
  const [trends, setTrends] = useState([]);
  const [trendsTotal, setTrendsTotal] = useState(0);
  const [trendLabels, setTrendLabels] = useState([]);

  const [summarySearch, setSummarySearch] = useState('');
  const [summaryPage, setSummaryPage] = useState(1);
  const [distSearch, setDistSearch] = useState('');
  const [distPage, setDistPage] = useState(1);
  const [flagsSearch, setFlagsSearch] = useState('');
  const [flagsComp, setFlagsComp] = useState('all');
  const [flagsPage, setFlagsPage] = useState(1);
  const [gapMode, setGapMode] = useState('student');
  const [gapSearch, setGapSearch] = useState('');
  const [gapPage, setGapPage] = useState(1);
  const [heatSearch, setHeatSearch] = useState('');
  const [heatComp, setHeatComp] = useState('all');
  const [heatPageS, setHeatPageS] = useState(1);
  const [heatPageC, setHeatPageC] = useState(1);
  const [heatCells, setHeatCells] = useState({});
  const [heatLoading, setHeatLoading] = useState(false);
  // Students whose classroom-scoped cell fetch failed. Failures are never
  // cached as data — this set drives per-cell + section-level retry.
  const [heatErrors, setHeatErrors] = useState([]);
  const [heatRetryTick, setHeatRetryTick] = useState(0);
  const [drillSearch, setDrillSearch] = useState('');
  const [drillPage, setDrillPage] = useState(1);
  const [drillStudent, setDrillStudent] = useState(null);
  const [drillData, setDrillData] = useState(null);
  const [drillLoading, setDrillLoading] = useState(false);
  const [drillError, setDrillError] = useState(null);
  const [cellModal, setCellModal] = useState(null);

  const subjectId = classroom.subject_id;
  // Per-section fetch errors. A failed section never renders as
  // "no results" — each failed section shows an error + retry instead.
  const [sectionErrors, setSectionErrors] = useState({});
  const [reloadTick, setReloadTick] = useState(0);
  const retrySections = () => setReloadTick((t) => t + 1);

  // Bound the per-student mastery cache: it resets on every classroom switch
  // (which refetches anyway) and is hard-capped below, so it cannot grow
  // unbounded across a session with large rosters.
  useEffect(() => {
    setHeatCells({});
    setHeatErrors([]);
  }, [classroom.id]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      setSectionErrors({});
      try {
        const [heatR, flagR, gapSR, gapSecR, pplR, trendR] = await Promise.allSettled([
          getClassroomHeatmap(classroom.id),
          getNotCompetentFlags({ subject_id: subjectId }),
          getGapReport({ subject_id: subjectId, group_by: 'student' }),
          getGapReport({ subject_id: subjectId, group_by: 'section' }),
          getClassroomPeople(classroom.id, { per_page: 100 }),
          getTrends({ subject_id: subjectId }),
        ]);
        if (cancelled) return;
        const errs = {};
        if (heatR.status === 'fulfilled') {
          const heat = heatR.value;
          setCompetencies(Array.isArray(heat?.competencies) ? heat.competencies : []);
        } else {
          errs.heatmap = heatR.reason;
          setCompetencies([]);
        }
        if (flagR.status === 'fulfilled') {
          const flagRes = flagR.value;
          setFlags(Array.isArray(flagRes) ? flagRes : Array.isArray(flagRes?.data) ? flagRes.data : []);
        } else {
          errs.flags = flagR.reason;
          setFlags([]);
        }
        if (gapSR.status === 'fulfilled') {
          const gapS = gapSR.value;
          setGapStudent(Array.isArray(gapS?.gaps) ? gapS.gaps : []);
        } else {
          errs.gaps = gapSR.reason;
          setGapStudent([]);
        }
        if (gapSecR.status === 'fulfilled') {
          const gapSec = gapSecR.value;
          setGapSection(Array.isArray(gapSec?.gaps) ? gapSec.gaps : []);
        } else {
          errs.gaps = errs.gaps || gapSecR.reason;
          setGapSection([]);
        }
        if (pplR.status === 'fulfilled') {
          const ppl = pplR.value;
          setPeople(Array.isArray(ppl?.data) ? ppl.data : Array.isArray(ppl) ? ppl : []);
        } else {
          errs.people = pplR.reason;
          setPeople([]);
        }
        if (trendR.status === 'fulfilled') {
          const trendRes = trendR.value;
          const tList = Array.isArray(trendRes?.trends) ? trendRes.trends : Array.isArray(trendRes) ? trendRes : [];
          setTrends(tList.slice(0, 6));
          setTrendsTotal(tList.length);
          setTrendLabels(tList.slice(0, 6).map((t, i) => t.assessment_title || t.title || `Point ${i + 1}`));
        } else {
          errs.trends = trendR.reason;
          setTrends([]);
          setTrendsTotal(0);
          setTrendLabels([]);
        }
        setSectionErrors(errs);
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [classroom.id, subjectId, reloadTick]);

  const peopleById = useMemo(() => {
    const m = new Map();
    for (const p of people) {
      const sid = p.student_id ?? p.studentId ?? p.id;
      if (sid !== undefined) m.set(Number(sid), p.student_name || p.name || `Student ${sid}`);
    }
    return m;
  }, [people]);

  const compById = useMemo(() => {
    const m = new Map();
    for (const c of competencies) {
      if (c.competency_id !== undefined) m.set(Number(c.competency_id), c);
    }
    return m;
  }, [competencies]);

  function flagStudentName(f) {
    return f.student_name || peopleById.get(Number(f.student_id)) || `Student ${f.student_id ?? '—'}`;
  }

  function flagComp(f) {
    return compById.get(Number(f.competency_id)) || { code: f.competency_code, descriptor: f.competence_descriptor, competency_id: f.competency_id };
  }

  /* Heatmap visible-page slices (students × competencies). */
  const heatStudentsAll = useMemo(() => {
    const q = heatSearch.trim().toLowerCase();
    return people.filter((p) => {
      const name = String(p.student_name || p.name || '');
      return !q || name.toLowerCase().includes(q);
    });
  }, [people, heatSearch]);

  const heatCompsAll = useMemo(() => {
    if (heatComp === 'all') return competencies;
    return competencies.filter((c) => String(c.competency_id) === String(heatComp));
  }, [competencies, heatComp]);

  const totalHeatSPages = Math.max(1, Math.ceil(heatStudentsAll.length / 10));
  const totalHeatCPages = Math.max(1, Math.ceil(heatCompsAll.length / 6));
  const safeHeatS = Math.min(heatPageS, totalHeatSPages);
  const safeHeatC = Math.min(heatPageC, totalHeatCPages);
  const heatRows = heatStudentsAll.slice((safeHeatS - 1) * 10, safeHeatS * 10);
  const heatCols = heatCompsAll.slice((safeHeatC - 1) * 6, safeHeatC * 6);

  /* Heatmap cells: per-student values scoped to this classroom's subject.
     The classroom heatmap endpoint carries class aggregates only (no
     per-student cells), so cells come from subject-scoped mastery records —
     never the global per-student summary, which would pool other classes'
     results under this classroom's columns. Cache is keyed by
     classroom+student so switching classrooms refetches instead of reusing
     another classroom's cells. Failures are tracked separately and never
     cached as data. */
  const heatCacheKey = (sid) => `${classroom.id}:${sid}`;

  function retryHeatKey(key) {
    setHeatErrors((prev) => prev.filter((k) => k !== key));
    setHeatRetryTick((t) => t + 1);
  }

  function retryAllHeat() {
    setHeatErrors([]);
    setHeatRetryTick((t) => t + 1);
  }

  useEffect(() => {
    if (tab !== 'heatmap' || heatRows.length === 0) return;
    let cancelled = false;
    (async () => {
      const missing = heatRows
        .map((p) => p.student_id ?? p.id)
        .filter((sid) => sid !== undefined && heatCells[heatCacheKey(sid)] === undefined && !heatErrors.includes(heatCacheKey(sid)));
      if (missing.length === 0) return;
      setHeatLoading(true);
      try {
        const entries = await Promise.all(missing.map(async (sid) => {
          try {
            const recs = await getMasteryRecords({ student_id: sid, subject_id: subjectId });
            const list = Array.isArray(recs) ? recs : Array.isArray(recs?.data) ? recs.data : [];
            // Latest attempt wins per competency (newest record first).
            const sorted = list.slice().sort((a, b) => {
              const ta = new Date(a.last_assessed_at || a.created_at || a.updated_at || 0).getTime();
              const tb = new Date(b.last_assessed_at || b.created_at || b.updated_at || 0).getTime();
              if (tb !== ta) return tb - ta;
              return Number(b.id || 0) - Number(a.id || 0);
            });
            const map = {};
            for (const r of sorted) {
              const cid = r.competency_id ?? r.competencyId;
              if (cid === undefined || cid === null) continue;
              if (map[cid] === undefined && typeof r.mastery_percent === 'number') map[cid] = r.mastery_percent;
            }
            return { sid, map };
          } catch (err) {
            return { sid, error: err };
          }
        }));
        if (cancelled) return;
        const next = {};
        const failed = [];
        for (const e of entries) {
          if (e.error) failed.push(heatCacheKey(e.sid));
          else next[heatCacheKey(e.sid)] = e.map;
        }
        // Cap ~200 cached students (LRU-ish: keep newest); the classroom
        // switch above already resets the cache outright.
        if (Object.keys(next).length) {
          setHeatCells((prev) => {
            const merged = { ...prev, ...next };
            const keys = Object.keys(merged);
            if (keys.length <= 200) return merged;
            const trimmed = {};
            for (const k of keys.slice(keys.length - 200)) trimmed[k] = merged[k];
            return trimmed;
          });
        }
        if (failed.length) {
          setHeatErrors((prev) => [...new Set([...prev, ...failed])]);
        }
      } finally {
        if (!cancelled) setHeatLoading(false);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, safeHeatS, heatSearch, people.length, heatCols.length, heatRetryTick, subjectId]);

  /* Drill-down */
  const drillStudentsAll = useMemo(() => {
    const q = drillSearch.trim().toLowerCase();
    return people.filter((p) => {
      const name = String(p.student_name || p.name || '');
      return !q || name.toLowerCase().includes(q);
    });
  }, [people, drillSearch]);
  const drillTotalPages = Math.max(1, Math.ceil(drillStudentsAll.length / 8));
  const safeDrillPage = Math.min(drillPage, drillTotalPages);
  const drillPageStudents = drillStudentsAll.slice((safeDrillPage - 1) * 8, safeDrillPage * 8);
  const chosenStudent = drillStudent && people.some((p) => (p.student_id ?? p.id) === (drillStudent.student_id ?? drillStudent.id))
    ? drillStudent
    : (drillPageStudents[0] || people[0] || null);

  useEffect(() => {
    if (tab !== 'drilldown' || !chosenStudent) return;
    const sid = chosenStudent.student_id ?? chosenStudent.id;
    if (sid === undefined) return;
    let cancelled = false;
    (async () => {
      setDrillLoading(true);
      setDrillError(null);
      try {
        const d = await getStudentDrillDown({ student_id: sid, subject_id: subjectId });
        if (!cancelled) setDrillData(d);
      } catch (err) {
        if (!cancelled) {
          setDrillData(null);
          setDrillError(err);
        }
      } finally {
        if (!cancelled) setDrillLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [tab, chosenStudent, subjectId]);

  if (loading) return <SkeletonTable rows={6} />;
  if (error) return <ErrorNotice error={error} onRetry={() => window.location.reload()} />;
  if (competencies.length === 0 && flags.length === 0) {
    // A failed section never renders as "no results" — surface the error
    // with a retry instead. The empty state below is for genuine emptiness
    // only (every section loaded cleanly and found nothing).
    const failed = Object.entries(sectionErrors);
    if (failed.length > 0) {
      return (
        <div>
          {failed.map(([key, err]) => (
            <ErrorNotice key={key} error={err} onRetry={retrySections} retryLabel="Retry analytics" />
          ))}
          <div className="note-line">Some analytics sections could not be loaded. Nothing here means a load failed, not that the class has no results.</div>
        </div>
      );
    }
    return <EmptyState title="No Recorded results yet in this class" hint="Mastery appears once students complete Recorded assessments." />;
  }

  const summaryRowsAll = competencies
    .map((c) => ({
      ...c,
      avg: typeof c.average_mastery_percent === 'number' ? c.average_mastery_percent : (c.mastery_rate_percent ?? 0),
    }))
    .filter((c) => compLabel(c).toLowerCase().includes(summarySearch.trim().toLowerCase()));
  const summaryPages = Math.max(1, Math.ceil(summaryRowsAll.length / 8));
  const safeSummaryPage = Math.min(summaryPage, summaryPages);
  const summaryRows = summaryRowsAll.slice((safeSummaryPage - 1) * 8, safeSummaryPage * 8);

  const distRowsAll = competencies
    .map((c) => ({
      ...c,
      mastered: Number(c.mastered_count || 0),
      total: Number(c.total_students_assessed || 0),
    }))
    .filter((c) => compLabel(c).toLowerCase().includes(distSearch.trim().toLowerCase()));
  const distPages = Math.max(1, Math.ceil(distRowsAll.length / 8));
  const safeDistPage = Math.min(distPage, distPages);
  const distRows = distRowsAll.slice((safeDistPage - 1) * 8, safeDistPage * 8);

  let flagsFiltered = flags.slice();
  if (flagsComp !== 'all') flagsFiltered = flagsFiltered.filter((f) => String(f.competency_id) === String(flagsComp));
  const fq = flagsSearch.trim().toLowerCase();
  if (fq) {
    flagsFiltered = flagsFiltered.filter((f) =>
      flagStudentName(f).toLowerCase().includes(fq) || compLabel(flagComp(f)).toLowerCase().includes(fq));
  }
  const flagsPages = Math.max(1, Math.ceil(flagsFiltered.length / 10));
  const safeFlagsPage = Math.min(flagsPage, flagsPages);
  const flagsRows = flagsFiltered.slice((safeFlagsPage - 1) * 10, safeFlagsPage * 10);

  const trendSeries = trends.length === 0 ? [] : [{
    key: 'Class mastery rate',
    values: trends.map((t) => Number(t.mastery_rate_percent ?? t.rate ?? t.value ?? 0)),
  }];
  // Guard the empty-series case explicitly instead of spreading an empty
  // array into Math.max (which yields -Infinity without a fallback).
  const trendLabelCount = trendSeries.length > 0 ? Math.max(...trendSeries.map((s) => s.values.length)) : 0;

  return (
    <div>
      <div className="page-sub page-sub--flush">
        Recorded assessments only · {competencies.length} competencies · {people.length} students · {flags.length} flags below 80% · latest attempt wins.
      </div>

      {trendSeries.length > 0 && (
        <div className="card mb-16">
          <div className="trend-legend">
            {trendSeries.map((s, i) => (
              <span key={s.key}><i className={CHART_DOT_CLASSES[i % 4]} />{s.key}</span>
            ))}
          </div>
          <TrendSvg series={trendSeries} labels={trendLabels.slice(0, trendLabelCount)} />
          <div className="note-line">Trend points reflect released Recorded results over time; regrades correct earlier points immediately.</div>
          {trendsTotal > 6 && <div className="note-line">Showing 6 of {trendsTotal} trends.</div>}
        </div>
      )}

      <div className="analytics-subbar">
        {SUBTABS.map(([k, label]) => (
          <button key={k} type="button" className={tab === k ? 'active' : undefined} onClick={() => setTab(k)}>
            {label}{k === 'flags' ? ` (${flags.length})` : ''}
          </button>
        ))}
      </div>

      {tab === 'summary' && (
        <div>
          {sectionErrors.heatmap ? <ErrorNotice error={sectionErrors.heatmap} onRetry={retrySections} retryLabel="Retry analytics" /> : null}
          <div className="toolbar">
            <input id="ca-summary-search" type="search" placeholder="Search competencies…" value={summarySearch} onChange={(e) => { setSummarySearch(e.target.value); setSummaryPage(1); }} />
            <div className="spacer" />
            <span className="count-note">Showing {summaryRowsAll.length === 0 ? 0 : (safeSummaryPage - 1) * 8 + 1}–{(safeSummaryPage - 1) * 8 + summaryRows.length} of {summaryRowsAll.length} competencies</span>
          </div>
          {summaryRows.length === 0 ? (
            <EmptyState title={`No competencies match “${summarySearch}”`} hint="Try a shorter search, or clear the search." />
          ) : (
            <>
              <div className="scroll-x">
              <table className="data-table">
                <thead><tr><th>Competency</th><th>Class avg</th><th>%</th><th>Mastered</th><th>Status</th></tr></thead>
                <tbody>
                  {summaryRows.map((r) => (
                    <tr key={r.competency_id}>
                      <td>{compLabel(r)}</td>
                      <td><MasteryBar pct={r.avg} /></td>
                      <td>{typeof r.avg === 'number' ? `${Number(r.avg).toFixed(1)}%` : '—'}</td>
                      <td className="meta-faint">{r.mastered_count ?? 0}/{r.total_students_assessed ?? 0}</td>
                      <td>{Number(r.mastery_rate_percent ?? r.avg ?? 0) >= 80 ? <span className="pill pill-green">On track</span> : <span className="pill pill-amber">Needs attention</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
              {summaryPages > 1 && (
                <div className="pagination">
                  <button type="button" disabled={safeSummaryPage <= 1} onClick={() => setSummaryPage(safeSummaryPage - 1)}>← Prev</button>
                  <span>Page {safeSummaryPage} of {summaryPages}</span>
                  <button type="button" disabled={safeSummaryPage >= summaryPages} onClick={() => setSummaryPage(safeSummaryPage + 1)}>Next →</button>
                </div>
              )}
            </>
          )}
        </div>
      )}

      {tab === 'distribution' && (
        <div>
          {sectionErrors.heatmap ? <ErrorNotice error={sectionErrors.heatmap} onRetry={retrySections} retryLabel="Retry analytics" /> : null}
          <div className="toolbar">
            <input id="ca-dist-search" type="search" placeholder="Search competencies…" value={distSearch} onChange={(e) => { setDistSearch(e.target.value); setDistPage(1); }} />
            <div className="spacer" />
            <span className="count-note">Showing {distRowsAll.length === 0 ? 0 : (safeDistPage - 1) * 8 + 1}–{(safeDistPage - 1) * 8 + distRows.length} of {distRowsAll.length}</span>
          </div>
          {distRows.length === 0 ? (
            <EmptyState title="No matches" hint="Clear the search to see all competencies." />
          ) : (
            <>
              <div className="scroll-x">
              <table className="data-table">
                <thead><tr><th>Competency</th><th className="minw-160">Mastered vs needs help</th><th>Mastered</th><th>Needs help</th></tr></thead>
                <tbody>
                  {distRows.map((r) => {
                    const pct = r.total > 0 ? Math.round((r.mastered / r.total) * 100) : 0;
                    return (
                      <tr key={r.competency_id}>
                        <td>{compLabel(r)}</td>
                        <td><div className="dist-bar"><div className="fill-green" style={{ width: `${pct}%` }} /><div className="fill-amber" style={{ width: `${100 - pct}%` }} /></div></td>
                        <td><span className="pill pill-green">{r.mastered}</span></td>
                        <td><span className="pill pill-amber">{Math.max(0, r.total - r.mastered)}</span></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              </div>
              {distPages > 1 && (
                <div className="pagination">
                  <button type="button" disabled={safeDistPage <= 1} onClick={() => setDistPage(safeDistPage - 1)}>← Prev</button>
                  <span>Page {safeDistPage} of {distPages}</span>
                  <button type="button" disabled={safeDistPage >= distPages} onClick={() => setDistPage(safeDistPage + 1)}>Next →</button>
                </div>
              )}
            </>
          )}
        </div>
      )}

      {tab === 'flags' && (
        <div>
          {sectionErrors.flags ? <ErrorNotice error={sectionErrors.flags} onRetry={retrySections} retryLabel="Retry analytics" /> : null}
          <div className="warn-line">{flagsFiltered.length} flag{flagsFiltered.length === 1 ? '' : 's'} below 80% in this classroom. Flags update as soon as you regrade.</div>
          <div className="toolbar">
            <div className="filter-field">
              <label htmlFor="ca-flags-search">Search</label>
              <input id="ca-flags-search" type="search" placeholder="Student or competency…" value={flagsSearch} onChange={(e) => { setFlagsSearch(e.target.value); setFlagsPage(1); }} />
            </div>
            <div className="filter-field">
              <label htmlFor="ca-flags-comp">Competency</label>
              <select id="ca-flags-comp" value={flagsComp} onChange={(e) => { setFlagsComp(e.target.value); setFlagsPage(1); }}>
                <option value="all">All competencies ({competencies.length})</option>
                {competencies.map((c) => <option key={c.competency_id} value={c.competency_id}>{shortLabel(c, 40)}</option>)}
              </select>
            </div>
            <div className="spacer" />
            <span className="count-note">Showing {flagsFiltered.length === 0 ? 0 : (safeFlagsPage - 1) * 10 + 1}–{(safeFlagsPage - 1) * 10 + flagsRows.length} of {flagsFiltered.length}</span>
          </div>
          {flagsRows.length === 0 ? (
            <EmptyState title="No flags match these filters" hint="Everyone here is at or above threshold — or try clearing the search." />
          ) : (
            <>
              <div className="scroll-x">
              <table className="data-table">
                <thead><tr><th>Student</th><th>Competency</th><th>Flagged</th><th></th></tr></thead>
                <tbody>
                  {flagsRows.map((f) => (
                    <tr key={f.id}>
                      <td>{flagStudentName(f)}</td>
                      <td>{compLabel(flagComp(f))}</td>
                      <td className="cell-date">{formatDateTime(f.created_at)}</td>
                      <td><button type="button" className="btn btn-sm" onClick={() => { setTab('drilldown'); const p = people.find((x) => Number(x.student_id ?? x.id) === Number(f.student_id)); if (p) setDrillStudent(p); }}>View student</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
              {flagsPages > 1 && (
                <div className="pagination">
                  <button type="button" disabled={safeFlagsPage <= 1} onClick={() => setFlagsPage(safeFlagsPage - 1)}>← Prev</button>
                  <span>Page {safeFlagsPage} of {flagsPages}</span>
                  <button type="button" disabled={safeFlagsPage >= flagsPages} onClick={() => setFlagsPage(safeFlagsPage + 1)}>Next →</button>
                </div>
              )}
            </>
          )}
        </div>
      )}

      {tab === 'gap' && (
        <div>
          {sectionErrors.gaps ? <ErrorNotice error={sectionErrors.gaps} onRetry={retrySections} retryLabel="Retry analytics" /> : null}
          <div className="gap-toggle">
            <button type="button" className={`btn btn-sm${gapMode === 'student' ? ' btn-primary' : ''}`} onClick={() => { setGapMode('student'); setGapPage(1); }}>By student</button>
            <button type="button" className={`btn btn-sm${gapMode === 'section' ? ' btn-primary' : ''}`} onClick={() => { setGapMode('section'); setGapPage(1); }}>By competency</button>
          </div>
          {gapMode === 'student' ? (
            <GapByStudent gaps={gapStudent} peopleById={peopleById} compById={compById} search={gapSearch} setSearch={setGapSearch} page={gapPage} setPage={setGapPage} onDrill={(sid) => { setTab('drilldown'); const p = people.find((x) => Number(x.student_id ?? x.id) === Number(sid)); if (p) setDrillStudent(p); }} />
          ) : (
            <GapByCompetency gaps={gapSection} search={gapSearch} setSearch={setGapSearch} page={gapPage} setPage={setGapPage} />
          )}
        </div>
      )}

      {tab === 'heatmap' && (
        <div>
          <div className="toolbar">
            <div className="filter-field">
              <label htmlFor="ca-heat-search">Search</label>
              <input id="ca-heat-search" type="search" placeholder="Students…" value={heatSearch} onChange={(e) => { setHeatSearch(e.target.value); setHeatPageS(1); }} />
            </div>
            <div className="filter-field">
              <label htmlFor="ca-heat-comp">Competency</label>
              <select id="ca-heat-comp" value={heatComp} onChange={(e) => { setHeatComp(e.target.value); setHeatPageC(1); }}>
                <option value="all">All competencies ({competencies.length})</option>
                {competencies.map((c) => <option key={c.competency_id} value={c.competency_id}>{shortLabel(c, 40)}</option>)}
              </select>
            </div>
            <div className="spacer" />
            <span className="count-note">{heatStudentsAll.length} students · {heatCompsAll.length} competencies{heatLoading ? ' · loading cells…' : ''}</span>
          </div>
          {sectionErrors.heatmap ? <ErrorNotice error={sectionErrors.heatmap} onRetry={retrySections} retryLabel="Retry analytics" /> : null}
          {sectionErrors.people ? <ErrorNotice error={sectionErrors.people} onRetry={retrySections} retryLabel="Retry analytics" /> : null}
          {heatErrors.length > 0 && (
            <div className="warn-line">
              Could not load cells for {heatErrors.length} student{heatErrors.length === 1 ? '' : 's'} in this class.{' '}
              <button type="button" className="btn btn-sm" onClick={retryAllHeat}>Retry failed cells</button>
            </div>
          )}
          {heatRows.length === 0 || heatCols.length === 0 ? (
            <EmptyState title="Nothing to show" hint="Try clearing the search or competency filter." />
          ) : (
            <>
              <div className="heatmap-matrix-wrap">
                <table className="matrix">
                  <thead><tr><th>Student \ Competency</th>{heatCols.map((c) => <th key={c.competency_id} title={compLabel(c)}>{shortLabel(c, 22)}</th>)}</tr></thead>
                  <tbody>
                    {heatRows.map((p) => {
                      const sid = p.student_id ?? p.id;
                      const name = p.student_name || p.name || `Student ${sid}`;
                      const rowKey = heatCacheKey(sid);
                      const rowFailed = heatErrors.includes(rowKey);
                      return (
                        <tr key={sid}>
                          <th>{name}</th>
                          {heatCols.map((c) => {
                            const pct = heatCells[rowKey]?.[c.competency_id];
                            const known = typeof pct === 'number';
                            if (rowFailed && !known) {
                              return (
                                <td key={c.competency_id}>
                                  <button type="button" className="matrix-cell" title={`Could not load ${name}'s results for this class.`} onClick={() => retryHeatKey(rowKey)}>
                                    Retry
                                  </button>
                                </td>
                              );
                            }
                            return (
                              <td key={c.competency_id}>
                                <button type="button" className={`matrix-cell ${known ? `tone-${toneForPct(pct)}` : 'tone-empty'}`} onClick={() => setCellModal({ student: name, sid, comp: c, pct })}>
                                  {known ? `${Number(pct).toFixed(0)}%` : '—'}
                                </button>
                              </td>
                            );
                          })}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="trend-foot">
                <div className="trend-legend trend-legend--flush">
                  <span><i className="dot-green" />≥80 mastered</span>
                  <span><i className="dot-amber" />60–79</span>
                  <span><i className="dot-red" />&lt;60</span>
                </div>
                <div className="spacer" />
                <div className="pagination pagination--flush">
                  <span>Students</span>
                  <button type="button" disabled={safeHeatS <= 1} onClick={() => setHeatPageS(safeHeatS - 1)}>←</button>
                  <span>{safeHeatS}/{totalHeatSPages}</span>
                  <button type="button" disabled={safeHeatS >= totalHeatSPages} onClick={() => setHeatPageS(safeHeatS + 1)}>→</button>
                </div>
                <div className="pagination pagination--flush">
                  <span>Competencies</span>
                  <button type="button" disabled={safeHeatC <= 1} onClick={() => setHeatPageC(safeHeatC - 1)}>←</button>
                  <span>{safeHeatC}/{totalHeatCPages}</span>
                  <button type="button" disabled={safeHeatC >= totalHeatCPages} onClick={() => setHeatPageC(safeHeatC + 1)}>→</button>
                </div>
              </div>
              <div className="note-line">Click any cell for the exact score and a shortcut to drill-down. Lists are paged so large rosters never turn into one giant grid.</div>
            </>
          )}
        </div>
      )}

      {tab === 'drilldown' && (
        <div className="drill-layout">
          <div>
            {sectionErrors.people ? <ErrorNotice error={sectionErrors.people} onRetry={retrySections} retryLabel="Retry analytics" /> : null}
            <div className="toolbar toolbar--tight">
              <div className="filter-field ff-wide">
                <label htmlFor="ca-drill-search">Search students</label>
                <input id="ca-drill-search" type="search" placeholder="Name…" value={drillSearch} onChange={(e) => { setDrillSearch(e.target.value); setDrillPage(1); }} className="ff-wide" />
              </div>
            </div>
            <div className="student-pick">
              {drillPageStudents.map((p) => {
                const sid = p.student_id ?? p.id;
                const name = p.student_name || p.name || `Student ${sid}`;
                const active = chosenStudent && (chosenStudent.student_id ?? chosenStudent.id) === sid;
                return <button key={sid} type="button" className={active ? 'active' : undefined} onClick={() => setDrillStudent(p)}>{name}</button>;
              })}
              {drillPageStudents.length === 0 && <div className="list-note">No students match.</div>}
            </div>
            {drillTotalPages > 1 && (
              <div className="pagination">
                <button type="button" disabled={safeDrillPage <= 1} onClick={() => setDrillPage(safeDrillPage - 1)}>← Prev</button>
                <span>{safeDrillPage}/{drillTotalPages}</span>
                <button type="button" disabled={safeDrillPage >= drillTotalPages} onClick={() => setDrillPage(safeDrillPage + 1)}>Next →</button>
              </div>
            )}
          </div>
          <div>
            {!chosenStudent ? (
              <EmptyState title="Pick a student" hint="Choose a learner on the left to see per-competency history." />
            ) : drillLoading ? (
              <SkeletonTable rows={5} />
            ) : drillError ? (
              <ErrorNotice error={drillError} onRetry={() => window.location.reload()} />
            ) : !drillData ? (
              <EmptyState title="No drill-down yet" hint="Mastery history appears after Recorded attempts." />
            ) : (
              <>
                <h3 className="trend-title">{chosenStudent.student_name || chosenStudent.name}</h3>
                <div className="page-sub page-sub--tight">Recorded only · latest attempt wins.</div>
                <table className="data-table">
                  <thead><tr><th>Competency</th><th>%</th><th>Status</th><th>Updated</th></tr></thead>
                  <tbody>
                    {(drillData.competencies || []).map((r) => (
                      <tr key={r.competency_id}>
                        <td>{r.descriptor ? `${r.code ? `${r.code} — ` : ''}${r.descriptor}` : (r.code || `Competency ${r.competency_id}`)}</td>
                        <td><span className={`pill pill-${Number(r.current_mastery_percent) >= 80 ? 'green' : Number(r.current_mastery_percent) >= 60 ? 'amber' : 'red'}`}>{Number(r.current_mastery_percent ?? 0).toFixed(0)}%</span></td>
                        <td className="cell-date">{r.current_mastery_status === 'Mastered' ? 'Mastered' : 'Not mastered'}</td>
                        <td className="cell-date">{formatDateTime(r.last_assessed_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {(drillData.competencies || []).length === 0 && <EmptyState title="No competency history" hint="This learner has no Recorded mastery records yet." />}
              </>
            )}
          </div>
        </div>
      )}

      {cellModal && (
        <Modal
          title={cellModal.student}
          sub={`${compLabel(cellModal.comp)} · ${typeof cellModal.pct === 'number' ? `${Number(cellModal.pct).toFixed(1)}%` : 'no Recorded result yet'}`}
          onClose={() => setCellModal(null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" onClick={() => setCellModal(null)}>Close</button>
              <button type="button" className="btn btn-primary" onClick={() => { const p = people.find((x) => (x.student_id ?? x.id) === cellModal.sid); if (p) setDrillStudent(p); setCellModal(null); setTab('drilldown'); }}>Open drill-down</button>
            </>
          }
        />
      )}
    </div>
  );
}

function GapByStudent({ gaps, peopleById, compById, search, setSearch, page, setPage, onDrill }) {
  const q = search.trim().toLowerCase();
  const groups = useMemo(() => {
    const byStudent = new Map();
    for (const g of gaps) {
      const sid = g.student_id;
      if (!byStudent.has(sid)) byStudent.set(sid, []);
      byStudent.get(sid).push(g);
    }
    let list = Array.from(byStudent.entries()).map(([sid, items]) => {
      const name = items[0]?.student_name || peopleById.get(Number(sid)) || `Student ${sid}`;
      const sorted = items.slice().sort((a, b) => Number(a.mastery_percent || 0) - Number(b.mastery_percent || 0));
      return { sid, name, items: sorted, count: sorted.length, lowest: Number(sorted[0]?.mastery_percent || 0) };
    });
    if (q) list = list.filter((g) => g.name.toLowerCase().includes(q));
    list.sort((a, b) => a.lowest - b.lowest);
    return list;
  }, [gaps, peopleById, q]);

  const pages = Math.max(1, Math.ceil(groups.length / 6));
  const safe = Math.min(page, pages);
  const rows = groups.slice((safe - 1) * 6, safe * 6);

  return (
    <div>
      <div className="toolbar">
        <div className="filter-field">
          <label htmlFor="ca-gap-student-search">Search students</label>
          <input id="ca-gap-student-search" type="search" placeholder="Name…" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
        </div>
        <div className="spacer" />
        <span className="count-note">Showing {groups.length === 0 ? 0 : (safe - 1) * 6 + 1}–{(safe - 1) * 6 + rows.length} of {groups.length} students with gaps</span>
      </div>
      {rows.length === 0 ? (
        <EmptyState title="No gaps found" hint="Nothing below threshold matches the current search." />
      ) : (
        <>
          <div className="row-list">
            {rows.map((g) => (
              <div key={g.sid} className="row" role="button" tabIndex={0} aria-label={`View ${g.name} in drill-down`} onClick={() => onDrill(g.sid)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onDrill(g.sid); } }}>
                <div className="main">
                  <div className="title">{g.name}</div>
                  <div className="meta">{g.count} gap{g.count === 1 ? '' : 's'} · lowest {Number(g.lowest).toFixed(0)}% · click to view</div>
                </div>
                <div className="side"><span className="pill pill-amber">{g.count} gap{g.count === 1 ? '' : 's'}</span></div>
              </div>
            ))}
          </div>
          {pages > 1 && (
            <div className="pagination">
              <button type="button" disabled={safe <= 1} onClick={() => setPage(safe - 1)}>← Prev</button>
              <span>Page {safe} of {pages}</span>
              <button type="button" disabled={safe >= pages} onClick={() => setPage(safe + 1)}>Next →</button>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function GapByCompetency({ gaps, search, setSearch, page, setPage }) {
  const q = search.trim().toLowerCase();
  const list = useMemo(() => {
    let rows = gaps.slice();
    if (q) rows = rows.filter((g) => String(g.descriptor || g.code || '').toLowerCase().includes(q));
    rows.sort((a, b) => Number(b.not_mastered_count || 0) - Number(a.not_mastered_count || 0));
    return rows;
  }, [gaps, q]);

  const pages = Math.max(1, Math.ceil(list.length / 6));
  const safe = Math.min(page, pages);
  const rows = list.slice((safe - 1) * 6, safe * 6);

  return (
    <div>
      <div className="toolbar">
        <div className="filter-field">
          <label htmlFor="ca-gap-comp-search">Search competencies</label>
          <input id="ca-gap-comp-search" type="search" placeholder="Code or descriptor…" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
        </div>
        <div className="spacer" />
        <span className="count-note">Showing {list.length === 0 ? 0 : (safe - 1) * 6 + 1}–{(safe - 1) * 6 + rows.length} of {list.length} competencies with gaps</span>
      </div>
      {rows.length === 0 ? (
        <EmptyState title="No gaps found" hint="Try clearing the search." />
      ) : (
        <>
          <div className="row-list">
            {rows.map((g) => (
              <div key={g.competency_id} className="row static">
                <div className="main">
                  <div className="title">{g.descriptor ? `${g.code ? `${g.code} — ` : ''}${g.descriptor}` : (g.code || `Competency ${g.competency_id}`)}</div>
                  <div className="meta">{g.not_mastered_count ?? 0} below 80% · mastery rate {Number(g.mastery_rate_percent ?? 0).toFixed(1)}%</div>
                </div>
                <div className="side"><span className="pill pill-amber">{g.not_mastered_count ?? 0} students</span></div>
              </div>
            ))}
          </div>
          {pages > 1 && (
            <div className="pagination">
              <button type="button" disabled={safe <= 1} onClick={() => setPage(safe - 1)}>← Prev</button>
              <span>Page {safe} of {pages}</span>
              <button type="button" disabled={safe >= pages} onClick={() => setPage(safe + 1)}>Next →</button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
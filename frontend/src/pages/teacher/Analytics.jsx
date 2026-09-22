import { useEffect, useMemo, useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import Card from '../../components/shared/Card.jsx';
import { PageHead, InlineAlert, EmptyState, SkeletonTable, MasteryBar, TrendSvg } from '../../components/teacher/ui.jsx';
import FormField from '../../components/shared/FormField.jsx';
import {
  listTeacherClassrooms,
  getClassroomHeatmap,
  getTrends,
  getNotCompetentFlags,
  getTeacherCompetencySummary,
  getMasteryRecords,
  getMasterySummary,
  getClassroomPeople,
} from '../../components/teacher/teacherApi.js';

const CHART_DOT_CLASSES = ['dot-green', 'dot-blue', 'dot-amber', 'dot-purple'];

export default function Analytics() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rooms, setRooms] = useState([]);
  const [classroomId, setClassroomId] = useState('');
  const [subjectId, setSubjectId] = useState(null);
  const [competencies, setCompetencies] = useState([]);
  const [selected, setSelected] = useState([]);
  const [compSearch, setCompSearch] = useState('');
  const [trendPoints, setTrendPoints] = useState([]);
  const [trendLabels, setTrendLabels] = useState([]);
  const [flags, setFlags] = useState([]);
  const [summary, setSummary] = useState([]);
  const [masteryProbe, setMasteryProbe] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const list = await listTeacherClassrooms().catch(() => []);
        const arr = Array.isArray(list) ? list : Array.isArray(list?.data) ? list.data : [];
        if (cancelled) return;
        setRooms(arr);
        if (arr.length > 0) {
          setClassroomId(String(arr[0].id));
          setSubjectId(arr[0].subject_id ?? null);
        }
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (!classroomId) return;
    let cancelled = false;
    (async () => {
      try {
        const room = rooms.find((r) => String(r.id) === String(classroomId));
        const sid = room?.subject_id ?? subjectId;
        if (sid) setSubjectId(sid);
        const [heat, trendRes, flagRes, sumRes] = await Promise.all([
          getClassroomHeatmap(classroomId).catch(() => ({ competencies: [] })),
          sid ? getTrends({ subject_id: sid }).catch(() => ({ trends: [] })) : { trends: [] },
          getNotCompetentFlags({ classroom_id: classroomId, subject_id: sid || undefined }).catch(() => []),
          getTeacherCompetencySummary({ classroom_id: classroomId, subject_id: sid || undefined }).catch(() => []),
        ]);
        if (cancelled) return;
        const comps = Array.isArray(heat?.competencies) ? heat.competencies : [];
        setCompetencies(comps);
        setSelected((prev) => {
          const valid = prev.filter((s) => comps.some((c) => String(c.competency_id) === String(s)));
          if (valid.length > 0) return valid.slice(0, 4);
          return comps.slice(0, 2).map((c) => String(c.competency_id));
        });
        const tList = Array.isArray(trendRes?.trends) ? trendRes.trends : [];
        // Trends payload is per-assessment aggregates; keep raw for the table and
        // derive one polyline per selected competency below.
        setTrendPoints(tList);
        setTrendLabels(tList.map((t, i) => t.assessment_title || t.title || `Point ${i + 1}`));
        const flagList = Array.isArray(flagRes) ? flagRes : Array.isArray(flagRes?.data) ? flagRes.data : [];
        setFlags(flagList);
        const sumList = Array.isArray(sumRes) ? sumRes : Array.isArray(sumRes?.data) ? sumRes.data : [];
        setSummary(sumList);
      } catch (err) {
        if (!cancelled) setError(err);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [classroomId]);

  // Mastery probe: first student's summary, to prove mastery views work end to end.
  useEffect(() => {
    if (!classroomId) return;
    let cancelled = false;
    (async () => {
      try {
        const ppl = await getClassroomPeople(classroomId, { per_page: 5 }).catch(() => ({ data: [] }));
        const list = Array.isArray(ppl?.data) ? ppl.data : [];
        if (!list.length || cancelled) return;
        const sid = list[0].student_id ?? list[0].id;
        const [records, summ] = await Promise.all([
          getMasteryRecords({ student_id: sid }).catch(() => []),
          getMasterySummary(sid).catch(() => null),
        ]);
        if (!cancelled) setMasteryProbe({ student: list[0].student_name || list[0].name, records, summary: summ });
      } catch {
        if (!cancelled) setMasteryProbe(null);
      }
    })();
    return () => { cancelled = true; };
  }, [classroomId]);

  const compById = useMemo(() => {
    const m = new Map();
    for (const c of competencies) m.set(String(c.competency_id), c);
    return m;
  }, [competencies]);

  const pickList = useMemo(() => {
    const q = compSearch.trim().toLowerCase();
    return competencies.filter((c) => {
      const label = `${c.code || ''} ${c.descriptor || ''}`.toLowerCase();
      return !q || label.includes(q);
    });
  }, [competencies, compSearch]);

  function toggleComp(id) {
    setSelected((prev) => {
      if (prev.includes(id)) return prev.filter((s) => s !== id);
      if (prev.length >= 4) return prev;
      return [...prev, id];
    });
  }

  const series = useMemo(() => {
    // Trend endpoint aggregates per assessment; chart the class mastery rate
    // across assessments, split by selected competency when the payload carries
    // per-competency points, otherwise one class line.
    if (trendPoints.length === 0) return [];
    const withComp = trendPoints.filter((t) => t.competency_id || t.competency_code);
    if (withComp.length > 0 && selected.length > 0) {
      return selected.slice(0, 4).map((sid) => {
        const comp = compById.get(String(sid));
        const vals = trendPoints
          .filter((t) => String(t.competency_id ?? '') === String(sid))
          .map((t) => Number(t.mastery_rate_percent ?? t.rate ?? 0));
        return { key: comp?.code || comp?.descriptor || `Competency ${sid}`, values: vals };
      }).filter((s) => s.values.length > 0);
    }
    return [{
      key: 'Class mastery rate',
      values: trendPoints.map((t) => Number(t.mastery_rate_percent ?? t.rate ?? t.value ?? 0)),
    }];
  }, [trendPoints, selected, compById]);

  const selectedLabels = useMemo(() => selected.map((sid) => {
    const c = compById.get(String(sid));
    if (!c) return `Competency ${sid}`;
    return c.code && c.descriptor ? `${c.code} — ${c.descriptor}` : (c.descriptor || c.code);
  }), [selected, compById]);

  // Guard the empty-series case explicitly instead of spreading an empty
  // array into Math.max (which yields -Infinity without a fallback).
  const maxSeriesLen = series.length > 0 ? Math.max(...series.map((s) => s.values.length)) : 0;

  if (loading) return <div className="teacher-page"><SkeletonTable rows={6} /></div>;

  return (
    <div className="teacher-page">
      <PageHead
        title="Analytics — Trends"
        sub="Global view. Classroom detail (flags, gaps, heatmaps) lives inside each classroom's Analytics tab."
      />
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {error ? null : rooms.length === 0 ? (
        <EmptyState title="No classrooms yet" hint="Trends appear once you own a classroom with Recorded results." />
      ) : (
        <>
          <div className="two-col drill-cols">
            <Card title="Classroom">
              <form className="stacked" onSubmit={(e) => e.preventDefault()}>
                <FormField label="Classroom">
                  <select
                    value={classroomId}
                    onChange={(e) => { setClassroomId(e.target.value); setCompSearch(''); }}
                  >
                    {rooms.map((r) => <option key={r.id} value={r.id}>{r.name || `Classroom ${r.id}`}</option>)}
                  </select>
                </FormField>
                <FormField label="Find competencies">
                  <input type="search" value={compSearch} onChange={(e) => setCompSearch(e.target.value)} placeholder={`Type to filter ${competencies.length} competencies…`} />
                </FormField>
                <div className="comp-pick-list">
                  {pickList.length === 0
                    ? <div className="list-note--sm">No competencies match.</div>
                    : pickList.map((c) => (
                      <label key={c.competency_id}>
                        <input type="checkbox" checked={selected.includes(String(c.competency_id))} onChange={() => toggleComp(String(c.competency_id))} />
                        {(c.code ? `${c.code} — ` : '') + (c.descriptor || `Competency ${c.competency_id}`)}
                      </label>
                    ))}
                </div>
                <div className="note-line note-line--flush">Pick up to 4 to keep the chart readable. {competencies.length} available.</div>
              </form>
            </Card>
            <div>
              <div className="chip-row">
                {selectedLabels.map((s, i) => (
                  <span key={s} className="chip">
                    <i className={`chip-dot ${CHART_DOT_CLASSES[i % 4]}`} />
                    {s}
                    <button type="button" onClick={() => setSelected((prev) => prev.filter((_, j) => j !== i))} aria-label={`Remove ${s}`}>×</button>
                  </span>
                ))}
                {selectedLabels.length === 0 && <span className="page-sub">Select at least one competency.</span>}
              </div>
              {series.length === 0 ? (
                <EmptyState title="Nothing to chart" hint="Tick at least one competency on the left, or wait for Recorded results." />
              ) : (
                <>
                  <Card>
                    <div className="trend-legend">
                      {series.map((s, i) => <span key={s.key}><i className={CHART_DOT_CLASSES[i % 4]} />{s.key}</span>)}
                    </div>
                    <TrendSvg series={series} labels={trendLabels.slice(0, maxSeriesLen)} />
                    <div className="note-line">Trends aggregate released Recorded results over time; regrades correct earlier points immediately.</div>
                  </Card>
                  <div className="scroll-x">
                  <table className="data-table mt-14">
                    <thead><tr><th>Point</th>{series.map((s) => <th key={s.key}>{String(s.key).length > 20 ? `${String(s.key).slice(0, 20)}…` : s.key}</th>)}</tr></thead>
                    <tbody>
                      {trendLabels.slice(0, maxSeriesLen).map((d, di) => (
                        <tr key={di}>
                          <td>{d}</td>
                          {series.map((s) => {
                            const v = Number(s.values[di] ?? 0);
                            return <td key={s.key}><span className={`pill pill-${v >= 80 ? 'green' : v >= 60 ? 'amber' : 'red'}`}>{v.toFixed(0)}%</span></td>;
                          })}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  </div>
                </>
              )}
            </div>
          </div>

          <div className="section-head"><h2>Competency summary</h2><span className="count">{summary.length} competencies</span></div>
          {summary.length === 0 ? (
            <EmptyState title="No summary yet" hint="Summaries aggregate once Recorded attempts are scored." />
          ) : (
            <Card>
              <div className="scroll-x">
              <table className="data-table">
                <thead><tr><th>Competency</th><th>Avg</th><th>Mastered</th><th>Status</th></tr></thead>
                <tbody>
                  {summary.slice(0, 8).map((r) => (
                    <tr key={r.competency_id ?? r.code}>
                      <td>{r.descriptor ? `${r.code ? `${r.code} — ` : ''}${r.descriptor}` : (r.code || `Competency ${r.competency_id}`)}</td>
                      <td><MasteryBar pct={r.average_mastery_percent ?? r.mastery_rate_percent} /></td>
                      <td className="meta-faint">{r.mastered_count ?? 0}/{r.total_students_assessed ?? r.total_records ?? 0}</td>
                      <td>{Number(r.mastery_rate_percent ?? 0) >= 80 ? <span className="pill pill-green">On track</span> : <span className="pill pill-amber">Needs attention</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
              {summary.length > 8 && <div className="note-line">Showing 8 of {summary.length}.</div>}
            </Card>
          )}

          <div className="section-head"><h2>Competency flags</h2><span className="count">{flags.length} below 80%</span></div>
          {flags.length === 0 ? (
            <EmptyState title="No flags" hint="Not-competent flags appear here when learners fall below 80%." />
          ) : (
            <Card>
              <div className="scroll-x">
              <table className="data-table">
                <thead><tr><th>Student</th><th>Competency</th><th>Flagged</th></tr></thead>
                <tbody>
                  {flags.slice(0, 8).map((f) => (
                    <tr key={f.id}>
                      <td>Student {f.student_id}</td>
                      <td>{f.competence_descriptor ? `${f.competency_code ? `${f.competency_code} — ` : ''}${f.competence_descriptor}` : (f.competency_code || `Competency ${f.competency_id}`)}</td>
                      <td className="cell-date">{f.created_at ? new Date(f.created_at).toLocaleDateString() : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
              {flags.length > 8 && <div className="note-line">Showing 8 of {flags.length}.</div>}
              <div className="note-line">Below the 80% mastery threshold, computed at query time from the latest Recorded attempt.</div>
            </Card>
          )}

          {masteryProbe && (
            <>
              <div className="section-head"><h2>Mastery spotlight</h2><span className="count">{masteryProbe.student}</span></div>
              <Card>
                {(masteryProbe.summary?.competencies || []).length === 0 ? (
                  <div className="note-line">No mastery records for {masteryProbe.student} yet.</div>
                ) : (
                  <div className="scroll-x">
                  <table className="data-table">
                    <thead><tr><th>Competency</th><th>%</th><th>Status</th></tr></thead>
                    <tbody>
                      {(masteryProbe.summary.competencies || []).slice(0, 6).map((c) => (
                        <tr key={c.competency_id}>
                          <td>{c.competency_code || `Competency ${c.competency_id}`}</td>
                          <td><span className={`pill pill-${Number(c.mastery_percent) >= 80 ? 'green' : Number(c.mastery_percent) >= 60 ? 'amber' : 'red'}`}>{Number(c.mastery_percent).toFixed(0)}%</span></td>
                          <td className="cell-date">{c.mastery_status}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  </div>
                )}
                {(masteryProbe.summary?.competencies || []).length > 6 && <div className="note-line">Showing 6 of {(masteryProbe.summary.competencies || []).length}.</div>}
                <div className="note-line">Full per-learner history lives in each classroom&apos;s Analytics → Drill-down. <Link to={`/teacher/classrooms/${classroomId}?tab=analytics`}>Open classroom analytics →</Link></div>
              </Card>
            </>
          )}
        </>
      )}
    </div>
  );
}
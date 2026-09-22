import { useState } from 'react';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import '../../components/teacher/teacher.css';
import Card from '../../components/shared/Card.jsx';
import { PageHead, EmptyState, SkeletonTable } from '../../components/teacher/ui.jsx';
import FormField from '../../components/shared/FormField.jsx';
import { getClassLevelReport, ApiError } from '../../components/teacher/teacherApi.js';

/* Class-level mastery report (#75): per-competency mastered / not-mastered
   distribution for one section, latest Recorded result per student wins.
   Real fields only: section_id, section_name, total_students, generated_at,
   competency_reports[{competency_id, competency_code, descriptor,
   mastered_count, not_mastered_count, total_assessed, not_mastered_percent}]. */

function masteredRate(r) {
  const total = Number(r.total_assessed ?? 0);
  if (total <= 0) return null;
  return Math.round((Number(r.mastered_count ?? 0) / total) * 100);
}

export default function ClassReport() {
  const [sectionId, setSectionId] = useState('');
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [loaded, setLoaded] = useState(false);

  async function load(e) {
    e?.preventDefault();
    setError(null);
    setReport(null);
    const sid = sectionId.trim() ? Number(sectionId.trim()) : NaN;
    if (!Number.isInteger(sid) || sid <= 0) {
      setError('Enter the section number for the class you want to review.');
      setLoaded(true);
      return;
    }
    setLoading(true);
    try {
      const data = await getClassLevelReport(sid);
      setReport(data?.data ?? data ?? null);
      setLoaded(true);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) {
        setError('You are not assigned to any class in this section. If this keeps happening, contact your school administrator.');
      } else if (err instanceof ApiError && err.status === 404) {
        setError('Section not found. Check the section number and try again.');
      } else {
        setError(err);
      }
      setLoaded(true);
    } finally {
      setLoading(false);
    }
  }

  const rows = Array.isArray(report?.competency_reports) ? report.competency_reports : [];

  return (
    <div className="teacher-page">
      <PageHead
        title="Class report"
        sub="Per-competency mastered vs. not-mastered for one section — latest Recorded result per student."
      />
      <Card title="Pick a section">
        <form className="stacked" onSubmit={load}>
          <FormField label="Section number">
            <input
              value={sectionId}
              onChange={(e) => setSectionId(e.target.value)}
              placeholder="e.g. 12"
              inputMode="numeric"
            />
          </FormField>
          <div>
            <button type="submit" className="btn btn-sm btn-primary" disabled={loading}>{loading ? 'Loading…' : 'Load report'}</button>
          </div>
        </form>
      </Card>
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}

      {loading ? (
        <SkeletonTable rows={6} />
      ) : !loaded ? (
        <EmptyState title="No report yet" hint="Enter a section number above to see how the class is doing per competency." />
      ) : !report || rows.length === 0 ? (
        !error ? <EmptyState title="No assessed competencies yet" hint="Results appear here once Recorded assessments in this section are scored." /> : null
      ) : (
        <>
          <div className="section-head">
            <h2>{report.section_name || `Section ${report.section_id}`}</h2>
            <span className="count">{report.total_students ?? 0} students · {rows.length} competencies</span>
          </div>
          <Card>
            <table className="data-table">
              <thead><tr><th>Competency</th><th>Mastered</th><th>Not mastered</th><th>Assessed</th><th>Mastery rate</th></tr></thead>
              <tbody>
                {rows.map((r) => {
                  const rate = masteredRate(r);
                  return (
                    <tr key={r.competency_id}>
                      <td className="cell-main">
                        {r.competency_code || `Competency ${r.competency_id}`}
                        {r.descriptor ? <div className="cell-sub">{r.descriptor}</div> : null}
                      </td>
                      <td><span className="pill pill-green">{r.mastered_count ?? 0}</span></td>
                      <td><span className="pill pill-amber">{r.not_mastered_count ?? 0}</span></td>
                      <td className="meta-faint tabular">{r.total_assessed ?? 0}</td>
                      <td className="tabular">
                        {rate === null ? '—' : <span className={`pill pill-${rate >= 80 ? 'green' : rate >= 60 ? 'amber' : 'red'}`}>{rate}%</span>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            <div className="note-line">
              Not-mastered share per competency is computed from the latest Recorded attempt.
              {report.generated_at ? ` Generated ${new Date(report.generated_at).toLocaleString()}.` : ''}
            </div>
          </Card>
        </>
      )}
    </div>
  );
}

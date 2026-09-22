import { useEffect, useMemo, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import Pager from '../../components/shared/Pager.jsx';
import { PageHead, EmptyState, SkeletonTable } from '../../components/admin/ui.jsx';
import { listCompetencyTags, listSubjects, isDigitsOnly, ApiError } from '../../components/admin/adminApi.js';

const PER_PAGE = 15;
const GRADES = ['7', '8', '9', '10', '11', '12'];
const SEMESTERS = ['1', '2', '3'];

export default function Competencies() {
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [subjectId, setSubjectId] = useState('all');
  const [grade, setGrade] = useState('all');
  const [semester, setSemester] = useState('all');
  const [subjects, setSubjects] = useState([]);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  // The subject filter loads one bulk page: when it fills the page, more
  // subjects may exist beyond the dropdown — say so honestly.
  const [subjectsCapped, setSubjectsCapped] = useState(false);

  const digitsOnly = isDigitsOnly(search);
  const tooShort = search.trim() !== '' && search.trim().length === 1;

  // Debounce the search input so the server is not hit on every keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  // Guards mirror listCompetencyTags: digits-only and min-2 never reach the server.
  const effectiveSearch = useMemo(() => {
    const q = String(debouncedSearch ?? '').trim();
    if (q.length >= 2 && !isDigitsOnly(q)) return q;
    return undefined;
  }, [debouncedSearch]);

  // Subject options for the filter dropdown (single bulk load; the subject
  // catalogue is small and has no search endpoint).
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await listSubjects({ page: 1, per_page: 100 }).catch(() => ({ data: [] }));
        if (!cancelled) {
          setSubjects(Array.isArray(res?.data) ? res.data : []);
          setSubjectsCapped(Array.isArray(res?.data) && res.data.length >= 100);
        }
      } catch {
        if (!cancelled) { setSubjects([]); setSubjectsCapped(false); }
      }
    })();
    return () => { cancelled = true; };
  }, []);

  useEffect(() => { setPage(1); }, [debouncedSearch, subjectId, grade, semester]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const res = await listCompetencyTags({
          search: effectiveSearch,
          subject_id: subjectId !== 'all' ? subjectId : undefined,
          grade_level: grade !== 'all' ? grade : undefined,
          semester: semester !== 'all' ? semester : undefined,
          page,
          per_page: PER_PAGE,
        });
        if (cancelled) return;
        setRows(Array.isArray(res?.data) ? res.data : []);
        setMeta(res?.meta || null);
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 422) {
          // Only search-field failures are search errors — other 422s
          // (subject, grade, semester, paging) surface as-is, never as search errors.
          const fieldMap = err.fields && typeof err.fields === 'object' ? err.fields : null;
          const keys = fieldMap ? Object.keys(fieldMap) : [];
          if (keys.length === 0 || keys.includes('search')) {
            setError(friendlyError(err, { action: 'defaults', fallback: 'Search by code or descriptor only. Numbers cannot be used for search.' }));
          } else {
            setError(err);
          }
        } else if (err instanceof ApiError && err.status === 403) {
          setError('You do not have permission to view competency tags. If this keeps happening, contact your school administrator.');
        } else {
          setError(err);
        }
        setRows([]);
        setMeta(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [effectiveSearch, subjectId, grade, semester, page]);

  const total = meta?.total ?? rows.length;
  const totalPages = meta?.last_page ?? 1;
  const safePage = meta?.current_page ?? page;

  return (
    <div className="admin-page">
      <PageHead title="Competencies" sub={`${total} competency tags in the catalogue.`} />
      <div className="filter-bar filter-bar--labeled">
        <div className="filter-field">
          <label htmlFor="comp-search">Search</label>
          <input
            id="comp-search"
            type="text"
            placeholder="Code or descriptor (min 2 chars)"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="filter-field">
          <label htmlFor="comp-subject">Subject</label>
          <select id="comp-subject" value={subjectId} onChange={(e) => setSubjectId(e.target.value)}>
            <option value="all">All subjects</option>
            {subjects.map((s) => (
              <option key={s.id} value={s.id}>{s.code ? `${s.name} (${s.code})` : s.name}</option>
            ))}
          </select>
        </div>
        <div className="filter-field">
          <label htmlFor="comp-grade">Grade level</label>
          <select id="comp-grade" value={grade} onChange={(e) => setGrade(e.target.value)}>
            <option value="all">All grades</option>
            {GRADES.map((g) => <option key={g} value={g}>Grade {g}</option>)}
          </select>
        </div>
        <div className="filter-field">
          <label htmlFor="comp-semester">Semester</label>
          <select id="comp-semester" value={semester} onChange={(e) => setSemester(e.target.value)}>
            <option value="all">All semesters</option>
            {SEMESTERS.map((s) => <option key={s} value={s}>Semester {s}</option>)}
          </select>
        </div>
      </div>
      {digitsOnly && <div className="err-line">Search can&apos;t be digits only — try a code or descriptor instead.</div>}
      {tooShort && !digitsOnly && <div className="note-line mb-12">Type at least 2 characters to search.</div>}
      {subjectsCapped && <div className="note-line mb-12">Subject filter shows the first 100 subjects.</div>}
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}

      {loading ? (
        <SkeletonTable rows={8} />
      ) : error ? null : rows.length === 0 ? (
        <EmptyState title="No competency tags match these filters" hint="Try a different search text, subject, grade, or semester — or import tags from Onboarding (columns: code, descriptor, subject_id, grade_level, semester)." />
      ) : (
        <Card>
          <div className="scroll-x">
          <table className="dtable">
            <thead><tr><th>Code</th><th>Descriptor</th><th>Subject</th><th>Grade</th><th>Semester</th></tr></thead>
            <tbody>
              {rows.map((t) => (
                <tr key={t.id}>
                  <td><div className="cell-main">{t.code || '—'}</div></td>
                  <td className="meta-soft">{t.descriptor || '—'}</td>
                  <td>{t.subject_name ? `${t.subject_name}${t.subject_code ? ` (${t.subject_code})` : ''}` : (t.subject_id ? `Subject ${t.subject_id}` : '—')}</td>
                  <td className="meta-faint">{t.grade_level ?? '—'}</td>
                  <td className="meta-faint">{t.semester ? `Semester ${t.semester}` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
          <Pager page={safePage} pages={totalPages} onChange={setPage} />
        </Card>
      )}
    </div>
  );
}

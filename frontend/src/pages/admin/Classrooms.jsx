import { useEffect, useMemo, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link } from 'react-router-dom';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import Pager from '../../components/shared/Pager.jsx';
import { PageHead, EmptyState, SkeletonTable } from '../../components/admin/ui.jsx';
import { listClassrooms, isDigitsOnly, ApiError } from '../../components/admin/adminApi.js';

const PER_PAGE = 15;

function masteryPercent(m) {
  if (!m) return null;
  if (typeof m.mastery_rate_percent === 'number' && m.total_students_assessed > 0) return Math.round(m.mastery_rate_percent);
  if (typeof m.average_mastery_percent === 'number') return Math.round(m.average_mastery_percent);
  if (typeof m.average === 'number') return Math.round(m.average);
  return null;
}

export default function Classrooms() {
  const [statusFilter, setStatusFilter] = useState('all');
  const [yearFilter, setYearFilter] = useState('all');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const digitsOnly = isDigitsOnly(search);
  const tooShort = search.trim() !== '' && search.trim().length === 1;

  // Debounce the search input so the server is not hit on every keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 350);
    return () => clearTimeout(t);
  }, [search]);

  // Guards mirror adminApi.listClassrooms: digits-only and min-2 never reach the server.
  const effectiveSearch = useMemo(() => {
    const q = String(debouncedSearch ?? '').trim();
    if (q.length >= 2 && !isDigitsOnly(q)) return q;
    return undefined;
  }, [debouncedSearch]);

  useEffect(() => { setPage(1); }, [statusFilter, yearFilter, debouncedSearch]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const archived = statusFilter === 'active' ? false : statusFilter === 'archived' ? true : undefined;
        const schoolYear = yearFilter !== 'all' ? yearFilter : undefined;
        const res = await listClassrooms({ archived, school_year: schoolYear, page, per_page: PER_PAGE, search: effectiveSearch });
        if (cancelled) return;
        setRows(Array.isArray(res?.data) ? res.data : []);
        setMeta(res?.meta || null);
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 422) setError(friendlyError(err, { action: 'defaults', fallback: 'Search by name or code only. Numbers cannot be used for search.' }));
        else if (err instanceof ApiError && err.status === 403) setError('You do not have permission to list classrooms. If this keeps happening, contact your school administrator.');
        else if (err instanceof ApiError && err.status === 404) setError('We could not load classrooms. If this keeps happening, contact your school administrator.');
        else setError(err);
        setRows([]);
        setMeta(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [statusFilter, yearFilter, page, effectiveSearch]);

  const years = useMemo(() => {
    const s = new Set();
    for (const c of rows) {
      const y = c.school_year || c.school_year_name;
      if (y) s.add(y);
    }
    // Keep the active year selectable even when the current page holds no rows for it.
    if (yearFilter !== 'all') s.add(yearFilter);
    return [...s].sort();
  }, [rows, yearFilter]);

  // All filters (archived, school_year, search) are applied server-side;
  // the rows here are exactly the current page.
  const pageItems = rows;
  const totalPages = meta?.last_page ?? 1;
  const safePage = meta?.current_page ?? page;

  return (
    <div className="admin-page">
      <PageHead title="Classrooms" sub="All classrooms across the school, with enrollment and mastery summaries." />
      <div className="filter-bar filter-bar--labeled">
        <div className="filter-field">
          <label htmlFor="rooms-search">Search</label>
          <input id="rooms-search" type="text" placeholder="Classroom, teacher, subject (min 2 chars)" value={search} onChange={(e) => setSearch(e.target.value)} className="w-search" />
        </div>
        <div className="filter-field">
          <label htmlFor="rooms-status">Status</label>
          <select id="rooms-status" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="all">All classrooms</option>
            <option value="active">Active only</option>
            <option value="archived">Archived only</option>
          </select>
        </div>
        <div className="filter-field">
          <label htmlFor="rooms-year">School year</label>
          <select id="rooms-year" value={yearFilter} onChange={(e) => setYearFilter(e.target.value)}>
            <option value="all">All years</option>
            {years.map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </div>
      </div>
      {digitsOnly && <div className="err-line">Search can&apos;t be digits only — try a name instead.</div>}
      {tooShort && !digitsOnly && <div className="note-line mb-12">Type at least 2 characters to search.</div>}
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}

      {loading ? <SkeletonTable rows={8} /> : error ? null : pageItems.length === 0 ? (
        <EmptyState title="No classrooms match these filters" hint="Try a different status, year, or search." />
      ) : (
        <Card>
          <table className="dtable">
            <thead><tr><th>Classroom</th><th>Teacher</th><th>Year</th><th>Key</th><th>Enrolled</th><th>Mastery</th><th>Status</th></tr></thead>
            <tbody>
              {pageItems.map((c) => {
                const mp = masteryPercent(c.mastery);
                return (
                  <tr key={c.id}>
                    <td><div className="cell-main"><Link to={`/admin/classrooms/${c.id}`}>{c.name || `Classroom ${c.id}`}</Link></div><div className="cell-sub">{c.subject_name ? `${c.subject_name} · ` : ''}{c.section_name || c.suffix || ''}</div></td>
                    <td><div className="cell-main">{c.teacher_name || '—'}</div>{c.teacher_school_id ? <div className="cell-sub mono">{c.teacher_school_id}</div> : null}</td>
                    <td>{c.school_year || c.school_year_name || '—'}</td>
                    <td>{c.is_join_enabled ? <span className="pill pill-green">Active</span> : <span className="pill pill-neutral">Revoked</span>}</td>
                    <td className="tabular">{c.enrollment_count ?? 0}</td>
                    <td>
                      {mp === null
                        ? <span className="meta-faint">No recorded data</span>
                        : <span className="bar-inline"><span className="bar-track"><span className={`bar-fill ${mp >= 80 ? 'fill-green' : 'fill-amber'}`} style={{ width: `${mp}%` }} /></span><span>{mp}%</span></span>}
                    </td>
                    <td>{c.archived_at ? <span className="pill pill-neutral">Archived</span> : (c.is_join_enabled ? <span className="pill pill-green">Joining on</span> : <span className="pill pill-amber">Joining off</span>)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <Pager page={safePage} pages={totalPages} onChange={setPage} />
        </Card>
      )}
    </div>
  );
}

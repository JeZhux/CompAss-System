import { useCallback, useEffect, useMemo, useState } from 'react';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import { Link, useSearchParams } from 'react-router-dom';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import { PageHead, EmptyState, SkeletonTable, RolePill, ActivePill, InlineAlert, useConfirm } from '../../components/admin/ui.jsx';
import Pager from '../../components/shared/Pager.jsx';
import { listUsers, isActiveUser, isDigitsOnly, loginOf, formatDate, ApiError } from '../../components/admin/adminApi.js';
import EnrollStudentsTab from './tabs/EnrollStudentsTab.jsx';
import ApplyTeachersTab from './tabs/ApplyTeachersTab.jsx';

const PER_PAGE = 8;
const VALID_TABS = ['list', 'enroll', 'apply'];
const TAB_LABEL = { list: 'List', enroll: 'Enroll Students', apply: 'Apply Teachers' };

function tabFromParams(params) {
  const t = params?.get?.('tab');
  return VALID_TABS.includes(t) ? t : 'list';
}

export default function Users() {
  // #1: tab is URL-synced (?tab=list|enroll|apply, default list) following the
  // useSearchParams pattern in admin/Audit.jsx. Bare /admin/users is the
  // canonical list URL; invalid values fall back to list.
  const [params, setParams] = useSearchParams();
  const [tab, setTabState] = useState(() => tabFromParams(params));
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [role, setRole] = useState('all');
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [digitsNote, setDigitsNote] = useState(false);
  const [listTick, setListTick] = useState(0);
  // #4: post-action affordance — set by handleChanged, cleared on tab switch.
  const [notice, setNotice] = useState(null);
  // #2: unsaved state reported up by the enroll/apply children.
  const [enrollDirty, setEnrollDirty] = useState(false);
  const [applyDirty, setApplyDirty] = useState(false);
  const { ask, node: confirmNode } = useConfirm();
  const handleEnrollDirty = useCallback((d) => setEnrollDirty(!!d), []);
  const handleApplyDirty = useCallback((d) => setApplyDirty(!!d), []);

  // Follow external URL changes (browser back/forward, deep-links from
  // Onboarding/Dashboard/Help) while mounted. URL-driven switches route
  // through requestTab so the dirty guard applies. When the guard blocks, the
  // address bar is first reverted to the mounted tab (replace) so Cancel keeps
  // URL and UI in sync instead of leaving a desynced ?tab= behind.
  const urlTab = tabFromParams(params);
  useEffect(() => {
    if (urlTab === tab) return;
    const leavingDirty = (tab === 'enroll' && enrollDirty) || (tab === 'apply' && applyDirty);
    if (leavingDirty) {
      setParams((prev) => {
        const nextParams = new URLSearchParams(prev);
        if (tab === 'list') nextParams.delete('tab');
        else nextParams.set('tab', tab);
        return nextParams;
      }, { replace: true });
    }
    requestTab(urlTab);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [urlTab, tab, enrollDirty, applyDirty]);

  // Normalize an invalid ?tab= back to the canonical bare URL (replace, so no
  // extra history entry) on every URL change — not just mount — so pasted or
  // in-app navigations to ?tab=bogus never linger. ?tab=list is valid and left
  // as-is. Skipped while the dirty guard owns the navigation (the URL effect
  // above reverts + confirms instead); the guard settles to a valid URL.
  useEffect(() => {
    const t = params.get('tab');
    if (t === null || VALID_TABS.includes(t)) return;
    const leavingDirty = (tab === 'enroll' && enrollDirty) || (tab === 'apply' && applyDirty);
    if (leavingDirty) return;
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      // Re-check at write time so this cannot clobber the guard's revert.
      if (next.get('tab') !== null && !VALID_TABS.includes(next.get('tab'))) {
        next.delete('tab');
      }
      return next;
    }, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params, tab, enrollDirty, applyDirty]);

  function applyTab(next) {
    setTabState(next);
    setNotice(null);
    setParams((prev) => {
      const nextParams = new URLSearchParams(prev);
      if (next === 'list') nextParams.delete('tab');
      else nextParams.set('tab', next);
      return nextParams;
    });
  }

  // #2 (minimal safe option): confirm before a tab switch that would unmount
  // an enroll/apply child holding unsaved state — an unacknowledged one-time
  // temporary_password (SingleAccountForm.created) or an import past the
  // upload step with no result yet (BulkWizard preview/confirm, or a chosen
  // file). Cancelling keeps the tab mounted so nothing is lost; confirming
  // unmounts and the in-progress state is deliberately discarded.
  // Pass { moveFocus: true } for keyboard activation so focus follows only a
  // confirmed activation; clicks and URL-driven switches leave focus alone.
  function focusTab(id) {
    requestAnimationFrame(() => {
      document.getElementById(`users-tab-${id}`)?.focus();
    });
  }
  function requestTab(next, opts = {}) {
    if (next === tab || !VALID_TABS.includes(next)) return;
    const leavingDirty = (tab === 'enroll' && enrollDirty) || (tab === 'apply' && applyDirty);
    const moveFocus = opts.moveFocus === true;
    if (!leavingDirty) {
      applyTab(next);
      if (moveFocus) focusTab(next);
      return;
    }
    ask(
      'Leave without finishing?',
      'Switching tabs discards this screen: a one-time temporary password that was never acknowledged, or an import that has not reached its result step. This cannot be undone.',
      'Leave anyway',
      () => {
        applyTab(next);
        if (moveFocus) focusTab(next);
      },
      true,
    );
  }

  // #5: arrow-key nav is cheap here (three tabs, automatic activation), so it
  // is implemented rather than documented away. Native buttons already cover
  // Tab/Enter/Space; roving tabindex + arrows follow the WAI-ARIA tabs pattern.
  // Focus moves only on confirmed activation — a guard-blocked switch leaves
  // focus where it is until the user confirms (requestTab handles the move).
  function onTabKeyDown(e) {
    const order = VALID_TABS;
    const i = order.indexOf(tab);
    let next = null;
    if (e.key === 'ArrowRight') next = order[(i + 1) % order.length];
    else if (e.key === 'ArrowLeft') next = order[(i - 1 + order.length) % order.length];
    else if (e.key === 'Home') next = order[0];
    else if (e.key === 'End') next = order[order.length - 1];
    if (next) {
      e.preventDefault();
      requestTab(next, { moveFocus: true });
    }
  }

  const digitsOnly = isDigitsOnly(search);
  const tooShort = search.trim() !== '' && search.trim().length === 1;
  // Status has no server-side filter. With a status filter active the page
  // scans every page (per_page:100, cancelled between pages) and filters
  // locally, so totals and paging cover the full set. With 'all' the
  // server-paged read below is used as-is.
  const filteringByStatus = status !== 'all';
  const [scanRows, setScanRows] = useState([]);
  const [scanTick, setScanTick] = useState(0);

  // Debounce the search input so the server is not hit on every keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 350);
    return () => clearTimeout(t);
  }, [search]);

  // Guards mirror adminApi.listUsers: digits-only and min-2 never reach the server.
  const effectiveSearch = useMemo(() => {
    const q = String(debouncedSearch ?? '').trim();
    if (q.length >= 2 && !isDigitsOnly(q)) return q;
    return undefined;
  }, [debouncedSearch]);

  useEffect(() => { setPage(1); }, [debouncedSearch, role, status]);
  useEffect(() => { setDigitsNote(digitsOnly); }, [search]);

  function mapListError(err) {
    if (err instanceof ApiError && err.status === 422) {
      return friendlyError(err, { action: 'defaults', fallback: 'Search by name or code only. Numbers cannot be used for search.' });
    }
    if (err instanceof ApiError && err.status === 403) {
      return 'You do not have permission to list users. If this keeps happening, contact your school administrator.';
    }
    return err;
  }

  useEffect(() => {
    if (filteringByStatus) return;
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const res = await listUsers({ role, page, per_page: PER_PAGE, search: effectiveSearch });
        if (cancelled) return;
        setRows(Array.isArray(res?.data) ? res.data : []);
        setMeta(res?.meta || null);
      } catch (err) {
        if (cancelled) return;
        setError(mapListError(err));
        setRows([]);
        setMeta(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [role, page, effectiveSearch, filteringByStatus, listTick]);

  useEffect(() => {
    if (!filteringByStatus) return;
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const perPage = 100;
        let p = 1;
        let total = Infinity;
        const all = [];
        for (;;) {
          const res = await listUsers({ role, page: p, per_page: perPage, search: effectiveSearch });
          if (cancelled) return;
          const chunk = Array.isArray(res?.data) ? res.data : [];
          if (p === 1) total = Number(res?.meta?.total ?? chunk.length);
          all.push(...chunk);
          if (chunk.length < perPage || chunk.length === 0 || all.length >= total) break;
          p += 1;
        }
        if (cancelled) return;
        setScanRows(all);
        setMeta({ total: all.length, current_page: 1, last_page: 1 });
      } catch (err) {
        if (cancelled) return;
        setError(mapListError(err));
        setScanRows([]);
        setMeta(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [role, effectiveSearch, filteringByStatus, scanTick, listTick]);

  // Status has no server-side filter — applied locally. Under an active
  // status filter the source is the full scan above, so totals and paging
  // honestly cover every page; with 'all' the source is the server page.
  const filtered = useMemo(() => {
    const source = filteringByStatus ? scanRows : rows;
    let list = [...source];
    if (status !== 'all') {
      list = list.filter((u) => (status === 'active' ? isActiveUser(u) : !isActiveUser(u)));
    }
    return list.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
  }, [rows, scanRows, status, filteringByStatus]);

  const total = filteringByStatus ? filtered.length : (meta?.total ?? filtered.length);
  const totalPages = filteringByStatus ? Math.max(1, Math.ceil(filtered.length / PER_PAGE)) : (meta?.last_page ?? 1);
  const safePage = filteringByStatus ? Math.min(page, totalPages) : (meta?.current_page ?? page);
  const pageItems = filteringByStatus ? filtered.slice((safePage - 1) * PER_PAGE, safePage * PER_PAGE) : filtered;

  // #4: refresh the list AND guide the user back — the inline note below
  // offers a View in list button. It routes through requestTab so the #2
  // guard still applies (e.g. a fresh temporary password is not abandoned
  // silently when jumping to the list). The note says "is refreshing" (not
  // "has been refreshed") because the list fetch completes asynchronously;
  // child resets ("Add another" / "Start a new import") clear it via onReset.
  function handleChanged(kind) {
    if (filteringByStatus) setScanTick((t) => t + 1);
    else setListTick((t) => t + 1);
    setNotice({ kind: kind === 'apply' ? 'apply' : 'enroll' });
  }

  const sub = tab === 'list'
    ? `${total} accounts across all roles.`
    : tab === 'enroll'
      ? 'Enroll one student by name or bulk-upload a full_name-only sheet. CompAss IDs are auto-generated.'
      : 'Apply one teacher by name or bulk-upload a full_name-only sheet. CompAss IDs are auto-generated.';

  return (
    <div className="admin-page">
      <PageHead title="Users" sub={sub} />
      <div className="filter-chips mb-18" role="tablist" aria-label="Users sections" onKeyDown={onTabKeyDown}>
        {VALID_TABS.map((id) => (
          <button
            key={id}
            type="button"
            role="tab"
            id={`users-tab-${id}`}
            // Panels unmount on switch (so a dirty guard can discard in-progress
            // state), so only the mounted panel is referenced — otherwise
            // aria-controls would point at unmounted ids.
            aria-controls={tab === id ? `users-panel-${id}` : undefined}
            aria-selected={tab === id}
            tabIndex={tab === id ? 0 : -1}
            className={tab === id ? 'active' : ''}
            onClick={() => requestTab(id)}
          >
            {TAB_LABEL[id]}
          </button>
        ))}
      </div>

      {notice && tab !== 'list' && (
        <div className="mb-12">
          <InlineAlert kind="ok">
            {notice.kind === 'apply'
              ? 'Teacher account saved — the list is refreshing.'
              : 'Student enrolled — the list is refreshing.'}{' '}
            <button type="button" className="btn btn-sm" onClick={() => requestTab('list')}>View in list</button>
          </InlineAlert>
        </div>
      )}

      {tab === 'enroll' ? (
        <div role="tabpanel" id="users-panel-enroll" aria-labelledby="users-tab-enroll" tabIndex={0}>
          <EnrollStudentsTab onChanged={() => handleChanged('enroll')} onDirtyChange={handleEnrollDirty} onReset={() => setNotice(null)} />
        </div>
      ) : tab === 'apply' ? (
        <div role="tabpanel" id="users-panel-apply" aria-labelledby="users-tab-apply" tabIndex={0}>
          <ApplyTeachersTab onChanged={() => handleChanged('apply')} onDirtyChange={handleApplyDirty} onReset={() => setNotice(null)} />
        </div>
      ) : (
        <div role="tabpanel" id="users-panel-list" aria-labelledby="users-tab-list" tabIndex={0}>
          <div className="filter-bar filter-bar--labeled">
            <div className="filter-field">
              <label htmlFor="users-search">Search</label>
              <input
                id="users-search"
                type="text"
                placeholder="Name or CompAss ID (min 2 chars)"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className="filter-field">
              <label htmlFor="users-role">Role</label>
              <select id="users-role" value={role} onChange={(e) => setRole(e.target.value)}>
                <option value="all">All roles</option>
                <option value="admin">Admin</option>
                <option value="teacher">Teacher</option>
                <option value="student">Student</option>
              </select>
            </div>
            <div className="filter-field">
              <label htmlFor="users-status">Status</label>
              <select id="users-status" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="deactivated">Deactivated</option>
              </select>
            </div>
          </div>
          {digitsNote && <div className="err-line">Search can&apos;t be digits only — try a name instead.</div>}
          {tooShort && !digitsOnly && <div className="note-line mb-12">Type at least 2 characters to search.</div>}
          {filteringByStatus && !loading && !error && (
            <div className="note-line mb-12">Status is filtered across all {scanRows.length} loaded accounts (the server has no status filter) — totals and pages below cover the full set.</div>
          )}
          {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}

          {loading ? (
            <SkeletonTable rows={8} />
          ) : error ? null : pageItems.length === 0 ? (
            <EmptyState title="No matching users" hint="Try a different name, ID, or filter combination." />
          ) : (
            <Card>
              <div className="scroll-x">
              <table className="dtable">
                <thead><tr><th>Name</th><th>CompAss ID</th><th>Role</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                  {pageItems.map((u) => (
                    <tr key={u.id}>
                      <td>
                        <div className="cell-main"><Link to={`/admin/users/${u.id}`}>{u.name}</Link></div>
                        {u.must_change_password && <div className="cell-sub">Pending forced password change</div>}
                      </td>
                      <td>{loginOf(u)}</td>
                      <td><RolePill role={u.role} /></td>
                      <td><ActivePill active={isActiveUser(u)} /></td>
                      <td className="meta-faint">{formatDate(u.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
              <Pager page={safePage} pages={totalPages} onChange={setPage} />
            </Card>
          )}
        </div>
      )}
      {confirmNode}
    </div>
  );
}

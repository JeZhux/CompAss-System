import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import '../../components/admin/admin.css';
import Card from '../../components/shared/Card.jsx';
import FormField from '../../components/shared/FormField.jsx';
import { PageHead, InlineAlert } from '../../components/admin/ui.jsx';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import {
  downloadTemplate, downloadErrorReportUrl, importCompetencyTags,
  listClassrooms, placeLearner, moveEnrollment, firstFieldError, ApiError,
} from '../../components/admin/adminApi.js';

const MAX_BYTES = 15 * 1024 * 1024;

function PlaceMove() {
  const [mode, setMode] = useState('place');
  const [rooms, setRooms] = useState([]);
  const [roomsErr, setRoomsErr] = useState(null);
  const [form, setForm] = useState({ full_name: '', classroom_id: '', enrollment_id: '', dest_classroom_id: '' });
  const [msg, setMsg] = useState(null);
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `busy` disables the button.
  const busyRef = useRef(false);

  useEffect(() => {
    (async () => {
      try {
        const res = await listClassrooms({ archived: false, per_page: 100 });
        setRooms(Array.isArray(res?.data) ? res.data : []);
      } catch (e) {
        setRoomsErr(e);
      }
    })();
  }, []);

  async function doPlace() {
    setMsg(null); setErr(null);
    if (!form.full_name.trim() || !form.classroom_id) {
      setErr('Full name and destination classroom are required.');
      return;
    }
    if (busyRef.current || busy) return;
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await placeLearner({
        full_name: form.full_name.trim(),
        classroom_id: Number(form.classroom_id),
      });
      const who = data?.display_name || form.full_name.trim();
      const cid = data?.school_id ? ` (${data.school_id})` : '';
      setMsg(`Placed ${who}${cid} into the chosen classroom. The placement and a history entry were saved.`);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const f = firstFieldError(e);
        const base = friendlyError(e);
        setErr(f ? `${base} (${f})` : base);
      } else {
        setErr(e);
      }
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  async function doMove() {
    setMsg(null); setErr(null);
    if (!form.enrollment_id || !form.dest_classroom_id) {
      setErr('The learner enrollment reference and destination classroom are required.');
      return;
    }
    if (busyRef.current || busy) return;
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await moveEnrollment(Number(form.enrollment_id), Number(form.dest_classroom_id));
      setMsg(data?.message || 'Learner moved to the chosen classroom.');
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) setErr(friendlyError(e));
      else if (e instanceof ApiError && e.status === 422) {
        const f = firstFieldError(e);
        const base = friendlyError(e);
        setErr(f ? `${base} (${f})` : base);
      } else setErr(e);
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  return (
    <div className="grid-2 maxw-820">
      <Card title={mode === 'place' ? 'Place a learner' : 'Move an enrollment'}>
        <div className="filter-chips mb-16">
          <button type="button" className={mode === 'place' ? 'active' : ''} onClick={() => { setMode('place'); setMsg(null); setErr(null); }}>Place</button>
          <button type="button" className={mode === 'move' ? 'active' : ''} onClick={() => { setMode('move'); setMsg(null); setErr(null); }}>Move</button>
        </div>
        {roomsErr ? <ErrorNotice error={roomsErr} onRetry={() => window.location.reload()} /> : null}
        {msg && <InlineAlert kind="ok">{msg}</InlineAlert>}
        {err ? <ErrorNotice error={err} /> : null}
        {mode === 'place' ? (
          <div className="stacked">
            <p className="lede lede--sm">
              Creates a new student account with an auto-generated CompAss ID (STU-…) and enrolls it in one step. For existing or bulk learners, use <Link to="/admin/users?tab=enroll">Users → Enroll Students</Link>.
            </p>
            <FormField label="Full name" hint="Each place creates a new account — reusing a name creates a duplicate account, not a match.">
              <input className="ff-wide" placeholder="e.g. Jonas dela Cruz" maxLength={255} value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
            </FormField>
            <FormField label="Destination classroom">
              <select className="ff-wide" value={form.classroom_id} onChange={(e) => setForm({ ...form, classroom_id: e.target.value })}>
                <option value="">Pick an active classroom</option>
                {rooms.map((c) => <option key={c.id} value={c.id}>{c.name} — {c.school_year || ''}</option>)}
              </select>
            </FormField>
            <div><button type="button" className="btn btn-primary" disabled={busy} onClick={doPlace}>{busy ? 'Placing…' : 'Place learner'}</button></div>
          </div>
        ) : (
          <div className="stacked">
            <FormField label="Learner enrollment (class placement)" hint="Enter the reference number from the placement response.">
              <input className="ff-wide" placeholder="Enrollment reference number" inputMode="numeric" value={form.enrollment_id} onChange={(e) => setForm({ ...form, enrollment_id: e.target.value })} />
            </FormField>
            <FormField label="Destination classroom">
              <select className="ff-wide" value={form.dest_classroom_id} onChange={(e) => setForm({ ...form, dest_classroom_id: e.target.value })}>
                <option value="">Pick an active classroom</option>
                {rooms.map((c) => <option key={c.id} value={c.id}>{c.name} — {c.school_year || ''}</option>)}
              </select>
            </FormField>
            <div><button type="button" className="btn btn-primary" disabled={busy} onClick={doMove}>{busy ? 'Moving…' : 'Move learner'}</button></div>
          </div>
        )}
      </Card>
      <Card title="What this does">
        {/* dev: place → 201 created; move → 200 ok; 409 identical destination; 410 archived destination */}
        <p className="lede lede--sm">
          Place creates a new learner account and adds it to a classroom in one step. Move switches an existing learner to a new classroom — moving to the same classroom changes nothing, and archived classrooms stay closed. Every place or move is recorded with who did it, which learner, the old and new classroom, and when. <Link to="/admin/help">Learn more</Link>.
        </p>
        <div className="note-line">Bulk enrollment lives under <Link to="/admin/users?tab=enroll">Users → Enroll Students</Link>; bulk teacher creation under <Link to="/admin/users?tab=apply">Users → Apply Teachers</Link>.</div>
      </Card>
    </div>
  );
}

export default function Onboarding() {
  const [tab, setTab] = useState('place');
  return (
    <div className="admin-page">
      <PageHead title="Onboarding" sub="Hand place or move a single learner, and import competency tags. Bulk enrollment lives under Users." />
      <div className="filter-chips mb-18">
        <button type="button" className={tab === 'place' ? 'active' : ''} onClick={() => setTab('place')}>Hand place / move</button>
        <button type="button" className={tab === 'competencies' ? 'active' : ''} onClick={() => setTab('competencies')}>Competency import</button>
      </div>
      {tab === 'place' ? <PlaceMove /> : <CompetencyTagImport />}
    </div>
  );
}

function CompetencyTagImport() {
  const [file, setFile] = useState(null);
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `busy` disables the button.
  const busyRef = useRef(false);
  // Download guard: the error-sheet download is an idempotent read, but
  // double clicks must not stack it.
  const [dlBusy, setDlBusy] = useState(false);
  const dlBusyRef = useRef(false);

  function pickFile(f) {
    setErr(null);
    setResult(null);
    if (!f) { setFile(null); return; }
    const okExt = /\.(xlsx|xls)$/i.test(f.name || '');
    if (!okExt) { setErr('Only .xlsx or .xls files are accepted.'); return; }
    if (f.size > MAX_BYTES) { setErr('Each file must be at most 15 MB.'); return; }
    setFile(f);
  }

  async function doImport() {
    setErr(null);
    setResult(null);
    if (!file) { setErr('Choose a sheet first.'); return; }
    if (busyRef.current || busy) return;
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await importCompetencyTags(file);
      setResult(data);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const f = firstFieldError(e);
        const base = friendlyError(e, { action: 'import' });
        setErr(f ? `${base} (${f})` : base);
      } else {
        setErr(e);
      }
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  async function doDownloadErrorSheet() {
    if (dlBusyRef.current || dlBusy) return;
    dlBusyRef.current = true;
    setDlBusy(true);
    setErr(null);
    const url = result?.error_report_url;
    if (!url) {
      setErr('No error sheet for this import.');
      dlBusyRef.current = false;
      setDlBusy(false);
      return;
    }
    try {
      await downloadErrorReportUrl(url);
    } catch (e) {
      setErr(e);
    } finally {
      dlBusyRef.current = false;
      setDlBusy(false);
    }
  }

  async function doDownloadCompetencyTemplate() {
    if (dlBusyRef.current || dlBusy) return;
    dlBusyRef.current = true;
    setDlBusy(true);
    setErr(null);
    try {
      // Blank template headers: code, descriptor, subject_id, grade_level, semester.
      await downloadTemplate('competency_tags');
    } catch (e) {
      setErr(e);
    } finally {
      dlBusyRef.current = false;
      setDlBusy(false);
    }
  }

  return (
    <Card title="Import competency tags" sub="Spreadsheet upload (.xlsx or .xls, up to 15 MB).">
      <p className="lede lede--sm">
        Columns: <b>code</b>, <b>descriptor</b>, <b>subject_id</b>, <b>grade_level (7–12)</b>, <b>semester (1–3)</b>. The grade level and semester must match the subject&apos;s own grade level and semester. Rows with duplicate codes, unknown subjects, or mismatched grade/semester are listed in the error sheet; valid rows are imported. <Link to="/admin/help">How imports work</Link>.
      </p>
      {err ? <ErrorNotice error={err} action="import" onRetry={doImport} /> : null}
      <div className="upload-box">
        <b>Drop file here</b> or click to browse<br />
        <span className="upload-name">{file ? file.name : 'No file chosen yet'}</span>
        <div className="mt-10">
          <input type="file" accept=".xlsx,.xls" onChange={(e) => pickFile(e.target.files?.[0])} aria-label="Choose competency-tag sheet" />
        </div>
      </div>
      <div className="admin-row mt-10">
        <button type="button" className="btn btn-sm" disabled={dlBusy} onClick={doDownloadCompetencyTemplate}>{dlBusy ? 'Preparing…' : 'Download competency template'}</button>
        <button type="button" className="btn btn-primary" disabled={!file || busy} onClick={doImport}>{busy ? 'Importing…' : 'Upload & import'}</button>
        {result?.has_error_report && <button type="button" className="btn" disabled={dlBusy} onClick={doDownloadErrorSheet}>{dlBusy ? 'Preparing…' : 'Download error sheet'}</button>}
      </div>
      {busy && <div className="upload-progress" role="progressbar" aria-label="Importing…"><span /></div>}
      {result && (
        <div className="mt-12">
          <div className="ok-line">Imported {result.imported_rows ?? 0}, rejected {result.failed_rows ?? 0}.</div>
          {result.has_error_report
            ? <div className="note-line">The error sheet link works once and expires in 7 days — re-run the import to generate a new one.</div>
            : <div className="note-line">No error sheet — every row imported cleanly.</div>}
        </div>
      )}
    </Card>
  );
}

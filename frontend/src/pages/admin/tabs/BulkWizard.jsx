import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Card from '../../../components/shared/Card.jsx';
import { useConfirm } from '../../../components/admin/ui.jsx';
import { friendlyError } from '../../../components/shared/errors.js';
import { ErrorNotice } from '../../../components/shared/Feedback.jsx';
import {
  downloadTemplate, downloadErrorReportUrl,
  firstFieldError, ApiError,
} from '../../../components/admin/adminApi.js';

const MAX_BYTES = 15 * 1024 * 1024;
const STEPS = ['upload', 'preview', 'confirm', 'result'];
const STEP_LABEL = { upload: 'Upload', preview: 'Review counts', confirm: 'Confirm', result: 'Result' };

function Steps({ current }) {
  const idx = STEPS.indexOf(current);
  return (
    <div className="steps" role="list" aria-label="Import progress">
      {STEPS.map((s, i) => (
        <span key={s} className="steps-item" role="listitem">
          <span
            className={`step ${i < idx ? 'done' : ''} ${i === idx ? 'current' : ''}`}
            aria-current={i === idx ? 'step' : undefined}
          >
            <span className="dot" aria-hidden="true">
              {i < idx ? (
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
                  <path d="M2.5 6.5 5 9l4.5-5.5" />
                </svg>
              ) : (
                i + 1
              )}
            </span>
            {STEP_LABEL[s]}
            <span className="visually-hidden">
              {i < idx ? ' (completed)' : i === idx ? ' (current step)' : ''}
            </span>
          </span>
          {i < STEPS.length - 1 && <span className="step-sep" aria-hidden="true" />}
        </span>
      ))}
    </div>
  );
}

export default function BulkWizard({
  kind = 'student',
  previewFn,
  confirmFn,
  templateType = 'student-enrollments',
  onConfirmed,
  onDirtyChange,
  onReset,
}) {
  const isStudent = kind === 'student';
  const [step, setStep] = useState('upload');
  const [file, setFile] = useState(null);
  const [preview, setPreview] = useState(null);
  const [result, setResult] = useState(null);
  const [reviewed, setReviewed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const { ask, node } = useConfirm();
  const busyRef = useRef(false);
  const [dlBusy, setDlBusy] = useState(false);
  const dlBusyRef = useRef(false);

  // Dirty = in-progress import that tab-switch unmount would destroy:
  // a chosen file not yet previewed, or preview/confirm steps before result.
  // The result step is safe (token already consumed, counts shown).
  const dirty = step === 'preview' || step === 'confirm' || (step === 'upload' && !!file);
  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);

  function pickFile(f) {
    setErr(null);
    if (!f) { setFile(null); return; }
    const okExt = /\.(xlsx|xls)$/i.test(f.name || '');
    if (!okExt) { setErr('Only .xlsx or .xls files are accepted.'); return; }
    if (f.size > MAX_BYTES) { setErr('Each file must be at most 15 MB.'); return; }
    setFile(f);
  }

  async function doPreview() {
    setErr(null);
    if (!file) { setErr('Choose a sheet first.'); return; }
    if (busyRef.current || busy) return;
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await previewFn(file);
      setPreview(data);
      setReviewed(false);
      setStep('preview');
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

  async function doConfirm() {
    setErr(null);
    if (!preview?.preview_token) { setErr('Preview token is missing. Re-upload the sheet.'); return; }
    if (busyRef.current || busy) return;
    busyRef.current = true;
    setBusy(true);
    try {
      const data = await confirmFn(preview.preview_token);
      setResult(data);
      setStep('result');
      onConfirmed?.(data);
    } catch (e) {
      if (e instanceof ApiError && e.status === 410) {
        setErr(e);
        setStep('upload');
        setPreview(null);
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

  async function doDownloadTemplate() {
    if (dlBusyRef.current || dlBusy) return;
    dlBusyRef.current = true;
    setDlBusy(true);
    setErr(null);
    try {
      await downloadTemplate(templateType);
    } catch (e) {
      setErr(e);
    } finally {
      dlBusyRef.current = false;
      setDlBusy(false);
    }
  }

  function reset() {
    setStep('upload');
    setFile(null);
    setPreview(null);
    setResult(null);
    setReviewed(false);
    setErr(null);
    // "Start over" / "Start a new import" discards the result screen, so any
    // parent post-action notice tied to it is stale — let the parent clear it.
    onReset?.();
  }

  const wizardRetry = step === 'result'
    ? (result?.has_error_report ? doDownloadErrorSheet : null)
    : step === 'confirm' ? doConfirm : doPreview;
  const wizardRetryLabel = step === 'confirm' ? 'Retry confirm' : step === 'result' ? 'Retry download' : 'Retry upload';

  const uploadTitle = isStudent ? 'Upload learner sheet' : 'Upload teacher sheet';
  const idExample = isStudent ? 'STU-6181-30913' : 'TEA-6181-30913';

  return (
    <div>
      <Steps current={step} />
      {err ? <ErrorNotice error={err} action="import" onRetry={wizardRetry} retryLabel={wizardRetryLabel} /> : null}

      {step === 'upload' && (
        <Card title={uploadTitle} sub=".xlsx or .xls, up to 15MB, up to 5,000 rows.">
          <p className="lede lede--sm">
            Requires a single <b>full_name</b> column. CompAss IDs (e.g. <span className="mono">{idExample}</span>) are auto-generated by the server — never typed into the sheet. <Link to="/admin/help">How imports work</Link>.
          </p>
          <div className="upload-box">
            <b>Drop file here</b> or click to browse<br />
            <span className="upload-name">{file ? file.name : 'No file chosen yet'}</span>
            <div className="mt-10">
              <input type="file" accept=".xlsx,.xls" onChange={(e) => pickFile(e.target.files?.[0])} aria-label={isStudent ? 'Choose learner sheet' : 'Choose teacher sheet'} />
            </div>
          </div>
          <div className="admin-row">
            <button type="button" className="btn btn-sm" disabled={dlBusy} onClick={doDownloadTemplate}>{dlBusy ? 'Preparing…' : 'Download template'}</button>
            <button type="button" className="btn btn-primary" disabled={!file || busy} onClick={doPreview}>{busy ? 'Uploading…' : 'Upload & preview'}</button>
          </div>
          {busy && <div className="upload-progress" role="progressbar" aria-label="Uploading…"><span /></div>}
        </Card>
      )}

      {step === 'preview' && preview && (
        <div>
          <div className="warn-line">This is a preview only — nothing is written yet. The token expires in 60 minutes and can only be used once.</div>
          <div className="grid-4 maxw-760 mb-18">
            <div className="stat-card"><div className="num">{preview.total_rows ?? 0}</div><div className="lbl">Total rows</div></div>
            <div className="stat-card"><div className="num">{preview.valid_rows ?? 0}</div><div className="lbl">Valid</div></div>
            <div className="stat-card"><div className="num">{preview.invalid_rows ?? 0}</div><div className="lbl">Invalid</div></div>
            <div className="stat-card"><div className="num">{preview.duplicate_matches ?? 0}</div><div className="lbl">In-file repeats</div></div>
          </div>
          <div className="note-line">Expires {preview.expires_at ? new Date(preview.expires_at).toLocaleString() : 'in 60 minutes'}. Valid rows will create new {isStudent ? 'student' : 'teacher'} accounts with fresh CompAss IDs; invalid rows will be rejected to the error sheet. In-file repeats are allowed — each repeat creates its own account (names are not unique); the count covers valid rows repeating a name already seen in the sheet.</div>
          <label className="checkline mt-16 mb-16">
            <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} />
            I&apos;ve reviewed these counts and want to proceed
          </label>
          <div className="admin-row">
            <button type="button" className="btn" onClick={reset}>Start over</button>
            <button type="button" className="btn btn-primary" disabled={!reviewed || busy} onClick={() => setStep('confirm')}>Continue</button>
          </div>
        </div>
      )}

      {step === 'confirm' && preview && (
        <Card title="Confirm import" sub={isStudent ? 'Adds student accounts only — no class placements.' : 'Adds teacher accounts only.'}>
          <p className="lede">
            This creates {preview.valid_rows ?? 0} {isStudent ? 'student' : 'teacher'} accounts and skips {preview.invalid_rows ?? 0} rows. {isStudent && <><b>No class placements are created by this step</b> — learners join through join keys or hand place/move. </>}<Link to="/admin/help">Learn more</Link>.
          </p>
          <div className="admin-row mt-16">
            <button type="button" className="btn" onClick={() => setStep('preview')}>Back</button>
            <button type="button" className="btn btn-primary" disabled={busy} onClick={() => ask('Confirm this import?', 'Valid rows will be written now. The preview token is consumed and cannot be reused.', 'Confirm import', doConfirm)}>{busy ? 'Confirming…' : 'Confirm import'}</button>
          </div>
        </Card>
      )}

      {step === 'result' && result && (
        <div>
          <div className="ok-line">Import confirmed. Token consumed — this preview can&apos;t be reused.</div>
          <div className="grid-3 maxw-560 mb-18">
            <div className="stat-card"><div className="num">{result.imported_rows ?? 0}</div><div className="lbl">Created</div></div>
            <div className="stat-card"><div className="num">{result.updated_rows ?? 0}</div><div className="lbl">Updated</div></div>
            <div className="stat-card"><div className="num">{result.failed_rows ?? 0}</div><div className="lbl">Rejected</div></div>
          </div>
          <div className="note-line">Counts are accounts, not placements: created = new accounts with fresh CompAss IDs, rejected = rows that failed validation. Updated is always 0 — names are never matched to existing accounts; in-file repeats each create their own account.</div>
          <div className="admin-row">
            {result.has_error_report && <button type="button" className="btn" disabled={dlBusy} onClick={doDownloadErrorSheet}>{dlBusy ? 'Preparing…' : 'Download error sheet'}</button>}
            <button type="button" className="btn btn-primary" onClick={reset}>Start a new import</button>
          </div>
          <div className="note-line">The error sheet link works once and expires in 7 days — re-run the import to generate a new one. <Link to="/admin/help">How imports work</Link>.</div>
        </div>
      )}
      {node}
    </div>
  );
}

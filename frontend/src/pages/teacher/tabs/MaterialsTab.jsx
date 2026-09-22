import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../../components/shared/errors.js';
import { ErrorNotice } from '../../../components/shared/Feedback.jsx';
import { InlineAlert, EmptyState, SkeletonTable, Modal, useConfirm } from '../../../components/teacher/ui.jsx';
import FormField from '../../../components/shared/FormField.jsx';
import Card from '../../../components/shared/Card.jsx';
import {
  listLearningMaterials,
  storeLearningMaterial,
  updateLearningMaterial,
  deleteLearningMaterial,
  getClassroomCompetencyContext,
  listClassroomCompetencyTags,
  isCompetencyMismatch,
  competencyMismatchMessage,
  firstFieldError,
  formatFileSize,
  formatDateTime,
} from '../../../components/teacher/teacherApi.js';

function competencyOptions(summary) {
  const list = Array.isArray(summary) ? summary : Array.isArray(summary?.data) ? summary.data : [];
  return list.map((r) => ({
    id: r.competency_id ?? r.id,
    label: r.descriptor ? `${r.code ? `${r.code} — ` : ''}${r.descriptor}` : (r.code || `Competency ${r.competency_id ?? r.id}`),
  })).filter((o) => o.id !== undefined && o.id !== null);
}

export default function MaterialsTab({ classroom }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rows, setRows] = useState([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [competencies, setCompetencies] = useState([]);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [title, setTitle] = useState('');
  const [competencyId, setCompetencyId] = useState('');
  const [file, setFile] = useState(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const [notice, setNotice] = useState(null);
  const { ask, node: confirmNode } = useConfirm();
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `saving` disables the button.
  const savingRef = useRef(false);
  const deletingRef = useRef(false);
  const [deleting, setDeleting] = useState(false);
  // Competency-list failures are tracked separately: the materials list and
  // the upload dropdown stay usable, but a failed fetch never renders
  // silently as "no competencies".
  const [compErr, setCompErr] = useState(null);

  const archived = Boolean(classroom.archived_at);
  // Subject scope comes from the classroom (subject_id) or, when the room
  // payload predates it, from the competency-context triple.
  const [compCtx, setCompCtx] = useState(null);
  const subjectId = classroom.subject_id ?? compCtx?.subject_id;

  async function load(p = 1) {
    setLoading(true);
    setError(null);
    setCompErr(null);
    try {
      const ctxRaw = await getClassroomCompetencyContext(classroom.id).catch(() => null);
      const ctx = ctxRaw?.data ?? ctxRaw ?? null;
      if (ctx) setCompCtx(ctx);
      const sid = classroom.subject_id ?? ctx?.subject_id;
      const mP = listLearningMaterials({ subject_id: sid, page: p, per_page: 15 });
      // Competency picker: competency-context triple → filtered catalog
      // (?subject_id&grade_level&semester), so every option already matches
      // the COMPETENCY_MISMATCH triple rule.
      let compRows = [];
      try {
        const tRes = await listClassroomCompetencyTags(classroom.id);
        compRows = Array.isArray(tRes?.data) ? tRes.data : [];
      } catch (e) {
        compRows = [];
        setCompErr(e);
      }
      const mRes = await mP;
      const data = Array.isArray(mRes?.data) ? mRes.data : [];
      setRows(data);
      const total = Number(mRes?.meta?.total || 0);
      const per = Number(mRes?.meta?.per_page || 15);
      setPages(total > 0 ? Math.max(1, Math.ceil(total / per)) : 1);
      setPage(Number(mRes?.meta?.current_page || p));
      setCompetencies(competencyOptions(compRows));
      return data;
    } catch (err) {
      setError(err);
      return null;
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [classroom.id]);

  function openUpload() {
    setTitle('');
    setCompetencyId(competencies[0]?.id ? String(competencies[0].id) : '');
    setFile(null);
    setFormError(null);
    setUploadOpen(true);
  }

  function openEdit(row) {
    setEditing(row);
    setTitle(row.original_filename || '');
    setCompetencyId(row.competency_id ? String(row.competency_id) : '');
    setFile(null);
    setFormError(null);
  }

  function validateFile(f, required) {
    if (!f && required) return 'A PDF or DOCX file is required (max 15 MB).';
    if (!f) return null;
    const name = String(f.name || '').toLowerCase();
    if (!name.endsWith('.pdf') && !name.endsWith('.docx')) return 'Only PDF or DOCX files are accepted.';
    if (f.size > 15 * 1024 * 1024) return 'File must be 15 MB or smaller.';
    return null;
  }

  async function handleUpload(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    if (!competencyId) { setFormError('Choose the competency this material grounds.'); return; }
    const fileErr = validateFile(file, true);
    if (fileErr) { setFormError(fileErr); return; }
    savingRef.current = true;
    setSaving(true);
    try {
      await storeLearningMaterial({ subject_id: subjectId, competency_id: Number(competencyId), title: title.trim() || file.name, file });
      setUploadOpen(false);
      setNotice('Material uploaded. It now grounds AI explanations for this competency.');
      await load(1);
    } catch (err) {
      if (isCompetencyMismatch(err)) setFormError(competencyMismatchMessage());
      else setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleEditSave(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    const fileErr = validateFile(file, false);
    if (fileErr) { setFormError(fileErr); return; }
    savingRef.current = true;
    setSaving(true);
    try {
      await updateLearningMaterial(editing.id, { title: title.trim() || undefined, file: file || undefined });
      setEditing(null);
      setNotice('Material updated.');
      await load(page);
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleDelete(row) {
    ask(`Delete "${row.original_filename}"?`, 'The file is removed and AI explanations lose this grounding source. This cannot be undone.', 'Delete', async () => {
      if (deletingRef.current || deleting) return;
      deletingRef.current = true;
      setDeleting(true);
      try {
        await deleteLearningMaterial(row.id);
        setNotice('Material deleted.');
        const data = await load(page);
        if (data !== null && data.length === 0 && page > 1) await load(page - 1);
      } catch (err) {
        setError(err);
      } finally {
        deletingRef.current = false;
        setDeleting(false);
      }
    }, true);
  }

  return (
    <div>
      <div className="toolbar-split">
        <div className="page-sub">Materials for {classroom.name || 'this classroom'} — shared with its students</div>
        <button type="button" className="btn btn-primary btn-sm" disabled={archived} onClick={openUpload}>Upload material</button>
      </div>
      {notice && <InlineAlert kind="ok">{notice}</InlineAlert>}
      {error ? <ErrorNotice error={error} onRetry={() => window.location.reload()} /> : null}
      {compErr ? <ErrorNotice error={compErr} onRetry={() => load(page)} /> : null}
      {confirmNode}
      {loading ? (
        <SkeletonTable rows={4} />
      ) : error ? null : rows.length === 0 ? (
        <EmptyState title="No materials uploaded yet" hint="Materials you add here ground the AI explanations your students can request." />
      ) : (
        <>
          <div className="row-list">
            {rows.map((m) => (
              <div key={m.id} className="row static">
                <div className="main">
                  <div className="title">{m.original_filename}</div>
                  <div className="meta">{m.original_filename || ''} · {(m.mime_type || '').split('/').pop()?.toUpperCase() || 'FILE'} · {formatFileSize(m.file_size)} · added {formatDateTime(m.created_at)}</div>
                </div>
                <div className="side">
                  <button type="button" className="btn btn-sm" disabled={archived} onClick={() => openEdit(m)}>Edit</button>
                  <button type="button" className="btn btn-sm btn-danger" disabled={archived || deleting} onClick={() => handleDelete(m)}>Delete</button>
                </div>
              </div>
            ))}
          </div>
          {pages > 1 && (
            <div className="pagination">
              <button type="button" disabled={page <= 1} onClick={() => load(page - 1)}>← Prev</button>
              <span>Page {page} of {pages}</span>
              <button type="button" disabled={page >= pages} onClick={() => load(page + 1)}>Next →</button>
            </div>
          )}
        </>
      )}
      <Card>
        <div className="note-line">Grounded AI note: explanations cite uploaded materials for the tagged competency. Without a material, the AI answers from its own knowledge and is marked ungrounded in Moderation.</div>
      </Card>

      {uploadOpen && (
        <Modal
          title="Upload material"
          sub="PDF or DOCX up to 15 MB. Tag the competency it supports."
          onClose={() => (!saving ? setUploadOpen(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setUploadOpen(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleUpload}>{saving ? 'Uploading…' : 'Upload'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleUpload}>
            <FormField label="Title">
              <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Slope-Intercept Practice" maxLength={255} />
            </FormField>
            <FormField label="Competency" hint="Filtered to this classroom's subject, grade level, and semester.">
              <select value={competencyId} onChange={(e) => setCompetencyId(e.target.value)}>
                <option value="">Select a competency…</option>
                {competencies.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
              </select>
            </FormField>
            <FormField label="File (PDF or DOCX, max 15 MB)">
              <input type="file" accept=".pdf,.docx" onChange={(e) => setFile(e.target.files?.[0] || null)} />
            </FormField>
          </form>
        </Modal>
      )}

      {editing && (
        <Modal
          title="Edit material"
          onClose={() => (!saving ? setEditing(null) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setEditing(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleEditSave}>{saving ? 'Saving…' : 'Save changes'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleEditSave}>
            <FormField label="Title">
              <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} />
            </FormField>
            <FormField label="Replace file (optional, PDF or DOCX, max 15 MB)">
              <input type="file" accept=".pdf,.docx" onChange={(e) => setFile(e.target.files?.[0] || null)} />
            </FormField>
          </form>
        </Modal>
      )}
    </div>
  );
}
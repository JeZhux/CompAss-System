import { useEffect, useRef, useState } from 'react';
import { InlineAlert, EmptyState, SkeletonTable, Modal, useConfirm } from '../../../components/teacher/ui.jsx';
import FormField from '../../../components/shared/FormField.jsx';
import { friendlyError } from '../../../components/shared/errors.js';
import { ErrorNotice } from '../../../components/shared/Feedback.jsx';
import {
  listClassroomAnnouncements,
  createClassroomAnnouncement,
  updateAnnouncement,
  deleteAnnouncement,
  firstFieldError,
  formatDateTime,
} from '../../../components/teacher/teacherApi.js';

const ACCEPT = '.pdf,.docx,.pptx,.xlsx,.jpg,.jpeg,.png,.zip';
const MAX_FILE_BYTES = 15 * 1024 * 1024;

function oversizedFile(files) {
  return (files || []).find((f) => Number(f?.size) > MAX_FILE_BYTES);
}

export default function StreamTab({ classroom }) {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [rows, setRows] = useState([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [modal, setModal] = useState(null); // null | { mode: 'create' } | { mode: 'edit', row }
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [files, setFiles] = useState([]);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const { ask, node: confirmNode } = useConfirm();
  // Double-submit guard: React state lags a frame, so the ref blocks a
  // second click before `saving` disables the button.
  const savingRef = useRef(false);
  const deletingRef = useRef(false);
  const [deleting, setDeleting] = useState(false);

  const archived = Boolean(classroom.archived_at);

  async function load(p = 1) {
    setLoading(true);
    setError(null);
    try {
      const res = await listClassroomAnnouncements(classroom.id, { page: p, per_page: 15 });
      const data = Array.isArray(res?.data) ? res.data : [];
      setRows(data);
      const total = Number(res?.meta?.total || 0);
      const per = Number(res?.meta?.per_page || 15);
      setPages(total > 0 ? Math.max(1, Math.ceil(total / per)) : 1);
      setPage(Number(res?.meta?.current_page || p));
      return data;
    } catch (err) {
      setError(err);
      setRows([]);
      return null;
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [classroom.id]);

  function openCreate() {
    setTitle('');
    setBody('');
    setFiles([]);
    setFormError(null);
    setModal({ mode: 'create' });
  }

  function openEdit(row) {
    setTitle(row.title || '');
    setBody(row.body || '');
    setFiles([]);
    setFormError(null);
    setModal({ mode: 'edit', row });
  }

  async function handleSave(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    if (!title.trim()) { setFormError('Title is required (max 255 characters).'); return; }
    if (title.trim().length > 255) { setFormError('Title must be 255 characters or fewer.'); return; }
    if (!body.trim()) { setFormError('Body is required.'); return; }
    const big = oversizedFile(files);
    if (big) { setFormError(`"${big.name || 'File'}" exceeds 15 MB. Remove it or choose a smaller file.`); return; }
    savingRef.current = true;
    setSaving(true);
    try {
      if (modal.mode === 'create') {
        await createClassroomAnnouncement(classroom.id, { title: title.trim(), body: body.trim(), files });
      } else {
        await updateAnnouncement(modal.row.id, {
          title: title.trim(),
          body: body.trim(),
          files: files.length ? files : null,
        });
      }
      setModal(null);
      await load(modal.mode === 'create' ? 1 : page);
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleDelete(row) {
    ask(`Delete "${row.title}"?`, 'Attached files are removed with it. This cannot be undone.', 'Delete', async () => {
      if (deletingRef.current || deleting) return;
      deletingRef.current = true;
      setDeleting(true);
      try {
        await deleteAnnouncement(row.id);
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
      <div className="toolbar-end">
        <button type="button" className="btn btn-primary btn-sm" disabled={archived} onClick={openCreate}>New announcement</button>
      </div>
      {error ? <ErrorNotice error={error} onRetry={() => load(page)} /> : null}
      {confirmNode}
      {loading ? (
        <SkeletonTable rows={4} />
      ) : error ? null : rows.length === 0 ? (
        <EmptyState title="No announcements yet" hint="Post one to open the Stream for this classroom." />
      ) : (
        <>
          {rows.map((s) => (
            <div key={s.id} className="stream-item">
              <div className="meta">
                <span>{formatDateTime(s.created_at)}</span>
                <span className="btn-row">
                  <button type="button" className="btn btn-sm" disabled={archived} onClick={() => openEdit(s)}>Edit</button>
                  <button type="button" className="btn btn-sm" disabled={archived || deleting} onClick={() => handleDelete(s)}>Delete</button>
                </span>
              </div>
              <h4>{s.title}</h4>
              <p>{s.body}</p>
              {s.has_attachments ? <div className="note-line">Has attachments — students download them from the Stream.</div> : null}
            </div>
          ))}
          {pages > 1 && (
            <div className="pagination">
              <button type="button" disabled={page <= 1} onClick={() => load(page - 1)}>← Prev</button>
              <span>Page {page} of {pages}</span>
              <button type="button" disabled={page >= pages} onClick={() => load(page + 1)}>Next →</button>
            </div>
          )}
        </>
      )}

      {modal && (
        <Modal
          title={modal.mode === 'create' ? 'New announcement' : 'Edit announcement'}
          onClose={() => (!saving ? setModal(null) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setModal(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleSave}>{saving ? 'Saving…' : modal.mode === 'create' ? 'Post announcement' : 'Save changes'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleSave}>
            <FormField label="Title (max 255 characters)">
              <input value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} />
            </FormField>
            <FormField label="Body">
              <textarea value={body} onChange={(e) => setBody(e.target.value)} />
            </FormField>
            <FormField label="Attach files (optional)">
              <input type="file" multiple accept={ACCEPT} onChange={(e) => setFiles(Array.from(e.target.files || []))} />
            </FormField>
            {files.length > 0 && <div className="note-line">{files.length} file{files.length === 1 ? '' : 's'} selected. Max 15 MB each.</div>}
          </form>
        </Modal>
      )}
    </div>
  );
}
import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { InlineAlert, EmptyState, SkeletonTable, Modal, Pager } from '../../../components/teacher/ui.jsx';
import FormField from '../../../components/shared/FormField.jsx';
import { friendlyError } from '../../../components/shared/errors.js';
import { ErrorNotice } from '../../../components/shared/Feedback.jsx';
import {
  listClassroomAssignments,
  createClassroomAssignment,
  listClassroomAssessments,
  createClassroomAssessment,
  firstFieldError,
  formatDateTime,
} from '../../../components/teacher/teacherApi.js';

const ACCEPT = '.pdf,.docx,.pptx,.xlsx,.jpg,.jpeg,.png,.zip';
const MAX_FILE_BYTES = 15 * 1024 * 1024;

function oversizedFile(files) {
  return (files || []).find((f) => Number(f?.size) > MAX_FILE_BYTES);
}

export default function ClassworkTab({ classroom }) {
  const [filter, setFilter] = useState('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [assignments, setAssignments] = useState([]);
  const [assessments, setAssessments] = useState([]);
  const [aPage, setAPage] = useState(1);
  const [aPages, setAPages] = useState(1);
  const [aTotal, setATotal] = useState(0);
  const [qPage, setQPage] = useState(1);
  const [qPages, setQPages] = useState(1);
  const [qTotal, setQTotal] = useState(0);
  const [assignModal, setAssignModal] = useState(false);
  const [assessModal, setAssessModal] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  // Double-submit guard: the ref blocks a second click before `saving`
  // disables the button (covers both assignment + assessment creates).
  const savingRef = useRef(false);

  const [aTitle, setATitle] = useState('');
  const [aDesc, setADesc] = useState('');
  const [aDue, setADue] = useState('');
  const [aFiles, setAFiles] = useState([]);

  const [qTitle, setQTitle] = useState('');
  const [qDesc, setQDesc] = useState('');
  const [qType, setQType] = useState('Recorded');
  const [qTime, setQTime] = useState('');
  const [qStart, setQStart] = useState('');
  const [qEnd, setQEnd] = useState('');

  const archived = Boolean(classroom.archived_at);

  async function load(aP = 1, qP = 1) {
    setLoading(true);
    setError(null);
    try {
      // List failures propagate to the error notice — a failed fetch must
      // never render as an empty "no assignments yet" state.
      const [aRes, qRes] = await Promise.all([
        listClassroomAssignments(classroom.id, { page: aP, per_page: 15 }),
        listClassroomAssessments(classroom.id, { page: qP, per_page: 15 }),
      ]);
      setAssignments(Array.isArray(aRes?.data) ? aRes.data : []);
      const aTotalNum = Number(aRes?.meta?.total ?? (Array.isArray(aRes?.data) ? aRes.data.length : 0));
      const aPer = Number(aRes?.meta?.per_page || 15);
      setATotal(Number.isFinite(aTotalNum) ? aTotalNum : 0);
      setAPages(aTotalNum > 0 ? Math.max(1, Math.ceil(aTotalNum / aPer)) : 1);
      setAPage(Number(aRes?.meta?.current_page || aP));
      setAssessments(Array.isArray(qRes?.data) ? qRes.data : []);
      const qTotalNum = Number(qRes?.meta?.total ?? (Array.isArray(qRes?.data) ? qRes.data.length : 0));
      const qPer = Number(qRes?.meta?.per_page || 15);
      setQTotal(Number.isFinite(qTotalNum) ? qTotalNum : 0);
      setQPages(qTotalNum > 0 ? Math.max(1, Math.ceil(qTotalNum / qPer)) : 1);
      setQPage(Number(qRes?.meta?.current_page || qP));
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1, 1); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [classroom.id]);

  async function handleCreateAssignment(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    if (!aTitle.trim()) { setFormError('Title is required.'); return; }
    if (!aDue) { setFormError('Due date is required.'); return; }
    const big = oversizedFile(aFiles);
    if (big) { setFormError(`"${big.name || 'File'}" exceeds 15 MB. Remove it or choose a smaller file.`); return; }
    savingRef.current = true;
    setSaving(true);
    try {
      const due = aDue.length === 10 ? `${aDue}T23:59:00` : aDue;
      await createClassroomAssignment(classroom.id, { title: aTitle.trim(), description: aDesc.trim() || undefined, due_date: due, files: aFiles });
      setAssignModal(false);
      setATitle(''); setADesc(''); setADue(''); setAFiles([]);
      await load(1, 1);
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleCreateAssessment(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    if (!qTitle.trim()) { setFormError('Title is required.'); return; }
    if (!['Recorded', 'Unrecorded'].includes(qType)) { setFormError('Type must be Recorded or Unrecorded. This is locked after creation.'); return; }
    savingRef.current = true;
    setSaving(true);
    try {
      await createClassroomAssessment(classroom.id, {
        title: qTitle.trim(),
        description: qDesc.trim() || undefined,
        type: qType,
        time_limit: qTime.trim() === '' ? undefined : Number(qTime),
        availability_starts_at: qStart || undefined,
        availability_ends_at: qEnd || undefined,
      });
      setAssessModal(false);
      setQTitle(''); setQDesc(''); setQType('Recorded'); setQTime(''); setQStart(''); setQEnd('');
      await load(1, 1);
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  const showAssignments = filter === 'all' || filter === 'assignments';
  const showAssessments = filter === 'all' || filter === 'assessments';

  return (
    <div>
      <div className="toolbar-split">
        <div className="filter-chips">
          <button type="button" className={filter === 'all' ? 'active' : undefined} onClick={() => setFilter('all')}>All</button>
          <button type="button" className={filter === 'assignments' ? 'active' : undefined} onClick={() => setFilter('assignments')}>Assignments</button>
          <button type="button" className={filter === 'assessments' ? 'active' : undefined} onClick={() => setFilter('assessments')}>Assessments</button>
        </div>
        <div className="btn-row">
          <button type="button" className="btn btn-sm" disabled={archived} onClick={() => { setFormError(null); setAssignModal(true); }}>New assignment</button>
          <button type="button" className="btn btn-sm" disabled={archived} onClick={() => { setFormError(null); setAssessModal(true); }}>New assessment</button>
        </div>
      </div>

      {error ? <ErrorNotice error={error} onRetry={() => load(aPage, qPage)} /> : null}
      {loading ? (
        <SkeletonTable rows={5} />
      ) : error ? null : (
        <>
          {showAssignments && (
            <>
              <div className="section-head"><h2>Assignments</h2><span className="count">{aTotal}</span></div>
              {assignments.length === 0 ? (
                <EmptyState title="No assignments yet" hint="Post the first assignment for this classroom." />
              ) : (
                <div className="row-list">
                  {assignments.map((a) => (
                    <Link key={a.id} to={`/teacher/assignments/${a.id}`} className="row">
                      <div className="main">
                        <div className="title">{a.title}</div>
                        <div className="meta">Assignment · Due {formatDateTime(a.due_date)} · {a.submission_count ?? 0} submissions</div>
                      </div>
                      <div className="side"><span className="pill pill-blue">{a.submission_count ?? 0} submitted</span></div>
                    </Link>
                  ))}
                </div>
              )}
              <Pager page={aPage} pages={aPages} onChange={(p) => load(p, qPage)} />
            </>
          )}
          {showAssessments && (
            <>
              <div className="section-head"><h2>Assessments</h2><span className="count">{qTotal}</span></div>
              {assessments.length === 0 ? (
                <EmptyState title="No assessments yet" hint="Create a Recorded or Unrecorded assessment. The type locks on creation." />
              ) : (
                <div className="row-list">
                  {assessments.map((a) => (
                    <Link key={a.id} to={`/teacher/assessments/${a.id}`} className="row">
                      <div className="main">
                        <div className="title">{a.title}</div>
                        <div className="meta">{a.type || 'Assessment'} · {a.item_count ?? 0} items · {a.status === 'released' ? 'Released' : 'Draft'}</div>
                      </div>
                      <div className="side">
                        {a.status === 'draft' ? <span className="pill pill-neutral">Draft</span> : <span className="pill pill-blue">Released</span>}
                      </div>
                    </Link>
                  ))}
                </div>
              )}
              <Pager page={qPage} pages={qPages} onChange={(p) => load(aPage, p)} />
            </>
          )}
        </>
      )}

      {assignModal && (
        <Modal
          title="New assignment"
          onClose={() => (!saving ? setAssignModal(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setAssignModal(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleCreateAssignment}>{saving ? 'Creating…' : 'Create assignment'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleCreateAssignment}>
            <FormField label="Title">
              <input value={aTitle} onChange={(e) => setATitle(e.target.value)} placeholder="e.g. Problem Set 6" maxLength={255} />
            </FormField>
            <FormField label="Description">
              <textarea value={aDesc} onChange={(e) => setADesc(e.target.value)} placeholder="Instructions for students" />
            </FormField>
            <div className="form-grid-2">
              <FormField label="Due date">
                <input type="date" value={aDue} onChange={(e) => setADue(e.target.value)} />
              </FormField>
              <FormField label="Attach files">
                <input type="file" multiple accept={ACCEPT} onChange={(e) => setAFiles(Array.from(e.target.files || []))} />
              </FormField>
            </div>
            {aFiles.length > 0 && <div className="note-line note-line--flush">{aFiles.length} file{aFiles.length === 1 ? '' : 's'} selected. Max 15 MB each.</div>}
          </form>
        </Modal>
      )}

      {assessModal && (
        <Modal
          title="New assessment"
          sub="Set the basics now — you add items on the next screen. Recorded/Unrecorded cannot be changed after creation."
          onClose={() => (!saving ? setAssessModal(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setAssessModal(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleCreateAssessment}>{saving ? 'Creating…' : 'Create and add items'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleCreateAssessment}>
            <FormField label="Title">
              <input value={qTitle} onChange={(e) => setQTitle(e.target.value)} placeholder="e.g. Unit 3 Assessment" maxLength={255} />
            </FormField>
            <FormField label="Description (optional)">
              <textarea value={qDesc} onChange={(e) => setQDesc(e.target.value)} />
            </FormField>
            <div className="form-grid-2">
              <FormField label="Type (locked after creation)">
                <select value={qType} onChange={(e) => setQType(e.target.value)}>
                  <option>Recorded</option>
                  <option>Unrecorded</option>
                </select>
              </FormField>
              <FormField label="Time limit (minutes, optional)">
                <input type="number" min={1} value={qTime} onChange={(e) => setQTime(e.target.value)} placeholder="e.g. 45" />
              </FormField>
            </div>
            <div className="form-grid-2">
              <FormField label="Available from (optional)">
                <input type="datetime-local" value={qStart} onChange={(e) => setQStart(e.target.value)} />
              </FormField>
              <FormField label="Available until (optional)">
                <input type="datetime-local" value={qEnd} onChange={(e) => setQEnd(e.target.value)} />
              </FormField>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}
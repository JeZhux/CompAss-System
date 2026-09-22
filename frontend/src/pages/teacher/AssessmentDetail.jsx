import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import '../../components/teacher/teacher.css';
import { PageHead, InlineAlert, EmptyState, SkeletonTable, Modal, useConfirm } from '../../components/teacher/ui.jsx';
import FormField from '../../components/shared/FormField.jsx';
import { friendlyError } from '../../components/shared/errors.js';
import { ErrorNotice } from '../../components/shared/Feedback.jsx';
import {
  getTeacherAssessment,
  updateTeacherAssessment,
  deleteTeacherAssessment,
  createAssessmentItem,
  deleteAssessmentItem,
  releaseAssessment,
  listPendingGrading,
  releaseAssessmentResults,
  requestResubmission,
  listClassroomCompetencyTags,
  isCompetencyMismatch,
  competencyMismatchMessage,
  getClassroomCompetencySummary,
  firstFieldError,
  formatDateTime,
  ApiError,
} from '../../components/teacher/teacherApi.js';

const ACCEPT = '.pdf,.docx,.pptx,.xlsx,.jpg,.jpeg,.png,.zip';
const MAX_FILE_BYTES = 15 * 1024 * 1024;

function oversizedFile(files) {
  return (files || []).find((f) => Number(f?.size) > MAX_FILE_BYTES);
}

export default function AssessmentDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [assessment, setAssessment] = useState(null);
  const [pending, setPending] = useState([]);
  const [pendingTotal, setPendingTotal] = useState(0);
  // Pending-list failures are tracked separately: only a genuine empty list
  // shows the empty state, never a failed fetch.
  const [pendingErr, setPendingErr] = useState(null);
  const [attempts, setAttempts] = useState([]);
  const [competencies, setCompetencies] = useState([]);
  const [pickerError, setPickerError] = useState(null);
  const [itemModal, setItemModal] = useState(false);
  const [itemType, setItemType] = useState('multiple_choice');
  const [prompt, setPrompt] = useState('');
  const [maxPoints, setMaxPoints] = useState('2');
  const [competencyTagId, setCompetencyTagId] = useState('');
  const [correctAnswer, setCorrectAnswer] = useState('');
  const [itemFiles, setItemFiles] = useState([]);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [editOpen, setEditOpen] = useState(false);
  const [eTitle, setETitle] = useState('');
  const [eDesc, setEDesc] = useState('');
  const [resubmitFor, setResubmitFor] = useState(null);
  const [resubmitReason, setResubmitReason] = useState('');
  const [resultsReleased, setResultsReleased] = useState(false);
  const { ask, node: confirmNode } = useConfirm();
  // Double-submit guard for teacher creates (add-item): the ref blocks a
  // second click before `saving` disables the button.
  const savingRef = useRef(false);
  // In-flight guard for the one-way release actions (freeze + results):
  // the ref blocks a second confirm while the request is in flight.
  const releasingRef = useRef(false);
  const [releasing, setReleasing] = useState(false);
  const cancelledRef = useRef(false);

  async function load() {
    setLoading(true);
    setError(null);
    setPendingErr(null);
    try {
      const data = await getTeacherAssessment(id);
      if (cancelledRef.current) return;
      setAssessment(data);
      setResultsReleased(Boolean(data?.results_released_at || data?.resultsReleased));
      let pend = null;
      let pendErr = null;
      try {
        pend = await listPendingGrading({ assessment_id: id, per_page: 50 });
      } catch (e) {
        pendErr = e;
      }
      if (cancelledRef.current) return;
      if (pendErr) {
        setPendingErr(pendErr);
        setPending([]);
        setPendingTotal(0);
        setAttempts([]);
      } else {
        const pendList = Array.isArray(pend?.data) ? pend.data : [];
        setPending(pendList);
        setPendingTotal(Number(pend?.meta?.total ?? pendList.length));
        // No scored-attempt roster endpoint exists (teacherShow carries items
        // only; pending-grading lists status=pending_grading): this list is
        // honestly the awaiting-scoring queue, not "all attempts".
        setAttempts(pendList);
      }
      try {
        // Competency picker: competency-context triple → filtered catalog
        // (?subject_id&grade_level&semester) for this classroom, so every
        // option already matches the COMPETENCY_MISMATCH triple rule.
        let arr = [];
        let pickerErr = null;
        if (data?.classroom_id) {
          try {
            const res = await listClassroomCompetencyTags(data.classroom_id);
            const list = res?.data ?? [];
            arr = Array.isArray(list) ? list : [];
          } catch (e) {
            pickerErr = e;
          }
        }
        if (arr.length === 0) {
          // Fallback to the classroom competency summary (aggregate) so the
          // picker never renders empty on a catalog read failure.
          const comp = data?.classroom_id
            ? await getClassroomCompetencySummary(data.classroom_id).catch(() => null)
            : null;
          const list = comp?.competencies || [];
          arr = Array.isArray(list) ? list : [];
          if (arr.length > 0) pickerErr = null;
        }
        setCompetencies(arr);
        setPickerError(pickerErr);
      } catch {
        setCompetencies([]);
      }
    } catch (err) {
      if (cancelledRef.current) return;
      if (err instanceof ApiError && err.status === 404) setError('Assessment not found. If this keeps happening, contact your school administrator.');
      else setError(err);
      setAssessment(null);
    } finally {
      if (!cancelledRef.current) setLoading(false);
    }
  }

  useEffect(() => {
    cancelledRef.current = false;
    load();
    return () => { cancelledRef.current = true; };
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, [id]);

  const items = Array.isArray(assessment?.items) ? assessment.items : [];
  const isDraft = (assessment?.status || 'draft') === 'draft';
  const canRelease = isDraft && items.length > 0;

  async function handleRelease() {
    if (releasingRef.current || releasing) return;
    ask('Release this assessment?', 'Students will see it and the structure freezes — items can no longer be added, edited, or removed. This cannot be undone.', 'Release', async () => {
      if (releasingRef.current) return;
      releasingRef.current = true;
      setReleasing(true);
      setNotice(null);
      setError(null);
      try {
        await releaseAssessment(id);
        setNotice('Assessment released. Structure is now frozen.');
        await load();
      } catch (err) {
        setError(err);
      } finally {
        releasingRef.current = false;
        setReleasing(false);
      }
    });
  }

  async function handleAddItem(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    if (!prompt.trim()) { setFormError('Prompt is required.'); return; }
    const pts = Number(maxPoints);
    if (!Number.isFinite(pts) || pts < 0.01) { setFormError('Max points must be at least 0.01.'); return; }
    if (!competencyTagId) { setFormError('Choose a competency tag for this item.'); return; }
    if ((itemType === 'multiple_choice' || itemType === 'true_false') && !correctAnswer.trim()) {
      setFormError('Correct answer is required for multiple-choice and true/false items.');
      return;
    }
    const big = oversizedFile(itemFiles);
    if (big) { setFormError(`"${big.name || 'File'}" exceeds 15 MB. Remove it or choose a smaller file.`); return; }
    savingRef.current = true;
    setSaving(true);
    try {
      const nextOrder = items.length;
      await createAssessmentItem(id, {
        item_type: itemType,
        prompt: prompt.trim(),
        max_points: pts,
        correct_answer: correctAnswer.trim() || undefined,
        competency_tag_id: Number(competencyTagId),
        sort_order: nextOrder,
        files: itemFiles,
      });
      setItemModal(false);
      setPrompt(''); setMaxPoints('2'); setCompetencyTagId(''); setCorrectAnswer(''); setItemFiles([]);
      setNotice('Item added.');
      await load();
    } catch (err) {
      if (isCompetencyMismatch(err)) setFormError(competencyMismatchMessage());
      else setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleRemoveItem(itemId) {
    ask('Remove this item?', 'The item and its correct answer are removed. This cannot be undone.', 'Remove', async () => {
      try {
        await deleteAssessmentItem(itemId);
        setNotice('Item removed.');
        await load();
      } catch (err) {
        setError(err);
      }
    }, true);
  }

  async function handleDelete() {
    ask('Delete this draft assessment?', 'Items are removed with it. Released assessments cannot be deleted.', 'Delete draft', async () => {
      try {
        await deleteTeacherAssessment(id);
        navigate(assessment?.classroom_id ? `/teacher/classrooms/${assessment.classroom_id}?tab=classwork` : '/teacher/classrooms');
      } catch (err) {
        setError(err);
      }
    }, true);
  }

  async function handleEditSave(e) {
    e?.preventDefault();
    if (savingRef.current || saving) return;
    setFormError(null);
    savingRef.current = true;
    setSaving(true);
    try {
      await updateTeacherAssessment(id, { title: eTitle.trim() || undefined, description: eDesc });
      setEditOpen(false);
      setNotice('Assessment updated.');
      await load();
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function handleReleaseResults() {
    if (releasingRef.current || releasing) return;
    ask('Release results to students?', 'Every student will see their scores and feedback. This cannot be undone.', 'Release results', async () => {
      if (releasingRef.current) return;
      releasingRef.current = true;
      setReleasing(true);
      setNotice(null);
      setError(null);
      try {
        await releaseAssessmentResults(id);
        setResultsReleased(true);
        setNotice('Results released to students.');
      } catch (err) {
        setError(err);
      } finally {
        releasingRef.current = false;
        setReleasing(false);
      }
    });
  }

  async function handleResubmit() {
    if (savingRef.current || saving) return;
    setFormError(null);
    const reason = resubmitReason.trim();
    if (reason.length < 1 || reason.length > 1000) {
      setFormError('Reason is required (1–1000 characters).');
      return;
    }
    savingRef.current = true;
    setSaving(true);
    try {
      await requestResubmission(id, resubmitFor.attempt_id ?? resubmitFor.attemptId ?? resubmitFor.id, reason);
      setResubmitFor(null);
      setResubmitReason('');
      setNotice('Resubmission requested. The student receives a new numbered attempt.');
      await load();
    } catch (err) {
      setFormError(firstFieldError(err) || friendlyError(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  if (loading) return <div className="teacher-page"><SkeletonTable rows={6} /></div>;
  if (error && !assessment) return <div className="teacher-page"><ErrorNotice error={error} onRetry={load} /></div>;
  if (!assessment) return <div className="teacher-page"><EmptyState title="Assessment not found" /></div>;

  const backTo = assessment.classroom_id ? `/teacher/classrooms/${assessment.classroom_id}?tab=classwork` : '/teacher/classrooms';

  return (
    <div className="teacher-page">
      <div className="crumb"><Link to={backTo}>Classwork</Link> / {assessment.title}</div>
      <PageHead
        title={assessment.title}
        sub={`${assessment.type || 'Assessment'} · ${assessment.status === 'released' ? 'Released' : 'Draft'} · ${items.length} item${items.length === 1 ? '' : 's'}`}
        actions={
          <>
            {isDraft && <button type="button" className="btn btn-sm" onClick={() => { setETitle(assessment.title || ''); setEDesc(assessment.description || ''); setFormError(null); setEditOpen(true); }}>Edit</button>}
            {isDraft && <button type="button" className="btn btn-sm btn-danger" onClick={handleDelete}>Delete draft</button>}
            {isDraft && (
              <button type="button" className="btn btn-primary btn-sm" disabled={!canRelease || releasing} onClick={handleRelease}>
                {releasing ? 'Releasing…' : canRelease ? 'Release' : 'Add ≥1 item to release'}
              </button>
            )}
          </>
        }
      />
      {error ? <ErrorNotice error={error} onRetry={load} /> : null}
      {notice && <InlineAlert kind="ok">{notice}</InlineAlert>}
      {confirmNode}
      {assessment.description && <p className="lede">{assessment.description}</p>}
      <div className="assess-meta">
        {assessment.time_limit ? <span>Time limit: {assessment.time_limit} min</span> : null}
        {assessment.availability_starts_at || assessment.availability_ends_at ? (
          <span>Window: {formatDateTime(assessment.availability_starts_at)} → {formatDateTime(assessment.availability_ends_at)}</span>
        ) : null}
        <span>{items.length} items</span>
      </div>

      <div className="section-head">
        <h2>Items</h2>
        {isDraft ? <button type="button" className="btn btn-sm" onClick={() => { setFormError(null); setItemModal(true); }}>Add item</button> : null}
      </div>
      {!isDraft && <div className="note-line mb-10">Structure is frozen — items can no longer be added, edited, or removed after release.</div>}
      {items.length === 0 ? (
        <EmptyState title="No items yet" hint="Add at least one to release this assessment." />
      ) : (
        items.map((it) => (
          <div key={it.id} className="item-card">
            <div className="top">
              <div>
                <div className="prompt">{it.prompt}</div>
                <div className="meta">{String(it.item_type || '').replace('_', ' ')} · {it.max_points} pt{Number(it.max_points) > 1 ? 's' : ''} · tagged: {it.competency_tag_id ?? '—'}{it.correct_answer ? ` · correct: ${it.correct_answer}` : ''}</div>
              </div>
              {isDraft
                ? <button type="button" className="btn btn-sm btn-danger" onClick={() => handleRemoveItem(it.id)}>Remove</button>
                : (
                  <div className="lock">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true" focusable="false">
                      <rect x="2.5" y="5.5" width="7" height="4.5" rx="1" />
                      <path d="M4 5.5V4a2 2 0 0 1 4 0v1.5" />
                    </svg>
                    <span>Locked</span>
                  </div>
                )}
            </div>
          </div>
        ))
      )}

      {!isDraft && (
        <>
          <div className="section-head"><h2>Pending grading</h2><span className="count">{pendingTotal} awaiting</span></div>
          {pendingErr ? <ErrorNotice error={pendingErr} onRetry={load} /> : null}
          {pendingErr ? null : pending.length === 0 ? (
            <EmptyState title="Nothing waiting on you" hint="Every subjective item has been scored." />
          ) : (
            <div className="row-list">
              {pending.map((at) => (
                <div key={at.id} className="row" role="button" tabIndex={0} onClick={() => navigate(`/teacher/assessments/${id}/score/${at.id}`)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); navigate(`/teacher/assessments/${id}/score/${at.id}`); } }}>
                  <div className="main">
                    <div className="title">{at.student_name || `Submission ${at.id}`}</div>
                    <div className="meta">Submitted {formatDateTime(at.submitted_at || at.created_at)} · awaiting a score</div>
                  </div>
                  <div className="side"><span className="pill pill-amber">Pending</span></div>
                </div>
              ))}
              {pendingTotal > pending.length && (
                <div className="note-line">Showing {pending.length} of {pendingTotal} — page through Bulk grade to reach them all.</div>
              )}
            </div>
          )}

          <div className="section-head">
            <h2>Awaiting scoring</h2>
            <button type="button" className="btn btn-sm" onClick={() => navigate(`/teacher/assessments/${id}/bulk`)}>Bulk grade</button>
          </div>
          <div className="note-line">Only submissions still awaiting a score appear here — scored submissions leave this list.</div>
          {pendingErr ? null : attempts.length === 0 ? (
            <EmptyState title="Nothing awaiting scoring" hint="Submissions appear here once students submit, and leave once scored." />
          ) : (
            <table className="data-table">
              <thead><tr><th>Student</th><th>Status</th><th></th></tr></thead>
              <tbody>
                {attempts.map((at) => (
                  <tr key={at.id}>
                    <td><Link to={`/teacher/assessments/${id}/score/${at.id}`}>{at.student_name || `Submission ${at.id}`}</Link></td>
                    <td><span className="pill pill-amber">Pending</span></td>
                    <td>
                      <button
                        type="button"
                        className="btn btn-sm"
                        onClick={(e) => { e.stopPropagation(); setResubmitFor(at); setResubmitReason(''); setFormError(null); }}
                      >
                        Request resubmission
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          <div className="section-head"><h2>Release results</h2></div>
          {resultsReleased ? (
            <div className="ok-line">Results have been released to students.</div>
          ) : pending.length > 0 ? (
            <div className="block-line">Blocked — {pendingTotal} attempt{pendingTotal === 1 ? '' : 's'} still {pendingTotal === 1 ? 'has' : 'have'} pending subjective grading.</div>
          ) : (
            <button type="button" className="btn btn-primary" disabled={releasing} onClick={handleReleaseResults}>{releasing ? 'Releasing…' : 'Release results to students'}</button>
          )}
        </>
      )}

      {editOpen && (
        <Modal
          title="Edit assessment"
          sub="Only title and description can change. Type locks at creation; items lock after release."
          onClose={() => (!saving ? setEditOpen(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setEditOpen(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleEditSave}>{saving ? 'Saving…' : 'Save changes'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleEditSave}>
            <FormField label="Title">
              <input value={eTitle} onChange={(e) => setETitle(e.target.value)} maxLength={255} />
            </FormField>
            <FormField label="Description">
              <textarea value={eDesc} onChange={(e) => setEDesc(e.target.value)} />
            </FormField>
          </form>
        </Modal>
      )}

      {itemModal && (
        <Modal
          title="Add item"
          sub="Multiple-choice and true/false need a correct answer. Essays are scored by hand."
          wide
          onClose={() => (!saving ? setItemModal(false) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setItemModal(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleAddItem}>{saving ? 'Adding…' : 'Add item'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={handleAddItem}>
            <FormField label="Type">
              <select value={itemType} onChange={(e) => setItemType(e.target.value)}>
                <option value="multiple_choice">Multiple choice</option>
                <option value="true_false">True / False</option>
                <option value="essay">Essay (subjective)</option>
              </select>
            </FormField>
            <FormField label="Prompt">
              <textarea value={prompt} onChange={(e) => setPrompt(e.target.value)} placeholder="Question text" />
            </FormField>
            <div className="form-grid-2">
              <FormField label="Max points">
                <input type="number" min={0.01} step="0.01" value={maxPoints} onChange={(e) => setMaxPoints(e.target.value)} />
              </FormField>
              <FormField label="Competency tag" hint="Filtered to this classroom's subject, grade level, and semester.">
                <select value={competencyTagId} onChange={(e) => setCompetencyTagId(e.target.value)}>
                  <option value="">Select a tag…</option>
                  {competencies.map((c) => (
                    <option key={c.competency_id ?? c.id} value={c.competency_id ?? c.id}>
                      {(c.code ? `${c.code} — ` : '') + (c.descriptor || `Competency ${c.competency_id ?? c.id}`)}
                    </option>
                  ))}
                </select>
              </FormField>
              {pickerError && <div className="note-line">Couldn&apos;t refresh the filtered competency list — showing the last known options. If this keeps happening, contact your school administrator.</div>}
            </div>
            <FormField label={<>Correct answer {(itemType === 'multiple_choice' || itemType === 'true_false') ? '(required)' : '(objective items only)'}</>}>
              <input value={correctAnswer} onChange={(e) => setCorrectAnswer(e.target.value)} maxLength={1000} placeholder="e.g. x = 5" />
            </FormField>
            <FormField label="Attachments (optional)">
              <input type="file" multiple accept={ACCEPT} onChange={(e) => setItemFiles(Array.from(e.target.files || []))} />
            </FormField>
            {itemFiles.length > 0 && <div className="note-line">{itemFiles.length} file{itemFiles.length === 1 ? '' : 's'} selected. Max 15 MB each.</div>}
          </form>
        </Modal>
      )}

      {resubmitFor && (
        <Modal
          title="Request resubmission"
          sub="This creates a new numbered attempt for the student. Explain why so they understand what changed."
          onClose={() => (!saving ? setResubmitFor(null) : null)}
          actions={
            <>
              <button type="button" className="btn btn-quiet" disabled={saving} onClick={() => setResubmitFor(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={saving} onClick={handleResubmit}>{saving ? 'Sending…' : 'Send resubmission request'}</button>
            </>
          }
        >
          {formError && <div className="modal-msg err">{formError}</div>}
          <form className="stacked" onSubmit={(e) => { e.preventDefault(); handleResubmit(); }}>
            <FormField label="Reason (required, 1–1000 characters)">
              <textarea value={resubmitReason} onChange={(e) => setResubmitReason(e.target.value)} maxLength={1000} placeholder="e.g. Technical issue cut off your last two answers — please retake." />
            </FormField>
            <div className="note-line">{resubmitReason.trim().length}/1000</div>
          </form>
        </Modal>
      )}
    </div>
  );
}
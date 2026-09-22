import { useState } from 'react';
import Modal from '../shared/Modal.jsx';
import { StatusPill } from '../shared/DataTable.jsx';
import { EmptyState as SharedEmpty, LoadingSkeleton, Notice } from '../shared/Feedback.jsx';
import './admin.css';

export function PageHead({ title, sub, actions }) {
  return (
    <div className="page-head">
      <div className="page-head-row">
        <div className="page-head-main">
          <h2>{title}</h2>
          {sub && <p className="page-sub">{sub}</p>}
        </div>
        {actions && <div className="page-head-actions">{actions}</div>}
      </div>
    </div>
  );
}

export function StatCard({ value, label, sub }) {
  return (
    <div className="stat-card">
      <div className="num">{value}</div>
      <div className="lbl">{label}</div>
      {sub && <div className="sub">{sub}</div>}
    </div>
  );
}

export function InlineAlert({ kind = 'note', children }) {
  if (!children) return null;
  const tone = kind === 'error' ? 'error' : kind === 'warn' ? 'warn' : kind === 'ok' ? 'ok' : 'note';
  return <Notice tone={tone}>{children}</Notice>;
}

export function EmptyState({ title = 'Nothing here yet', hint, action }) {
  return <SharedEmpty title={title} hint={hint} action={action} />;
}

export function SkeletonTable({ rows = 5 }) {
  return <LoadingSkeleton rows={rows} />;
}

export function useConfirm() {
  const [pending, setPending] = useState(null);
  const ask = (title, sub, confirmLabel, onConfirm, danger = false) =>
    setPending({ title, sub, confirmLabel, onConfirm, danger });
  const close = () => setPending(null);
  const node = pending ? (
    <Modal
      title={pending.title}
      sub={pending.sub}
      onClose={close}
      actions={
        <>
          <button type="button" className="btn btn-quiet" onClick={close}>Cancel</button>
          <button
            type="button"
            className={pending.danger ? 'btn btn-danger' : 'btn btn-primary'}
            onClick={async () => {
              const fn = pending.onConfirm;
              setPending(null);
              await fn?.();
            }}
          >
            {pending.confirmLabel || 'Confirm'}
          </button>
        </>
      }
    />
  ) : null;
  return { ask, close, node };
}

export function toneForRole(role) {
  const r = String(role || '').toLowerCase();
  if (r === 'admin') return 'purple';
  if (r === 'teacher') return 'blue';
  return 'green';
}

export function RolePill({ role }) {
  const r = String(role || 'Student');
  const label = r.charAt(0).toUpperCase() + r.slice(1).toLowerCase();
  return <StatusPill tone={toneForRole(r)}>{label}</StatusPill>;
}

export function ActivePill({ active }) {
  return active ? <StatusPill tone="green">Active</StatusPill> : <StatusPill tone="neutral">Deactivated</StatusPill>;
}

import Modal from '../shared/Modal.jsx';
import Pager from '../shared/Pager.jsx';
import { StatusPill } from '../shared/DataTable.jsx';
import { EmptyState as SharedEmpty, LoadingSkeleton, Notice } from '../shared/Feedback.jsx';
import './student.css';

export { Modal, Pager, StatusPill };

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

export function MasteryBar({ pct }) {
  const n = Number(pct);
  const safe = Number.isFinite(n) ? Math.max(0, Math.min(100, n)) : 0;
  const toneCls = safe >= 80 ? 'tone-green' : safe >= 60 ? 'tone-amber' : 'tone-red';
  return (
    <span className="mastery-bar-track" aria-label={`${safe}%`}>
      <span className={`mastery-bar-fill ${toneCls}`} style={{ width: `${safe}%` }} />
    </span>
  );
}

export function initialsOf(name) {
  return String(name || '?')
    .trim()
    .split(/\s+/)
    .map((x) => x[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

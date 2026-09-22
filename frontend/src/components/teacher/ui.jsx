import { useState } from 'react';
import Modal from '../shared/Modal.jsx';
import Pager from '../shared/Pager.jsx';
import { StatusPill } from '../shared/DataTable.jsx';
import { EmptyState as SharedEmpty, LoadingSkeleton, Notice } from '../shared/Feedback.jsx';
import './teacher.css';

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

export function TrendSvg({ series = [], labels = [], width = 560, height = 200 }) {
  const pad = 34;
  const colors = ['var(--green)', 'var(--blue)', 'var(--amber)', 'var(--purple)'];
  function pointsFor(vals) {
    if (!vals.length) return '';
    if (vals.length === 1) {
      const y = height - pad - (Number(vals[0]) / 100) * (height - 2 * pad);
      return `${pad},${y} ${width - pad},${y}`;
    }
    return vals.map((v, i) => {
      const n = Math.max(0, Math.min(100, Number(v) || 0));
      const x = pad + (i / (vals.length - 1)) * (width - 2 * pad);
      const y = height - pad - (n / 100) * (height - 2 * pad);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
  }
  function dotsFor(vals, color) {
    return vals.map((v, i) => {
      const n = Math.max(0, Math.min(100, Number(v) || 0));
      const x = vals.length === 1 ? pad + (width - 2 * pad) / 2 : pad + (i / (vals.length - 1)) * (width - 2 * pad);
      const y = height - pad - (n / 100) * (height - 2 * pad);
      return <circle key={i} cx={x} cy={y} r={3.5} fill={color}><title>{`${n}%`}</title></circle>;
    });
  }
  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="trend-svg" role="img" aria-label="Mastery trend chart">
      <line x1={pad} y1={height - pad} x2={width - pad} y2={height - pad} stroke="var(--line-strong)" />
      <line x1={pad} y1={pad} x2={pad} y2={height - pad} stroke="var(--line-strong)" />
      {[80, 60].map((v) => {
        const y = height - pad - (v / 100) * (height - 2 * pad);
        return (
          <g key={v}>
            <line x1={pad} y1={y} x2={width - pad} y2={y} stroke="var(--line)" strokeDasharray="4 4" />
            <text x={4} y={y + 4} fontSize={9} fill="var(--ink-faint)">{`${v}%`}</text>
          </g>
        );
      })}
      {series.map((s, i) => {
        const color = colors[i % colors.length];
        return (
          <g key={s.key || i}>
            <polyline points={pointsFor(s.values)} fill="none" stroke={color} strokeWidth={2.5} />
            {dotsFor(s.values, color)}
          </g>
        );
      })}
      {labels.length > 0 && (
        <g fontSize={9} fill="var(--ink-faint)">
          {labels.map((d, i) => {
            const x = labels.length === 1 ? width / 2 : pad + (i / (labels.length - 1)) * (width - 2 * pad);
            return <text key={i} x={x} y={height - 8} textAnchor="middle">{d}</text>;
          })}
        </g>
      )}
    </svg>
  );
}
